<?php

namespace Noo\CraftRemoteSync\console\controllers;

use Closure;
use craft\console\Controller;
use Noo\CraftRemoteSync\console\traits\ResolvesRemote;
use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Snapshots\Snapshots;
use Noo\CraftRemoteSync\Support\CleanupStack;
use Noo\CraftRemoteSync\Sync\Direction;
use Noo\CraftRemoteSync\Sync\Planner;
use Noo\CraftRemoteSync\Sync\Rsync;
use Noo\CraftRemoteSync\Sync\SyncPlan;
use RuntimeException;
use yii\console\ExitCode;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\warning;

/**
 * Push the local database and/or storage files to a remote environment.
 *
 * Usage: craft remote-sync/push [remote] [--database] [--files] [--no-backup]
 *        [--delete] [--path=app,uploads] [--dry-run] [--force]
 */
class PushController extends Controller
{
    use ResolvesRemote;

    public $defaultAction = 'index';

    public bool $noBackup = false;

    public bool $delete = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'database', 'files', 'force', 'verbose', 'dryRun',
            'noBackup', 'delete', 'path',
        ]);
    }

    public function actionIndex(?string $remote = null): int
    {
        return $this->sync(Direction::Push, $remote);
    }

    protected function plan(Planner $planner, bool $database, bool $files, array $paths): SyncPlan
    {
        return $planner->push(
            database: $database,
            files: $files,
            backup: ! $this->noBackup,
            delete: $this->delete,
            paths: $paths,
        );
    }

    /**
     * Pushing overwrites shared data, so the scope must be explicit in scripts.
     *
     * @return array{0: bool, 1: bool}|null
     */
    protected function resolveScope(): ?array
    {
        if ($this->database || $this->files) {
            return [$this->database, $this->files];
        }

        if (! $this->isInteractive()) {
            error($this->t('errors.push_scope_required'));

            return null;
        }

        $selected = multiselect(
            label: $this->t('prompts.operations.push_label'),
            options: [
                'database' => $this->t('prompts.operations.database'),
                'files' => $this->t('prompts.operations.files'),
            ],
            default: ['database'],
            required: true,
        );

        return [in_array('database', $selected, true), in_array('files', $selected, true)];
    }

    protected function executePlan(string $remoteName, Connection $connection, RemoteInfo $info, SyncPlan $plan, Rsync $rsync): int
    {
        $cleanup = new CleanupStack();
        $snapshots = new Snapshots($connection, $info);

        $this->ensureCleanupOnExit($cleanup);

        try {
            if (! $this->runSteps($this->buildSteps($remoteName, $connection, $info, $plan, $snapshots, $rsync, $cleanup))) {
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } finally {
            $this->runCleanup($cleanup);
        }

        info($this->t('success.pushed', ['scope' => $plan->scopeSummary(), 'name' => $remoteName]));

        return ExitCode::OK;
    }

    /**
     * @return list<array{label: string, run: Closure(): ?string}>
     */
    protected function buildSteps(string $remoteName, Connection $connection, RemoteInfo $info, SyncPlan $plan, Snapshots $snapshots, Rsync $rsync, CleanupStack $cleanup): array
    {
        $steps = [];

        if ($plan->database) {
            if ($plan->backup) {
                $steps[] = [
                    'label' => "Creating remote backup {$plan->backupName} on [{$remoteName}]",
                    'run' => function () use ($snapshots, $plan): ?string {
                        $result = $snapshots->createRemote($plan->backupName, zip: true);

                        return $result->successful()
                            ? null
                            : $this->t('errors.failed_remote_backup', ['error' => trim($result->errorOutput())]);
                    },
                ];
            }

            $steps[] = [
                'label' => 'Creating local snapshot',
                'run' => function () use ($snapshots, $plan, $cleanup): ?string {
                    try {
                        $snapshots->createLocal($plan->snapshotName, zip: false);
                    } catch (RuntimeException $e) {
                        return $this->t('errors.failed_local_snapshot', ['error' => $e->getMessage()]);
                    }

                    Snapshots::sanitize(Snapshots::localPath($plan->snapshotName));
                    $cleanup->push('local snapshot file', fn () => Snapshots::deleteLocal($plan->snapshotName));

                    return null;
                },
            ];

            $steps[] = [
                'label' => "Uploading snapshot to [{$remoteName}]",
                'run' => function () use ($snapshots, $plan, $cleanup, $remoteName): ?string {
                    $result = $snapshots->upload($plan->snapshotName);

                    if (! $result->successful()) {
                        return $this->t('errors.failed_upload_snapshot', ['error' => trim($result->errorOutput())]);
                    }

                    $cleanup->push("temporary snapshot on [{$remoteName}]", function () use ($snapshots, $plan) {
                        if (! $snapshots->deleteRemote($plan->snapshotName)->successful()) {
                            throw new RuntimeException("delete it manually: {$plan->snapshotName}");
                        }
                    });

                    return null;
                },
            ];

            $steps[] = [
                'label' => "Loading snapshot on [{$remoteName}]",
                'run' => function () use ($snapshots, $plan, $info, $remoteName): ?string {
                    $result = $snapshots->loadRemote($plan->snapshotName);

                    if (! $result->successful()) {
                        if ($plan->backup) {
                            $this->failureNotes[] = $this->t('info.remote_restore_hint', [
                                'name' => $remoteName,
                                'path' => $info->backupDir().'/'.$plan->backupName.'.sql.zip',
                            ]);
                        }

                        return $this->t('errors.failed_remote_load', ['error' => trim($result->errorOutput())]);
                    }

                    return null;
                },
            ];

            $steps[] = [
                'label' => "Running craft up on [{$remoteName}]",
                'run' => function () use ($connection, $info, $remoteName): ?string {
                    if (! $connection->craft($info, 'up --interactive=0')->successful()) {
                        warning($this->t('warnings.remote_up_failed', ['name' => $remoteName]));
                    }

                    return null;
                },
            ];
        }

        foreach ($plan->paths as $path) {
            $steps[] = [
                'label' => "Syncing files storage/{$path} to [{$remoteName}]",
                'run' => function () use ($rsync, $info, $plan, $path): ?string {
                    $localPath = \Craft::$app->getPath()->getStoragePath().DIRECTORY_SEPARATOR.$path;

                    if (! is_dir($localPath)) {
                        warning($this->t('warnings.local_path_not_exists', ['path' => $path]));

                        return null;
                    }

                    $result = $rsync->transfer(
                        Direction::Push,
                        "{$info->storagePath()}/{$path}/",
                        rtrim($localPath, '/').'/',
                        delete: $plan->delete,
                    );

                    return $result->successful()
                        ? null
                        : $this->t('errors.failed_file_sync', ['path' => $path, 'error' => trim($result->errorOutput())]);
                },
            ];
        }

        return $steps;
    }
}

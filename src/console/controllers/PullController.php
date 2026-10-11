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
 * Pull the database and/or storage files from a remote environment.
 *
 * Usage: craft remote-sync/pull [remote] [--database] [--files] [--no-backup]
 *        [--discard-snapshot] [--delete] [--path=app,uploads] [--dry-run] [--force]
 */
class PullController extends Controller
{
    use ResolvesRemote;

    public $defaultAction = 'index';

    public bool $noBackup = false;

    public bool $discardSnapshot = false;

    public bool $delete = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'database', 'files', 'force', 'verbose', 'dryRun',
            'noBackup', 'discardSnapshot', 'delete', 'path',
        ]);
    }

    public function actionIndex(?string $remote = null): int
    {
        return $this->sync(Direction::Pull, $remote);
    }

    protected function plan(Planner $planner, bool $database, bool $files, array $paths): SyncPlan
    {
        return $planner->pull(
            database: $database,
            files: $files,
            backup: ! $this->noBackup,
            keepSnapshot: ! $this->discardSnapshot,
            delete: $this->delete,
            paths: $paths,
        );
    }

    /**
     * @return array{0: bool, 1: bool}|null
     */
    protected function resolveScope(): ?array
    {
        if ($this->database || $this->files) {
            return [$this->database, $this->files];
        }

        if (! $this->isInteractive() || $this->force) {
            return [true, true];
        }

        $selected = multiselect(
            label: $this->t('prompts.operations.pull_label'),
            options: [
                'database' => $this->t('prompts.operations.database'),
                'files' => $this->t('prompts.operations.files'),
            ],
            default: ['database', 'files'],
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

        info($this->t('success.pulled', ['scope' => $plan->scopeSummary(), 'name' => $remoteName]));

        if ($plan->database && $plan->keepSnapshot) {
            info($this->t('info.snapshot_kept', ['path' => Snapshots::localPath($plan->snapshotName)]));
        }

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
                    'label' => "Creating local backup {$plan->backupName}",
                    'run' => function () use ($snapshots, $plan): ?string {
                        try {
                            $snapshots->createLocal($plan->backupName, zip: true);

                            return null;
                        } catch (RuntimeException $e) {
                            return $this->t('errors.failed_local_backup', ['error' => $e->getMessage()]);
                        }
                    },
                ];
            }

            $steps[] = [
                'label' => "Creating snapshot on [{$remoteName}]",
                'run' => function () use ($snapshots, $plan, $cleanup, $remoteName): ?string {
                    $result = $snapshots->createRemote($plan->snapshotName);

                    if (! $result->successful()) {
                        return $this->t('errors.failed_remote_snapshot', ['error' => trim($result->errorOutput())]);
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
                'label' => "Downloading snapshot from [{$remoteName}]",
                'run' => function () use ($snapshots, $plan, $cleanup): ?string {
                    $result = $snapshots->download($plan->snapshotName);

                    if (! $result->successful()) {
                        return $this->t('errors.failed_download_snapshot', ['error' => trim($result->errorOutput())]);
                    }

                    if (! $plan->keepSnapshot) {
                        $cleanup->push('downloaded snapshot file', fn () => Snapshots::deleteLocal($plan->snapshotName));
                    }

                    return null;
                },
            ];

            $steps[] = [
                'label' => 'Verifying snapshot integrity',
                'run' => fn (): ?string => $snapshots->verifyIntegrity($plan->snapshotName)
                    ? null
                    : $this->t('errors.corrupt_snapshot'),
            ];

            $steps[] = [
                'label' => 'Importing database',
                'run' => function () use ($snapshots, $plan, $cleanup): ?string {
                    $result = $snapshots->restoreLocal($plan->snapshotName);

                    if (! $result->successful()) {
                        $this->noteImportFailure($plan, $cleanup);

                        return $this->t('errors.failed_import', ['error' => trim($result->errorOutput())]);
                    }

                    return null;
                },
            ];

            $steps[] = [
                'label' => 'Running craft up and clearing caches',
                'run' => function () use ($connection): ?string {
                    $this->runCraftMaintenance($connection);

                    return null;
                },
            ];
        }

        foreach ($plan->paths as $path) {
            $steps[] = [
                'label' => "Syncing files storage/{$path}",
                'run' => function () use ($rsync, $info, $plan, $path): ?string {
                    $localPath = \Craft::$app->getPath()->getStoragePath().DIRECTORY_SEPARATOR.$path;

                    if (! is_dir($localPath)) {
                        mkdir($localPath, 0755, true);
                    }

                    $result = $rsync->transfer(
                        Direction::Pull,
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

    protected function runCraftMaintenance(Connection $connection): void
    {
        if (! $this->runLocalCraft($connection, 'up', '--interactive=0')->successful()) {
            warning($this->t('warnings.up_failed'));
        }

        $this->runLocalCraft($connection, 'clear-caches/all', '--interactive=0');
        $this->runLocalCraft($connection, 'gc', '--interactive=0');
    }

    protected function noteImportFailure(SyncPlan $plan, CleanupStack $cleanup): void
    {
        $cleanup->forget('downloaded snapshot file');

        if ($plan->backup) {
            $this->failureNotes[] = $this->t('info.restore_hint', [
                'path' => Snapshots::localPath($plan->backupName).'.zip',
            ]);
        }

        $this->failureNotes[] = $this->t('info.snapshot_kept', [
            'path' => Snapshots::localPath($plan->snapshotName),
        ]);
    }
}

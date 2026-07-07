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
use Noo\CraftRemoteSync\Sync\PlanRenderer;
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

    public ?string $path = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'database', 'files', 'force', 'verbose', 'dryRun',
            'noBackup', 'delete', 'path',
        ]);
    }

    public function actionIndex(?string $remote = null): int
    {
        if (! $this->guardProduction()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $remoteConfig = $this->resolveRemote($remote);

        if ($remoteConfig === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (! $remoteConfig->push) {
            error($this->t('errors.push_not_allowed', ['name' => $remoteConfig->name]));

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $scope = $this->resolveScope();

        if ($scope === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        [$database, $files] = $scope;

        $connection = new Connection($remoteConfig);

        if (! $this->verifyHostKey($connection)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $info = $this->probeRemote($connection);

        if ($info === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (! $info->hasRsync) {
            error($this->t('errors.rsync_missing_on_remote', ['name' => $remoteConfig->name]));

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $paths = [];

        if ($files) {
            $paths = $this->resolvePaths($this->path);

            if ($paths === null) {
                return ExitCode::UNSPECIFIED_ERROR;
            }

            if ($paths === []) {
                warning($this->t('warnings.no_paths_configured'));
                $files = false;
            }
        }

        if (! $database && ! $files) {
            info($this->t('info.no_operations_selected'));

            return ExitCode::OK;
        }

        $rsync = new Rsync($connection, interactive: $this->isInteractive(), verbose: $this->verbose);
        $snapshots = new Snapshots($connection, $info);
        $planner = new Planner($info, $rsync);

        try {
            $plan = $this->buildPlan(fn (): SyncPlan => $planner->push(
                database: $database,
                files: $files,
                backup: ! $this->noBackup,
                delete: $this->delete,
                paths: $paths,
            ));
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return ExitCode::UNSPECIFIED_ERROR;
        }

        (new PlanRenderer($this))->render($remoteConfig, $info, $plan);

        if ($this->dryRun) {
            info($this->t('info.dry_run_done'));

            return ExitCode::OK;
        }

        if (! $this->confirmPush($remoteConfig->name, $plan)) {
            if ($this->isInteractive()) {
                info($this->t('info.operation_cancelled'));

                return ExitCode::OK;
            }

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return $this->executePlan($remoteConfig->name, $connection, $info, $plan, $snapshots, $rsync);
    }

    /**
     * Pushing overwrites shared data, so the scope must be explicit in scripts
     * and the interactive confirmation is always a typed yes.
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

    protected function confirmPush(string $remoteName, SyncPlan $plan): bool
    {
        if ($this->force) {
            return true;
        }

        if (! $this->isInteractive()) {
            error($this->t('errors.confirmation_required'));

            return false;
        }

        return $this->confirmWithTypedYes($this->t('prompts.confirm.push', [
            'scope' => $plan->scopeSummary(),
            'name' => $remoteName,
        ]).' '.$this->t('prompts.confirm.typed_yes_suffix'));
    }

    protected function executePlan(string $remoteName, Connection $connection, RemoteInfo $info, SyncPlan $plan, Snapshots $snapshots, Rsync $rsync): int
    {
        $cleanup = new CleanupStack();

        $this->trapSignals(function () use ($cleanup) {
            $this->stdout("\n");
            warning($this->t('warnings.interrupt_cleanup'));
            $this->runCleanup($cleanup);
            exit(1);
        });

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
                        excludes: $plan->fileExcludes[$path] ?? [],
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

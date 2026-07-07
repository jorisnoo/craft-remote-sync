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
 * Pull the database and/or storage files from a remote environment.
 *
 * Usage: craft remote-sync/pull [remote] [--database] [--files] [--no-backup]
 *        [--keep-snapshot] [--delete] [--path=app,uploads] [--dry-run] [--force]
 */
class PullController extends Controller
{
    use ResolvesRemote;

    public $defaultAction = 'index';

    public bool $noBackup = false;

    public bool $keepSnapshot = false;

    public bool $delete = false;

    public ?string $path = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'database', 'files', 'force', 'verbose', 'dryRun',
            'noBackup', 'keepSnapshot', 'delete', 'path',
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

        [$database, $files] = $this->resolveScope();

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
            $plan = $this->buildPlan(fn (): SyncPlan => $planner->pull(
                database: $database,
                files: $files,
                backup: ! $this->noBackup,
                keepSnapshot: $this->keepSnapshot,
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

        if (! $this->confirmPlan($this->t('prompts.confirm.pull', [
            'scope' => $plan->scopeSummary(),
            'name' => $remoteConfig->name,
        ]))) {
            if ($this->isInteractive()) {
                info($this->t('info.operation_cancelled'));
            }

            return $this->isInteractive() ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        return $this->executePlan($remoteConfig->name, $info, $plan, $snapshots, $rsync);
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    protected function resolveScope(): array
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

    protected function executePlan(string $remoteName, RemoteInfo $info, SyncPlan $plan, Snapshots $snapshots, Rsync $rsync): int
    {
        $cleanup = new CleanupStack();

        $this->ensureCleanupOnExit($cleanup);

        try {
            if (! $this->runSteps($this->buildSteps($remoteName, $info, $plan, $snapshots, $rsync, $cleanup))) {
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } finally {
            $this->runCleanup($cleanup);
        }

        info($this->t('success.pulled', ['scope' => $plan->scopeSummary(), 'name' => $remoteName]));

        return ExitCode::OK;
    }

    /**
     * @return list<array{label: string, run: Closure(): ?string}>
     */
    protected function buildSteps(string $remoteName, RemoteInfo $info, SyncPlan $plan, Snapshots $snapshots, Rsync $rsync, CleanupStack $cleanup): array
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
                'run' => function (): ?string {
                    $this->runCraftMaintenance();

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

    protected function runCraftMaintenance(): void
    {
        if (! $this->runLocalCraft('up', '--interactive=0')->successful()) {
            warning($this->t('warnings.up_failed'));
        }

        $this->runLocalCraft('clear-caches/all', '--interactive=0');
        $this->runLocalCraft('gc', '--interactive=0');
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

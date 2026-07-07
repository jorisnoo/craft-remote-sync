<?php

namespace Noo\CraftRemoteSync\console\controllers;

use craft\console\Controller;
use Noo\CraftRemoteSync\console\traits\ResolvesRemote;
use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Snapshots\Snapshots;
use yii\console\ExitCode;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Prune old database snapshots created by remote-sync, locally and/or on a remote.
 *
 * Usage: craft remote-sync/prune [remote] [--local] [--remote] [--keep=5]
 *        [--all] [--dry-run] [--force]
 */
class PruneController extends Controller
{
    use ResolvesRemote;

    public $defaultAction = 'index';

    public bool $local = false;

    public bool $remote = false;

    public int $keep = 5;

    public bool $all = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'local', 'remote', 'keep', 'all', 'dryRun', 'force', 'verbose',
        ]);
    }

    public function actionIndex(?string $remote = null): int
    {
        $pruneLocal = $this->local || ! $this->remote;
        $pruneRemote = $this->remote || ! $this->local;
        $keep = max(0, $this->keep);

        $localVictims = $pruneLocal ? $this->prunable(Snapshots::listLocal(), $keep) : [];

        $snapshots = null;
        $remoteName = null;
        $remoteVictims = [];

        if ($pruneRemote) {
            $remoteConfig = $this->resolveRemote($remote);

            if ($remoteConfig === null) {
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $connection = new Connection($remoteConfig);

            if (! $this->verifyHostKey($connection)) {
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $info = $this->probeRemote($connection);

            if ($info === null) {
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $remoteName = $remoteConfig->name;
            $snapshots = new Snapshots($connection, $info);
            $remoteVictims = $this->prunable($snapshots->listRemote(), $keep);
        }

        if ($localVictims === [] && $remoteVictims === []) {
            info($this->t('info.nothing_to_prune', ['keep' => $keep]));

            return ExitCode::OK;
        }

        $this->renderVictims($localVictims, $remoteVictims, $remoteName, $keep);

        if ($this->dryRun) {
            info($this->t('info.dry_run_done'));

            return ExitCode::OK;
        }

        if (! $this->confirmPrune(count($localVictims) + count($remoteVictims))) {
            if ($this->isInteractive()) {
                info($this->t('info.operation_cancelled'));

                return ExitCode::OK;
            }

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $failures = 0;

        foreach ($localVictims as $snapshot) {
            if (! Snapshots::deleteLocal($snapshot['name'])) {
                warning($this->t('errors.failed_delete_snapshot', ['name' => $snapshot['name']]));
                $failures++;
            }
        }

        if ($localVictims !== []) {
            info($this->t('info.deleted_local_snapshots', ['count' => count($localVictims) - $failures]));
        }

        $remoteFailures = 0;

        if ($snapshots !== null) {
            foreach ($remoteVictims as $snapshot) {
                if (! $snapshots->deleteRemote($snapshot['name'])->successful()) {
                    warning($this->t('errors.failed_delete_snapshot', ['name' => $snapshot['name']]));
                    $remoteFailures++;
                }
            }
        }

        if ($remoteVictims !== []) {
            info($this->t('info.deleted_remote_snapshots', ['count' => count($remoteVictims) - $remoteFailures]));
        }

        return ($failures + $remoteFailures) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Keep the N most recent snapshots and return the rest. Snapshots not
     * created by this package are left alone unless --all is given.
     *
     * @param  array<int, array{name: string, path: string, mtime: int}>  $snapshots
     * @return array<int, array{name: string, path: string, mtime: int}>
     */
    protected function prunable(array $snapshots, int $keep): array
    {
        if (! $this->all) {
            $snapshots = array_values(array_filter(
                $snapshots,
                fn (array $snapshot) => Snapshots::isOwnSnapshot($snapshot['name'])
            ));
        }

        return array_slice($snapshots, $keep);
    }

    /**
     * @param  array<int, array{name: string, path: string, mtime: int}>  $localVictims
     * @param  array<int, array{name: string, path: string, mtime: int}>  $remoteVictims
     */
    protected function renderVictims(array $localVictims, array $remoteVictims, ?string $remoteName, int $keep): void
    {
        if ($localVictims !== []) {
            info($this->t('info.local_prune_header', ['keep' => $keep]));

            foreach ($localVictims as $snapshot) {
                $this->stdout('  '.str_pad($snapshot['name'], 42).date('Y-m-d H:i:s', $snapshot['mtime'])."\n");
            }
        }

        if ($remoteVictims !== []) {
            info($this->t('info.remote_prune_header', ['name' => $remoteName, 'keep' => $keep]));

            foreach ($remoteVictims as $snapshot) {
                $this->stdout('  '.str_pad($snapshot['name'], 42).date('Y-m-d H:i:s', $snapshot['mtime'])."\n");
            }
        }
    }

    protected function confirmPrune(int $count): bool
    {
        if ($this->force) {
            return true;
        }

        if (! $this->isInteractive()) {
            error($this->t('errors.confirmation_required'));

            return false;
        }

        return confirm(label: $this->t('prompts.confirm.prune', ['count' => $count]), default: true);
    }
}

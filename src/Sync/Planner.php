<?php

namespace Noo\CraftRemoteSync\Sync;

use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Snapshots\Snapshots;
use RuntimeException;

/**
 * Builds an immutable SyncPlan without performing any side effects. File
 * dry-runs are executed so the preview can report exact transfers and
 * deletions; the database is always a full drop-and-replace.
 */
class Planner
{
    public function __construct(
        private RemoteInfo $info,
        private Rsync $rsync,
    ) {}

    /**
     * @param  list<string>  $paths
     *
     * @throws RuntimeException when a files dry-run fails
     */
    public function pull(
        bool $database,
        bool $files,
        bool $backup = true,
        bool $keepSnapshot = true,
        bool $delete = false,
        array $paths = []
    ): SyncPlan {
        [$fileChanges, $fileExcludes] = $files
            ? $this->analyzeFiles(Direction::Pull, $paths)
            : [[], []];

        return new SyncPlan(
            direction: Direction::Pull,
            database: $database,
            files: $files,
            backup: $backup,
            keepSnapshot: $keepSnapshot,
            delete: $delete,
            paths: $files ? $paths : [],
            fileChanges: $fileChanges,
            fileExcludes: $fileExcludes,
            snapshotName: $database ? Snapshots::transferName() : '',
            backupName: ($database && $backup) ? Snapshots::pullBackupName() : '',
        );
    }

    /**
     * @param  list<string>  $paths
     *
     * @throws RuntimeException when a files dry-run fails
     */
    public function push(
        bool $database,
        bool $files,
        bool $backup = true,
        bool $delete = false,
        array $paths = []
    ): SyncPlan {
        [$fileChanges, $fileExcludes] = $files
            ? $this->analyzeFiles(Direction::Push, $paths)
            : [[], []];

        return new SyncPlan(
            direction: Direction::Push,
            database: $database,
            files: $files,
            backup: $backup,
            delete: $delete,
            paths: $files ? $paths : [],
            fileChanges: $fileChanges,
            fileExcludes: $fileExcludes,
            snapshotName: $database ? Snapshots::transferName() : '',
            backupName: ($database && $backup) ? Snapshots::pushBackupName() : '',
        );
    }

    /**
     * Dry-run every path to discover transfers and deletable files. The
     * dry-run always includes --delete so the preview can report what a
     * deletion pass would remove, even when the user did not opt in.
     *
     * @param  list<string>  $paths
     * @return array{0: array<string, FileChanges>, 1: array<string, list<string>>}
     */
    protected function analyzeFiles(Direction $direction, array $paths): array
    {
        $changes = [];
        $excludes = [];

        foreach ($paths as $path) {
            $localPath = \Craft::$app->getPath()->getStoragePath().DIRECTORY_SEPARATOR.$path;

            if ($direction === Direction::Pull && ! is_dir($localPath)) {
                mkdir($localPath, 0755, true);
            }

            if ($direction === Direction::Push && ! is_dir($localPath)) {
                $changes[$path] = new FileChanges();
                $excludes[$path] = [];

                continue;
            }

            $excludes[$path] = $this->snapshotDirExcludesFor($path);

            try {
                $changes[$path] = $this->rsync->dryRun(
                    $direction,
                    "{$this->info->storagePath()}/{$path}/",
                    rtrim($localPath, '/').'/',
                    excludes: $excludes[$path],
                    delete: true,
                );
            } catch (RuntimeException $e) {
                throw new RuntimeException("Could not analyze files for storage/{$path}: {$e->getMessage()}");
            }
        }

        return [$changes, $excludes];
    }

    /**
     * Keep the database backup directory (storage/backups, on both sides) out
     * of a file sync when the synced path would otherwise include it.
     *
     * @return list<string>
     */
    protected function snapshotDirExcludesFor(string $path): array
    {
        $backupsRelative = 'backups';

        if ($path === '') {
            return ['/'.$backupsRelative];
        }

        if (str_starts_with($backupsRelative, $path.'/')) {
            return ['/'.substr($backupsRelative, strlen($path) + 1)];
        }

        return [];
    }
}

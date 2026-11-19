<?php

namespace Noo\CraftRemoteSync\Sync;

use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Snapshots\Snapshots;
use Noo\CraftRemoteSync\Support\StoragePath;
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
        private ?string $localStoragePath = null,
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
            files: $fileChanges !== [],
            backup: $backup,
            keepSnapshot: $keepSnapshot,
            delete: $delete,
            paths: array_map('strval', array_keys($fileChanges)),
            fileChanges: $fileChanges,
            fileExcludes: $fileExcludes,
            snapshotName: $database ? Snapshots::transferName() : '',
            backupName: ($database && $backup) ? Snapshots::pullBackupName() : '',
            skippedPaths: $files ? array_values(array_diff($paths, array_keys($fileChanges))) : [],
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
            files: $fileChanges !== [],
            backup: $backup,
            delete: $delete,
            paths: array_map('strval', array_keys($fileChanges)),
            fileChanges: $fileChanges,
            fileExcludes: $fileExcludes,
            snapshotName: $database ? Snapshots::transferName() : '',
            backupName: ($database && $backup) ? Snapshots::pushBackupName() : '',
            skippedPaths: $files ? array_values(array_diff($paths, array_keys($fileChanges))) : [],
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
        foreach ($paths as $path) {
            if (($error = StoragePath::validate($path)) !== null) {
                throw new RuntimeException($error);
            }
        }

        $missingRemote = $direction === Direction::Pull
            ? array_fill_keys($this->rsync->missingRemoteSources($this->info->workingPath, $paths), true)
            : [];

        foreach ($paths as $path) {
            if (isset($missingRemote[$path])) {
                continue;
            }

            $storage = $this->localStoragePath ?? \Craft::$app->getPath()->getStoragePath(false);
            $localPath = $this->previewLocalPath($storage.DIRECTORY_SEPARATOR.$path);

            if ($direction === Direction::Push && ! is_dir($localPath)) {
                continue;
            }

            try {
                $changes[$path] = $this->rsync->dryRun(
                    $direction,
                    "{$this->info->storagePath()}/{$path}/",
                    rtrim($localPath, '/').'/',
                    delete: true,
                );
            } catch (RuntimeException $e) {
                throw new RuntimeException("Could not analyze files for storage/{$path}: {$e->getMessage()}", previous: $e);
            }
        }

        return [$changes, array_fill_keys(array_keys($changes), [])];
    }

    /**
     * A dry-run can compare against a missing destination but some rsyncs
     * require its parent to exist. The first missing directory represents
     * the same empty tree; never create the destination just to preview it.
     */
    private function previewLocalPath(string $path): string
    {
        while (! is_dir($parent = dirname($path))) {
            if (file_exists($parent) || is_link($parent) || $parent === $path) {
                throw new RuntimeException("Not a storage directory: {$parent}");
            }
            $path = $parent;
        }

        if (! is_executable($parent) || ((file_exists($path) || is_link($path)) && ! is_dir($path))) {
            throw new RuntimeException("Not an accessible storage directory: {$path}");
        }

        return $path;
    }
}

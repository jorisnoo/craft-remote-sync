<?php

namespace Noo\CraftRemoteSync\Sync;

readonly class SyncPlan
{
    /**
     * @param  list<string>  $paths  Storage-relative paths in the files scope.
     * @param  array<string, FileChanges>  $fileChanges  Keyed by storage-relative path.
     */
    public function __construct(
        public Direction $direction,
        public bool $database,
        public bool $files,
        public bool $backup = true,
        public bool $keepSnapshot = true,
        public bool $delete = false,
        public array $paths = [],
        public array $fileChanges = [],
        public string $snapshotName = '',
        public string $backupName = '',
    ) {}

    public function scopeSummary(): string
    {
        return implode(' and ', array_filter([
            $this->database ? 'database' : null,
            $this->files ? 'files' : null,
        ]));
    }

    /**
     * The file changes of all paths combined.
     */
    public function allFileChanges(): FileChanges
    {
        return array_reduce(
            $this->fileChanges,
            fn (FileChanges $carry, FileChanges $changes) => $carry->merge($changes),
            new FileChanges(),
        );
    }

    public function totalTransfers(): int
    {
        return array_sum(array_map(
            fn (FileChanges $changes) => $changes->transferCount(),
            $this->fileChanges
        ));
    }

    public function totalDeletions(): int
    {
        return array_sum(array_map(
            fn (FileChanges $changes) => $changes->deletionCount(),
            $this->fileChanges
        ));
    }
}

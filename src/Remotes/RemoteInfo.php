<?php

namespace Noo\CraftRemoteSync\Remotes;

readonly class RemoteInfo
{
    /**
     * @param  string|null  $phpBinary  Detected or configured PHP binary; null when detection failed.
     * @param  bool  $backupDirWritable  Whether storage/backups is writable, or can be created.
     */
    public function __construct(
        public ?string $phpBinary,
        public bool $isAtomic,
        public string $workingPath,
        public bool $hasRsync,
        public bool $backupDirWritable = false,
    ) {}

    public function storagePath(): string
    {
        return "{$this->workingPath}/storage";
    }

    public function backupDir(): string
    {
        return "{$this->workingPath}/storage/backups";
    }
}

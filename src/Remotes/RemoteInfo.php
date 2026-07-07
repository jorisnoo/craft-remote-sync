<?php

namespace Noo\CraftRemoteSync\Remotes;

readonly class RemoteInfo
{
    /**
     * @param  string|null  $phpBinary  Detected or configured PHP binary; null when detection failed.
     */
    public function __construct(
        public ?string $phpBinary,
        public bool $isAtomic,
        public string $workingPath,
        public bool $hasRsync,
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

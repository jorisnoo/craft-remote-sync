<?php

namespace Noo\CraftRemoteSync\Remotes;

use RuntimeException;

readonly class RemoteInfo
{
    public const DB_TOOLS = [
        'mysql' => ['mysql', 'mysqldump'],
        'pgsql' => ['psql', 'pg_dump'],
    ];

    /**
     * @param  string|null  $phpBinary  Detected or configured PHP binary; null when detection failed.
     * @param  list<string>  $dbTools  Database client/dump executables found on the remote PATH.
     */
    public function __construct(
        public ?string $phpBinary,
        public bool $isAtomic,
        public string $workingPath,
        public bool $hasRsync,
        public bool $backupDirWritable = false,
        public array $dbTools = [],
    ) {}

    public function requirePhpBinary(Remote $remote): string
    {
        if ($this->phpBinary !== null) {
            return $this->phpBinary;
        }

        throw new RuntimeException($remote->phpBinary !== null
            ? "Configured php_binary '{$remote->phpBinary}' on [{$remote->name}] was not found or failed the project's platform check."
            : "No compatible PHP binary found on [{$remote->name}]. Set 'php_binary' for this remote.");
    }

    public function storagePath(): string
    {
        return "{$this->workingPath}/storage";
    }

    public function backupDir(): string
    {
        return "{$this->workingPath}/storage/backups";
    }
}

<?php

namespace Noo\CraftRemoteSync\Remotes;

use RuntimeException;

/**
 * Inspects a remote in a single SSH round-trip: it resolves the atomic-deploy
 * working path, finds a PHP binary whose platform satisfies the project's
 * requirements, and checks that rsync is present and storage/backups is
 * writable (or can be created). The remote's answer is a
 * handful of marker lines parsed into an immutable RemoteInfo.
 */
class Probe
{
    protected const CD_FAILED_MARKER = 'REMOTE_SYNC_CD_FAILED';

    /** @var list<string> */
    protected const PHP_CANDIDATES = [
        'php',
        'php8.5',
        'php85',
        'php8.4',
        'php84',
        'php8.3',
        'php83',
        'php8.2',
        'php82',
        'php8.1',
        'php81',
    ];

    public function run(Connection $connection): RemoteInfo
    {
        $remote = $connection->remote;
        $result = $connection->run($this->command($remote), $connection->remoteTimeout());

        if (str_contains($result->output(), self::CD_FAILED_MARKER)) {
            throw new RuntimeException(
                "Cannot access '{$remote->path}' on [{$remote->name}]: directory not found. Check the remote's path configuration."
            );
        }

        if (! $result->successful()) {
            $error = trim($result->errorOutput()) ?: trim($result->output());

            throw new RuntimeException("Cannot reach [{$remote->name}] ({$remote->host}): {$error}");
        }

        return $this->parse($result->output(), $remote);
    }

    public function command(Remote $remote): string
    {
        $candidates = $remote->phpBinary !== null
            ? [$remote->phpBinary]
            : self::PHP_CANDIDATES;

        $escapedCandidates = implode(' ', array_map('escapeshellarg', $candidates));
        $base = escapeshellarg($remote->path);
        $marker = self::CD_FAILED_MARKER;

        return "BASE={$base}; "
            .'if [ -L "$BASE/current" ]; then WORK="$BASE/current"; ATOMIC=1; else WORK="$BASE"; ATOMIC=0; fi; '
            ."cd \"\$WORK\" || { echo {$marker}; exit 1; }; "
            .'echo "ATOMIC=$ATOMIC"; '
            ."PHP=''; for candidate in {$escapedCandidates}; do "
            .'if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r \'if (file_exists("vendor/composer/platform_check.php")) { require "vendor/composer/platform_check.php"; }\' >/dev/null 2>&1; then PHP="$candidate"; break; fi; '
            .'done; '
            .'echo "PHP=$PHP"; '
            .'if command -v rsync >/dev/null 2>&1; then echo RSYNC=1; else echo RSYNC=0; fi; '
            .'if { [ -d storage/backups ] && [ -w storage/backups ]; } || { [ ! -e storage/backups ] && [ -d storage ] && [ -w storage ]; }; then echo BACKUPS=1; else echo BACKUPS=0; fi; '
            .'exit 0';
    }

    protected function parse(string $output, Remote $remote): RemoteInfo
    {
        $atomic = false;
        $phpBinary = null;
        $hasRsync = false;
        $backupDirWritable = false;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'ATOMIC=')) {
                $atomic = substr($line, strlen('ATOMIC=')) === '1';
            } elseif (str_starts_with($line, 'PHP=')) {
                $value = substr($line, strlen('PHP='));
                $phpBinary = $value !== '' ? $value : null;
            } elseif (str_starts_with($line, 'RSYNC=')) {
                $hasRsync = substr($line, strlen('RSYNC=')) === '1';
            } elseif (str_starts_with($line, 'BACKUPS=')) {
                $backupDirWritable = substr($line, strlen('BACKUPS=')) === '1';
            }
        }

        $endsWithCurrent = str_ends_with($remote->path, '/current');
        $isAtomic = $atomic || $endsWithCurrent;
        $workingPath = ($atomic && ! $endsWithCurrent) ? "{$remote->path}/current" : $remote->path;

        return new RemoteInfo(
            phpBinary: $phpBinary,
            isAtomic: $isAtomic,
            workingPath: $workingPath,
            hasRsync: $hasRsync,
            backupDirWritable: $backupDirWritable,
        );
    }
}

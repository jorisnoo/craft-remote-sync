<?php

namespace Noo\CraftRemoteSync\Snapshots;

use Noo\CraftRemoteSync\Process\ProcessResult;
use Noo\CraftRemoteSync\Process\ProcessRunner;
use Noo\CraftRemoteSync\Process\SymfonyProcessRunner;
use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use RuntimeException;

/**
 * Database snapshot lifecycle over Craft's native db/backup and db/restore.
 *
 * Two kinds of files by prefix: an ephemeral transfer snapshot (remote-sync-),
 * always cleaned up, and a kept backup (pre-pull- or pre-push-) taken before a
 * side is overwritten. Transfer snapshots are stored plain (.sql) so their
 * MariaDB sandbox header can be stripped before restore; kept backups are zipped.
 */
class Snapshots
{
    /** @var list<string> Name prefixes of snapshots created by this package. */
    public const NAME_PREFIXES = ['remote-sync-', 'pre-pull-', 'pre-push-'];

    private ProcessRunner $runner;

    public function __construct(
        private Connection $connection,
        private RemoteInfo $info,
        ?ProcessRunner $runner = null,
    ) {
        $this->runner = $runner ?? new SymfonyProcessRunner();
    }

    // Naming

    public static function transferName(): string
    {
        return 'remote-sync-'.date('Y-m-d-H-i-s').'-'.bin2hex(random_bytes(4));
    }

    public static function pullBackupName(): string
    {
        return 'pre-pull-'.date('Y-m-d-H-i-s');
    }

    public static function pushBackupName(): string
    {
        return 'pre-push-'.date('Y-m-d-H-i-s');
    }

    public static function isOwnSnapshot(string $name): bool
    {
        foreach (self::NAME_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // Local storage

    public static function localDir(): string
    {
        return \Craft::$app->getPath()->getDbBackupPath();
    }

    public static function localPath(string $name): string
    {
        return static::localDir().DIRECTORY_SEPARATOR.$name.'.sql';
    }

    /**
     * Create a local database backup at a controlled name. Transfer snapshots
     * are left plain so they can be sanitised; kept backups are zipped.
     *
     * @return string the path of the created file
     */
    public function createLocal(string $name, bool $zip = false): string
    {
        $target = static::localDir().DIRECTORY_SEPARATOR.$name.'.sql';

        $args = [PHP_BINARY, static::craftBinary(), 'db/backup', $target, '--overwrite', '--interactive=0'];

        if ($zip) {
            $args[] = '--zip';
        }

        $result = $this->runner->run($args, $this->connection->transferTimeout());

        if (! $result->successful()) {
            throw new RuntimeException('Failed to create local backup: '.trim($result->errorOutput()));
        }

        return $zip ? "{$target}.zip" : $target;
    }

    /**
     * List local snapshot files, newest first.
     *
     * @return array<int, array{name: string, path: string, mtime: int}>
     */
    public static function listLocal(): array
    {
        $dir = static::localDir();
        $files = array_merge(glob($dir.'/*.sql') ?: [], glob($dir.'/*.sql.zip') ?: []);
        $snapshots = [];

        foreach ($files as $file) {
            $snapshots[] = [
                'name' => static::stripExtension(basename($file)),
                'path' => $file,
                'mtime' => (int) filemtime($file),
            ];
        }

        usort($snapshots, fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);

        return $snapshots;
    }

    public static function deleteLocal(string $name): bool
    {
        $deleted = false;

        foreach (['.sql', '.sql.zip'] as $extension) {
            $path = static::localDir().DIRECTORY_SEPARATOR.$name.$extension;

            if (file_exists($path)) {
                $deleted = unlink($path) || $deleted;
            }
        }

        return $deleted;
    }

    public function verifyIntegrity(string $name): bool
    {
        $path = static::localPath($name);

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Restore a downloaded transfer snapshot into the local database. Strips
     * the MariaDB sandbox header first, then drops all tables and restores.
     */
    public function restoreLocal(string $name): ProcessResult
    {
        $path = static::localPath($name);

        static::sanitize($path);

        $args = [PHP_BINARY, static::craftBinary(), 'db/restore', $path, '--drop-all-tables', '--interactive=0'];

        return $this->runner->run($args, $this->connection->transferTimeout());
    }

    // Remote storage

    public function remotePath(string $name): string
    {
        return $this->info->backupDir().'/'.$name.'.sql';
    }

    public function createRemote(string $name, bool $zip = false): ProcessResult
    {
        $this->ensureRemoteBackupDir();

        $target = escapeshellarg($this->info->backupDir().'/'.$name.'.sql');
        $arguments = 'db/backup '.$target.($zip ? ' --zip' : '').' --overwrite --interactive=0';

        return $this->connection->craft($this->info, $arguments, $this->connection->transferTimeout());
    }

    public function loadRemote(string $name): ProcessResult
    {
        $path = escapeshellarg($this->remotePath($name));

        return $this->connection->craft(
            $this->info,
            'db/restore '.$path.' --drop-all-tables --interactive=0',
            $this->connection->transferTimeout(),
        );
    }

    public function deleteRemote(string $name): ProcessResult
    {
        $sql = escapeshellarg($this->info->backupDir().'/'.$name.'.sql');
        $zip = escapeshellarg($this->info->backupDir().'/'.$name.'.sql.zip');

        return $this->connection->run("rm -f {$sql} {$zip}", $this->connection->remoteTimeout());
    }

    public function download(string $name): ProcessResult
    {
        $dir = static::localDir();

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $args = $this->rsyncBase();
        $args[] = $this->connection->sshTarget().':'.$this->remotePath($name);
        $args[] = $dir.DIRECTORY_SEPARATOR;

        return $this->runner->run($args, $this->connection->transferTimeout());
    }

    public function upload(string $name): ProcessResult
    {
        $this->ensureRemoteBackupDir();

        $args = $this->rsyncBase();
        $args[] = static::localPath($name);
        $args[] = $this->connection->sshTarget().':'.$this->info->backupDir().'/';

        return $this->runner->run($args, $this->connection->transferTimeout());
    }

    /**
     * List snapshot files in the remote backup directory, newest first.
     *
     * @return array<int, array{name: string, path: string, mtime: int}>
     */
    public function listRemote(): array
    {
        $dir = escapeshellarg($this->info->backupDir());
        $names = "\\( -name '*.sql' -o -name '*.sql.zip' \\)";
        $command = "{ find {$dir} -maxdepth 1 {$names} -exec stat -c '%Y %n' {} + 2>/dev/null"
            ." || find {$dir} -maxdepth 1 {$names} -exec stat -f '%m %N' {} + 2>/dev/null; } | sort -rn || true";

        $result = $this->connection->run($command, $this->connection->remoteTimeout());

        if (! $result->successful()) {
            return [];
        }

        $snapshots = [];

        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);

            if ($parts === false || count($parts) !== 2) {
                continue;
            }

            [$mtime, $path] = $parts;
            $snapshots[] = [
                'name' => static::stripExtension(basename($path)),
                'path' => $path,
                'mtime' => (int) $mtime,
            ];
        }

        return $snapshots;
    }

    // Helpers

    private function ensureRemoteBackupDir(): void
    {
        $this->connection->run(
            'mkdir -p '.escapeshellarg($this->info->backupDir()),
            $this->connection->remoteTimeout(),
        );
    }

    /**
     * @return list<string>
     */
    private function rsyncBase(): array
    {
        $args = ['rsync', '-az', '--partial'];

        if (($port = $this->connection->sshPort()) !== null) {
            $args[] = '-e';
            $args[] = "ssh -p {$port}";
        }

        return $args;
    }

    private static function stripExtension(string $filename): string
    {
        foreach (['.sql.zip', '.sql'] as $extension) {
            if (str_ends_with($filename, $extension)) {
                return substr($filename, 0, -strlen($extension));
            }
        }

        return $filename;
    }

    private static function craftBinary(): string
    {
        return \Craft::getAlias('@root').DIRECTORY_SEPARATOR.'craft';
    }

    /**
     * Strip the MariaDB 10.5+ sandbox-mode header from a dump file. Older
     * MariaDB `mysql` clients don't recognise the `\-` token and abort restore
     * at line 1 with "Unknown command '\-'". A no-op on missing files, and on
     * compressed archives whose header text is not reachable as plain lines.
     */
    public static function sanitize(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        $magic = fread($handle, 2);
        fclose($handle);
        $isGzipped = $magic === "\x1f\x8b";

        $tmpPath = $path.'.sanitize.tmp';
        $in = $isGzipped ? gzopen($path, 'rb') : fopen($path, 'rb');
        $out = $isGzipped ? gzopen($tmpPath, 'wb') : fopen($tmpPath, 'wb');

        if ($in === false || $out === false) {
            if ($in !== false) {
                $isGzipped ? gzclose($in) : fclose($in);
            }
            if ($out !== false) {
                $isGzipped ? gzclose($out) : fclose($out);
            }
            @unlink($tmpPath);
            throw new RuntimeException("Could not open backup for sanitization: {$path}");
        }

        $readLine = $isGzipped ? 'gzgets' : 'fgets';
        $write = $isGzipped ? 'gzwrite' : 'fwrite';
        $eof = $isGzipped ? 'gzeof' : 'feof';

        while (! $eof($in)) {
            $line = $readLine($in);
            if ($line === false) {
                break;
            }
            // MariaDB 10.5+ emits either `/*M!999999\- …` or (historically) `/*!999999\- …`.
            if (preg_match('~^/\*M?!999999\\\\-~', $line)) {
                continue;
            }
            $write($out, $line);
        }

        $isGzipped ? gzclose($in) : fclose($in);
        $isGzipped ? gzclose($out) : fclose($out);

        if (! rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException("Could not replace sanitized backup: {$path}");
        }
    }
}

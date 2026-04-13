<?php

namespace Noo\CraftRemoteSync\services;

use Noo\CraftRemoteSync\models\RemoteConfig;
use Noo\CraftRemoteSync\Module;
use Symfony\Component\Process\Process;
use yii\base\Component;

class RemoteSyncService extends Component
{
    private function getConfig(): array
    {
        return Module::$instance->getConfig();
    }

    private function getTimeout(string $operation): int
    {
        return $this->getConfig()['timeouts'][$operation] ?? 300;
    }

    private function parseSshHost(string $host): array
    {
        if (preg_match('/^(.+):(\d+)$/', $host, $matches)) {
            return ['host' => $matches[1], 'port' => (int) $matches[2]];
        }
        return ['host' => $host, 'port' => null];
    }

    private function getSshHost(RemoteConfig $remote): string
    {
        return $this->parseSshHost($remote->host)['host'];
    }

    private function buildSshArgs(RemoteConfig $remote, string $command): array
    {
        $parsed = $this->parseSshHost($remote->host);
        $args = ['ssh'];
        if ($parsed['port'] !== null) {
            $args[] = '-p';
            $args[] = (string) $parsed['port'];
        }
        $args[] = $parsed['host'];
        $args[] = $command;
        return $args;
    }

    private function buildRsyncArgs(RemoteConfig $remote, string $source, string $dest, bool $dryRun = false, array $excludePaths = []): array
    {
        $parsed = $this->parseSshHost($remote->host);
        $args = ['rsync', '-avz', '--progress', '--exclude=.*'];

        foreach ($excludePaths as $path) {
            $args[] = '--exclude=' . $path;
        }

        if ($parsed['port'] !== null) {
            $args[] = '-e';
            $args[] = 'ssh -p ' . $parsed['port'];
        }

        if ($dryRun) {
            $args[] = '--dry-run';
        }

        $args[] = $source;
        $args[] = $dest;

        return $args;
    }

    private function formatProcessError(string $label, Process $process): string
    {
        $parts = [$label];
        $parts[] = 'Command: ' . $process->getCommandLine();
        $parts[] = 'Exit code: ' . ($process->getExitCode() ?? 'unknown');

        $stderr = trim($process->getErrorOutput());
        $stdout = trim($process->getOutput());

        if ($stderr !== '') {
            $parts[] = "stderr:\n" . $stderr;
        }
        if ($stdout !== '') {
            $parts[] = "stdout:\n" . $stdout;
        }
        if ($stderr === '' && $stdout === '') {
            $parts[] = '(no output captured)';
        }

        return implode("\n", $parts);
    }

    private function runSshCommand(RemoteConfig $remote, string $command, int $timeout, ?callable $callback = null): string
    {
        $args = $this->buildSshArgs($remote, $command);
        $process = new Process($args);
        $process->setTimeout($timeout);
        $process->run($callback);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException($this->formatProcessError('SSH command failed.', $process));
        }

        return $process->getOutput();
    }

    private function runProcess(array $args, int $timeout, ?callable $callback = null): string
    {
        $process = new Process($args);
        $process->setTimeout($timeout);
        $process->run($callback);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException($this->formatProcessError('Process failed.', $process));
        }

        return $process->getOutput();
    }

    private function runProcessStreaming(array $args, int $timeout): void
    {
        $process = new Process($args);
        $process->setTimeout($timeout);
        $process->run(function ($type, $buffer) {
            if ($type === Process::OUT) {
                fwrite(STDOUT, $buffer);
            }
        });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($this->formatProcessError('Process failed.', $process));
        }
    }

    private function parseBackupFilename(string $output): string
    {
        // Craft outputs the backup path, e.g.: "Backed up the database to: /path/storage/backups/cp-backup-….sql.gz"
        if (preg_match('/storage\/backups\/([^\s\'"]+)/', $output, $matches)) {
            return $matches[1];
        }

        throw new \RuntimeException('Could not parse backup filename from output: ' . $output);
    }

    // --- Public API ---

    public function getRemote(string $name): RemoteConfig
    {
        $config = $this->getConfig();
        $remotes = $config['remotes'] ?? [];

        if (!isset($remotes[$name])) {
            throw new \InvalidArgumentException("Remote '{$name}' is not configured.");
        }

        $remoteConfig = $remotes[$name];

        return new RemoteConfig(
            name: $name,
            host: $remoteConfig['host'],
            path: $remoteConfig['path'],
            pushAllowed: $remoteConfig['pushAllowed'] ?? false,
        );
    }

    public function getAvailableRemotes(): array
    {
        return array_keys($this->getConfig()['remotes'] ?? []);
    }

    public function getAvailablePushRemotes(): array
    {
        $remotes = $this->getConfig()['remotes'] ?? [];
        return array_keys(array_filter($remotes, fn($c) => (bool) ($c['pushAllowed'] ?? false)));
    }

    public function verifySshHost(RemoteConfig $remote): void
    {
        $parsed = $this->parseSshHost($remote->host);
        $args = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5'];
        if ($parsed['port'] !== null) {
            $args[] = '-p';
            $args[] = (string) $parsed['port'];
        }
        $args[] = $parsed['host'];
        $args[] = 'true';

        $process = new Process($args);
        $process->setTimeout(10);
        $process->run();

        if ($process->isSuccessful() || !str_contains($process->getErrorOutput(), 'Host key verification failed')) {
            return;
        }

        // Host key not known yet — run interactively so user can accept the fingerprint
        $args = ['ssh', '-o', 'ConnectTimeout=10'];
        if ($parsed['port'] !== null) {
            $args[] = '-p';
            $args[] = (string) $parsed['port'];
        }
        $args[] = $parsed['host'];
        $args[] = 'true';

        $interactive = new Process($args);
        $interactive->setTimeout(60);
        $interactive->setTty(Process::isTtySupported());
        $interactive->run();

        if (!$interactive->isSuccessful()) {
            throw new \RuntimeException('SSH host verification failed. The host key was not accepted.');
        }
    }

    public function detectAtomicDeployment(RemoteConfig $remote): bool
    {
        $command = '[ -L ' . escapeshellarg($remote->path . '/current') . ' ] && echo yes || echo no';

        try {
            $output = $this->runSshCommand($remote, $command, 30);
            return trim($output) === 'yes';
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function createLocalBackup(?callable $callback = null): string
    {
        $craftPath = \Craft::getAlias('@root') . DIRECTORY_SEPARATOR . 'craft';
        $args = [PHP_BINARY, $craftPath, 'db/backup', '--zip'];
        $output = $this->runProcess($args, $this->getTimeout('createSnapshot'), $callback);
        return $this->parseBackupFilename($output);
    }

    public function createRemoteBackup(RemoteConfig $remote, ?callable $callback = null): string
    {
        $command = 'cd ' . escapeshellarg($remote->workingPath()) . ' && php craft db/backup --zip 2>&1';
        $output = $this->runSshCommand($remote, $command, $this->getTimeout('createSnapshot'), $callback);
        return $this->parseBackupFilename($output);
    }

    public function downloadBackup(RemoteConfig $remote, string $filename): void
    {
        $remoteHost = $this->getSshHost($remote);
        $remotePath = $remote->storagePath() . '/backups/' . $filename;
        $localPath = \Craft::$app->getPath()->getDbBackupPath() . DIRECTORY_SEPARATOR . $filename;

        $args = $this->buildRsyncArgs($remote, $remoteHost . ':' . $remotePath, $localPath);
        $this->runProcessStreaming($args, $this->getTimeout('download'));
    }

    public function uploadBackup(RemoteConfig $remote, string $filename): void
    {
        $remoteHost = $this->getSshHost($remote);
        $localPath = \Craft::$app->getPath()->getDbBackupPath() . DIRECTORY_SEPARATOR . $filename;
        $remotePath = $remote->storagePath() . '/backups/' . $filename;

        $args = $this->buildRsyncArgs($remote, $localPath, $remoteHost . ':' . $remotePath);
        $this->runProcessStreaming($args, $this->getTimeout('upload'));
    }

    public function restoreLocalBackup(string $path, ?callable $callback = null): void
    {
        $craftPath = \Craft::getAlias('@root') . DIRECTORY_SEPARATOR . 'craft';
        $args = [PHP_BINARY, $craftPath, 'db/restore', $path, '--drop-all-tables'];
        $this->runProcess($args, $this->getTimeout('download'), $callback);
    }

    // Strip the MariaDB 10.5+ sandbox-mode header from a dump file. Older MariaDB
    // `mysql` clients don't recognise the `\-` token and abort restore at line 1 with
    // "Unknown command '\-'".
    public function sanitizeBackup(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        $magic = fread($handle, 2);
        fclose($handle);
        $isGzipped = $magic === "\x1f\x8b";

        $tmpPath = $path . '.sanitize.tmp';
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
            throw new \RuntimeException("Could not open backup for sanitization: {$path}");
        }

        $readLine = $isGzipped ? 'gzgets' : 'fgets';
        $write = $isGzipped ? 'gzwrite' : 'fwrite';
        $eof = $isGzipped ? 'gzeof' : 'feof';

        while (!$eof($in)) {
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

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Could not replace sanitized backup: {$path}");
        }
    }

    public function loadRemoteBackup(RemoteConfig $remote, string $filename, ?callable $callback = null): void
    {
        $backupPath = $remote->storagePath() . '/backups/' . $filename;
        $command = 'cd ' . escapeshellarg($remote->workingPath()) . ' && php craft db/restore ' . escapeshellarg($backupPath) . ' --drop-all-tables 2>&1';
        $this->runSshCommand($remote, $command, $this->getTimeout('download'), $callback);
    }

    public function deleteRemoteBackup(RemoteConfig $remote, string $filename): void
    {
        $backupPath = $remote->storagePath() . '/backups/' . $filename;
        $command = 'rm -f ' . escapeshellarg($backupPath);
        $this->runSshCommand($remote, $command, 30);
    }

    public function rsyncDownload(RemoteConfig $remote, string $storagePath): void
    {
        $remoteHost = $this->getSshHost($remote);
        $remotePath = $remote->storagePath() . '/' . $storagePath . '/';
        $localPath = \Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . $storagePath . DIRECTORY_SEPARATOR;
        $excludePaths = $this->getConfig()['exclude_paths'] ?? [];

        $args = $this->buildRsyncArgs($remote, $remoteHost . ':' . $remotePath, $localPath, false, $excludePaths);
        $this->runProcessStreaming($args, $this->getTimeout('fileSync'));
    }

    public function rsyncUpload(RemoteConfig $remote, string $storagePath): void
    {
        $remoteHost = $this->getSshHost($remote);
        $localPath = \Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . $storagePath . DIRECTORY_SEPARATOR;
        $remotePath = $remote->storagePath() . '/' . $storagePath . '/';
        $excludePaths = $this->getConfig()['exclude_paths'] ?? [];

        $args = $this->buildRsyncArgs($remote, $localPath, $remoteHost . ':' . $remotePath, false, $excludePaths);
        $this->runProcessStreaming($args, $this->getTimeout('fileSync'));
    }

    public function rsyncDryRun(RemoteConfig $remote, string $storagePath, string $direction): string
    {
        $remoteHost = $this->getSshHost($remote);
        $remotePath = $remote->storagePath() . '/' . $storagePath . '/';
        $localPath = \Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . $storagePath . DIRECTORY_SEPARATOR;
        $excludePaths = $this->getConfig()['exclude_paths'] ?? [];

        $args = match ($direction) {
            'download' => $this->buildRsyncArgs($remote, $remoteHost . ':' . $remotePath, $localPath, true, $excludePaths),
            'upload'   => $this->buildRsyncArgs($remote, $localPath, $remoteHost . ':' . $remotePath, true, $excludePaths),
            default    => throw new \InvalidArgumentException("Direction must be 'download' or 'upload'."),
        };

        return $this->runProcess($args, $this->getTimeout('fileSync'));
    }
}

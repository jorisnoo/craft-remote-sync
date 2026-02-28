<?php

namespace jorge\craftremotesync\services;

use jorge\craftremotesync\models\RemoteConfig;
use jorge\craftremotesync\Plugin;
use Symfony\Component\Process\Process;
use yii\base\Component;

class RemoteSyncService extends Component
{
    private function getConfig(): array
    {
        return Plugin::$plugin->getConfig();
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

    private function buildRsyncArgs(RemoteConfig $remote, string $source, string $dest, bool $dryRun = false): array
    {
        $parsed = $this->parseSshHost($remote->host);
        $args = ['rsync', '-avz', '--progress'];

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

    private function runSshCommand(RemoteConfig $remote, string $command, int $timeout): string
    {
        $args = $this->buildSshArgs($remote, $command);
        $process = new Process($args);
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('SSH command failed: ' . $process->getErrorOutput());
        }

        return $process->getOutput();
    }

    private function runProcess(array $args, int $timeout, ?callable $callback = null): string
    {
        $process = new Process($args);
        $process->setTimeout($timeout);
        $process->run($callback);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Process failed: ' . $process->getErrorOutput());
        }

        return $process->getOutput();
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

    public function getRemote(?string $name = null): RemoteConfig
    {
        $config = $this->getConfig();
        $name = $name ?? $config['default'];
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

    public function createLocalBackup(): string
    {
        $craftPath = \Craft::getAlias('@root') . DIRECTORY_SEPARATOR . 'craft';
        $args = [PHP_BINARY, $craftPath, 'db/backup', '--zip'];
        $output = $this->runProcess($args, $this->getTimeout('createSnapshot'));
        return $this->parseBackupFilename($output);
    }

    public function createRemoteBackup(RemoteConfig $remote): string
    {
        $command = 'cd ' . escapeshellarg($remote->workingPath()) . ' && php craft db/backup --zip 2>&1';
        $output = $this->runSshCommand($remote, $command, $this->getTimeout('createSnapshot'));
        return $this->parseBackupFilename($output);
    }

    public function downloadBackup(RemoteConfig $remote, string $filename): void
    {
        $remoteHost = $this->getSshHost($remote);
        $remotePath = $remote->storagePath() . '/backups/' . $filename;
        $localPath = \Craft::$app->getPath()->getDbBackupPath() . DIRECTORY_SEPARATOR . $filename;

        $args = $this->buildRsyncArgs($remote, $remoteHost . ':' . $remotePath, $localPath);
        $this->runProcess($args, $this->getTimeout('download'));
    }

    public function uploadBackup(RemoteConfig $remote, string $filename): void
    {
        $remoteHost = $this->getSshHost($remote);
        $localPath = \Craft::$app->getPath()->getDbBackupPath() . DIRECTORY_SEPARATOR . $filename;
        $remotePath = $remote->storagePath() . '/backups/' . $filename;

        $args = $this->buildRsyncArgs($remote, $localPath, $remoteHost . ':' . $remotePath);
        $this->runProcess($args, $this->getTimeout('upload'));
    }

    public function restoreLocalBackup(string $path): void
    {
        $craftPath = \Craft::getAlias('@root') . DIRECTORY_SEPARATOR . 'craft';
        $args = [PHP_BINARY, $craftPath, 'db/restore', $path, '--drop-all-tables'];
        $this->runProcess($args, $this->getTimeout('download'));
    }

    public function loadRemoteBackup(RemoteConfig $remote, string $filename): void
    {
        $backupPath = $remote->storagePath() . '/backups/' . $filename;
        $command = 'cd ' . escapeshellarg($remote->workingPath()) . ' && php craft db/restore ' . escapeshellarg($backupPath) . ' --drop-all-tables 2>&1';
        $this->runSshCommand($remote, $command, $this->getTimeout('download'));
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

        $args = $this->buildRsyncArgs($remote, $remoteHost . ':' . $remotePath, $localPath);
        $this->runProcess($args, $this->getTimeout('fileSync'));
    }

    public function rsyncUpload(RemoteConfig $remote, string $storagePath): void
    {
        $remoteHost = $this->getSshHost($remote);
        $localPath = \Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . $storagePath . DIRECTORY_SEPARATOR;
        $remotePath = $remote->storagePath() . '/' . $storagePath . '/';

        $args = $this->buildRsyncArgs($remote, $localPath, $remoteHost . ':' . $remotePath);
        $this->runProcess($args, $this->getTimeout('fileSync'));
    }

    public function rsyncDryRun(RemoteConfig $remote, string $storagePath, string $direction): string
    {
        $remoteHost = $this->getSshHost($remote);
        $remotePath = $remote->storagePath() . '/' . $storagePath . '/';
        $localPath = \Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . $storagePath . DIRECTORY_SEPARATOR;

        $args = match ($direction) {
            'download' => $this->buildRsyncArgs($remote, $remoteHost . ':' . $remotePath, $localPath, true),
            'upload'   => $this->buildRsyncArgs($remote, $localPath, $remoteHost . ':' . $remotePath, true),
            default    => throw new \InvalidArgumentException("Direction must be 'download' or 'upload'."),
        };

        return $this->runProcess($args, $this->getTimeout('fileSync'));
    }
}

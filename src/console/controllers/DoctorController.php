<?php

namespace Noo\CraftRemoteSync\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use InvalidArgumentException;
use Noo\CraftRemoteSync\Process\SymfonyProcessRunner;
use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\Probe;
use Noo\CraftRemoteSync\Remotes\RemoteRegistry;
use Noo\CraftRemoteSync\Snapshots\Snapshots;
use RuntimeException;
use yii\console\ExitCode;

/**
 * Check local and remote prerequisites for remote-sync.
 *
 * Usage: craft remote-sync/doctor [remote]
 */
class DoctorController extends Controller
{
    public $defaultAction = 'index';

    protected bool $healthy = true;

    public function actionIndex(?string $remote = null): int
    {
        $this->healthy = true;

        $this->checkLocal();

        $registry = new RemoteRegistry();
        $names = $remote !== null ? [$registry->expandName($remote)] : $registry->names();

        if ($names === []) {
            $this->failCheck('Remotes', 'none configured in config/remote-sync.php');
        }

        foreach ($names as $name) {
            $this->checkRemote($registry, $name);
        }

        $this->stdout("\n");

        if ($this->healthy) {
            $this->stdout("Everything looks good.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout("Some checks failed — fix the items marked FAIL above.\n", Console::FG_RED);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    protected function checkLocal(): void
    {
        $this->stdout("\nLocal environment\n", Console::BOLD);

        $db = Craft::$app->getDb();
        $driver = $db->getIsMysql() ? 'mysql' : ($db->getIsPgsql() ? 'pgsql' : $db->getDriverName());

        if ($driver === 'mysql') {
            $this->passCheck('Database driver', 'mysql');
            $this->checkLocalBinary('mysql');
            $this->checkLocalBinary('mysqldump');
        } elseif ($driver === 'pgsql') {
            $this->passCheck('Database driver', 'pgsql');
            $this->checkLocalBinary('psql');
            $this->checkLocalBinary('pg_dump');
        } else {
            $this->failCheck('Database driver', "{$driver} (backups need mysql or pgsql)");
        }

        foreach (['rsync', 'ssh'] as $binary) {
            $this->checkLocalBinary($binary);
        }

        $backupDir = Snapshots::localDir();
        $writable = is_dir($backupDir) ? is_writable($backupDir) : is_writable(dirname($backupDir));

        $writable
            ? $this->passCheck('Backup directory', $backupDir)
            : $this->failCheck('Backup directory', "{$backupDir} is not writable");
    }

    protected function checkLocalBinary(string $binary): void
    {
        (new SymfonyProcessRunner())->run('command -v '.escapeshellarg($binary), 10)->successful()
            ? $this->passCheck($binary, 'found')
            : $this->failCheck($binary, 'not found on PATH');
    }

    protected function checkRemote(RemoteRegistry $registry, string $name): void
    {
        $this->stdout("\nRemote [{$name}]\n", Console::BOLD);

        try {
            $remote = $registry->get($name);
        } catch (InvalidArgumentException $e) {
            $this->failCheck('Configuration', $e->getMessage());

            return;
        }

        $this->passCheck('Configuration', "{$remote->host}:{$remote->path}");

        $connection = new Connection($remote);

        switch ($connection->checkHostKey()) {
            case 'changed':
                $this->failCheck('Host key', 'CHANGED — possible man-in-the-middle, check ~/.ssh/known_hosts');

                return;
            case 'unknown':
                $this->failCheck('Host key', 'unknown — connect once interactively (e.g. remote-sync/pull) to trust it');

                return;
            default:
                $this->passCheck('Host key', 'known');
        }

        try {
            $info = (new Probe())->run($connection);
        } catch (RuntimeException $e) {
            $this->failCheck('Connection', $e->getMessage());

            return;
        }

        $this->passCheck('Application path', $info->workingPath.($info->isAtomic ? ' (atomic deployment)' : ''));

        $info->phpBinary !== null
            ? $this->passCheck('PHP binary', $info->phpBinary)
            : $this->failCheck('PHP binary', "none of the known binaries work — set 'php_binary' for this remote");

        $info->hasRsync
            ? $this->passCheck('rsync', 'found')
            : $this->failCheck('rsync', 'not found on the remote');

        $info->backupDirWritable
            ? $this->passCheck('Backup directory', $info->backupDir())
            : $this->failCheck('Backup directory', "{$info->backupDir()} is not writable");
    }

    protected function passCheck(string $check, string $detail): void
    {
        $this->stdout('  '.str_pad($check, 20), Console::FG_GREY);
        $this->stdout('OK ', Console::FG_GREEN);
        $this->stdout($detail."\n");
    }

    protected function failCheck(string $check, string $detail): void
    {
        $this->healthy = false;

        $this->stdout('  '.str_pad($check, 20), Console::FG_GREY);
        $this->stdout('FAIL ', Console::FG_RED);
        $this->stdout($detail."\n");
    }
}

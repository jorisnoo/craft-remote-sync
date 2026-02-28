<?php

namespace Noo\CraftRemoteSync\console\controllers;

use craft\console\Controller;
use Noo\CraftRemoteSync\console\traits\InteractsWithRemote;
use Noo\CraftRemoteSync\models\RemoteConfig;
use Noo\CraftRemoteSync\Module;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Remote Sync Pull command.
 *
 * Usage: craft remote-sync/pull
 */
class PullController extends Controller
{
    use InteractsWithRemote;

    public $defaultAction = 'index';

    private const EXIT_ABORTED = 2;

    /**
     * Pull database and/or storage files from a remote environment to local.
     */
    public function actionIndex(): int
    {
        $this->ensureNotProduction();

        intro('Remote Sync — Pull');

        $remote = $this->selectRemote();
        $remote = spin(fn() => $this->initializeRemote($remote), 'Checking remote configuration...');

        if ($remote->isAtomic) {
            info('Atomic deployment detected.');
        }

        $operation = $this->selectOperation('pull');

        if ($operation === 'database' || $operation === 'both') {
            $result = $this->pullDatabase($remote);
            if ($result !== 0) {
                return $result === self::EXIT_ABORTED ? 0 : $result;
            }
        }

        if ($operation === 'files' || $operation === 'both') {
            $result = $this->pullFiles($remote);
            if ($result !== 0) {
                return $result;
            }
        }

        outro('Done!');
        return 0;
    }

    private function pullDatabase(RemoteConfig $remote): int
    {
        $service = Module::$instance->getRemoteSyncService();

        $this->displayDatabasePreview();

        if (!$this->confirmDbPull()) {
            info('Aborted.');
            return self::EXIT_ABORTED;
        }

        // Create local backup as a safety net before any destructive operation
        try {
            $localSafetyBackup = spin(fn() => $service->createLocalBackup(), 'Creating local safety backup...');
            info("Local safety backup: {$localSafetyBackup}");
        } catch (\RuntimeException $e) {
            warning("Could not create local safety backup: " . $e->getMessage());
        }

        $remoteFilename = null;
        $localBackupPath = null;

        try {
            $remoteFilename = spin(fn() => $service->createRemoteBackup($remote), 'Creating remote backup...');
            spin(fn() => $service->downloadBackup($remote, $remoteFilename), 'Downloading backup...');

            $localBackupPath = \Craft::$app->getPath()->getDbBackupPath() . DIRECTORY_SEPARATOR . $remoteFilename;

            spin(fn() => $service->restoreLocalBackup($localBackupPath), 'Restoring database...');
        } catch (\RuntimeException $e) {
            error("Error during database pull: " . $e->getMessage());

            if ($remoteFilename !== null) {
                try {
                    spin(fn() => $service->deleteRemoteBackup($remote, $remoteFilename), 'Cleaning up remote backup...');
                } catch (\RuntimeException $cleanupError) {
                    warning("Could not clean up remote backup: " . $cleanupError->getMessage());
                }
            }

            return 1;
        }

        // Clean up the downloaded backup
        if ($remoteFilename !== null) {
            try {
                spin(fn() => $service->deleteRemoteBackup($remote, $remoteFilename), 'Cleaning up remote backup...');
            } catch (\RuntimeException $e) {
                warning("Could not clean up remote backup: " . $e->getMessage());
            }
        }

        if ($localBackupPath !== null && file_exists($localBackupPath)) {
            spin(fn() => @unlink($localBackupPath), 'Cleaning up local backup...');
        }

        return 0;
    }

    private function pullFiles(RemoteConfig $remote): int
    {
        $service = Module::$instance->getRemoteSyncService();
        $config = Module::$instance->getConfig();
        $paths = $config['paths'] ?? [];

        if (empty($paths)) {
            info('No storage paths configured in config/remote-sync.php. Skipping files sync.');
            return 0;
        }

        foreach ($paths as $storagePath) {
            try {
                $dryRunOutput = spin(fn() => $service->rsyncDryRun($remote, $storagePath, 'download'), "Previewing '{$storagePath}'...");
                note("Path: {$storagePath}");
                $this->displayFilesPreview($dryRunOutput);
            } catch (\RuntimeException $e) {
                error("Could not run preview for '{$storagePath}': " . $e->getMessage());
                return 1;
            }
        }

        if (!$this->confirmFilesPull()) {
            info('Aborted.');
            return 0;
        }

        foreach ($paths as $storagePath) {
            try {
                info("Syncing '{$storagePath}'...");
                $service->rsyncDownload($remote, $storagePath);
            } catch (\RuntimeException $e) {
                error("Error syncing '{$storagePath}': " . $e->getMessage());
                return 1;
            }
        }

        return 0;
    }
}

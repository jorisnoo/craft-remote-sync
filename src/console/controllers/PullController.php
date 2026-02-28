<?php

namespace Noo\CraftRemoteSync\console\controllers;

use craft\console\Controller;
use Noo\CraftRemoteSync\console\traits\InteractsWithRemote;
use Noo\CraftRemoteSync\models\RemoteConfig;
use Noo\CraftRemoteSync\Module;

/**
 * Remote Sync Pull command.
 *
 * Usage: craft remote-sync/pull
 */
class PullController extends Controller
{
    use InteractsWithRemote;

    public $defaultAction = 'index';

    /**
     * Pull database and/or storage files from a remote environment to local.
     */
    public function actionIndex(): int
    {
        $this->ensureNotProduction();

        $remote = $this->selectRemote();
        $remote = $this->initializeRemote($remote);

        $operation = $this->selectOperation();

        if ($operation === 'database' || $operation === 'both') {
            $result = $this->pullDatabase($remote);
            if ($result !== 0) {
                return $result;
            }
        }

        if ($operation === 'files' || $operation === 'both') {
            $result = $this->pullFiles($remote);
            if ($result !== 0) {
                return $result;
            }
        }

        $this->stdout("\nDone!\n");
        return 0;
    }

    private function selectOperation(): string
    {
        $this->stdout("\nSelect operation:\n");
        $this->stdout("  [1] database\n");
        $this->stdout("  [2] files\n");
        $this->stdout("  [3] both\n");

        $input = $this->prompt('Select operation', ['default' => '3']);

        return match ($input) {
            '1', 'database' => 'database',
            '2', 'files'    => 'files',
            default         => 'both',
        };
    }

    private function pullDatabase(RemoteConfig $remote): int
    {
        $service = Module::$instance->getRemoteSyncService();

        $this->displayDatabasePreview();

        if (!$this->confirmPull()) {
            $this->stdout("Aborted.\n");
            return 0;
        }

        // Create local backup as a safety net before any destructive operation
        $this->stdout("\nCreating local backup (safety net)...\n");
        try {
            $localSafetyBackup = $service->createLocalBackup();
            $this->stdout("Local backup created: {$localSafetyBackup}\n");
        } catch (\RuntimeException $e) {
            $this->stderr("Warning: Could not create local safety backup: " . $e->getMessage() . "\n");
        }

        $remoteFilename = null;
        $localBackupPath = null;

        try {
            $this->stdout("\nCreating remote backup...\n");
            $remoteFilename = $service->createRemoteBackup($remote);
            $this->stdout("Remote backup created: {$remoteFilename}\n");

            $this->stdout("Downloading backup...\n");
            $service->downloadBackup($remote, $remoteFilename);
            $this->stdout("Download complete.\n");

            $localBackupPath = \Craft::$app->getPath()->getDbBackupPath() . DIRECTORY_SEPARATOR . $remoteFilename;

            $this->stdout("Restoring database...\n");
            $service->restoreLocalBackup($localBackupPath);
            $this->stdout("Database restored.\n");
        } catch (\RuntimeException $e) {
            $this->stderr("\nError during database pull: " . $e->getMessage() . "\n");

            if ($remoteFilename !== null) {
                $this->stdout("Cleaning up remote backup...\n");
                try {
                    $service->deleteRemoteBackup($remote, $remoteFilename);
                } catch (\RuntimeException $cleanupError) {
                    $this->stderr("Could not clean up remote backup: " . $cleanupError->getMessage() . "\n");
                }
            }

            return 1;
        }

        // Clean up the downloaded backup
        if ($remoteFilename !== null) {
            $this->stdout("Cleaning up remote backup...\n");
            try {
                $service->deleteRemoteBackup($remote, $remoteFilename);
            } catch (\RuntimeException $e) {
                $this->stderr("Warning: Could not clean up remote backup: " . $e->getMessage() . "\n");
            }
        }

        if ($localBackupPath !== null && file_exists($localBackupPath)) {
            $this->stdout("Cleaning up local downloaded backup...\n");
            @unlink($localBackupPath);
        }

        return 0;
    }

    private function pullFiles(RemoteConfig $remote): int
    {
        $service = Module::$instance->getRemoteSyncService();
        $config = Module::$instance->getConfig();
        $paths = $config['paths'] ?? [];

        if (empty($paths)) {
            $this->stdout("\nNo storage paths configured in config/remote-sync.php. Skipping files sync.\n");
            return 0;
        }

        $this->stdout("\nPreviewing files to sync...\n");

        foreach ($paths as $storagePath) {
            $this->stdout("\nPath: {$storagePath}\n");
            try {
                $dryRunOutput = $service->rsyncDryRun($remote, $storagePath, 'download');
                $this->displayFilesPreview($dryRunOutput);
            } catch (\RuntimeException $e) {
                $this->stderr("Could not run preview for '{$storagePath}': " . $e->getMessage() . "\n");
                return 1;
            }
        }

        if (!$this->confirmPull()) {
            $this->stdout("Aborted.\n");
            return 0;
        }

        foreach ($paths as $storagePath) {
            $this->stdout("\nSyncing '{$storagePath}'...\n");
            try {
                $service->rsyncDownload($remote, $storagePath);
                $this->stdout("Done syncing '{$storagePath}'.\n");
            } catch (\RuntimeException $e) {
                $this->stderr("Error syncing '{$storagePath}': " . $e->getMessage() . "\n");
                return 1;
            }
        }

        return 0;
    }
}

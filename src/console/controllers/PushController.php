<?php

namespace jorge\craftremotesync\console\controllers;

use craft\console\Controller;
use jorge\craftremotesync\console\traits\InteractsWithRemote;
use jorge\craftremotesync\models\RemoteConfig;
use jorge\craftremotesync\Module;

/**
 * Remote Sync Push command.
 *
 * Usage: craft remote-sync/push
 */
class PushController extends Controller
{
    use InteractsWithRemote;

    public $defaultAction = 'index';

    /**
     * Push database and/or storage files from local to a remote environment.
     */
    public function actionIndex(): int
    {
        $this->ensureNotProduction();

        $remote = $this->selectRemote();
        $this->ensurePushAllowed($remote);
        $remote = $this->initializeRemote($remote);

        $operation = $this->selectOperation();

        if ($operation === 'database' || $operation === 'both') {
            $result = $this->pushDatabase($remote);
            if ($result !== 0) {
                return $result;
            }
        }

        if ($operation === 'files' || $operation === 'both') {
            $result = $this->pushFiles($remote);
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

    private function pushDatabase(RemoteConfig $remote): int
    {
        $service = Module::$instance->getRemoteSyncService();

        $this->displayDatabasePreview();

        if (!$this->confirmPush()) {
            $this->stdout("Aborted.\n");
            return 0;
        }

        // Create a remote backup as a safety net before overwriting the remote database
        $this->stdout("\nCreating remote backup (safety net)...\n");
        try {
            $remoteSafetyBackup = $service->createRemoteBackup($remote);
            $this->stdout("Remote backup created: {$remoteSafetyBackup}\n");
        } catch (\RuntimeException $e) {
            $this->stderr("Warning: Could not create remote safety backup: " . $e->getMessage() . "\n");
        }

        $localFilename = null;

        try {
            $this->stdout("\nCreating local backup...\n");
            $localFilename = $service->createLocalBackup();
            $this->stdout("Local backup created: {$localFilename}\n");

            $this->stdout("Uploading backup...\n");
            $service->uploadBackup($remote, $localFilename);
            $this->stdout("Upload complete.\n");

            $this->stdout("Restoring database on remote...\n");
            $service->loadRemoteBackup($remote, $localFilename);
            $this->stdout("Remote database restored.\n");
        } catch (\RuntimeException $e) {
            $this->stderr("\nError during database push: " . $e->getMessage() . "\n");
            return 1;
        }

        // Clean up the uploaded backup on remote
        if ($localFilename !== null) {
            $this->stdout("Cleaning up remote uploaded backup...\n");
            try {
                $service->deleteRemoteBackup($remote, $localFilename);
            } catch (\RuntimeException $e) {
                $this->stderr("Warning: Could not clean up remote backup: " . $e->getMessage() . "\n");
            }
        }

        // Clean up the local backup
        if ($localFilename !== null) {
            $localBackupPath = \Craft::$app->getPath()->getDbBackupPath() . DIRECTORY_SEPARATOR . $localFilename;
            if (file_exists($localBackupPath)) {
                $this->stdout("Cleaning up local backup...\n");
                @unlink($localBackupPath);
            }
        }

        return 0;
    }

    private function pushFiles(RemoteConfig $remote): int
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
                $dryRunOutput = $service->rsyncDryRun($remote, $storagePath, 'upload');
                $this->displayFilesPreview($dryRunOutput);
            } catch (\RuntimeException $e) {
                $this->stderr("Could not run preview for '{$storagePath}': " . $e->getMessage() . "\n");
                return 1;
            }
        }

        if (!$this->confirmPush()) {
            $this->stdout("Aborted.\n");
            return 0;
        }

        foreach ($paths as $storagePath) {
            $this->stdout("\nSyncing '{$storagePath}'...\n");
            try {
                $service->rsyncUpload($remote, $storagePath);
                $this->stdout("Done syncing '{$storagePath}'.\n");
            } catch (\RuntimeException $e) {
                $this->stderr("Error syncing '{$storagePath}': " . $e->getMessage() . "\n");
                return 1;
            }
        }

        return 0;
    }
}

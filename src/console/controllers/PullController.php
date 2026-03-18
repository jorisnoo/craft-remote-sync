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
        $this->ensureNotProduction(doubleConfirm: true);

        intro('Remote Sync — Pull');

        $remote = $this->selectRemote();
        $this->verifyHostConnection($remote);
        $remote = $this->runStep('Checking remote configuration...', fn() => $this->initializeRemote($remote));

        if ($remote->isAtomic) {
            info('Atomic deployment detected.');
        }

        $operation = $this->selectOperation('pull');

        if ($operation === 'both') {
            $this->displayDatabasePreview();

            $service = Module::$instance->getRemoteSyncService();
            $paths = Module::$instance->getConfig()['paths'] ?? [];
            foreach ($paths as $storagePath) {
                try {
                    $dryRunOutput = $this->runStep("Previewing '{$storagePath}'...", fn() => $service->rsyncDryRun($remote, $storagePath, 'download'));
                    note("Path: {$storagePath}");
                    $this->displayFilesPreview($dryRunOutput);
                } catch (\RuntimeException $e) {
                    error("Could not run preview for '{$storagePath}': " . $e->getMessage());
                    return 1;
                }
            }

            if (!$this->confirmBothPull()) {
                info('Aborted.');
                return 0;
            }

            $result = $this->pullDatabase($remote, confirmed: true);
            if ($result !== 0) {
                return $result === self::EXIT_ABORTED ? 0 : $result;
            }

            $result = $this->pullFiles($remote, confirmed: true);
            if ($result !== 0) {
                return $result;
            }
        }

        if ($operation === 'database') {
            $result = $this->pullDatabase($remote);
            if ($result !== 0) {
                return $result === self::EXIT_ABORTED ? 0 : $result;
            }
        }

        if ($operation === 'files') {
            $result = $this->pullFiles($remote);
            if ($result !== 0) {
                return $result;
            }
        }

        outro('Done!');
        return 0;
    }

    private function pullDatabase(RemoteConfig $remote, bool $confirmed = false): int
    {
        $service = Module::$instance->getRemoteSyncService();
        $callback = $this->streamingCallback();

        if (!$confirmed) {
            $this->displayDatabasePreview();

            if (!$this->confirmDbPull()) {
                info('Aborted.');
                return self::EXIT_ABORTED;
            }
        }

        // Create local backup as a safety net before any destructive operation
        try {
            $localSafetyBackup = $this->runStep('Creating local safety backup...', fn() => $service->createLocalBackup($callback));
            info("Local safety backup: {$localSafetyBackup}");
        } catch (\RuntimeException $e) {
            warning("Could not create local safety backup: " . $e->getMessage());
        }

        $remoteFilename = null;
        $localBackupPath = null;

        try {
            $remoteFilename = $this->runStep('Creating remote backup...', fn() => $service->createRemoteBackup($remote, $callback));
            $this->registerRemoteCleanup($remote, $remoteFilename);
            $this->runStep('Downloading backup...', fn() => $service->downloadBackup($remote, $remoteFilename, $callback));

            $localBackupPath = \Craft::$app->getPath()->getDbBackupPath() . DIRECTORY_SEPARATOR . $remoteFilename;

            $this->runStep('Restoring database...', fn() => $service->restoreLocalBackup($localBackupPath, $callback));
        } catch (\RuntimeException $e) {
            error("Error during database pull: " . $e->getMessage());

            if ($remoteFilename !== null) {
                try {
                    $this->runStep('Cleaning up remote backup...', fn() => $service->deleteRemoteBackup($remote, $remoteFilename));
                    $this->clearRemoteCleanup();
                } catch (\RuntimeException $cleanupError) {
                    warning("Could not clean up remote backup: " . $cleanupError->getMessage());
                }
            }

            return 1;
        }

        // Clean up the downloaded backup
        if ($remoteFilename !== null) {
            try {
                $this->runStep('Cleaning up remote backup...', fn() => $service->deleteRemoteBackup($remote, $remoteFilename));
                $this->clearRemoteCleanup();
            } catch (\RuntimeException $e) {
                warning("Could not clean up remote backup: " . $e->getMessage());
            }
        }

        if ($localBackupPath !== null && file_exists($localBackupPath)) {
            $this->runStep('Cleaning up local backup...', fn() => @unlink($localBackupPath));
        }

        return 0;
    }

    private function pullFiles(RemoteConfig $remote, bool $confirmed = false): int
    {
        $service = Module::$instance->getRemoteSyncService();
        $config = Module::$instance->getConfig();
        $paths = $config['paths'] ?? [];

        if (empty($paths)) {
            info('No storage paths configured in config/remote-sync.php. Skipping files sync.');
            return 0;
        }

        if (!$confirmed) {
            foreach ($paths as $storagePath) {
                try {
                    $dryRunOutput = $this->runStep("Previewing '{$storagePath}'...", fn() => $service->rsyncDryRun($remote, $storagePath, 'download'));
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

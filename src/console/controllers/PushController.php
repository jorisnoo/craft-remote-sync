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
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * Remote Sync Push command.
 *
 * Usage: craft remote-sync/push
 */
class PushController extends Controller
{
    use InteractsWithRemote;

    public $defaultAction = 'index';

    public ?string $remoteHost = null;

    public ?string $remotePath = null;

    public bool $database = false;

    public bool $files = false;

    public bool $force = false;

    private const EXIT_ABORTED = 2;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'remoteHost',
            'remotePath',
            'database',
            'files',
            'force',
        ]);
    }

    /**
     * Push database and/or storage files from local to a remote environment.
     */
    public function actionIndex(): int
    {
        intro('Remote Sync — Push');

        if ($this->remoteHost && $this->remotePath) {
            $remote = new RemoteConfig(
                name: 'zentrale-sync',
                host: $this->remoteHost,
                path: $this->remotePath,
                pushAllowed: true,
            );
            $this->verifyHostConnection($remote);
            $remote = $this->runStep('Checking remote configuration...', fn() => $this->initializeRemote($remote));
        } else {
            $remote = $this->selectPushRemote();
            $this->verifyHostConnection($remote);
            $remote = $this->runStep('Checking remote configuration...', fn() => $this->initializeRemote($remote));
        }

        if ($remote->isAtomic) {
            info('Atomic deployment detected.');
        }

        $operation = $this->resolveOperation();

        if ($operation === 'both') {
            $service = Module::$instance->getRemoteSyncService();

            if (!$this->force) {
                $this->displayDatabasePreview();

                $paths = Module::$instance->getConfig()['paths'] ?? [];
                foreach ($paths as $storagePath) {
                    try {
                        $dryRunOutput = $this->runStep("Previewing '{$storagePath}'...", fn() => $service->rsyncDryRun($remote, $storagePath, 'upload'));
                        note("Path: {$storagePath}");
                        $this->displayFilesPreview($dryRunOutput);
                    } catch (\RuntimeException $e) {
                        error("Could not run preview for '{$storagePath}': " . $e->getMessage());
                        return 1;
                    }
                }

                if (!$this->confirmBothPush()) {
                    info('Aborted.');
                    return 0;
                }
            }

            $result = $this->pushDatabase($remote, confirmed: true);
            if ($result !== 0) {
                return $result === self::EXIT_ABORTED ? 0 : $result;
            }

            $result = $this->pushFiles($remote, confirmed: true);
            if ($result !== 0) {
                return $result;
            }
        }

        if ($operation === 'database') {
            $result = $this->pushDatabase($remote);
            if ($result !== 0) {
                return $result === self::EXIT_ABORTED ? 0 : $result;
            }
        }

        if ($operation === 'files') {
            $result = $this->pushFiles($remote);
            if ($result !== 0) {
                return $result;
            }
        }

        outro('Done!');
        return 0;
    }

    private function resolveOperation(): string
    {
        if ($this->database && $this->files) {
            return 'both';
        }

        if ($this->database) {
            return 'database';
        }

        if ($this->files) {
            return 'files';
        }

        return $this->selectOperation('push');
    }

    private function pushDatabase(RemoteConfig $remote, bool $confirmed = false): int
    {
        $service = Module::$instance->getRemoteSyncService();
        $callback = $this->streamingCallback();

        if (!$confirmed && !$this->force) {
            $this->displayDatabasePreview();

            if (!$this->confirmDbPush()) {
                info('Aborted.');
                return self::EXIT_ABORTED;
            }
        }

        $createBackup = $this->force
            ? true
            : confirm(
                label: 'Create a remote backup before pushing?',
                default: true,
                yes: 'Yes',
                no: 'No, skip backup',
            );

        if ($createBackup) {
            // Create a remote backup as a safety net before overwriting the remote database
            try {
                $remoteSafetyBackup = $this->runStep('Creating remote safety backup...', fn() => $service->createRemoteBackup($remote, $callback));
                info("Remote safety backup: {$remoteSafetyBackup}");
            } catch (\RuntimeException $e) {
                warning("Could not create remote safety backup: " . $e->getMessage());
            }
        }

        $localFilename = null;

        try {
            $localFilename = $this->runStep('Creating local backup...', fn() => $service->createLocalBackup($callback));
            $this->runStep('Uploading backup...', fn() => $service->uploadBackup($remote, $localFilename, $callback));
            $this->registerRemoteCleanup($remote, $localFilename);
            $this->runStep('Restoring database on remote...', fn() => $service->loadRemoteBackup($remote, $localFilename, $callback));
        } catch (\RuntimeException $e) {
            error("Error during database push: " . $e->getMessage());
            return 1;
        }

        // Clean up the uploaded backup on remote
        if ($localFilename !== null) {
            try {
                $this->runStep('Cleaning up remote uploaded backup...', fn() => $service->deleteRemoteBackup($remote, $localFilename));
                $this->clearRemoteCleanup();
            } catch (\RuntimeException $e) {
                warning("Could not clean up remote backup: " . $e->getMessage());
            }
        }

        // Clean up the local backup
        if ($localFilename !== null) {
            $localBackupPath = \Craft::$app->getPath()->getDbBackupPath() . DIRECTORY_SEPARATOR . $localFilename;
            if (file_exists($localBackupPath)) {
                $this->runStep('Cleaning up local backup...', fn() => @unlink($localBackupPath));
            }
        }

        return 0;
    }

    private function pushFiles(RemoteConfig $remote, bool $confirmed = false): int
    {
        $service = Module::$instance->getRemoteSyncService();
        $config = Module::$instance->getConfig();
        $paths = $config['paths'] ?? [];

        if (empty($paths)) {
            info('No storage paths configured in config/remote-sync.php. Skipping files sync.');
            return 0;
        }

        if (!$confirmed && !$this->force) {
            foreach ($paths as $storagePath) {
                try {
                    $dryRunOutput = $this->runStep("Previewing '{$storagePath}'...", fn() => $service->rsyncDryRun($remote, $storagePath, 'upload'));
                    note("Path: {$storagePath}");
                    $this->displayFilesPreview($dryRunOutput);
                } catch (\RuntimeException $e) {
                    error("Could not run preview for '{$storagePath}': " . $e->getMessage());
                    return 1;
                }
            }

            if (!$this->confirmFilesPush()) {
                info('Aborted.');
                return 0;
            }
        }

        foreach ($paths as $storagePath) {
            try {
                info("Syncing '{$storagePath}'...");
                $service->rsyncUpload($remote, $storagePath);
            } catch (\RuntimeException $e) {
                error("Error syncing '{$storagePath}': " . $e->getMessage());
                return 1;
            }
        }

        return 0;
    }
}

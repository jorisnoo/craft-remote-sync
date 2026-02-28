<?php

namespace jorge\craftremotesync\console\traits;

use jorge\craftremotesync\models\RemoteConfig;
use jorge\craftremotesync\Plugin;

trait InteractsWithRemote
{
    protected ?RemoteConfig $selectedRemote = null;

    public function selectRemote(): RemoteConfig
    {
        $service = Plugin::$plugin->getRemoteSyncService();
        $remotes = $service->getAvailableRemotes();

        if (empty($remotes)) {
            $this->stderr("No remotes are configured in config/remote-sync.php\n");
            exit(1);
        }

        if (count($remotes) === 1) {
            $this->selectedRemote = $service->getRemote($remotes[0]);
            return $this->selectedRemote;
        }

        $this->stdout("Available remotes:\n");
        foreach ($remotes as $i => $name) {
            $this->stdout('  [' . ($i + 1) . "] {$name}\n");
        }

        $name = $this->prompt('Select a remote', ['default' => $remotes[0]]);

        if (!in_array($name, $remotes, true)) {
            $index = (int) $name - 1;
            if (isset($remotes[$index])) {
                $name = $remotes[$index];
            } else {
                $this->stderr("Invalid remote: {$name}\n");
                exit(1);
            }
        }

        $this->selectedRemote = $service->getRemote($name);
        return $this->selectedRemote;
    }

    public function initializeRemote(RemoteConfig $remote): RemoteConfig
    {
        $this->stdout("Checking remote configuration...\n");
        $service = Plugin::$plugin->getRemoteSyncService();
        $isAtomic = $service->detectAtomicDeployment($remote);

        if ($isAtomic) {
            $this->stdout("Atomic deployment detected (using /current symlink).\n");
        }

        $remote = $remote->withAtomic($isAtomic);
        $this->selectedRemote = $remote;
        return $remote;
    }

    public function ensureNotProduction(): void
    {
        $env = \Craft::$app->env;
        if ($env === 'production') {
            $this->stderr("This command cannot be run in a production environment.\n");
            exit(1);
        }
    }

    public function ensurePushAllowed(RemoteConfig $remote): void
    {
        if (!$remote->pushAllowed) {
            $this->stderr("Push is not allowed for remote '{$remote->name}'. Set 'pushAllowed' => true in config/remote-sync.php\n");
            exit(1);
        }
    }

    public function confirmPull(): bool
    {
        $this->stdout("\nWARNING: This will overwrite your local database with data from the remote.\n");
        $confirmation = $this->prompt('Type "yes" to continue');
        return $confirmation === 'yes';
    }

    public function confirmPush(): bool
    {
        $this->stdout("\nWARNING: This will overwrite the REMOTE database with your local data.\n");
        $this->stdout("This action is destructive and cannot be easily undone.\n");
        $confirmation = $this->prompt('Type "yes" to continue');
        return $confirmation === 'yes';
    }

    public function displayDatabasePreview(): void
    {
        if ($this->selectedRemote === null) {
            return;
        }

        $remote = $this->selectedRemote;
        $this->stdout("\nDatabase sync:\n");
        $this->stdout("  Remote : {$remote->name}\n");
        $this->stdout("  Host   : {$remote->host}\n");
        $this->stdout("  Path   : {$remote->workingPath()}\n");
    }

    public function displayFilesPreview(string $dryRunOutput): void
    {
        $lines = explode("\n", trim($dryRunOutput));
        $files = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            if (
                str_starts_with($line, 'sending ') ||
                str_starts_with($line, 'receiving ') ||
                str_starts_with($line, 'sent ') ||
                str_starts_with($line, 'total size ')
            ) {
                continue;
            }
            $files[] = $line;
        }

        $count = count($files);
        $this->stdout("\nFiles to sync: {$count} file(s)\n");

        if ($count > 0) {
            $display = array_slice($files, 0, 10);
            foreach ($display as $file) {
                $this->stdout("  {$file}\n");
            }
            if ($count > 10) {
                $this->stdout('  ... and ' . ($count - 10) . " more\n");
            }
        }
    }

    public function generateBackupName(): string
    {
        return 'remote-sync-' . date('Y-m-d-His') . '.sql.gz';
    }
}

<?php

namespace Noo\CraftRemoteSync\console\traits;

use Noo\CraftRemoteSync\models\RemoteConfig;
use Noo\CraftRemoteSync\Module;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

trait InteractsWithRemote
{
    protected ?RemoteConfig $selectedRemote = null;

    public function selectRemote(): RemoteConfig
    {
        $service = Module::$instance->getRemoteSyncService();
        $remotes = $service->getAvailableRemotes();

        if (empty($remotes)) {
            error("No remotes are configured in config/remote-sync.php");
            exit(1);
        }

        if (count($remotes) === 1) {
            $this->selectedRemote = $service->getRemote($remotes[0]);
            info('Remote: ' . $remotes[0]);
            return $this->selectedRemote;
        }

        $options = array_combine($remotes, $remotes);
        $name = select(label: 'Select a remote', options: $options, default: $remotes[0]);

        $this->selectedRemote = $service->getRemote($name);
        return $this->selectedRemote;
    }

    public function selectPushRemote(): RemoteConfig
    {
        $service = Module::$instance->getRemoteSyncService();
        $remotes = $service->getAvailablePushRemotes();

        if (empty($remotes)) {
            error("No remotes are configured with push enabled. Set 'pushAllowed' => true in config/remote-sync.php");
            exit(1);
        }

        if (count($remotes) === 1) {
            $this->selectedRemote = $service->getRemote($remotes[0]);
            info('Remote: ' . $remotes[0]);
            return $this->selectedRemote;
        }

        $options = array_combine($remotes, $remotes);
        $name = select(label: 'Select a remote to push to', options: $options, default: $remotes[0]);

        $this->selectedRemote = $service->getRemote($name);
        return $this->selectedRemote;
    }

    public function initializeRemote(RemoteConfig $remote): RemoteConfig
    {
        $service = Module::$instance->getRemoteSyncService();
        $isAtomic = $service->detectAtomicDeployment($remote);

        $remote = $remote->withAtomic($isAtomic);
        $this->selectedRemote = $remote;
        return $remote;
    }

    public function ensureNotProduction(): void
    {
        $env = \Craft::$app->env;
        if ($env === 'production') {
            error("This command cannot be run in a production environment.");
            exit(1);
        }
    }

    public function ensurePushAllowed(RemoteConfig $remote): void
    {
        if (!$remote->pushAllowed) {
            error("Push is not allowed for remote '{$remote->name}'. Set 'pushAllowed' => true in config/remote-sync.php");
            exit(1);
        }
    }

    public function confirmDbPull(): bool
    {
        warning('This will overwrite your LOCAL database with data from the remote.');
        return confirm(label: 'Do you want to continue?', default: false, yes: 'Yes, pull from remote', no: 'No, abort');
    }

    public function confirmDbPush(): bool
    {
        warning('This will overwrite the REMOTE database with your local data. This action is destructive and cannot be easily undone.');
        return confirm(label: 'Do you want to continue?', default: false, yes: 'Yes, push to remote', no: 'No, abort');
    }

    public function confirmFilesPull(): bool
    {
        warning('This will overwrite your LOCAL files with files from the remote.');
        return confirm(label: 'Do you want to continue?', default: false, yes: 'Yes, pull from remote', no: 'No, abort');
    }

    public function confirmFilesPush(): bool
    {
        warning('This will overwrite the REMOTE files with your local files. This action is destructive and cannot be easily undone.');
        return confirm(label: 'Do you want to continue?', default: false, yes: 'Yes, push to remote', no: 'No, abort');
    }

    public function displayDatabasePreview(): void
    {
        if ($this->selectedRemote === null) {
            return;
        }

        $remote = $this->selectedRemote;
        table(headers: ['Setting', 'Value'], rows: [
            ['Remote', $remote->name],
            ['Host',   $remote->host],
            ['Path',   $remote->workingPath()],
        ]);
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
        $display = array_slice($files, 0, 10);
        table(headers: ['Files to sync'], rows: array_map(fn($f) => [$f], $display));

        if ($count > 10) {
            note('... and ' . ($count - 10) . ' more file(s)');
        }
    }

    public function generateBackupName(): string
    {
        return 'remote-sync-' . date('Y-m-d-His') . '.sql.gz';
    }

    protected function selectOperation(string $direction): string
    {
        return select(
            label: "What would you like to {$direction}?",
            options: ['database' => 'Database', 'files' => 'Files', 'both' => 'Both'],
            default: 'both',
        );
    }
}

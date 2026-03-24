<?php

namespace Noo\CraftRemoteSync\console\traits;

use Noo\CraftRemoteSync\models\RemoteConfig;
use Noo\CraftRemoteSync\Module;

use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

trait InteractsWithRemote
{
    protected const EXIT_ABORTED = 2;

    public bool $verbose = false;

    protected ?RemoteConfig $selectedRemote = null;

    private ?string $pendingRemoteBackup = null;

    private ?RemoteConfig $cleanupRemote = null;

    public function selectRemote(): RemoteConfig
    {
        $service = Module::$instance->getRemoteSyncService();
        $remotes = array_values(array_filter(
            $service->getAvailableRemotes(),
            fn($name) => $name !== \Craft::$app->env,
        ));

        if (empty($remotes)) {
            error("No remotes are configured in config/remote-sync.php");
            exit(1);
        }

        $options = array_combine($remotes, $remotes);
        $name = select(label: 'Select a remote', options: $options);

        $this->selectedRemote = $service->getRemote($name);
        return $this->selectedRemote;
    }

    public function selectPushRemote(): RemoteConfig
    {
        $service = Module::$instance->getRemoteSyncService();
        $remotes = array_values(array_filter(
            $service->getAvailablePushRemotes(),
            fn($name) => $name !== \Craft::$app->env,
        ));

        if (empty($remotes)) {
            error("No remotes are configured with push enabled. Set 'pushAllowed' => true in config/remote-sync.php");
            exit(1);
        }

        $options = array_combine($remotes, $remotes);
        $name = select(label: 'Select a remote to push to', options: $options);

        $this->selectedRemote = $service->getRemote($name);
        return $this->selectedRemote;
    }

    public function verifyHostConnection(RemoteConfig $remote): void
    {
        Module::$instance->getRemoteSyncService()->verifySshHost($remote);
    }

    public function initializeRemote(RemoteConfig $remote): RemoteConfig
    {
        $service = Module::$instance->getRemoteSyncService();
        $isAtomic = $service->detectAtomicDeployment($remote);

        $remote = $remote->withAtomic($isAtomic);
        $this->selectedRemote = $remote;
        return $remote;
    }

    public function ensureNotProduction(bool $doubleConfirm = false): void
    {
        $env = \Craft::$app->env;
        if ($env === 'production') {
            warning('You are running this command in a production environment.');
            $confirmed = confirm(label: 'Are you sure you want to continue?', default: false);
            if (!$confirmed) {
                exit(1);
            }

            if ($doubleConfirm) {
                $confirmed = confirm(label: 'This will modify your production environment. Are you REALLY sure?', default: false);
                if (!$confirmed) {
                    exit(1);
                }
            }
        }
    }

    public function confirmDbPull(): bool
    {
        warning('This will overwrite your LOCAL database with data from the remote.');
        return confirm(label: 'Do you want to continue?', default: false, yes: 'Yes, pull from remote', no: 'No, abort');
    }

    public function confirmBothPush(): bool
    {
        warning('This will overwrite the REMOTE database and files with your local data. This action is destructive and cannot be easily undone.');
        return confirm(label: 'Do you want to continue?', default: false, yes: "Yes, push to {$this->selectedRemote->name}", no: 'No, abort');
    }

    public function confirmDbPush(): bool
    {
        warning('This will overwrite the REMOTE database with your local data. This action is destructive and cannot be easily undone.');
        return confirm(label: 'Do you want to continue?', default: false, yes: "Yes, push to {$this->selectedRemote->name}", no: 'No, abort');
    }

    public function confirmBothPull(): bool
    {
        warning('This will overwrite your LOCAL database and files with data from the remote.');
        return confirm(label: 'Do you want to continue?', default: false, yes: "Yes, pull from {$this->selectedRemote->name}", no: 'No, abort');
    }

    public function confirmFilesPull(): bool
    {
        warning('This will overwrite your LOCAL files with files from the remote.');
        return confirm(label: 'Do you want to continue?', default: false, yes: "Yes, pull from {$this->selectedRemote->name}", no: 'No, abort');
    }

    public function confirmFilesPush(): bool
    {
        warning('This will overwrite the REMOTE files with your local files. This action is destructive and cannot be easily undone.');
        return confirm(label: 'Do you want to continue?', default: false, yes: "Yes, push to {$this->selectedRemote->name}", no: 'No, abort');
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

    public function previewFiles(RemoteConfig $remote, string $direction): bool
    {
        $service = Module::$instance->getRemoteSyncService();
        $paths = Module::$instance->getConfig()['paths'] ?? [];

        foreach ($paths as $storagePath) {
            try {
                $dryRunOutput = $this->runStep("Previewing '{$storagePath}'...", fn() => $service->rsyncDryRun($remote, $storagePath, $direction));
                note("Path: {$storagePath}");
                $this->displayFilesPreview($dryRunOutput);
            } catch (\RuntimeException $e) {
                error("Could not run preview for '{$storagePath}': " . $e->getMessage());
                return false;
            }
        }

        return true;
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

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['verbose']);
    }

    public function streamingCallback(): ?callable
    {
        if (!$this->verbose) {
            return null;
        }

        return function ($type, $buffer) {
            if ($type === Process::OUT) {
                fwrite(STDOUT, $buffer);
            }
        };
    }

    public function runStep(string $label, callable $fn): mixed
    {
        if ($this->verbose) {
            info($label);
            return $fn();
        }

        return spin($fn, $label);
    }

    protected function selectOperation(string $direction): string
    {
        return select(
            label: "What would you like to {$direction}?",
            options: ['database' => 'Database', 'files' => 'Files', 'both' => 'Both'],
            default: 'both',
        );
    }

    protected function registerRemoteCleanup(RemoteConfig $remote, string $filename): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $this->cleanupRemote = $remote;
        $this->pendingRemoteBackup = $filename;

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, [$this, 'handleInterrupt']);
    }

    protected function clearRemoteCleanup(): void
    {
        $this->cleanupRemote = null;
        $this->pendingRemoteBackup = null;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_DFL);
        }
    }

    public function handleInterrupt(): void
    {
        if ($this->cleanupRemote !== null && $this->pendingRemoteBackup !== null) {
            $service = Module::$instance->getRemoteSyncService();

            warning("\nInterrupted. Cleaning up remote backup...");

            try {
                $service->deleteRemoteBackup($this->cleanupRemote, $this->pendingRemoteBackup);
                info("Removed remote backup: {$this->pendingRemoteBackup}");
            } catch (\RuntimeException $e) {
                warning("Could not clean up remote backup: " . $e->getMessage());
            }
        }

        exit(1);
    }
}

<?php

namespace Noo\CraftRemoteSync\console\traits;

use Closure;
use Craft;
use InvalidArgumentException;
use Noo\CraftRemoteSync\Module;
use Noo\CraftRemoteSync\Process\ProcessResult;
use Noo\CraftRemoteSync\Process\ProcessRunner;
use Noo\CraftRemoteSync\Process\SymfonyProcessRunner;
use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\Probe;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Remotes\RemoteRegistry;
use Noo\CraftRemoteSync\Snapshots\Snapshots;
use Noo\CraftRemoteSync\Support\CleanupStack;
use Noo\CraftRemoteSync\Support\StoragePath;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Shared spine for the sync commands: production guard, remote resolution,
 * host-key verification, probing, plan building, confirmation, numbered step
 * execution, and interrupt-safe cleanup.
 */
trait ResolvesRemote
{
    public bool $database = false;

    public bool $files = false;

    public bool $force = false;

    public bool $verbose = false;

    public bool $dryRun = false;

    /** @var list<string> Guidance printed under a failed step (e.g. restore instructions). */
    protected array $failureNotes = [];

    protected ?ProcessRunner $runner = null;

    protected function processRunner(): ProcessRunner
    {
        return $this->runner ??= new SymfonyProcessRunner();
    }

    protected function t(string $key, array $params = []): string
    {
        return Craft::t('remote-sync', $key, $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return Module::$instance->getConfig();
    }

    protected function isInteractive(): bool
    {
        return $this->interactive && stream_isatty(STDIN);
    }

    protected function runsInProduction(): bool
    {
        return Craft::$app->env === 'production';
    }

    /**
     * Pull and push refuse to run in production unless explicitly allowed.
     */
    protected function guardProduction(): bool
    {
        if (! $this->runsInProduction() || ($this->config()['allow_production'] ?? false)) {
            return true;
        }

        error($this->t('errors.production_refused'));

        return false;
    }

    protected function resolveRemote(?string $name): ?Remote
    {
        $registry = new RemoteRegistry($this->config());
        $names = $registry->names();

        if ($names === []) {
            error($this->t('errors.no_remotes_configured'));

            return null;
        }

        if ($name !== null) {
            $name = $registry->expandName($name);
        }

        if ($name === null && count($names) === 1) {
            $name = $names[0];
        }

        if ($name === null) {
            $default = $registry->defaultName();

            if ($this->isInteractive()) {
                $name = select(
                    label: $this->t('prompts.remote.label'),
                    options: array_combine($names, $names),
                    default: in_array($default, $names, true) ? $default : $names[0],
                );
            } elseif ($default !== null && $registry->has($default)) {
                $name = $default;
            } else {
                error($this->t('errors.ambiguous_remote', ['names' => implode(', ', $names)]));

                return null;
            }
        }

        try {
            return $registry->get($name);
        } catch (InvalidArgumentException $e) {
            error($e->getMessage());

            return null;
        }
    }

    protected function verifyHostKey(Connection $connection): bool
    {
        info($this->t('spinners.verifying_host'));
        $status = $connection->checkHostKey();

        if ($status === 'ok') {
            return true;
        }

        $hostname = $connection->remote->hostname();

        if ($status === 'changed') {
            error($this->t('errors.host_key_changed', ['host' => $hostname]));

            return false;
        }

        if (! $this->isInteractive()) {
            error($this->t('errors.host_key_unknown_noninteractive', ['host' => $hostname]));

            return false;
        }

        warning($this->t('warnings.unknown_host', ['host' => $hostname]));

        $fingerprints = $connection->hostFingerprints();

        $rows = [];
        foreach (explode("\n", $fingerprints ?? '') as $line) {
            if (preg_match('/^\d+\s+(SHA256:\S+)\s+.*\(([\w-]+)\)$/', trim($line), $matches)) {
                $rows[] = [$matches[2], $matches[1]];
            }
        }

        if ($rows === []) {
            error($this->t('errors.host_fingerprint_missing', ['host' => $hostname]));

            return false;
        }

        table(['Type', 'Fingerprint'], $rows);

        if (! confirm(label: $this->t('prompts.confirm.accept_host_key', ['host' => $hostname]), default: false)) {
            return false;
        }

        info($this->t('spinners.accepting_host_key'));
        $accepted = $connection->acceptHostKey();

        if (! $accepted) {
            error($this->t('errors.host_key_failed'));

            return false;
        }

        info($this->t('info.host_key_accepted', ['host' => $hostname]));

        return true;
    }

    protected function probeRemote(Connection $connection): ?RemoteInfo
    {
        try {
            info($this->t('spinners.probing', ['name' => $connection->remote->name]));
            $info = (new Probe())->run($connection);
            $info->requirePhpBinary($connection->remote);

            return $info;
        } catch (RuntimeException $e) {
            error($e->getMessage());
            info($this->t('info.doctor_hint', ['name' => $connection->remote->name]));

            return null;
        }
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    protected function buildPlan(Closure $callback): mixed
    {
        info($this->t('spinners.building_plan'));

        return $callback();
    }

    /**
     * The one confirmation before executing a plan. --force answers yes;
     * non-interactive runs without --force stop here; production runs escalate
     * to a typed confirmation.
     */
    protected function confirmPlan(string $label): bool
    {
        if ($this->force) {
            return true;
        }

        if (! $this->isInteractive()) {
            error($this->t('errors.confirmation_required'));

            return false;
        }

        if ($this->runsInProduction()) {
            return $this->confirmWithTypedYes($label.' '.$this->t('prompts.confirm.typed_yes_suffix'));
        }

        return confirm(label: $label, default: true);
    }

    protected function confirmWithTypedYes(string $label): bool
    {
        $response = text(
            label: $label,
            placeholder: 'yes',
            required: true,
            validate: fn (string $value) => $value === 'yes' ? null : $this->t('prompts.confirm.validation'),
        );

        return $response === 'yes';
    }

    /**
     * @return list<string>|null null when a path fails validation
     */
    protected function resolvePaths(?string $pathOption): ?array
    {
        $paths = ($pathOption !== null && $pathOption !== '')
            ? array_map('trim', explode(',', $pathOption))
            : ($this->config()['paths'] ?? []);

        $paths = array_values(array_filter(array_unique(array_map(
            fn (string $path) => StoragePath::normalize($path),
            $paths
        )), fn (string $path) => $path !== ''));

        foreach ($paths as $path) {
            if (($validationError = StoragePath::validate($path)) !== null) {
                error($validationError);

                return null;
            }
        }

        return $paths;
    }

    /**
     * Run numbered execution steps. Each step returns an error message or null;
     * the first failure prints the error plus any queued failure notes and stops.
     *
     * @param  list<array{label: string, run: Closure(): ?string, onFailure?: Closure(): void}>  $steps
     */
    protected function runSteps(array $steps): bool
    {
        $total = count($steps);
        $this->failureNotes = [];

        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $error = $this->runStep("{$number}/{$total} {$step['label']}", $step['run']);

            if ($error !== null) {
                if (isset($step['onFailure'])) {
                    $step['onFailure']();
                }
                error($error);

                foreach ($this->failureNotes as $note) {
                    $this->stdout("  {$note}\n");
                }

                return false;
            }
        }

        return true;
    }

    /**
     * @param  Closure(): ?string  $fn
     */
    protected function runStep(string $label, Closure $fn): ?string
    {
        // Do not fork a Prompts spinner while a sync is mutating data:
        // it replaces our SIGINT handler and interferes with rsync output.
        info($label);
        try {
            return $fn();
        } catch (Throwable $e) {
            return "{$label}: {$e->getMessage()}";
        }
    }

    protected function registerRemoteCleanup(CleanupStack $cleanup, Snapshots $snapshots, string $name, string $label): void
    {
        $cleanup->push($label, static function () use ($snapshots, $name) {
            if (! $snapshots->deleteRemote($name)->successful()) {
                throw new RuntimeException("delete it manually: {$name}");
            }
        });
    }

    protected function runCleanup(CleanupStack $cleanup): void
    {
        $result = $cleanup->run();

        foreach ($result['ran'] as $label) {
            info($this->t('info.cleaned_up', ['what' => $label]));
        }

        foreach ($result['failed'] as $label => $error) {
            warning($this->t('warnings.cleanup_failed', ['what' => $label, 'error' => $error]));
        }
    }

    /**
     * Best-effort cleanup for fatal exits and catchable termination signals.
     * Only the process that owns the snapshots may run its cleanup stack.
     */
    protected function ensureCleanupOnExit(CleanupStack $cleanup): Closure
    {
        $owner = getmypid();
        register_shutdown_function(function () use ($cleanup, $owner) {
            if (getmypid() !== $owner || $cleanup->isEmpty()) {
                return;
            }

            $this->stdout("\n");
            warning($this->t('warnings.interrupt_cleanup'));
            $this->runCleanup($cleanup);
        });

        $handlers = [];
        $async = false;
        if (function_exists('pcntl_signal')) {
            $async = pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, static fn (int $received) => exit(128 + $received));
            }
        }

        return static function () use ($handlers, $async) {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if ($handlers !== []) {
                pcntl_async_signals($async);
            }
        };
    }

    protected function runLocalCraft(string ...$args): ProcessResult
    {
        $command = array_merge([PHP_BINARY, Craft::getAlias('@root').DIRECTORY_SEPARATOR.'craft'], $args);

        return $this->processRunner()->run($command, (int) ($this->config()['timeouts']['transfer'] ?? 1800));
    }
}

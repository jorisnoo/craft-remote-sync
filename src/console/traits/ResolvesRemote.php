<?php

namespace Noo\CraftRemoteSync\console\traits;

use Closure;
use Craft;
use InvalidArgumentException;
use Noo\CraftRemoteSync\Module;
use Noo\CraftRemoteSync\Process\ProcessResult;
use Noo\CraftRemoteSync\Process\SymfonyProcessRunner;
use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\Probe;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Remotes\RemoteRegistry;
use Noo\CraftRemoteSync\Support\CleanupStack;
use Noo\CraftRemoteSync\Support\StoragePath;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
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
        $registry = new RemoteRegistry();
        $names = $registry->names();

        if ($names === []) {
            error($this->t('errors.no_remotes_configured'));

            return null;
        }

        if ($name !== null && ! $registry->has($name)) {
            $matches = array_values(array_filter($names, fn (string $candidate) => str_starts_with($candidate, $name)));

            if (count($matches) === 1) {
                $name = $matches[0];
            }
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
        $status = spin(fn () => $connection->checkHostKey(), $this->t('spinners.verifying_host'));

        if ($status === 'ok') {
            return true;
        }

        $hostname = $connection->remote->hostname();

        if ($status === 'changed') {
            error($this->t('errors.host_key_changed', ['host' => $hostname]));

            return false;
        }

        if (! $this->isInteractive()) {
            error($this->t('errors.host_key_unknown_noninteractive', [
                'host' => $hostname,
                'ssh_host' => $connection->remote->host,
            ]));

            return false;
        }

        warning($this->t('warnings.unknown_host', ['host' => $hostname]));

        $fingerprints = $connection->hostFingerprints();

        if ($fingerprints !== null) {
            $rows = [];

            foreach (explode("\n", $fingerprints) as $line) {
                if (preg_match('/^\d+\s+(SHA256:\S+)\s+.*\((\w+)\)$/', trim($line), $matches)) {
                    $rows[] = [$matches[2], $matches[1]];
                }
            }

            if ($rows !== []) {
                table(['Type', 'Fingerprint'], $rows);
            }
        }

        if (! confirm(label: $this->t('prompts.confirm.accept_host_key', ['host' => $hostname]), default: false)) {
            return false;
        }

        $accepted = spin(fn () => $connection->acceptHostKey(), $this->t('spinners.accepting_host_key'));

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
            return spin(
                fn () => (new Probe())->run($connection),
                $this->t('spinners.probing', ['name' => $connection->remote->name]),
            );
        } catch (RuntimeException $e) {
            error($e->getMessage());
            info($this->t('info.doctor_hint', ['name' => $connection->remote->name]));

            return null;
        }
    }

    /**
     * Build the sync plan behind a spinner. In verbose mode the spinner is
     * dropped so the file dry-run's live rsync output stays legible.
     *
     * @param  Closure(): mixed  $callback
     */
    protected function buildPlan(Closure $callback): mixed
    {
        if ($this->verbose) {
            info($this->t('spinners.building_plan'));

            return $callback();
        }

        return spin($callback, $this->t('spinners.building_plan'));
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
     * @param  list<array{label: string, run: Closure(): ?string}>  $steps
     */
    protected function runSteps(array $steps): bool
    {
        $total = count($steps);

        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $error = $this->runStep("{$number}/{$total} {$step['label']}", $step['run']);

            if ($error !== null) {
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
        if ($this->verbose) {
            info($label);

            return $fn();
        }

        return spin($fn, $label);
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

    protected function trapSignals(Closure $onSignal): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, $onSignal);
        pcntl_signal(SIGTERM, $onSignal);
    }

    protected function runLocalCraft(string ...$args): ProcessResult
    {
        $command = array_merge([PHP_BINARY, Craft::getAlias('@root').DIRECTORY_SEPARATOR.'craft'], $args);

        return (new SymfonyProcessRunner())->run($command, (int) ($this->config()['timeouts']['transfer'] ?? 1800));
    }
}

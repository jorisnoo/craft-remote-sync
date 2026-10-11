<?php

namespace Noo\CraftRemoteSync\Remotes;

use InvalidArgumentException;
use Noo\CraftRemoteSync\Module;

class RemoteRegistry
{
    /** @var array<string, mixed>|null */
    private ?array $config;

    /**
     * @param  array<string, mixed>|null  $config  Full remote-sync config; defaults to the module config.
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return $this->config ??= Module::$instance->getConfig();
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->config()['remotes'] ?? []);
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names(), true);
    }

    /**
     * Expand an unambiguous prefix to the configured remote name it matches
     * (e.g. "prod" for "production"). Other names are returned unchanged.
     */
    public function expandName(string $name): string
    {
        if ($this->has($name)) {
            return $name;
        }

        $matches = array_values(array_filter(
            $this->names(),
            fn (string $candidate) => str_starts_with($candidate, $name),
        ));

        return count($matches) === 1 ? $matches[0] : $name;
    }

    public function defaultName(): ?string
    {
        $default = $this->config()['default'] ?? null;

        return is_string($default) && $default !== '' ? $default : null;
    }

    public function get(string $name): Remote
    {
        $remotes = $this->config()['remotes'] ?? [];

        if (! array_key_exists($name, $remotes)) {
            $configured = implode(', ', $this->names());

            throw new InvalidArgumentException($configured === ''
                ? "Remote '{$name}' is not configured, and no remotes are defined in config/remote-sync.php."
                : "Remote '{$name}' is not configured. Configured remotes: {$configured}.");
        }

        $config = $remotes[$name];
        $phpBinary = $config['php_binary'] ?? null;

        return new Remote(
            name: $name,
            host: $this->validated($name, 'host', $config['host'] ?? null),
            path: rtrim($this->validated($name, 'path', $config['path'] ?? null), '/'),
            push: (bool) ($config['push'] ?? false),
            phpBinary: is_string($phpBinary) && trim($phpBinary) !== '' ? trim($phpBinary) : null,
        );
    }

    private function validated(string $remote, string $key, mixed $value): string
    {
        $envVar = 'REMOTE_SYNC_'.str_replace('-', '_', strtoupper($remote)).'_'.strtoupper($key);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Remote '{$remote}' has no {$key} configured. Set {$envVar} in your .env or edit config/remote-sync.php."
            );
        }

        $value = trim($value);

        if (str_contains($value, 'your-server') || str_contains($value, 'example.com')) {
            throw new InvalidArgumentException(
                "Remote '{$remote}' still uses the placeholder {$key} '{$value}'. Set {$envVar} in your .env or edit config/remote-sync.php."
            );
        }

        // A leading dash would be parsed as an option by ssh and rsync (the
        // whole target) or ssh-keyscan (the bare hostname after user@).
        if ($key === 'host' && (str_starts_with($value, '-') || str_contains($value, '@-'))) {
            throw new InvalidArgumentException(
                "Remote '{$remote}' has an invalid host '{$value}': neither the user nor the hostname may start with '-'."
            );
        }

        return $value;
    }
}

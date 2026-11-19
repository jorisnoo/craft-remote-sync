<?php

namespace Noo\CraftRemoteSync\Remotes;

use Noo\CraftRemoteSync\Module;
use Noo\CraftRemoteSync\Process\ProcessResult;
use Noo\CraftRemoteSync\Process\ProcessRunner;
use Noo\CraftRemoteSync\Process\SymfonyProcessRunner;

class Connection
{
    private ProcessRunner $runner;

    /** @var list<string> The fingerprints offered for confirmation by the latest scan. */
    private array $scannedFingerprints = [];

    /** @var array<string, mixed>|null */
    private ?array $config;

    /**
     * @param  array<string, mixed>|null  $config  Full remote-sync config; defaults to the module config (for timeouts).
     */
    public function __construct(
        public readonly Remote $remote,
        ?ProcessRunner $runner = null,
        ?array $config = null,
    ) {
        $this->runner = $runner ?? new SymfonyProcessRunner();
        $this->config = $config;
    }

    public function run(string $command, ?int $timeout = null): ProcessResult
    {
        return $this->runner->run($this->sshArgs([], $command), $timeout ?? $this->remoteTimeout());
    }

    /**
     * Run a craft console command on the remote, from the working path.
     */
    public function craft(RemoteInfo $info, string $arguments, ?int $timeout = null): ProcessResult
    {
        $php = escapeshellarg($info->requirePhpBinary($this->remote));
        $path = escapeshellarg($info->workingPath);

        return $this->run("cd {$path} && {$php} craft {$arguments}", $timeout);
    }

    /**
     * @return 'ok'|'unknown'|'changed'  'ok' when the key is known (or the failure is unrelated to host keys).
     */
    public function checkHostKey(): string
    {
        $result = $this->runner->run(
            $this->sshArgs(['-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=5'], 'exit'),
            10,
        );

        $error = $result->errorOutput();

        if (str_contains($error, 'REMOTE HOST IDENTIFICATION HAS CHANGED')) {
            return 'changed';
        }

        if (str_contains($error, 'Host key verification failed')) {
            return 'unknown';
        }

        return 'ok';
    }

    public function hostFingerprints(): ?string
    {
        $this->scannedFingerprints = [];
        // ssh-keyscan does not read ssh_config. Resolve the same target and
        // port as the connection, and never scan a direct route for a proxy.
        $settings = $this->runner->run($this->sshArgs(['-G']), 10);
        $output = $settings->output();
        if (! $settings->successful()
            || preg_match('/^(?:proxycommand|proxyjump)[ \t]+(?!none[ \t]*\r?$).+/mi', $output)
            || ! preg_match('/^hostname[ \t]+([\pL\pN_:][\pL\pN_.:%+-]*|\[[0-9a-f:.]+(?:%[\w.-]+)?\])[ \t]*\r?$/miu', $output, $host)
            || ! preg_match('/^port[ \t]+(\d+)[ \t]*\r?$/mi', $output, $match)) {
            return null;
        }

        $port = (int) $match[1];
        if ($port < 1 || $port > 65535) {
            return null;
        }
        $hostname = escapeshellarg(trim($host[1], '[]'));

        $result = $this->runner->run(
            "ssh-keyscan -p {$port} -t ed25519,rsa,ecdsa {$hostname} 2>/dev/null | ssh-keygen -E sha256 -lf -",
            10,
        );

        if (! $result->successful()) {
            return null;
        }

        $lines = [];
        foreach (explode("\n", trim($result->output())) as $line) {
            $line = trim($line);
            if (preg_match('/^\d+\s+(SHA256:[A-Za-z0-9+\/]{43})\s+.*\(\w+\)$/D', $line, $match)) {
                $this->scannedFingerprints[] = $match[1];
                $lines[] = $line;
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    public function acceptHostKey(): bool
    {
        $fingerprints = $this->scannedFingerprints;
        $this->scannedFingerprints = [];
        if ($fingerprints === []) {
            return false;
        }

        // Older clients cannot force askpass instead of reading /dev/tty.
        // Leave enrollment to the operator rather than falling back to TOFU.
        $version = $this->runner->run(['ssh', '-V'], 5);
        if (! $version->successful()
            || ! preg_match('/OpenSSH_(\d+\.\d+)/', $version->errorOutput().$version->output(), $match)
            || version_compare($match[1], '8.4', '<')) {
            return false;
        }

        $result = $this->runner->run([
            'env', 'LC_ALL=C', 'SSH_ASKPASS_REQUIRE=force', 'SSH_ASKPASS='.__DIR__.'/confirm-fingerprint',
            'REMOTE_SYNC_FINGERPRINTS='.implode(' ', $fingerprints),
            ...$this->sshArgs([
                '-S', 'none', '-o', 'StrictHostKeyChecking=ask', '-o', 'FingerprintHash=sha256',
                '-o', 'UpdateHostKeys=no', '-o', 'CheckHostIP=no', '-o', 'VerifyHostKeyDNS=no',
                '-o', 'BatchMode=no', '-o', 'NumberOfPasswordPrompts=0', '-o', 'LogLevel=INFO', '-o', 'ConnectTimeout=10',
            ], 'exit'),
        ], 15);

        // An encrypted identity or password-only account can fail to log in
        // through askpass even after OpenSSH has recorded the approved key.
        return $result->successful() || preg_match(
            "/^Warning: Permanently added .+ to the list of known hosts\\.\r?$/m",
            $result->errorOutput(),
        ) === 1;
    }

    public function remoteTimeout(): int
    {
        return (int) ($this->config()['timeouts']['remote'] ?? 300);
    }

    public function transferTimeout(): int
    {
        return (int) ($this->config()['timeouts']['transfer'] ?? 1800);
    }

    /**
     * Extra rsync exclude patterns from config (on top of the always-excluded dotfiles).
     *
     * @return list<string>
     */
    public function excludePaths(): array
    {
        return array_values($this->config()['exclude_paths'] ?? []);
    }

    /**
     * The SSH target (user@host) with any :port stripped; the port is passed via -p instead.
     */
    public function sshTarget(): string
    {
        return $this->remote->sshTarget();
    }

    public function sshPort(): ?int
    {
        return $this->remote->sshPort();
    }

    /**
     * @param  list<string>  $options
     * @return list<string>
     */
    private function sshArgs(array $options, ?string $command = null): array
    {
        $args = ['ssh', ...$options];

        if (($port = $this->sshPort()) !== null) {
            $args[] = '-p';
            $args[] = (string) $port;
        }

        $args[] = $this->sshTarget();
        if ($command !== null) {
            $args[] = $command;
        }

        return $args;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return $this->config ??= Module::$instance->getConfig();
    }
}

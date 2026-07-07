<?php

namespace Noo\CraftRemoteSync\Remotes;

use Noo\CraftRemoteSync\Module;
use Noo\CraftRemoteSync\Process\ProcessResult;
use Noo\CraftRemoteSync\Process\ProcessRunner;
use Noo\CraftRemoteSync\Process\SymfonyProcessRunner;

class Connection
{
    private ProcessRunner $runner;

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
        $php = escapeshellarg($info->phpBinary ?? 'php');
        $path = escapeshellarg($info->workingPath);

        return $this->run("cd {$path} && {$php} craft {$arguments}", $timeout);
    }

    /**
     * @return 'ok'|'unknown'|'changed'  'ok' when the key is known (or the failure is unrelated to host keys).
     */
    public function checkHostKey(): string
    {
        $result = $this->runner->run(
            $this->sshArgs(['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5'], 'exit'),
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
        $hostname = escapeshellarg($this->remote->hostname());
        $port = $this->sshPort();
        $portOption = $port !== null ? "-p {$port} " : '';

        $result = $this->runner->run(
            "ssh-keyscan {$portOption}-t ed25519,rsa,ecdsa {$hostname} 2>/dev/null | ssh-keygen -lf -",
            10,
        );

        if ($result->successful() && trim($result->output()) !== '') {
            return trim($result->output());
        }

        return null;
    }

    public function acceptHostKey(): bool
    {
        $result = $this->runner->run(
            $this->sshArgs(['-o', 'StrictHostKeyChecking=accept-new', '-o', 'ConnectTimeout=10'], 'exit'),
            15,
        );

        return ! str_contains($result->errorOutput(), 'Host key verification failed');
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
        return $this->parsedHost()['host'];
    }

    public function sshPort(): ?int
    {
        return $this->parsedHost()['port'];
    }

    /**
     * @param  list<string>  $options
     * @return list<string>
     */
    private function sshArgs(array $options, string $command): array
    {
        $args = ['ssh', ...$options];

        if (($port = $this->sshPort()) !== null) {
            $args[] = '-p';
            $args[] = (string) $port;
        }

        $args[] = $this->sshTarget();
        $args[] = $command;

        return $args;
    }

    /**
     * @return array{host: string, port: int|null}
     */
    private function parsedHost(): array
    {
        if (preg_match('/^(.+):(\d+)$/', $this->remote->host, $matches)) {
            return ['host' => $matches[1], 'port' => (int) $matches[2]];
        }

        return ['host' => $this->remote->host, 'port' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return $this->config ??= Module::$instance->getConfig();
    }
}

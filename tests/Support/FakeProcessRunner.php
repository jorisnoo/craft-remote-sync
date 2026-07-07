<?php

namespace Noo\CraftRemoteSync\Tests\Support;

use Noo\CraftRemoteSync\Process\ProcessResult;
use Noo\CraftRemoteSync\Process\ProcessRunner;

/**
 * A ProcessRunner that never spawns a process. Register canned results with
 * stub() (matched by substring against the command line) and inspect what was
 * run via $calls.
 */
class FakeProcessRunner implements ProcessRunner
{
    /** @var list<string> Command lines that were run, in order. */
    public array $calls = [];

    /** @var list<array{needle: string, stdout: string, stderr: string, exitCode: int}> */
    private array $stubs = [];

    /** @var array{stdout: string, stderr: string, exitCode: int} */
    private array $default = ['stdout' => '', 'stderr' => '', 'exitCode' => 0];

    /**
     * Register a canned result for any command whose line contains $needle.
     * The first matching stub (in registration order) wins.
     */
    public function stub(string $needle, string $stdout = '', string $stderr = '', int $exitCode = 0): self
    {
        $this->stubs[] = compact('needle', 'stdout', 'stderr', 'exitCode');

        return $this;
    }

    public function fallback(string $stdout = '', string $stderr = '', int $exitCode = 0): self
    {
        $this->default = compact('stdout', 'stderr', 'exitCode');

        return $this;
    }

    public function run(array|string $command, ?int $timeout = null, ?callable $onOutput = null, bool $tty = false): ProcessResult
    {
        $line = is_array($command) ? implode(' ', $command) : $command;
        $this->calls[] = $line;

        foreach ($this->stubs as $stub) {
            if (str_contains($line, $stub['needle'])) {
                return new ProcessResult($stub['stdout'], $stub['stderr'], $stub['exitCode']);
            }
        }

        return new ProcessResult($this->default['stdout'], $this->default['stderr'], $this->default['exitCode']);
    }
}

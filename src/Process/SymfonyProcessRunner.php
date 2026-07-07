<?php

namespace Noo\CraftRemoteSync\Process;

use Symfony\Component\Process\Process;

class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array|string $command, ?int $timeout = null, ?callable $onOutput = null, bool $tty = false): ProcessResult
    {
        $process = is_array($command)
            ? new Process($command)
            : Process::fromShellCommandline($command);

        $process->setTimeout($timeout);

        if ($tty && Process::isTtySupported()) {
            $process->setTty(true);
        }

        $process->run($onOutput);

        return new ProcessResult(
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            exitCode: $process->getExitCode() ?? 1,
        );
    }
}

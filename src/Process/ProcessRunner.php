<?php

namespace Noo\CraftRemoteSync\Process;

interface ProcessRunner
{
    /**
     * Run a command and return its result.
     *
     * @param  list<string>|string  $command  An argv array, or a shell command line string (for pipes/redirects).
     * @param  (callable(string, string): void)|null  $onOutput  Called with (type, buffer) as output streams in.
     * @param  bool  $tty  Attach a TTY (for interactive transfers); output is not captured when set.
     */
    public function run(array|string $command, ?int $timeout = null, ?callable $onOutput = null, bool $tty = false): ProcessResult;
}

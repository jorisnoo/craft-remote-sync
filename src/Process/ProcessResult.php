<?php

namespace Noo\CraftRemoteSync\Process;

readonly class ProcessResult
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function output(): string
    {
        return $this->stdout;
    }

    public function errorOutput(): string
    {
        return $this->stderr;
    }
}

<?php

namespace Noo\CraftRemoteSync\Sync;

use Noo\CraftRemoteSync\Process\ProcessResult;
use Noo\CraftRemoteSync\Process\ProcessRunner;
use Noo\CraftRemoteSync\Process\SymfonyProcessRunner;
use Noo\CraftRemoteSync\Remotes\Connection;
use RuntimeException;

class Rsync
{
    private ProcessRunner $runner;

    public function __construct(
        private Connection $connection,
        private bool $interactive = false,
        private bool $verbose = false,
        ?ProcessRunner $runner = null,
    ) {
        $this->runner = $runner ?? new SymfonyProcessRunner();
    }

    /**
     * @param  list<string>  $excludes  Extra exclude patterns for this transfer (on top of dotfiles and config).
     */
    public function transfer(
        Direction $direction,
        string $remotePath,
        string $localPath,
        array $excludes = [],
        bool $delete = false
    ): ProcessResult {
        $options = ['-avz', '--partial'];

        if ($this->interactive) {
            $options[] = '--info=progress2';
        }

        $command = $this->command($direction, $remotePath, $localPath, $options, $excludes, $delete);

        // In verbose non-interactive runs, stream rsync output instead of capturing it silently.
        $onOutput = (! $this->interactive && $this->verbose)
            ? fn (string $type, string $buffer) => fwrite($type === 'err' ? STDERR : STDOUT, $buffer)
            : null;

        return $this->runner->run($command, $this->connection->transferTimeout(), $onOutput, tty: $this->interactive);
    }

    /**
     * @param  list<string>  $excludes
     *
     * @throws RuntimeException when the dry-run itself fails
     */
    public function dryRun(
        Direction $direction,
        string $remotePath,
        string $localPath,
        array $excludes = [],
        bool $delete = false
    ): FileChanges {
        $options = ['-avz', '--dry-run', '--itemize-changes'];

        if ($this->verbose) {
            $options[] = '--stats';
        }

        $command = $this->command($direction, $remotePath, $localPath, $options, $excludes, $delete);

        if ($this->verbose) {
            fwrite(STDERR, '  $ '.implode(' ', $command).PHP_EOL);
        }

        $result = $this->runner->run(
            $command,
            $this->connection->transferTimeout(),
            $this->verbose ? fn (string $type, string $buffer) => fwrite($type === 'err' ? STDERR : STDOUT, $buffer) : null,
        );

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'rsync dry run failed');
        }

        return $this->parse($result->output());
    }

    /**
     * @param  list<string>  $options
     * @param  list<string>  $excludes
     * @return list<string>
     */
    protected function command(
        Direction $direction,
        string $remotePath,
        string $localPath,
        array $options,
        array $excludes,
        bool $delete
    ): array {
        $excludePatterns = array_values(array_unique(array_merge(
            ['.*'],
            $this->connection->excludePaths(),
            $excludes,
        )));

        $excludeOptions = array_map(fn (string $pattern) => "--exclude={$pattern}", $excludePatterns);

        if ($delete) {
            $options[] = '--delete';
        }

        $sshOption = [];

        if (($port = $this->connection->sshPort()) !== null) {
            $sshOption = ['-e', "ssh -p {$port}"];
        }

        $remoteSpec = $this->connection->sshTarget().':'.$remotePath;

        [$source, $destination] = $direction === Direction::Pull
            ? [$remoteSpec, $localPath]
            : [$localPath, $remoteSpec];

        return array_merge(['rsync'], $options, $sshOption, $excludeOptions, [$source, $destination]);
    }

    public function parse(string $output): FileChanges
    {
        $transfers = [];
        $deletions = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, 'sending') || str_starts_with($line, 'receiving')) {
                continue;
            }

            if (str_starts_with($line, '*deleting')) {
                $deletions[] = trim(substr($line, strlen('*deleting')));

                continue;
            }

            if (preg_match('/^[<>ch.][fdLDS]/', $line) && ! str_ends_with($line, '/')) {
                $transfers[] = preg_replace('/^[<>ch.][fdLDS][^ ]* /', '', $line);
            }
        }

        return new FileChanges($transfers, $deletions);
    }
}

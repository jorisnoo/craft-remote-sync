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
            $options[] = '--progress';
        }

        $command = $this->command($direction, $remotePath, $localPath, $options, $excludes, $delete);

        // Keep stderr available to the caller even when showing live progress.
        $onOutput = ($this->interactive || $this->verbose) ? self::streamOutput(...) : null;

        return $this->runner->run($command, $this->connection->transferTimeout(), $onOutput);
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
            $this->verbose ? self::streamOutput(...) : null,
        );

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'rsync dry run failed');
        }

        return $this->parse($result->output());
    }

    /**
     * Check all pull sources in one read-only SSH call. Walk the components
     * from a searchable directory so that an inaccessible parent (or a file
     * where a directory was expected) is an error, not a missing source.
     *
     * @param  list<string>  $paths  Validated storage-relative paths.
     * @return list<string>
     */
    public function missingRemoteSources(string $workingPath, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $script = 'CDPATH= cd '.escapeshellarg($workingPath)." || exit 1\n"
            .'set -- '.implode(' ', array_map('escapeshellarg', $paths))."\n"
            .<<<'SH'
            set -f
            for path do
                (
                    IFS=/
                    for part in storage $path; do
                        if [ ! -e "./$part" ] && [ ! -L "./$part" ]; then
                            printf 'MISSING=%s\n' "$path"
                            break
                        fi
                        cd "./$part" || exit 1
                    done
                ) || exit 1
            done
            SH;

        $result = $this->connection->run($script);
        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'Could not inspect remote storage directories.');
        }

        $missing = [];
        foreach (explode("\n", $result->output()) as $line) {
            if (str_starts_with($line, 'MISSING=')) {
                $missing[] = substr($line, 8);
            }
        }

        return $missing;
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

    private static function streamOutput(string $type, string $buffer): void
    {
        fwrite($type === 'err' ? STDERR : STDOUT, $buffer);
    }

    public function parse(string $output): FileChanges
    {
        $transfers = [];
        $deletions = [];

        foreach (explode("\n", $output) as $line) {
            $line = rtrim($line, "\r");

            if (preg_match('/^\*?deleting\s+(.+)$/', $line, $match)) {
                if (! str_ends_with($match[1], '/')) {
                    $deletions[] = $match[1];
                }

                continue;
            }

            // YXcstpoguax: only transferred/created non-directories count,
            // not directory entries or attribute-only (.f...) updates.
            if (preg_match('/^[<>c]([fLDS])\S{7,9} (.+)$/', $line, $match)) {
                $transfers[] = $match[1] === 'L'
                    ? preg_split('/ (?:->|=>) /', $match[2], 2)[0]
                    : $match[2];
            }
        }

        return new FileChanges($transfers, $deletions);
    }
}

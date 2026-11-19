<?php

use Noo\CraftRemoteSync\console\traits\ResolvesRemote;
use Noo\CraftRemoteSync\Support\CleanupStack;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$command = new class {
    use ResolvesRemote;

    public function interrupt(int $signal): void
    {
        $cleanup = new CleanupStack();
        $cleanup->push('probe', static fn () => print("cleanup performed\n"));
        $this->ensureCleanupOnExit($cleanup);

        // A child inherits the shutdown function but does not own the snapshot.
        $child = pcntl_fork();
        if ($child < 0) {
            throw new RuntimeException('fork failed');
        }
        if ($child === 0) {
            exit(0);
        }
        pcntl_waitpid($child, $status);
        posix_kill(getmypid(), $signal);
    }

    protected function t(string $key, array $params = []): string { return $key; }
    public function stdout(string $line): void { echo $line; }
};
$command->interrupt(constant($argv[1]));

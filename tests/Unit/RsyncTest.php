<?php

use Noo\CraftRemoteSync\Process\ProcessResult;
use Noo\CraftRemoteSync\Process\SymfonyProcessRunner;
use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Sync\Direction;
use Noo\CraftRemoteSync\Sync\Rsync;
use Noo\CraftRemoteSync\Tests\Support\FakeProcessRunner;

/**
 * @return array{0: Rsync, 1: FakeProcessRunner}
 */
function rsyncSetup(string $host, array $config): array
{
    $fake = new FakeProcessRunner();
    $connection = new Connection(new Remote(name: 'prod', host: $host, path: '/var/www'), $fake, $config);
    $rsync = new Rsync($connection, interactive: false, verbose: false, runner: $fake);

    return [$rsync, $fake];
}

test('transfer pull builds an rsync pull command', function () {
    [$rsync, $fake] = rsyncSetup('forge@server.test', ['timeouts' => ['transfer' => 10], 'exclude_paths' => []]);

    $rsync->transfer(Direction::Pull, 'app/', '/local/app/');

    expect($fake->calls[0])->toBe('rsync -avz --partial --exclude=.* forge@server.test:app/ /local/app/');
});

test('transfer push reverses source and destination', function () {
    [$rsync, $fake] = rsyncSetup('forge@server.test', ['timeouts' => ['transfer' => 10], 'exclude_paths' => []]);

    $rsync->transfer(Direction::Push, 'app/', '/local/app/');

    expect($fake->calls[0])->toBe('rsync -avz --partial --exclude=.* /local/app/ forge@server.test:app/');
});

test('interactive progress uses portable flags and captured streams rather than a tty', function () {
    $fake = new class extends FakeProcessRunner {
        public bool $tty;
        public bool $streams;

        public function run(array|string $command, ?int $timeout = null, ?callable $onOutput = null, bool $tty = false): ProcessResult
        {
            $this->tty = $tty;
            $this->streams = $onOutput !== null;

            return parent::run($command, $timeout, $onOutput, $tty);
        }
    };
    $fake->fallback(stderr: 'transfer interrupted', exitCode: 1);
    $connection = new Connection(new Remote('prod', 'server.test', '/var/www'), $fake, []);

    $result = (new Rsync($connection, interactive: true, runner: $fake))->transfer(Direction::Pull, 'app/', '/local/app/');

    expect($fake->calls)->toBe(['rsync -avz --partial --progress --exclude=.* server.test:app/ /local/app/'])
        ->and($fake->tty)->toBeFalse()
        ->and($fake->streams)->toBeTrue()
        ->and($result->errorOutput())->toBe('transfer interrupted');
});

test('transfer with a port adds an ssh -e option', function () {
    [$rsync, $fake] = rsyncSetup('forge@server.test:2222', ['timeouts' => ['transfer' => 10], 'exclude_paths' => []]);

    $rsync->transfer(Direction::Pull, 'app/', '/local/app/');

    expect($fake->calls[0])->toContain('-e ssh -p 2222')
        ->and($fake->calls[0])->toContain('forge@server.test:app/');
});

test('transfer merges configured and per-call excludes and can delete', function () {
    [$rsync, $fake] = rsyncSetup('forge@server.test', ['timeouts' => ['transfer' => 10], 'exclude_paths' => ['cache', '*.log']]);

    $rsync->transfer(Direction::Pull, 'app/', '/local/app/', excludes: ['sessions'], delete: true);

    expect($fake->calls[0])->toContain('--exclude=.*')
        ->and($fake->calls[0])->toContain('--exclude=cache')
        ->and($fake->calls[0])->toContain('--exclude=*.log')
        ->and($fake->calls[0])->toContain('--exclude=sessions')
        ->and($fake->calls[0])->toContain('--delete');
});

test('parse extracts transfers and deletions from itemized output', function () {
    [$rsync] = rsyncSetup('forge@server.test', ['timeouts' => ['transfer' => 10]]);

    $changes = $rsync->parse(implode("\n", [
        'sending incremental file list',
        '>f+++++++++ uploads/new.jpg',
        'cd+++++++++ uploads/',
        '>f.st...... uploads/changed.png',
        '*deleting uploads/old.jpg',
        '*deleting   uploads/empty/',
        'deleting uploads/old-protocol.txt',
        '.f...p..... uploads/permissions',
        '.L..t...... uploads/unchanged -> target',
        'cL+++++++++ uploads/link -> target/',
        '<f+++++++++ regular -> file',
        '<f+++++++++ trailing space ',
        '',
    ]));

    expect($changes->transfers)->toBe(['uploads/new.jpg', 'uploads/changed.png', 'uploads/link', 'regular -> file', 'trailing space '])
        ->and($changes->deletions)->toBe(['uploads/old.jpg', 'uploads/old-protocol.txt']);
});

test('dryRun parses the rsync output', function () {
    $fake = (new FakeProcessRunner())->fallback(stdout: ">f+++++++++ a.txt\n*deleting b.txt\n");
    $connection = new Connection(new Remote(name: 'prod', host: 'forge@server.test', path: '/var/www'), $fake, ['timeouts' => ['transfer' => 10], 'exclude_paths' => []]);
    $rsync = new Rsync($connection, runner: $fake);

    $changes = $rsync->dryRun(Direction::Pull, 'app/', '/local/app/');

    expect($changes->transfers)->toBe(['a.txt'])
        ->and($changes->deletions)->toBe(['b.txt']);
});

test('dryRun throws when rsync fails', function () {
    $fake = (new FakeProcessRunner())->fallback(stderr: 'rsync: connection unexpectedly closed', exitCode: 255);
    $connection = new Connection(new Remote(name: 'prod', host: 'forge@server.test', path: '/var/www'), $fake, ['timeouts' => ['transfer' => 10], 'exclude_paths' => []]);

    (new Rsync($connection, runner: $fake))->dryRun(Direction::Pull, 'app/', '/local/app/');
})->throws(RuntimeException::class);

test('source inspection distinguishes absent nested directories from invalid paths without SSH', function () {
    $root = sys_get_temp_dir().'/craft remote-sync-'.bin2hex(random_bytes(8));
    mkdir($root.'/storage/app/uploads', 0700, true);
    touch($root.'/storage/not-a-directory');

    // Run only the remote shell fragment against this disposable local tree.
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('run')->andReturnUsing(fn (string $script) =>
        (new SymfonyProcessRunner())->run(['sh', '-c', $script], 5));
    $rsync = new Rsync($connection, runner: new FakeProcessRunner());

    try {
        expect($rsync->missingRemoteSources($root, ['app/uploads', 'no/parent', 'app/absent']))
            ->toBe(['no/parent', 'app/absent']);
        expect(fn () => $rsync->missingRemoteSources($root, ['not-a-directory/child']))
            ->toThrow(RuntimeException::class);
    } finally {
        unlink($root.'/storage/not-a-directory');
        rmdir($root.'/storage/app/uploads');
        rmdir($root.'/storage/app');
        rmdir($root.'/storage');
        rmdir($root);
    }
});

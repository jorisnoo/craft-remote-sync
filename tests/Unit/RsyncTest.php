<?php

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
        '',
    ]));

    expect($changes->transfers)->toBe(['uploads/new.jpg', 'uploads/changed.png'])
        ->and($changes->deletions)->toBe(['uploads/old.jpg']);
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

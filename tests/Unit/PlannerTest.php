<?php

use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Sync\Direction;
use Noo\CraftRemoteSync\Sync\Planner;
use Noo\CraftRemoteSync\Sync\Rsync;
use Noo\CraftRemoteSync\Tests\Support\FakeProcessRunner;

function plannerFixture(): Planner
{
    $fake = new FakeProcessRunner();
    $info = new RemoteInfo(phpBinary: 'php', isAtomic: false, workingPath: '/var/www', hasRsync: true);
    $connection = new Connection(new Remote(name: 'prod', host: 'forge@server.test', path: '/var/www'), $fake, ['timeouts' => ['transfer' => 10]]);

    return new Planner($info, new Rsync($connection, runner: $fake));
}

test('pull plans a database-only sync with a pre-pull backup', function () {
    $plan = plannerFixture()->pull(database: true, files: false);

    expect($plan->direction)->toBe(Direction::Pull)
        ->and($plan->database)->toBeTrue()
        ->and($plan->files)->toBeFalse()
        ->and($plan->paths)->toBe([])
        ->and($plan->snapshotName)->toStartWith('remote-sync-')
        ->and($plan->backupName)->toStartWith('pre-pull-');
});

test('pull keeps the downloaded snapshot by default', function () {
    expect(plannerFixture()->pull(database: true, files: false)->keepSnapshot)->toBeTrue()
        ->and(plannerFixture()->pull(database: true, files: false, keepSnapshot: false)->keepSnapshot)->toBeFalse();
});

test('pull without a backup leaves the backup name empty', function () {
    $plan = plannerFixture()->pull(database: true, files: false, backup: false);

    expect($plan->backup)->toBeFalse()
        ->and($plan->backupName)->toBe('');
});

test('pull without a database has no snapshot or backup name', function () {
    $plan = plannerFixture()->pull(database: false, files: false);

    expect($plan->snapshotName)->toBe('')
        ->and($plan->backupName)->toBe('');
});

test('push plans a database-only sync with a pre-push backup', function () {
    $plan = plannerFixture()->push(database: true, files: false, delete: true);

    expect($plan->direction)->toBe(Direction::Push)
        ->and($plan->delete)->toBeTrue()
        ->and($plan->snapshotName)->toStartWith('remote-sync-')
        ->and($plan->backupName)->toStartWith('pre-push-');
});

<?php

use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Sync\Direction;
use Noo\CraftRemoteSync\Sync\Planner;
use Noo\CraftRemoteSync\Sync\Rsync;
use Noo\CraftRemoteSync\Tests\Support\FakeProcessRunner;

function plannerFixture(?FakeProcessRunner $fake = null, ?string $storage = null): Planner
{
    $fake ??= new FakeProcessRunner();
    $info = new RemoteInfo(phpBinary: 'php', isAtomic: false, workingPath: '/var/www', hasRsync: true);
    $connection = new Connection(new Remote(name: 'prod', host: 'forge@server.test', path: '/var/www'), $fake, ['timeouts' => ['transfer' => 10]]);

    return new Planner($info, new Rsync($connection, runner: $fake), $storage);
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

test('pull skips only absent sources, still plans the database, and never creates local directories', function () {
    $storage = sys_get_temp_dir().'/craft-planner-'.bin2hex(random_bytes(8));
    $fake = (new FakeProcessRunner())
        ->stub('set --', stdout: "MISSING=app\n")
        ->stub('rsync -avz', stdout: ">f+++++++++ image.jpg\n");

    $plan = plannerFixture($fake, $storage)->pull(true, true, delete: true, paths: ['app', 'assets/images']);

    expect($plan->paths)->toBe(['assets/images'])
        ->and($plan->skippedPaths)->toBe(['app'])
        ->and($plan->database)->toBeTrue()
        ->and($plan->fileChanges['assets/images']->transfers)->toBe(['image.jpg'])
        ->and($fake->calls)->toHaveCount(2)
        ->and($fake->calls[0])->toContain("set -- 'app' 'assets/images'")
        ->and($fake->calls[1])->toContain('--dry-run', '--delete', "/var/www/storage/assets/images/ {$storage}/")
        ->and(file_exists($storage))->toBeFalse();
});

test('push skips an absent local path without asking rsync to delete from the destination', function () {
    $fake = new FakeProcessRunner();
    $storage = sys_get_temp_dir().'/craft-planner-'.bin2hex(random_bytes(8));
    $plan = plannerFixture($fake, $storage)->push(false, true, delete: true, paths: ['app/uploads']);

    expect($plan->paths)->toBe([])
        ->and($plan->skippedPaths)->toBe(['app/uploads'])
        ->and($plan->files)->toBeFalse()
        ->and($fake->calls)->toBe([])
        ->and(file_exists($storage))->toBeFalse();
});

test('a non-directory local source is an error, not a missing source', function () {
    expect(fn () => plannerFixture(new FakeProcessRunner(), __DIR__)->push(false, true, paths: ['PlannerTest.php/nested']))
        ->toThrow(RuntimeException::class, 'Not a storage directory');
});

test('a source inspection error aborts the pull even if some paths were reported missing', function () {
    $fake = (new FakeProcessRunner())->stub('set --', stdout: "MISSING=app\n", stderr: 'permission denied', exitCode: 1);

    expect(fn () => plannerFixture($fake, __DIR__)->pull(true, true, paths: ['app', 'uploads']))
        ->toThrow(RuntimeException::class, 'permission denied');
    expect($fake->calls)->toHaveCount(1);
});

test('ordinary partial rsync failures abort the plan', function () {
    $fake = (new FakeProcessRunner())->stub('rsync -avz', stderr: 'could not read a nested file', exitCode: 23);

    expect(fn () => plannerFixture($fake, __DIR__)->pull(true, true, paths: ['app']))
        ->toThrow(RuntimeException::class, 'Could not analyze files for storage/app: could not read a nested file');
});

test('the planner itself refuses reserved paths before any command runs', function () {
    $fake = new FakeProcessRunner();

    expect(fn () => plannerFixture($fake, __DIR__)->pull(true, true, delete: true, paths: ['app', 'Backups/archive']))
        ->toThrow(RuntimeException::class, 'database backups');
    expect($fake->calls)->toBe([]);
});

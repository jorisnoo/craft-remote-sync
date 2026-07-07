<?php

use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Snapshots\Snapshots;
use Noo\CraftRemoteSync\Tests\Support\FakeProcessRunner;

/**
 * @return array{0: Snapshots, 1: FakeProcessRunner}
 */
function snapshotsSetup(?FakeProcessRunner $fake = null, string $host = 'forge@server.test'): array
{
    $fake ??= new FakeProcessRunner();
    $connection = new Connection(new Remote(name: 'prod', host: $host, path: '/var/www'), $fake, ['timeouts' => ['remote' => 5, 'transfer' => 10]]);
    $info = new RemoteInfo(phpBinary: 'php', isAtomic: false, workingPath: '/var/www', hasRsync: true);

    return [new Snapshots($connection, $info, $fake), $fake];
}

function snapshotLine(FakeProcessRunner $fake, string $needle): string
{
    foreach ($fake->calls as $call) {
        if (str_contains($call, $needle)) {
            return $call;
        }
    }

    return '';
}

// Naming

test('transferName is prefixed and timestamped', function () {
    expect(Snapshots::transferName())->toMatch('/^remote-sync-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-[0-9a-f]{8}$/');
});

test('backup names carry the pull and push prefixes', function () {
    expect(Snapshots::pullBackupName())->toStartWith('pre-pull-')
        ->and(Snapshots::pushBackupName())->toStartWith('pre-push-');
});

test('isOwnSnapshot recognises package prefixes only', function () {
    expect(Snapshots::isOwnSnapshot('remote-sync-2024-01-01-00-00-00-deadbeef'))->toBeTrue()
        ->and(Snapshots::isOwnSnapshot('pre-pull-2024-01-01-00-00-00'))->toBeTrue()
        ->and(Snapshots::isOwnSnapshot('pre-push-2024-01-01-00-00-00'))->toBeTrue()
        ->and(Snapshots::isOwnSnapshot('cp-backup--2024-01-01'))->toBeFalse();
});

// Remote operations

test('remotePath points at the remote backup dir', function () {
    [$snap] = snapshotsSetup();

    expect($snap->remotePath('remote-sync-x'))->toBe('/var/www/storage/backups/remote-sync-x.sql');
});

test('createRemote ensures the dir, then runs db/backup plain', function () {
    [$snap, $fake] = snapshotsSetup();

    $snap->createRemote('pre-push-x');

    expect($fake->calls[0])->toContain("mkdir -p '/var/www/storage/backups'")
        ->and(snapshotLine($fake, 'db/backup'))->toContain("craft db/backup '/var/www/storage/backups/pre-push-x.sql' --overwrite --interactive=0")
        ->and(snapshotLine($fake, 'db/backup'))->not->toContain('--zip');
});

test('createRemote can zip a kept backup', function () {
    [$snap, $fake] = snapshotsSetup();

    $snap->createRemote('pre-push-x', zip: true);

    expect(snapshotLine($fake, 'db/backup'))->toContain("db/backup '/var/www/storage/backups/pre-push-x.sql' --zip --overwrite --interactive=0");
});

test('loadRemote restores with drop-all-tables', function () {
    [$snap, $fake] = snapshotsSetup();

    $snap->loadRemote('remote-sync-x');

    expect(snapshotLine($fake, 'db/restore'))->toContain("craft db/restore '/var/www/storage/backups/remote-sync-x.sql' --drop-all-tables --interactive=0");
});

test('deleteRemote removes both extensions', function () {
    [$snap, $fake] = snapshotsSetup();

    $snap->deleteRemote('pre-push-x');

    expect(snapshotLine($fake, 'rm -f'))->toContain("rm -f '/var/www/storage/backups/pre-push-x.sql' '/var/www/storage/backups/pre-push-x.sql.zip'");
});

test('listRemote parses stat output into named snapshots', function () {
    $fake = (new FakeProcessRunner())->stub('find', stdout: "1700000200 /var/www/storage/backups/remote-sync-b.sql\n1700000100 /var/www/storage/backups/pre-pull-a.sql.zip\n");
    [$snap] = snapshotsSetup($fake);

    $list = $snap->listRemote();

    expect($list)->toHaveCount(2)
        ->and($list[0]['name'])->toBe('remote-sync-b')
        ->and($list[0]['mtime'])->toBe(1700000200)
        ->and($list[1]['name'])->toBe('pre-pull-a')
        ->and($list[1]['mtime'])->toBe(1700000100);
});

// Sanitize

function mariaDumpWithHeader(string $prefix = '/*M!'): string
{
    return "{$prefix}999999\\- enable the sandbox mode */\n"
        ."-- MariaDB dump\n"
        ."CREATE TABLE foo (id INT);\n";
}

function mariaDumpWithoutHeader(): string
{
    return "-- MariaDB dump\n"
        ."CREATE TABLE foo (id INT);\n";
}

test('sanitize strips the /*M! sandbox header', function () {
    $path = tempnam(sys_get_temp_dir(), 'sanitize').'.sql';
    file_put_contents($path, mariaDumpWithHeader('/*M!'));

    Snapshots::sanitize($path);

    expect(file_get_contents($path))->toBe(mariaDumpWithoutHeader());

    @unlink($path);
});

test('sanitize strips the /*! sandbox header', function () {
    $path = tempnam(sys_get_temp_dir(), 'sanitize').'.sql';
    file_put_contents($path, mariaDumpWithHeader('/*!'));

    Snapshots::sanitize($path);

    expect(file_get_contents($path))->toBe(mariaDumpWithoutHeader());

    @unlink($path);
});

test('sanitize handles gzipped dumps', function () {
    $path = tempnam(sys_get_temp_dir(), 'sanitize').'.sql.gz';
    file_put_contents($path, gzencode(mariaDumpWithHeader()));

    Snapshots::sanitize($path);

    expect(gzdecode(file_get_contents($path)))->toBe(mariaDumpWithoutHeader());

    @unlink($path);
});

test('sanitize leaves headerless dumps intact', function () {
    $path = tempnam(sys_get_temp_dir(), 'sanitize').'.sql';
    $original = mariaDumpWithoutHeader();
    file_put_contents($path, $original);

    Snapshots::sanitize($path);

    expect(file_get_contents($path))->toBe($original);

    @unlink($path);
});

test('sanitize is a no-op when the file is missing', function () {
    Snapshots::sanitize('/nonexistent/path/foo.sql');
})->throwsNoExceptions();

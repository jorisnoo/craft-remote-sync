<?php

use Noo\CraftRemoteSync\models\RemoteConfig;
use Noo\CraftRemoteSync\services\RemoteSyncService;

function callPrivate(object $object, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);

    return $ref->invoke($object, ...$args);
}

function makeService(): RemoteSyncService
{
    return new class extends RemoteSyncService {
        // Bypass Yii Component constructor
        public function __construct() {}
    };
}

function makeRemote(string $host = 'forge@example.com', string $path = '/var/www'): RemoteConfig
{
    return new RemoteConfig(name: 'prod', host: $host, path: $path);
}

function seedRemotePhpBinary(RemoteSyncService $service, RemoteConfig $remote, string $binary): void
{
    $ref = new ReflectionProperty(RemoteSyncService::class, 'remotePhpBinaries');
    $ref->setAccessible(true);
    $ref->setValue($service, [
        $remote->host . '|' . $remote->workingPath() => $binary,
    ]);
}

// --- parseSshHost ---

test('parseSshHost parses host without port', function () {
    $result = callPrivate(makeService(), 'parseSshHost', ['forge@example.com']);

    expect($result)->toBe(['host' => 'forge@example.com', 'port' => null]);
});

test('parseSshHost parses host with port', function () {
    $result = callPrivate(makeService(), 'parseSshHost', ['forge@example.com:2222']);

    expect($result)->toBe(['host' => 'forge@example.com', 'port' => 2222]);
});

test('parseSshHost handles ip address with port', function () {
    $result = callPrivate(makeService(), 'parseSshHost', ['user@192.168.1.1:22']);

    expect($result)->toBe(['host' => 'user@192.168.1.1', 'port' => 22]);
});

test('parseSshHost handles ip address without port', function () {
    $result = callPrivate(makeService(), 'parseSshHost', ['user@192.168.1.1']);

    expect($result)->toBe(['host' => 'user@192.168.1.1', 'port' => null]);
});

// --- buildSshArgs ---

test('buildSshArgs without port', function () {
    $remote = makeRemote('forge@example.com');
    $result = callPrivate(makeService(), 'buildSshArgs', [$remote, 'ls -la']);

    expect($result)->toBe(['ssh', 'forge@example.com', 'ls -la']);
});

test('buildSshArgs with port', function () {
    $remote = makeRemote('forge@example.com:2222');
    $result = callPrivate(makeService(), 'buildSshArgs', [$remote, 'ls -la']);

    expect($result)->toBe(['ssh', '-p', '2222', 'forge@example.com', 'ls -la']);
});

// --- buildRsyncArgs ---

test('buildRsyncArgs basic', function () {
    $remote = makeRemote('forge@example.com');
    $result = callPrivate(makeService(), 'buildRsyncArgs', [$remote, '/src/', '/dest/']);

    expect($result)->toBe(['rsync', '-avz', '--progress', '--exclude=.*', '/src/', '/dest/']);
});

test('buildRsyncArgs with port', function () {
    $remote = makeRemote('forge@example.com:2222');
    $result = callPrivate(makeService(), 'buildRsyncArgs', [$remote, '/src/', '/dest/']);

    expect($result)->toBe(['rsync', '-avz', '--progress', '--exclude=.*', '-e', 'ssh -p 2222', '/src/', '/dest/']);
});

test('buildRsyncArgs with dry run', function () {
    $remote = makeRemote('forge@example.com');
    $result = callPrivate(makeService(), 'buildRsyncArgs', [$remote, '/src/', '/dest/', true]);

    expect($result)->toBe(['rsync', '-avz', '--progress', '--exclude=.*', '--dry-run', '/src/', '/dest/']);
});

test('buildRsyncArgs with exclude paths', function () {
    $remote = makeRemote('forge@example.com');
    $result = callPrivate(makeService(), 'buildRsyncArgs', [$remote, '/src/', '/dest/', false, ['cache', '*.log']]);

    expect($result)->toBe(['rsync', '-avz', '--progress', '--exclude=.*', '--exclude=cache', '--exclude=*.log', '/src/', '/dest/']);
});

test('buildRsyncArgs with port, dry run, and excludes', function () {
    $remote = makeRemote('forge@example.com:2222');
    $result = callPrivate(makeService(), 'buildRsyncArgs', [$remote, '/src/', '/dest/', true, ['temp']]);

    expect($result)->toBe([
        'rsync', '-avz', '--progress', '--exclude=.*',
        '--exclude=temp',
        '-e', 'ssh -p 2222',
        '--dry-run',
        '/src/', '/dest/',
    ]);
});

// --- remoteCraftCommand ---

test('remoteCraftCommand uses detected php binary', function () {
    $service = makeService();
    $remote = makeRemote();
    seedRemotePhpBinary($service, $remote, 'php8.5');

    $result = callPrivate($service, 'remoteCraftCommand', [$remote, 'db/backup --zip 2>&1']);

    expect($result)->toBe("'php8.5' craft db/backup --zip 2>&1");
});

test('remoteCraftCommand escapes detected php binary path', function () {
    $service = makeService();
    $remote = makeRemote();
    seedRemotePhpBinary($service, $remote, '/usr/local/bin/php8.5');

    $result = callPrivate($service, 'remoteCraftCommand', [$remote, 'db/restore backup.sql.gz']);

    expect($result)->toBe("'/usr/local/bin/php8.5' craft db/restore backup.sql.gz");
});

// --- parseBackupFilename ---

test('parseBackupFilename extracts filename from craft output', function () {
    $output = 'Backed up the database to: /home/forge/site/storage/backups/cp-backup--2024-01-15.sql.gz';
    $result = callPrivate(makeService(), 'parseBackupFilename', [$output]);

    expect($result)->toBe('cp-backup--2024-01-15.sql.gz');
});

test('parseBackupFilename handles multiline output', function () {
    $output = "Some initial output\nBacked up to: /path/storage/backups/my-backup.sql.gz\nDone.";
    $result = callPrivate(makeService(), 'parseBackupFilename', [$output]);

    expect($result)->toBe('my-backup.sql.gz');
});

test('parseBackupFilename throws on missing filename', function () {
    callPrivate(makeService(), 'parseBackupFilename', ['no backup path here']);
})->throws(RuntimeException::class, 'Could not parse backup filename');

// --- sanitizeBackup ---

function dumpWithSandboxHeader(string $prefix = '/*M!'): string
{
    return "{$prefix}999999\\- enable the sandbox mode */\n"
        . "-- MariaDB dump\n"
        . "CREATE TABLE foo (id INT);\n";
}

function dumpWithoutSandboxHeader(): string
{
    return "-- MariaDB dump\n"
        . "CREATE TABLE foo (id INT);\n";
}

test('sanitizeBackup strips MariaDB sandbox header (/*M! variant)', function () {
    $path = tempnam(sys_get_temp_dir(), 'sanitize') . '.sql';
    file_put_contents($path, dumpWithSandboxHeader('/*M!'));

    makeService()->sanitizeBackup($path);

    expect(file_get_contents($path))->toBe(dumpWithoutSandboxHeader());

    @unlink($path);
});

test('sanitizeBackup strips MariaDB sandbox header (/*! variant)', function () {
    $path = tempnam(sys_get_temp_dir(), 'sanitize') . '.sql';
    file_put_contents($path, dumpWithSandboxHeader('/*!'));

    makeService()->sanitizeBackup($path);

    expect(file_get_contents($path))->toBe(dumpWithoutSandboxHeader());

    @unlink($path);
});

test('sanitizeBackup strips sandbox header from gzipped SQL file', function () {
    $path = tempnam(sys_get_temp_dir(), 'sanitize') . '.sql.gz';
    file_put_contents($path, gzencode(dumpWithSandboxHeader()));

    makeService()->sanitizeBackup($path);

    $decoded = gzdecode(file_get_contents($path));
    expect($decoded)->toBe(dumpWithoutSandboxHeader());

    @unlink($path);
});

test('sanitizeBackup leaves dumps without the header intact', function () {
    $path = tempnam(sys_get_temp_dir(), 'sanitize') . '.sql';
    $original = dumpWithoutSandboxHeader();
    file_put_contents($path, $original);

    makeService()->sanitizeBackup($path);

    expect(file_get_contents($path))->toBe($original);

    @unlink($path);
});

test('sanitizeBackup is a no-op when file does not exist', function () {
    makeService()->sanitizeBackup('/nonexistent/path/foo.sql');
})->throwsNoExceptions();

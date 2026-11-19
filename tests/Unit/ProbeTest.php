<?php

use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\Probe;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Tests\Support\FakeProcessRunner;
use Symfony\Component\Process\Process;
use yii\helpers\FileHelper;

function probeRemote(string $path = '/var/www', ?string $php = null): Remote
{
    return new Remote(name: 'prod', host: 'forge@server.test', path: $path, phpBinary: $php);
}

/**
 * Run a probe against a fake that returns the given marker output.
 */
function runProbe(string $stdout, Remote $remote, int $exitCode = 0)
{
    $fake = (new FakeProcessRunner())->fallback(stdout: $stdout, exitCode: $exitCode);
    $connection = new Connection($remote, $fake, ['timeouts' => ['remote' => 5]]);

    return (new Probe())->run($connection);
}

test('command checks the application, PATH tools, and backup location in one round-trip', function () {
    $cmd = (new Probe())->command(probeRemote());

    expect($cmd)->toContain('[ -L "$BASE/current" ]')
        ->and($cmd)->toContain("BASE='/var/www'")
        ->and($cmd)->toContain('platform_check.php')
        ->and($cmd)->toContain('echo "ATOMIC=$ATOMIC"')
        ->and($cmd)->toContain('echo "PHP=$PHP"')
        ->and($cmd)->toContain('echo RSYNC=1')
        ->and($cmd)->toContain('for tool in mysql mysqldump psql pg_dump', 'command -v "$tool"', 'echo BACKUPS=1')
        ->and($cmd)->toContain("'php8.5'")
        ->and($cmd)->toContain('REMOTE_SYNC_CD_FAILED');
});

test('command uses only the pinned php binary when configured', function () {
    $cmd = (new Probe())->command(probeRemote(php: '/usr/bin/php8.4'));

    expect($cmd)->toContain("'/usr/bin/php8.4'")
        ->and($cmd)->not->toContain("'php8.5'");
});

test('run reads the marker lines and derives an atomic working path', function () {
    $info = runProbe("ATOMIC=1\nPHP=php8.4\nRSYNC=1\nDB_TOOL=mysql\nDB_TOOL=mysqldump\nBACKUPS=1\n", probeRemote());

    expect($info->isAtomic)->toBeTrue()
        ->and($info->phpBinary)->toBe('php8.4')
        ->and($info->hasRsync)->toBeTrue()
        ->and($info->backupDirWritable)->toBeTrue()
        ->and($info->dbTools)->toBe(['mysql', 'mysqldump'])
        ->and($info->workingPath)->toBe('/var/www/current');
});

test('run treats an empty PHP marker as null', function () {
    $info = runProbe("ATOMIC=0\nPHP=\nRSYNC=0\n", probeRemote());

    expect($info->phpBinary)->toBeNull()
        ->and($info->hasRsync)->toBeFalse()
        ->and($info->backupDirWritable)->toBeFalse()
        ->and($info->dbTools)->toBe([])
        ->and($info->isAtomic)->toBeFalse()
        ->and($info->workingPath)->toBe('/var/www');
});

test('run keeps an explicit /current path without doubling it', function () {
    $info = runProbe("ATOMIC=1\nPHP=php\nRSYNC=1\n", probeRemote('/var/www/current'));

    expect($info->workingPath)->toBe('/var/www/current')
        ->and($info->isAtomic)->toBeTrue();
});

test('run throws when the working path is missing', function () {
    runProbe("REMOTE_SYNC_CD_FAILED\n", probeRemote(), exitCode: 1);
})->throws(RuntimeException::class, 'directory not found');

test('run throws when the host is unreachable', function () {
    runProbe('', probeRemote(), exitCode: 255);
})->throws(RuntimeException::class, 'Cannot reach');

test('rejected pinned PHP is identified in the preflight failure', function () {
    $remote = probeRemote(php: '/opt/php');
    $info = runProbe("PHP=\nBACKUPS=0\n", $remote);

    expect(fn () => $info->requirePhpBinary($remote))->toThrow(RuntimeException::class, "Configured php_binary '/opt/php'");
});

test('the backup probe recognizes creatable, existing, and blocked paths without changing them', function () {
    $root = sys_get_temp_dir().'/craft-probe-'.bin2hex(random_bytes(12));
    mkdir($root, 0700);
    try {
        $command = (new Probe())->command(probeRemote($root, '/absent/php'));
        $marker = static function () use ($command): bool {
            $output = (new Process(['sh', '-c', $command]))->mustRun()->getOutput();

            return str_contains($output, "BACKUPS=1\n");
        };

        expect($marker())->toBeTrue()->and(file_exists("{$root}/storage"))->toBeFalse();
        mkdir("{$root}/storage");
        expect($marker())->toBeTrue()->and(file_exists("{$root}/storage/backups"))->toBeFalse();
        mkdir("{$root}/storage/backups");
        expect($marker())->toBeTrue();

        rmdir("{$root}/storage/backups");
        touch("{$root}/storage/backups");
        expect($marker())->toBeFalse();
        unlink("{$root}/storage/backups");
        symlink("{$root}/missing", "{$root}/storage/backups");
        expect($marker())->toBeFalse();
    } finally {
        FileHelper::removeDirectory($root);
    }
});

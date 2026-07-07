<?php

use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Tests\Support\FakeProcessRunner;

function conn(string $host, ?FakeProcessRunner $fake = null): Connection
{
    return new Connection(
        new Remote(name: 'prod', host: $host, path: '/var/www'),
        $fake ?? new FakeProcessRunner(),
        ['timeouts' => ['remote' => 5, 'transfer' => 10]],
    );
}

test('run builds ssh args without a port', function () {
    $fake = new FakeProcessRunner();
    conn('forge@server.test', $fake)->run('ls -la');

    expect($fake->calls[0])->toBe('ssh forge@server.test ls -la');
});

test('run builds ssh args with a port', function () {
    $fake = new FakeProcessRunner();
    conn('forge@server.test:2222', $fake)->run('ls -la');

    expect($fake->calls[0])->toBe('ssh -p 2222 forge@server.test ls -la');
});

test('craft runs a command from the working path using the detected binary', function () {
    $fake = new FakeProcessRunner();
    $info = new RemoteInfo(phpBinary: 'php8.4', isAtomic: false, workingPath: '/var/www/current', hasRsync: true);

    conn('forge@server.test', $fake)->craft($info, 'db/backup --zip');

    expect($fake->calls[0])->toBe("ssh forge@server.test cd '/var/www/current' && 'php8.4' craft db/backup --zip");
});

test('craft falls back to php when no binary was detected', function () {
    $fake = new FakeProcessRunner();
    $info = new RemoteInfo(phpBinary: null, isAtomic: false, workingPath: '/var/www', hasRsync: true);

    conn('forge@server.test', $fake)->craft($info, 'up');

    expect($fake->calls[0])->toBe("ssh forge@server.test cd '/var/www' && 'php' craft up");
});

test('checkHostKey returns ok when the connection succeeds', function () {
    expect(conn('forge@server.test', new FakeProcessRunner())->checkHostKey())->toBe('ok');
});

test('checkHostKey returns unknown for an unverified host', function () {
    $fake = (new FakeProcessRunner())->stub('BatchMode=yes', stderr: 'Host key verification failed.', exitCode: 255);

    expect(conn('forge@server.test', $fake)->checkHostKey())->toBe('unknown');
});

test('checkHostKey returns changed for a mismatched key', function () {
    $fake = (new FakeProcessRunner())->stub('BatchMode=yes', stderr: 'WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!', exitCode: 255);

    expect(conn('forge@server.test', $fake)->checkHostKey())->toBe('changed');
});

test('sshTarget and sshPort parse the host', function () {
    $withPort = conn('forge@server.test:2222');
    expect($withPort->sshTarget())->toBe('forge@server.test')
        ->and($withPort->sshPort())->toBe(2222);

    $withoutPort = conn('forge@server.test');
    expect($withoutPort->sshTarget())->toBe('forge@server.test')
        ->and($withoutPort->sshPort())->toBeNull();
});

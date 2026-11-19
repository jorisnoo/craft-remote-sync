<?php

use Noo\CraftRemoteSync\Remotes\Connection;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Tests\Support\FakeProcessRunner;
use Symfony\Component\Process\Process;

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

test('craft refuses to run when the probe did not find compatible PHP', function () {
    $fake = new FakeProcessRunner();
    $info = new RemoteInfo(phpBinary: null, isAtomic: false, workingPath: '/var/www', hasRsync: true);

    $connection = conn('forge@server.test', $fake);
    expect(fn () => $connection->craft($info, 'up'))->toThrow(RuntimeException::class, 'No compatible PHP binary');
    expect($fake->calls)->toBe([]);
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

test('fingerprints are scanned at the resolved endpoint and only displayed keys may be accepted', function () {
    $fingerprint = 'SHA256:'.str_repeat('A', 43);
    $line = "256 {$fingerprint} resolved.test (ED25519)";
    $fake = (new FakeProcessRunner())
        ->stub('ssh -G', "user deploy\nhostname resolved.test\nport 2222\n")
        ->stub('ssh-keyscan', "banner\n{$line}\n")
        ->stub('ssh -V', stderr: 'OpenSSH_9.8p1');
    $connection = conn('deploy@alias:2222', $fake);

    expect($connection->acceptHostKey())->toBeFalse()
        ->and($fake->calls)->toBe([])
        ->and($connection->hostFingerprints())->toBe($line)
        ->and($fake->calls[0])->toBe('ssh -G -p 2222 deploy@alias')
        ->and($fake->calls[1])->toContain("ssh-keyscan -p 2222 -t ed25519,rsa,ecdsa 'resolved.test'")
        ->and($connection->acceptHostKey())->toBeTrue()
        ->and($fake->calls[3])->toContain("REMOTE_SYNC_FINGERPRINTS={$fingerprint}", 'StrictHostKeyChecking=ask', 'SSH_ASKPASS_REQUIRE=force')
        ->and($connection->acceptHostKey())->toBeFalse();
});

test('a failed ssh configuration lookup cannot authorize a scan from partial output', function () {
    $fake = (new FakeProcessRunner())->stub('ssh -G', "hostname server.test\nport 22", exitCode: 1);
    $connection = conn('alias', $fake);

    expect($connection->hostFingerprints())->toBeNull()
        ->and($connection->acceptHostKey())->toBeFalse()
        ->and($fake->calls)->toHaveCount(1);
});

test('fingerprint lookup refuses invalid endpoints and proxy routes without invoking keyscan', function (string $settings) {
    $fake = (new FakeProcessRunner())->stub('ssh -G', $settings);
    $connection = conn('alias', $fake);

    expect($connection->hostFingerprints())->toBeNull()
        ->and($connection->acceptHostKey())->toBeFalse()
        ->and($fake->calls)->toBe(['ssh -G alias']);
})->with([
    'missing port' => "hostname effective.test\n",
    'missing hostname' => "port 22\n",
    'invalid host' => "hostname -invalid\nport 22",
    'invalid port' => "hostname effective.test\nport 65536",
    'proxy jump' => "hostname effective.test\nport 22\nproxyjump bastion.test",
    'proxy command' => "hostname effective.test\nport 22\nproxycommand custom-transport",
]);

test('a failed scan or non-fingerprint output cannot authorize acceptance', function (string $output, int $exitCode) {
    $fake = (new FakeProcessRunner())
        ->stub('ssh -G', "hostname server.test\nport 2200")
        ->stub('ssh-keyscan', stdout: $output, exitCode: $exitCode);
    $connection = conn('alias', $fake);

    expect($connection->hostFingerprints())->toBeNull()
        ->and($connection->acceptHostKey())->toBeFalse()
        ->and($fake->calls)->toHaveCount(2)
        ->and($fake->calls[1])->toContain('-p 2200');
})->with([['', 0], ['unrecognized output', 0], ['256 SHA256:'.str_repeat('A', 43).' server.test (ED25519)', 1]]);

test('enrollment fails closed on clients without forced askpass support', function () {
    $fake = (new FakeProcessRunner())
        ->stub('ssh -G', "hostname server.test\nport 22")
        ->stub('ssh-keyscan', '256 SHA256:'.str_repeat('A', 43).' server.test (ED25519)')
        ->stub('ssh -V', stderr: 'OpenSSH_8.3p1');
    $connection = conn('alias', $fake);

    expect($connection->hostFingerprints())->not->toBeNull()
        ->and($connection->acceptHostKey())->toBeFalse()
        ->and($fake->calls)->toHaveCount(3);
});

test('the askpass helper only returns an approved fingerprint, never a blanket confirmation', function () {
    $approved = 'SHA256:'.str_repeat('A', 43);
    $other = 'SHA256:'.str_repeat('B', 43);
    $helper = dirname(__DIR__, 2).'/src/Remotes/confirm-fingerprint';
    foreach ([
        ["ED25519 key fingerprint is {$approved}.\nAre you sure you want to continue connecting (yes/no/[fingerprint])? ", $approved],
        ["ED25519 key fingerprint is {$other}.\nAre you sure you want to continue connecting (yes/no/[fingerprint])? ", ''],
        ['Password: ', ''],
        ["Please type 'yes', 'no' or the fingerprint: ", ''],
    ] as [$prompt, $expected]) {
        $process = new Process([$helper, $prompt], env: ['REMOTE_SYNC_FINGERPRINTS' => $approved]);
        $process->run();
        expect(trim($process->getOutput()))->toBe($expected)
            ->and($process->isSuccessful())->toBe($expected !== '');
    }
});

<?php

use Noo\CraftRemoteSync\Remotes\RemoteRegistry;

function registry(array $config): RemoteRegistry
{
    return new RemoteRegistry($config);
}

test('names lists the configured remotes', function () {
    $registry = registry(['remotes' => ['production' => [], 'staging' => []]]);

    expect($registry->names())->toBe(['production', 'staging'])
        ->and($registry->has('staging'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse();
});

test('names is empty when no remotes are configured', function () {
    expect(registry([])->names())->toBe([]);
});

test('defaultName returns the configured default', function () {
    expect(registry(['default' => 'production'])->defaultName())->toBe('production');
});

test('defaultName is null when blank or unset', function () {
    expect(registry(['default' => ''])->defaultName())->toBeNull()
        ->and(registry([])->defaultName())->toBeNull();
});

test('get builds a Remote from config', function () {
    $registry = registry(['remotes' => [
        'production' => [
            'host' => 'forge@server.test',
            'path' => '/home/forge/site/',
            'push' => 1,
            'php_binary' => ' /usr/bin/php8.4 ',
        ],
    ]]);

    $remote = $registry->get('production');

    expect($remote->name)->toBe('production')
        ->and($remote->host)->toBe('forge@server.test')
        ->and($remote->path)->toBe('/home/forge/site')
        ->and($remote->push)->toBeTrue()
        ->and($remote->phpBinary)->toBe('/usr/bin/php8.4');
});

test('get defaults push to false and phpBinary to null', function () {
    $registry = registry(['remotes' => [
        'staging' => ['host' => 'user@host', 'path' => '/var/www'],
    ]]);

    $remote = $registry->get('staging');

    expect($remote->push)->toBeFalse()
        ->and($remote->phpBinary)->toBeNull();
});

test('get blank php_binary becomes null', function () {
    $registry = registry(['remotes' => [
        'staging' => ['host' => 'user@host', 'path' => '/var/www', 'php_binary' => '   '],
    ]]);

    expect($registry->get('staging')->phpBinary)->toBeNull();
});

test('get throws for an unknown remote', function () {
    registry(['remotes' => ['production' => ['host' => 'user@host', 'path' => '/var/www']]])
        ->get('missing');
})->throws(InvalidArgumentException::class, "Remote 'missing' is not configured");

test('get throws when host is missing, naming the env var', function () {
    registry(['remotes' => ['production' => ['path' => '/var/www']]])->get('production');
})->throws(InvalidArgumentException::class, 'REMOTE_SYNC_PRODUCTION_HOST');

test('get throws when path is missing, naming the env var', function () {
    registry(['remotes' => ['production' => ['host' => 'user@host']]])->get('production');
})->throws(InvalidArgumentException::class, 'REMOTE_SYNC_PRODUCTION_PATH');

test('get rejects placeholder hosts', function () {
    registry(['remotes' => ['production' => ['host' => 'forge@your-server.com', 'path' => '/var/www']]])
        ->get('production');
})->throws(InvalidArgumentException::class, 'placeholder');

test('get uppercases and underscores the remote name in the env var hint', function () {
    registry(['remotes' => ['my-remote' => ['path' => '/var/www']]])->get('my-remote');
})->throws(InvalidArgumentException::class, 'REMOTE_SYNC_MY_REMOTE_HOST');

test('get rejects a host that would be parsed as an option', function (string $host) {
    registry(['remotes' => ['production' => ['host' => $host, 'path' => '/var/www']]])->get('production');
})->with(['-oProxyCommand=evil', 'forge@-oProxyCommand=evil'])
    ->throws(InvalidArgumentException::class, "may start with '-'");

test('expandName expands an unambiguous prefix', function () {
    $registry = registry(['remotes' => ['production' => [], 'preview' => [], 'staging' => []]]);

    expect($registry->expandName('prod'))->toBe('production')
        ->and($registry->expandName('st'))->toBe('staging')
        ->and($registry->expandName('p'))->toBe('p')
        ->and($registry->expandName('staging'))->toBe('staging')
        ->and($registry->expandName('missing'))->toBe('missing');
});

<?php

use Noo\CraftRemoteSync\models\RemoteConfig;

test('constructor sets all properties', function () {
    $config = new RemoteConfig(
        name: 'production',
        host: 'forge@example.com',
        path: '/home/forge/example.com',
        pushAllowed: true,
        isAtomic: false,
    );

    expect($config->name)->toBe('production')
        ->and($config->host)->toBe('forge@example.com')
        ->and($config->path)->toBe('/home/forge/example.com')
        ->and($config->pushAllowed)->toBeTrue()
        ->and($config->isAtomic)->toBeFalse();
});

test('pushAllowed defaults to false', function () {
    $config = new RemoteConfig(name: 'staging', host: 'user@host', path: '/var/www');

    expect($config->pushAllowed)->toBeFalse();
});

test('isAtomic defaults to false', function () {
    $config = new RemoteConfig(name: 'staging', host: 'user@host', path: '/var/www');

    expect($config->isAtomic)->toBeFalse();
});

test('workingPath returns path when not atomic', function () {
    $config = new RemoteConfig(name: 'prod', host: 'user@host', path: '/var/www/site', isAtomic: false);

    expect($config->workingPath())->toBe('/var/www/site');
});

test('workingPath appends /current when atomic', function () {
    $config = new RemoteConfig(name: 'prod', host: 'user@host', path: '/var/www/site', isAtomic: true);

    expect($config->workingPath())->toBe('/var/www/site/current');
});

test('storagePath appends /storage to working path', function () {
    $config = new RemoteConfig(name: 'prod', host: 'user@host', path: '/var/www/site');

    expect($config->storagePath())->toBe('/var/www/site/storage');
});

test('storagePath uses atomic working path when atomic', function () {
    $config = new RemoteConfig(name: 'prod', host: 'user@host', path: '/var/www/site', isAtomic: true);

    expect($config->storagePath())->toBe('/var/www/site/current/storage');
});

test('withAtomic returns new instance with isAtomic changed', function () {
    $original = new RemoteConfig(name: 'prod', host: 'user@host', path: '/var/www', pushAllowed: true, isAtomic: false);
    $atomic = $original->withAtomic(true);

    expect($atomic)->not->toBe($original)
        ->and($atomic->isAtomic)->toBeTrue()
        ->and($atomic->name)->toBe('prod')
        ->and($atomic->host)->toBe('user@host')
        ->and($atomic->path)->toBe('/var/www')
        ->and($atomic->pushAllowed)->toBeTrue();
});

test('withAtomic does not mutate original instance', function () {
    $original = new RemoteConfig(name: 'prod', host: 'user@host', path: '/var/www', isAtomic: false);
    $original->withAtomic(true);

    expect($original->isAtomic)->toBeFalse();
});

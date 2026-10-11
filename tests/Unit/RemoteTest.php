<?php

use Noo\CraftRemoteSync\Remotes\Remote;

test('constructor sets all properties', function () {
    $remote = new Remote(
        name: 'production',
        host: 'forge@server.test',
        path: '/home/forge/site',
        push: true,
        phpBinary: '/usr/bin/php8.4',
    );

    expect($remote->name)->toBe('production')
        ->and($remote->host)->toBe('forge@server.test')
        ->and($remote->path)->toBe('/home/forge/site')
        ->and($remote->push)->toBeTrue()
        ->and($remote->phpBinary)->toBe('/usr/bin/php8.4');
});

test('push defaults to false and phpBinary to null', function () {
    $remote = new Remote(name: 'staging', host: 'user@host', path: '/var/www');

    expect($remote->push)->toBeFalse()
        ->and($remote->phpBinary)->toBeNull();
});

test('hostname strips the user prefix', function () {
    $remote = new Remote(name: 'prod', host: 'forge@server.test', path: '/var/www');

    expect($remote->hostname())->toBe('server.test');
});

test('hostname strips a port suffix', function () {
    $remote = new Remote(name: 'prod', host: 'forge@server.test:2222', path: '/var/www');

    expect($remote->hostname())->toBe('server.test');
});

test('hostname returns a bare host unchanged', function () {
    $remote = new Remote(name: 'prod', host: 'server.test', path: '/var/www');

    expect($remote->hostname())->toBe('server.test');
});

test('hostname strips a port from a bare host', function () {
    $remote = new Remote(name: 'prod', host: '192.168.1.10:22', path: '/var/www');

    expect($remote->hostname())->toBe('192.168.1.10');
});

test('sshTarget and sshPort split off a port suffix', function () {
    $remote = new Remote(name: 'prod', host: 'forge@server.test:2222', path: '/var/www');

    expect($remote->sshTarget())->toBe('forge@server.test')
        ->and($remote->sshPort())->toBe(2222)
        ->and((new Remote(name: 'prod', host: 'server.test', path: '/var/www'))->sshPort())->toBeNull();
});

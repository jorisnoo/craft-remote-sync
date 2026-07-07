<?php

use Noo\CraftRemoteSync\Remotes\RemoteInfo;

test('storagePath appends storage to the working path', function () {
    $info = new RemoteInfo(phpBinary: 'php', isAtomic: false, workingPath: '/var/www/site', hasRsync: true);

    expect($info->storagePath())->toBe('/var/www/site/storage');
});

test('backupDir appends storage/backups to the working path', function () {
    $info = new RemoteInfo(phpBinary: 'php', isAtomic: false, workingPath: '/var/www/site', hasRsync: true);

    expect($info->backupDir())->toBe('/var/www/site/storage/backups');
});

test('paths follow an atomic working path', function () {
    $info = new RemoteInfo(phpBinary: 'php', isAtomic: true, workingPath: '/var/www/site/current', hasRsync: true);

    expect($info->storagePath())->toBe('/var/www/site/current/storage')
        ->and($info->backupDir())->toBe('/var/www/site/current/storage/backups');
});

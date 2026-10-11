<?php

use Noo\CraftRemoteSync\Support\StoragePath;

test('accepts simple and nested paths', function () {
    expect(StoragePath::validate('app'))->toBeNull()
        ->and(StoragePath::validate('app/uploads'))->toBeNull()
        ->and(StoragePath::validate('re-brand_2'))->toBeNull();
});

test('rejects parent-directory traversal', function () {
    expect(StoragePath::validate('../etc'))->toContain('within the storage directory')
        ->and(StoragePath::validate('app/../..'))->toContain('within the storage directory');
});

test('rejects shell metacharacters and double slashes', function () {
    expect(StoragePath::validate('app; rm -rf /'))->not->toBeNull()
        ->and(StoragePath::validate('app//uploads'))->not->toBeNull()
        ->and(StoragePath::validate('/app'))->not->toBeNull();
});

test('normalize trims surrounding slashes', function () {
    expect(StoragePath::normalize('/app/uploads/'))->toBe('app/uploads')
        ->and(StoragePath::normalize('app'))->toBe('app');
});

test('rejects the database backups directory and anything below it', function () {
    expect(StoragePath::validate('backups'))->toContain('database backups')
        ->and(StoragePath::validate('backups/old'))->toContain('database backups')
        ->and(StoragePath::validate('Backups'))->toContain('database backups')
        ->and(StoragePath::validate('app/backups'))->toBeNull()
        ->and(StoragePath::validate('backups-old'))->toBeNull();
});

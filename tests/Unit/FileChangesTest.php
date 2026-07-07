<?php

use Noo\CraftRemoteSync\Sync\FileChanges;

test('counts transfers and deletions', function () {
    $changes = new FileChanges(['a', 'b'], ['c']);

    expect($changes->transferCount())->toBe(2)
        ->and($changes->deletionCount())->toBe(1);
});

test('defaults to empty', function () {
    $changes = new FileChanges();

    expect($changes->transferCount())->toBe(0)
        ->and($changes->deletionCount())->toBe(0);
});

test('merge concatenates both sides', function () {
    $merged = (new FileChanges(['a'], ['x']))->merge(new FileChanges(['b'], ['y']));

    expect($merged->transfers)->toBe(['a', 'b'])
        ->and($merged->deletions)->toBe(['x', 'y']);
});

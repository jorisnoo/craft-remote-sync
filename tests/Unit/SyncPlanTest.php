<?php

use Noo\CraftRemoteSync\Sync\Direction;
use Noo\CraftRemoteSync\Sync\FileChanges;
use Noo\CraftRemoteSync\Sync\SyncPlan;

test('scopeSummary reflects the selected scope', function () {
    expect((new SyncPlan(Direction::Pull, database: true, files: true))->scopeSummary())->toBe('database and files')
        ->and((new SyncPlan(Direction::Pull, database: true, files: false))->scopeSummary())->toBe('database')
        ->and((new SyncPlan(Direction::Pull, database: false, files: true))->scopeSummary())->toBe('files');
});

test('totals sum across all file changes', function () {
    $plan = new SyncPlan(Direction::Pull, database: false, files: true, fileChanges: [
        'app' => new FileChanges(['a', 'b'], ['x']),
        'uploads' => new FileChanges(['c'], ['y', 'z']),
    ]);

    expect($plan->totalTransfers())->toBe(3)
        ->and($plan->totalDeletions())->toBe(3);
});

test('totals are zero without file changes', function () {
    $plan = new SyncPlan(Direction::Pull, database: true, files: false);

    expect($plan->totalTransfers())->toBe(0)
        ->and($plan->totalDeletions())->toBe(0);
});

<?php

use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Sync\Direction;
use Noo\CraftRemoteSync\Sync\FileChanges;
use Noo\CraftRemoteSync\Sync\PlanRenderer;
use Noo\CraftRemoteSync\Sync\SyncPlan;
use yii\console\Controller;

function renderSyncPreview(SyncPlan $plan): string
{
    $output = '';
    $controller = Mockery::mock(Controller::class);
    $controller->shouldReceive('stdout')->andReturnUsing(function (string $text) use (&$output): int {
        $output .= $text;

        return strlen($text);
    });
    (new PlanRenderer($controller))->render(new Remote('prod', 'server.test', '/site'), new RemoteInfo('php', false, '/site', true), $plan);

    return $output;
}

test('preview shows totals and capped filenames across paths, and identifies skipped sources', function () {
    $plan = new SyncPlan(Direction::Pull, database: true, files: true, paths: ['app', 'uploads'], fileChanges: [
        'app' => new FileChanges(['photo.jpg'], ['old.jpg']),
        'uploads' => new FileChanges(range('a', 't')),
    ], snapshotName: 'remote-sync-test.sql', backupName: 'pre-pull-test.sql.zip', skippedPaths: ['absent']);

    $output = renderSyncPreview($plan);
    expect($output)->toContain('Pull from prod', 'pre-pull-test.sql.zip', 'remote-sync-test.sql', '21 files',
        'storage/app/photo.jpg, storage/uploads/a', 'storage/uploads/s (+1 more)', 'pass --delete',
        'Skipping storage/absent: source directory does not exist on [prod].')
        ->not->toContain('storage/uploads/t', 'storage/app/old.jpg');
});

test('push preview lists deletions only with delete enabled and names the affected remote', function () {
    $plan = new SyncPlan(Direction::Push, database: false, files: true, delete: true, paths: ['uploads'], fileChanges: [
        'uploads' => new FileChanges([], ['one.jpg', 'two.jpg']),
    ]);

    expect(renderSyncPreview($plan))->toContain('Push to prod', 'Delete on [prod]: 2 files', 'storage/uploads/one.jpg, storage/uploads/two.jpg')
        ->not->toContain('pass --delete', 'Database');
});

test('an entirely skipped preview does not announce a replacement', function () {
    $plan = new SyncPlan(Direction::Push, database: false, files: false, skippedPaths: ['app']);

    expect(renderSyncPreview($plan))->toContain('Skipping storage/app: source directory does not exist locally.')
        ->not->toContain('will be replaced');
});

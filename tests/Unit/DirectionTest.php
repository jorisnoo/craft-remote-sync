<?php

use Noo\CraftRemoteSync\Sync\Direction;

test('Direction has pull and push cases', function () {
    expect(Direction::Pull->value)->toBe('pull')
        ->and(Direction::Push->value)->toBe('push');
});

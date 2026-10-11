<?php

use Noo\CraftRemoteSync\Support\CleanupStack;

test('runs cleanups in reverse (LIFO) order', function () {
    $order = [];
    $stack = new CleanupStack();
    $stack->push('first', function () use (&$order) { $order[] = 'first'; });
    $stack->push('second', function () use (&$order) { $order[] = 'second'; });

    $result = $stack->run();

    expect($order)->toBe(['second', 'first'])
        ->and($result['ran'])->toBe(['second', 'first'])
        ->and($result['failed'])->toBe([]);
});

test('running is idempotent — a second run does nothing', function () {
    $count = 0;
    $stack = new CleanupStack();
    $stack->push('a', function () use (&$count) { $count++; });

    $stack->run();
    $second = $stack->run();

    expect($count)->toBe(1)
        ->and($second['ran'])->toBe([]);
});

test('forget removes a pending cleanup', function () {
    $ran = [];
    $stack = new CleanupStack();
    $stack->push('keep', function () use (&$ran) { $ran[] = 'keep'; });
    $stack->push('drop', function () use (&$ran) { $ran[] = 'drop'; });
    $stack->forget('drop');

    $stack->run();

    expect($ran)->toBe(['keep']);
});

test('captures failures without aborting the rest', function () {
    $ran = [];
    $stack = new CleanupStack();
    $stack->push('ok', function () use (&$ran) { $ran[] = 'ok'; });
    $stack->push('boom', function () { throw new RuntimeException('nope'); });

    $result = $stack->run();

    expect($result['ran'])->toBe(['ok'])
        ->and($result['failed'])->toBe(['boom' => 'nope']);
});

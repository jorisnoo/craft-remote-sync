<?php

use Symfony\Component\Process\Process;

test('termination cleans the owning process exactly once and returns a signal status', function (string $signal) {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl and posix are required to exercise process signals');
    }
    $process = new Process([PHP_BINARY, dirname(__DIR__).'/fixtures/interrupt.php', $signal]);
    $process->setTimeout(10);
    $process->run();
    expect($process->getExitCode())->toBe(128 + constant($signal))
        ->and(substr_count($process->getOutput(), 'cleanup performed'))->toBe(1);
})->with(['SIGINT', 'SIGTERM', 'SIGHUP']);

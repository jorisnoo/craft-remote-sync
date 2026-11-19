<?php

use Noo\CraftRemoteSync\console\controllers\DoctorController;
use Noo\CraftRemoteSync\Process\ProcessRunner;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use Noo\CraftRemoteSync\Tests\Support\FakeProcessRunner;

function doctorChecks(?FakeProcessRunner $runner = null): DoctorController
{
    return new class($runner ?? new FakeProcessRunner()) extends DoctorController {
        public array $passed = [];
        public array $failed = [];

        public function __construct(private ProcessRunner $runner) {}

        protected function processRunner(): ProcessRunner
        {
            return $this->runner;
        }

        public function inspectLocal(string $binary): void
        {
            $this->checkLocalBinary($binary);
        }

        public function inspectRemote(RemoteInfo $info, string $driver): void
        {
            $this->reportRemoteChecks(new Remote('prod', 'alias', '/app', phpBinary: '/opt/php'), $info, $driver);
        }

        protected function passCheck(string $check, string $detail): void
        {
            $this->passed[$check] = $detail;
        }

        protected function failCheck(string $check, string $detail): void
        {
            $this->failed[$check] = $detail;
        }
    };
}

test('doctor reports backup permissions, rejected pinned PHP and the relevant missing DB tool', function () {
    $doctor = doctorChecks();
    $doctor->inspectRemote(new RemoteInfo(null, false, '/app', true, false, ['mysql', 'psql', 'pg_dump']), 'mysql');

    expect($doctor->failed['PHP binary'])->toContain("Configured php_binary '/opt/php'")
        ->and($doctor->failed['Backup directory'])->toContain('/app/storage/backups')
        ->and($doctor->failed['mysqldump'])->toContain('not found')
        ->and($doctor->passed)->toHaveKey('mysql')
        ->and($doctor->passed)->not->toHaveKey('psql');
});

test('doctor recognizes complete PostgreSQL tools without requiring mysql', function () {
    $doctor = doctorChecks();
    $doctor->inspectRemote(new RemoteInfo('/opt/php', false, '/app', true, true, ['psql', 'pg_dump']), 'pgsql');

    expect($doctor->failed)->toBe([])
        ->and($doctor->passed)->toHaveKeys(['psql', 'pg_dump', 'Backup directory']);
});

test('doctor shows the local rsync version without imposing a version threshold', function (string $line) {
    $doctor = doctorChecks((new FakeProcessRunner())->stub('rsync --version', "{$line}\nother details"));
    $doctor->inspectLocal('rsync');

    expect($doctor->passed['rsync'])->toBe($line)->and($doctor->failed)->toBe([]);
})->with(['openrsync: protocol version 29', 'rsync  version 3.4.1  protocol version 32']);

test('an unavailable rsync version is diagnostic only when the executable is present', function () {
    $doctor = doctorChecks((new FakeProcessRunner())->stub('rsync --version', exitCode: 1));
    $doctor->inspectLocal('rsync');

    expect($doctor->passed['rsync'])->toContain('version unavailable')->and($doctor->failed)->toBe([]);
});

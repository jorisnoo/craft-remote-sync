<?php

use craft\services\Config;
use craft\services\Path;
use Laravel\Prompts\Output\BufferedConsoleOutput;
use Laravel\Prompts\Prompt;
use Noo\CraftRemoteSync\console\controllers\PruneController;
use Noo\CraftRemoteSync\console\controllers\PullController;
use Noo\CraftRemoteSync\console\controllers\PushController;
use Noo\CraftRemoteSync\Module;
use Noo\CraftRemoteSync\Process\ProcessResult;
use Noo\CraftRemoteSync\Process\ProcessRunner;
use Noo\CraftRemoteSync\Tests\Support\FakeProcessRunner;
use yii\console\Application;
use yii\console\ExitCode;
use yii\helpers\FileHelper;
use yii\i18n\PhpMessageSource;

trait RecordsSyncConsole
{
    public string $output = '';

    // Yii option binding is exercised; do not invalidate the host's sudo session.
    protected function checkRootUser(): bool { return true; }

    public function setProcessRunner(ProcessRunner $runner): void
    {
        $this->runner = $runner;
    }

    public function stdout($string): int
    {
        $this->output .= $string;

        return strlen($string);
    }
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/craft-sync-'.bin2hex(random_bytes(8));
    mkdir($this->root, 0700);
    $this->app = new class ([
        'id' => 'sync-tests', 'basePath' => $this->root, 'language' => 'en',
        'aliases' => ['@root' => $this->root, '@storage' => $this->root.'/storage'],
        'components' => [
            'config' => ['class' => Config::class, 'configDir' => $this->root],
            'path' => Path::class,
            'i18n' => ['translations' => ['remote-sync' => [
                'class' => PhpMessageSource::class, 'forceTranslation' => true,
                'basePath' => dirname(__DIR__, 2).'/src/translations',
            ]]],
        ],
    ]) extends Application {
        public string $env = 'dev';
        public function getConfig(): Config { return $this->get('config'); }
        public function getPath(): Path { return $this->get('path'); }
    };
    $this->module = new Module('remote-sync', $this->app);
    $this->prompts = new BufferedConsoleOutput();
    Prompt::setOutput($this->prompts);
    $this->configure = function (array $config): void {
        file_put_contents($this->root.'/remote-sync.php', '<?php return '.var_export($config, true).';');
    };
    $this->pull = new class ('pull', $this->module, ['interactive' => false]) extends PullController { use RecordsSyncConsole; };
    $this->push = new class ('push', $this->module, ['interactive' => false]) extends PushController { use RecordsSyncConsole; };
    // No external programs are run. Materialize only a harmless downloaded file in the temporary project.
    $this->runner = new class extends FakeProcessRunner {
        public ?string $throwOn = null;

        public function run(array|string $command, ?int $timeout = null, ?callable $onOutput = null, bool $tty = false): ProcessResult
        {
            $result = parent::run($command, $timeout, $onOutput, $tty);
            $line = end($this->calls);
            if ($this->throwOn !== null && str_contains($line, $this->throwOn)) {
                throw new RuntimeException('step timed out');
            }
            if (is_array($command) && $command[0] === 'rsync') {
                $source = $command[count($command) - 2];
                if (str_contains($source, ':') && str_ends_with($source, '.sql')) {
                    file_put_contents(end($command).basename($source), "-- fixture\n");
                }
            }

            return $result;
        }
    };
    foreach ([$this->pull, $this->push] as $command) {
        $command->setProcessRunner($this->runner);
        $command->force = true;
    }
    $this->remote = ['host' => 'server.test', 'path' => '/srv/craft', 'push' => true];
});

afterEach(function () {
    FileHelper::removeDirectory($this->root);
    Craft::$app = null;
});

test('module config replaces empty lists while preserving nested defaults', function () {
    ($this->configure)(['paths' => [], 'exclude_paths' => [], 'timeouts' => ['transfer' => 90]]);
    $config = $this->module->getConfig();
    expect($config['paths'])->toBe([])->and($config['exclude_paths'])->toBe([])
        ->and($config['timeouts'])->toBe(['remote' => 300, 'transfer' => 90]);
});

test('production and per-remote push gates stop even a forced command before any process', function () {
    ($this->configure)(['remotes' => ['production' => array_merge($this->remote, ['push' => false])]]);
    $this->app->env = 'production';
    expect($this->pull->actionIndex('prod'))->toBe(ExitCode::UNSPECIFIED_ERROR);
    $this->app->env = 'dev';
    expect($this->push->actionIndex('prod'))->toBe(ExitCode::UNSPECIFIED_ERROR)
        ->and($this->prompts->content())->toContain('Refusing to run in production', 'Push is not allowed for remote [production]')
        ->and($this->runner->calls)->toBe([]);
});

test('files-only pull rejects a backup path override before preview or transfer', function () {
    ($this->configure)(['remotes' => ['staging' => $this->remote]]);
    $this->runner->stub('ATOMIC=', "PHP=php\nRSYNC=1\n");
    $this->pull->files = true;
    $this->pull->path = 'app,backups';
    expect($this->pull->actionIndex('stag'))->toBe(ExitCode::UNSPECIFIED_ERROR)
        ->and($this->runner->calls)->toHaveCount(2)
        ->and($this->prompts->content())->toContain('backups');
});

test('a rejected PHP pin aborts before running steps and suggests doctor', function () {
    ($this->configure)(['remotes' => ['staging' => $this->remote + ['php_binary' => 'php-pinned']]]);
    $this->runner->stub('ATOMIC=', "PHP=\nRSYNC=1\n");
    expect($this->pull->actionIndex('staging'))->toBe(ExitCode::UNSPECIFIED_ERROR)
        ->and($this->runner->calls)->toHaveCount(2)
        ->and($this->prompts->content())->toContain('php-pinned', 'remote-sync/doctor staging');
});

test('unknown host without a displayable fingerprint never prompts for acceptance', function () {
    ($this->configure)(['remotes' => ['staging' => $this->remote]]);
    $this->runner->stub('BatchMode=yes', stderr: 'Host key verification failed', exitCode: 255);
    $pull = new class ('pull', $this->module) extends PullController {
        use RecordsSyncConsole;
        protected function isInteractive(): bool { return true; }
    };
    $pull->setProcessRunner($this->runner);
    $pull->database = true;
    expect($pull->actionIndex('staging'))->toBe(ExitCode::UNSPECIFIED_ERROR)
        ->and($this->prompts->content())->toContain('Could not display a fingerprint')
        ->and($this->prompts->content())->not->toContain('Do you want to trust');
});

test('pull restores retain the download on exception and print both recovery paths', function () {
    ($this->configure)(['remotes' => ['staging' => $this->remote]]);
    $this->runner->stub('ATOMIC=', "PHP=php\nRSYNC=1\n");
    $this->runner->throwOn = 'db/restore';
    $this->pull->database = $this->pull->discardSnapshot = true;
    expect($this->pull->actionIndex('staging'))->toBe(ExitCode::UNSPECIFIED_ERROR)
        ->and($this->prompts->content())->toContain('step timed out')
        ->and($this->pull->output)->toContain('Restore it with: craft db/restore', 'The downloaded snapshot was kept at:')
        ->and(glob($this->root.'/storage/backups/remote-sync-*.sql'))->toHaveCount(1)
        ->and(end($this->runner->calls))->toContain('rm -f', '/remote-sync-');
});

test('push restore exceptions report the remote safety backup and clean the transfer only', function () {
    ($this->configure)(['remotes' => ['staging' => $this->remote]]);
    $this->runner->stub('ATOMIC=', "PHP=php\nRSYNC=1\n");
    $this->runner->throwOn = 'db/restore';
    $this->push->database = true;
    expect($this->push->actionIndex('staging'))->toBe(ExitCode::UNSPECIFIED_ERROR)
        ->and($this->push->output)->toContain('Restore it on [staging]', '/pre-push-')
        ->and(end($this->runner->calls))->toContain('rm -f', '/remote-sync-')->not->toContain('pre-push-');
});

test('failed remote dump or upload already has its removal registered', function (string $direction, string $failure) {
    ($this->configure)(['remotes' => ['staging' => $this->remote]]);
    $this->runner->stub('ATOMIC=', "PHP=php\nRSYNC=1\n")->stub($failure, stderr: 'failed', exitCode: 1);
    $controller = $this->$direction;
    $controller->database = $controller->noBackup = true;
    expect($controller->actionIndex('staging'))->toBe(ExitCode::UNSPECIFIED_ERROR)
        ->and(end($this->runner->calls))->toContain('rm -f', '/remote-sync-');
})->with([['pull', 'db/backup'], ['push', 'rsync -az']]);

test('prune rejects a malformed Yii option before deleting anything', function () {
    $dir = $this->app->getPath()->getDbBackupPath();
    file_put_contents($dir.'/pre-pull-new.sql', '-- fixture');
    $prune = new class ('prune', $this->module, ['interactive' => false]) extends PruneController { use RecordsSyncConsole; };
    expect($prune->runAction('index', ['local' => true, 'force' => true, 'keep' => 'ten']))->toBe(ExitCode::USAGE)
        ->and(is_file($dir.'/pre-pull-new.sql'))->toBeTrue();
});

test('prune keeps independent budgets and never selects unrelated files by default', function () {
    $dir = $this->app->getPath()->getDbBackupPath();
    foreach (['remote-sync', 'pre-pull', 'pre-push', 'craft'] as $group) {
        foreach (['old' => 1000, 'new' => 2000] as $age => $time) {
            touch("{$dir}/{$group}-{$age}.sql", $time);
        }
    }
    $prune = new class ('prune', $this->module, ['interactive' => false]) extends PruneController { use RecordsSyncConsole; };
    expect($prune->runAction('index', ['local' => true, 'force' => true, 'keep' => '1']))->toBe(ExitCode::OK)
        ->and(array_map('basename', glob($dir.'/*.sql')))->toBe(['craft-new.sql', 'craft-old.sql', 'pre-pull-new.sql', 'pre-push-new.sql', 'remote-sync-new.sql']);
});

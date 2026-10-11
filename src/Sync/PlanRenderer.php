<?php

namespace Noo\CraftRemoteSync\Sync;

use craft\helpers\Console;
use Noo\CraftRemoteSync\Remotes\Remote;
use Noo\CraftRemoteSync\Remotes\RemoteInfo;
use yii\console\Controller;

/**
 * Renders a SyncPlan preview to the console before confirmation.
 */
class PlanRenderer
{
    protected const LIST_CAP = 20;

    public function __construct(protected Controller $controller) {}

    public function render(Remote $remote, RemoteInfo $info, SyncPlan $plan): void
    {
        $verb = $plan->direction === Direction::Pull ? 'Pull from' : 'Push to';

        $this->controller->stdout("\n{$verb} {$remote->name} ({$remote->host}:{$info->workingPath})\n", Console::FG_CYAN, Console::BOLD);

        if ($plan->database) {
            $this->renderDatabase($plan);
        }

        if ($plan->files) {
            $this->renderFiles($remote, $plan);
        }

        $target = $plan->direction === Direction::Pull ? 'Local' : "Remote [{$remote->name}]";
        $this->controller->stdout("\n{$target} {$plan->scopeSummary()} listed above will be replaced.\n", Console::FG_YELLOW);
    }

    protected function renderDatabase(SyncPlan $plan): void
    {
        $this->controller->stdout("\n  Database\n", Console::BOLD);

        if ($plan->direction === Direction::Pull) {
            $this->detail('Local backup', $plan->backup ? $plan->backupName : 'skipped (--no-backup)');
            $this->detail('Snapshot', $plan->keepSnapshot ? $plan->snapshotName : 'deleted after import');
            $this->detail('After import', 'craft up, clear-caches');
        } else {
            $this->detail('Remote backup', $plan->backup ? $plan->backupName : 'skipped (--no-backup)');
            $this->detail('After load', 'craft up on the remote');
        }

        $this->controller->stdout("  The entire database will be dropped and replaced.\n", Console::FG_YELLOW);
    }

    protected function renderFiles(Remote $remote, SyncPlan $plan): void
    {
        $paths = implode(', ', array_map(fn (string $path) => "storage/{$path}", $plan->paths));

        $this->controller->stdout("\n  Files ({$paths})\n", Console::BOLD);

        $changes = $plan->allFileChanges();

        $this->detail('Transfer', $plan->totalTransfers().' files');
        $this->listItems($changes->transfers);

        $deletions = $plan->totalDeletions();
        $deleteTarget = $plan->direction === Direction::Pull ? 'locally' : "on [{$remote->name}]";

        if ($deletions === 0) {
            $this->detail("Delete {$deleteTarget}", '0 files');
        } elseif ($plan->delete) {
            $this->controller->stdout("    Delete {$deleteTarget}: {$deletions} files\n", Console::FG_YELLOW);
            $this->listItems($changes->deletions);
        } else {
            $only = $plan->direction === Direction::Pull ? 'local-only' : 'remote-only';
            $this->detail("Delete {$deleteTarget}", "0 (pass --delete to remove {$deletions} {$only} files)");
        }
    }

    protected function detail(string $label, string $value): void
    {
        $this->controller->stdout('    '.str_pad($label, 16).'  ', Console::FG_GREY);
        $this->controller->stdout($value."\n");
    }

    /**
     * @param  list<string>  $items
     */
    protected function listItems(array $items): void
    {
        if ($items === []) {
            return;
        }

        $shown = array_slice($items, 0, self::LIST_CAP);
        $line = implode(', ', $shown);

        if (count($items) > self::LIST_CAP) {
            $line .= ' (+'.(count($items) - self::LIST_CAP).' more)';
        }

        $this->controller->stdout('      '.$line."\n", Console::FG_GREY);
    }
}

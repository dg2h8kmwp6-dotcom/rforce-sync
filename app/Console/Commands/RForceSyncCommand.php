<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunQueryDefinitionJob;
use App\Models\QueryDefinition;
use App\Services\RForce\RForceSyncManager;
use App\Services\Sync\SyncRunManager;
use Illuminate\Console\Command;

/**
 * Drive sync runs for all enabled QueryDefinitions.
 *
 *   rforce:sync                  → run every enabled query
 *   rforce:sync --inline         → run them in-process (skip the queue)
 *   rforce:sync --dry-run        → fetch + count, don't persist
 *
 * For a single query, use rforce:run-query.
 */
class RForceSyncCommand extends Command
{
    protected $signature = 'rforce:sync
        {--dry-run : Fetch + count records, do not write raw rows.}
        {--inline : Run synchronously instead of dispatching to the queue.}';

    protected $description = 'Run every enabled saved SOQL query against the rForce gateway.';

    public function handle(RForceSyncManager $sync, SyncRunManager $runs): int
    {
        $defs = QueryDefinition::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        if ($defs->isEmpty()) {
            $this->warn('No enabled QueryDefinitions to run.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $inline = (bool) $this->option('inline');

        foreach ($defs as $definition) {
            $job = new RunQueryDefinitionJob(
                queryDefinitionId: $definition->id,
                dryRun:            $dryRun,
                meta:              ['trigger' => 'cli:rforce:sync'],
            );

            if ($inline) {
                $this->line("Running '{$definition->name}' inline...");
                $job->handle($sync, $runs);
                $this->info("Finished '{$definition->name}'.");
            } else {
                dispatch($job);
                $this->info("Dispatched '{$definition->name}'.");
            }
        }

        return self::SUCCESS;
    }
}

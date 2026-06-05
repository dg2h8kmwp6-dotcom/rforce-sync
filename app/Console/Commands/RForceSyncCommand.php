<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunQueryDefinitionJob;
use App\Models\QueryDefinition;
use App\Services\RForce\RForceSyncManager;
use App\Services\Sync\SyncRunManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Drive sync runs from saved QueryDefinitions.
 *
 *   rforce:sync                         → run every enabled query
 *   rforce:sync <id|name>               → run a single query
 *   rforce:sync <id|name> --inline      → run in-process (skip the queue)
 *   rforce:sync <id|name> --dry-run     → fetch + count, don't persist
 *   rforce:sync <id|name> --since=...   → incremental filter
 *   rforce:sync <id|name> --limit=N     → cap records for this run
 */
class RForceSyncCommand extends Command
{
    protected $signature = 'rforce:sync
        {query? : QueryDefinition id or name. Omit to run all enabled queries.}
        {--since= : ISO timestamp; only fetch records modified since this point.}
        {--limit= : Cap records to fetch for this run.}
        {--dry-run : Fetch + count records, do not write raw rows.}
        {--inline : Run synchronously instead of dispatching to the queue.}';

    protected $description = 'Run one or all saved SOQL queries against the rForce gateway.';

    public function handle(RForceSyncManager $sync, SyncRunManager $runs): int
    {
        $arg = $this->argument('query');

        if ($arg === null) {
            return $this->runAll($sync, $runs);
        }

        $definition = $this->resolveDefinition((string) $arg);
        if ($definition === null) {
            $this->error("No QueryDefinition matches [{$arg}].");
            return self::FAILURE;
        }

        return $this->runOne($definition, $sync, $runs);
    }

    private function runAll(RForceSyncManager $sync, SyncRunManager $runs): int
    {
        $defs = QueryDefinition::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        if ($defs->isEmpty()) {
            $this->warn('No enabled QueryDefinitions to run.');
            return self::SUCCESS;
        }

        foreach ($defs as $definition) {
            $this->runOne($definition, $sync, $runs);
        }
        return self::SUCCESS;
    }

    private function runOne(QueryDefinition $definition, RForceSyncManager $sync, SyncRunManager $runs): int
    {
        $since  = $this->option('since') ? Carbon::parse((string) $this->option('since')) : null;
        $limit  = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');
        $meta   = ['trigger' => 'cli:rforce:sync'];

        if ($this->option('inline')) {
            $this->line("Running '{$definition->name}' inline...");
            $job = new RunQueryDefinitionJob(
                queryDefinitionId: $definition->id,
                since:             $since?->toIso8601String(),
                limit:             $limit,
                dryRun:            $dryRun,
                meta:              $meta,
            );
            $job->handle($sync, $runs);
            $this->info("Finished '{$definition->name}'.");
            return self::SUCCESS;
        }

        dispatch(new RunQueryDefinitionJob(
            queryDefinitionId: $definition->id,
            since:             $since?->toIso8601String(),
            limit:             $limit,
            dryRun:            $dryRun,
            meta:              $meta,
        ));

        $this->info("Dispatched '{$definition->name}'.");
        return self::SUCCESS;
    }

    private function resolveDefinition(string $arg): ?QueryDefinition
    {
        $q = QueryDefinition::query();
        return ctype_digit($arg) ? $q->find((int) $arg) : $q->where('name', $arg)->first();
    }
}

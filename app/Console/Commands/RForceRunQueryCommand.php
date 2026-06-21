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
 * Sync a single QueryDefinition by id or name.
 *
 *   php artisan rforce:run-query 7
 *   php artisan rforce:run-query accounts.smoke
 *   php artisan rforce:run-query accounts.smoke --dry-run
 *   php artisan rforce:run-query orders.incremental --since=2026-06-01T00:00:00Z
 *
 * Thin sibling of rforce:sync: takes a single definition reference, no
 * `--all`. Both go through RunQueryDefinitionJob.
 */
class RForceRunQueryCommand extends Command
{
    protected $signature = 'rforce:run-query
        {query : QueryDefinition id or name.}
        {--since= : ISO timestamp; only fetch records modified since this point.}
        {--limit= : Cap records to fetch for this run.}
        {--dry-run : Fetch + count records, do not write raw rows.}
        {--inline : Run synchronously instead of dispatching to the queue.}';

    protected $description = 'Sync a single saved QueryDefinition through the MuleSoft Data Gateway.';

    public function handle(RForceSyncManager $sync, SyncRunManager $runs): int
    {
        $arg = (string) $this->argument('query');

        $definition = ctype_digit($arg)
            ? QueryDefinition::query()->find((int) $arg)
            : QueryDefinition::query()->where('name', $arg)->first();

        if ($definition === null) {
            $this->error("No QueryDefinition matches [{$arg}].");
            return self::FAILURE;
        }

        $since  = $this->option('since') ? Carbon::parse((string) $this->option('since')) : null;
        $limit  = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        $job = new RunQueryDefinitionJob(
            queryDefinitionId: $definition->id,
            since:             $since?->toIso8601String(),
            limit:             $limit,
            dryRun:            $dryRun,
            meta:              ['trigger' => 'cli:rforce:run-query'],
        );

        if ($this->option('inline')) {
            $this->line("Running '{$definition->name}' inline...");
            $job->handle($sync, $runs);
            $this->info("Finished '{$definition->name}'.");
            return self::SUCCESS;
        }

        dispatch($job);
        $this->info("Dispatched '{$definition->name}'.");
        return self::SUCCESS;
    }
}

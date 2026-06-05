<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QueryDefinition;
use App\Services\RForce\RForceQueryExecutor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Read-side counterpart to rforce:sync.
 *
 * - `rforce:query`               → list saved queries
 * - `rforce:query <id-or-name>`  → execute against the gateway and print results
 *
 * Defaults to dry-run (no DB writes): records are streamed and printed to
 * stdout. Pass --persist to route through rforce:sync semantics (which
 * upserts raw + transforms).
 */
class RForceQueryCommand extends Command
{
    protected $signature = 'rforce:query
        {query? : QueryDefinition id or name. Omit to list all queries.}
        {--soql= : Run an ad-hoc SOQL string instead of a saved query.}
        {--since= : ISO timestamp; only fetch records modified since this point.}
        {--limit= : Cap records to fetch.}
        {--page-size= : Override page size for this run.}
        {--dry-run : Default. Print records, do not upsert.}
        {--persist : Dispatch via rforce:sync instead of streaming to stdout.}';

    protected $description = 'Inspect or run a saved SOQL QueryDefinition against the rForce gateway.';

    public function handle(RForceQueryExecutor $executor): int
    {
        $arg = $this->argument('query');
        $adhoc = (string) ($this->option('soql') ?? '');

        if ($arg === null && $adhoc === '') {
            return $this->listQueries();
        }

        $definition = null;
        if ($arg !== null) {
            $definition = $this->resolveDefinition((string) $arg);
            if ($definition === null) {
                $this->error("No QueryDefinition matches [{$arg}].");
                return self::FAILURE;
            }
        }

        if ($this->option('persist')) {
            $this->call('rforce:sync', array_filter([
                'query'       => $definition?->id ?? $definition?->name,
                '--since'     => $this->option('since'),
                '--limit'     => $this->option('limit'),
            ], fn ($v) => $v !== null));
            return self::SUCCESS;
        }

        $since    = $this->option('since') ? Carbon::parse((string) $this->option('since')) : null;
        $limit    = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $pageSize = $this->option('page-size') !== null ? (int) $this->option('page-size') : null;

        $soql = $adhoc !== ''
            ? RForceQueryExecutor::buildSoql($adhoc, $since, $limit)
            : RForceQueryExecutor::buildSoql($definition, $since, $limit);

        $this->line("<info>SOQL:</info> {$soql}");
        $this->line('---');

        $shown = 0;
        foreach ($executor->streamRecords($soql, $limit, $pageSize) as $record) {
            $this->line(json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $shown++;
        }

        $this->line('---');
        $this->info("Streamed {$shown} record(s). Nothing persisted (dry run).");
        return self::SUCCESS;
    }

    private function listQueries(): int
    {
        $rows = QueryDefinition::query()
            ->orderBy('name')
            ->get(['id', 'name', 'target_object', 'enabled', 'schedule', 'last_run_at'])
            ->map(fn (QueryDefinition $q): array => [
                'id'        => $q->id,
                'name'      => $q->name,
                'target'    => $q->target_object,
                'enabled'   => $q->enabled ? 'yes' : 'no',
                'schedule'  => $q->schedule ?? '—',
                'last_run'  => optional($q->last_run_at)->diffForHumans() ?? 'never',
            ])->all();

        if ($rows === []) {
            $this->warn('No QueryDefinitions yet. Run `php artisan db:seed --class=QueryDefinitionSeeder` or add some via /admin.');
            return self::SUCCESS;
        }

        $this->table(['id', 'name', 'target', 'enabled', 'schedule', 'last_run'], $rows);
        return self::SUCCESS;
    }

    private function resolveDefinition(string $arg): ?QueryDefinition
    {
        $query = QueryDefinition::query();
        return ctype_digit($arg)
            ? $query->find((int) $arg)
            : $query->where('name', $arg)->first();
    }
}

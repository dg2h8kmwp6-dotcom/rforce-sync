<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QueryDefinition;
use App\Services\RForce\Exceptions\RForceException;
use App\Services\RForce\RForceQueryExecutor;
use App\Services\RForce\Soql\SoqlLimiter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dry-run every enabled QueryDefinition against the gateway with a small
 * limit and report success/failure per query.
 *
 * Use this immediately after pointing a new environment at the gateway to
 * surface field/object mismatches before they cause production sync runs
 * to fail. The command does NOT:
 *   - persist raw_rforce_records
 *   - call transformers
 *   - update QueryDefinition.last_run_at
 *
 * A failure for any one query is reported and the command continues — one
 * bad starter query never blocks validation of the rest. The exit code is
 * non-zero only if at least one query failed, so this is also safe to use
 * as a CI smoke probe.
 *
 *   php artisan rforce:validate-queries
 *   php artisan rforce:validate-queries --only=orders.incremental
 *   php artisan rforce:validate-queries --limit=25
 */
class ValidateQueriesCommand extends Command
{
    protected $signature = 'rforce:validate-queries
        {--only=* : One or more QueryDefinition names to validate (repeatable).}
        {--limit=10 : Max records to fetch per query (caps the saved LIMIT too).}';

    protected $description = 'Dry-run enabled QueryDefinitions against the gateway and report per-query status.';

    public function handle(RForceQueryExecutor $executor): int
    {
        $only  = (array) $this->option('only');
        $limit = max(1, (int) $this->option('limit'));

        $query = QueryDefinition::query()->where('enabled', true)->orderBy('name');
        if ($only !== []) {
            $query->whereIn('name', $only);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, QueryDefinition> $definitions */
        $definitions = $query->get();

        if ($definitions->isEmpty()) {
            if ($only !== []) {
                $this->warn('No enabled QueryDefinitions match: '.implode(', ', $only));
            } else {
                $this->warn('No enabled QueryDefinitions to validate.');
            }
            return self::SUCCESS;
        }

        $failures = [];
        $rows     = [];

        foreach ($definitions as $definition) {
            $soql = SoqlLimiter::cap(
                RForceQueryExecutor::buildSoql($definition),
                $limit,
            );

            $start = microtime(true);

            try {
                $result = $executor->execute($soql);
                $ms = (int) round((microtime(true) - $start) * 1000);
                $rows[] = [
                    'name'    => $definition->name,
                    'dataset' => $definition->target_dataset ?? '—',
                    'status'  => '<info>ok</info>',
                    'total'   => (string) $result->totalSize,
                    'got'     => (string) count($result->records),
                    'ms'      => (string) $ms,
                    'detail'  => '',
                ];
            } catch (RForceException $e) {
                $ms = (int) round((microtime(true) - $start) * 1000);
                $failures[$definition->name] = $e->getMessage();
                $rows[] = [
                    'name'    => $definition->name,
                    'dataset' => $definition->target_dataset ?? '—',
                    'status'  => '<error>fail</error>',
                    'total'   => '—',
                    'got'     => '—',
                    'ms'      => (string) $ms,
                    'detail'  => $this->shortenError($e->getMessage()),
                ];
            } catch (Throwable $e) {
                $ms = (int) round((microtime(true) - $start) * 1000);
                $failures[$definition->name] = $e->getMessage();
                $rows[] = [
                    'name'    => $definition->name,
                    'dataset' => $definition->target_dataset ?? '—',
                    'status'  => '<error>err</error>',
                    'total'   => '—',
                    'got'     => '—',
                    'ms'      => (string) $ms,
                    'detail'  => $this->shortenError(get_class($e).': '.$e->getMessage()),
                ];
            }
        }

        $this->table(
            ['name', 'dataset', 'status', 'total', 'got', 'ms', 'detail'],
            $rows,
        );

        if ($failures !== []) {
            $this->line('');
            $this->error(sprintf('%d / %d queries failed:', count($failures), count($definitions)));
            foreach ($failures as $name => $message) {
                $this->line("  <comment>{$name}</comment>");
                foreach (explode("\n", wordwrap($message, 100)) as $line) {
                    $this->line("    {$line}");
                }
            }
            return self::FAILURE;
        }

        $this->info(sprintf('All %d query/queries validated successfully.', count($definitions)));
        return self::SUCCESS;
    }

    private function shortenError(string $message, int $max = 80): string
    {
        $single = preg_replace('/\s+/', ' ', $message) ?? $message;
        return strlen($single) > $max ? substr($single, 0, $max - 1).'…' : $single;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RForce\Exceptions\RForceException;
use App\Services\RForce\RForceQueryExecutor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Execute an ad-hoc SOQL string against the MuleSoft Data Gateway and print
 * the records to stdout. Pagination is followed automatically.
 *
 * Example:
 *   php artisan rforce:query "SELECT Name FROM Account LIMIT 10"
 *
 * For saved queries, use rforce:run-query {id|name}. For full sync runs that
 * persist raw rows + transform, use rforce:sync.
 */
class RForceQueryCommand extends Command
{
    protected $signature = 'rforce:query
        {soql : SOQL string to execute (quote it). Use rforce:run-query for saved queries.}
        {--limit= : Cap printed records (does not modify the SOQL).}
        {--no-pagination : Stop after the first page.}';

    protected $description = 'Run an ad-hoc SOQL string through the MuleSoft Data Gateway.';

    public function handle(RForceQueryExecutor $executor): int
    {
        $soql  = trim((string) $this->argument('soql'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($soql === '') {
            $this->error('SOQL is required.');
            return self::FAILURE;
        }

        $this->line("<info>SOQL:</info> {$soql}");
        $this->line('---');

        try {
            if ($this->option('no-pagination')) {
                $result = $executor->execute($soql);
                $records = $limit !== null
                    ? array_slice($result->records, 0, $limit)
                    : $result->records;
                foreach ($records as $record) {
                    $this->line(json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                $this->line('---');
                $this->info(sprintf(
                    'Printed %d / %d record(s). done=%s',
                    count($records),
                    $result->totalSize,
                    $result->done ? 'true' : 'false',
                ));
                return self::SUCCESS;
            }

            $shown = 0;
            foreach ($executor->streamRecords($soql, $limit) as $record) {
                $this->line(json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $shown++;
            }
            $this->line('---');
            $this->info("Streamed {$shown} record(s). Nothing persisted (dry run).");
            return self::SUCCESS;
        } catch (RForceException $e) {
            $this->error('Gateway error: '.$e->getMessage());
            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Unexpected error: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}

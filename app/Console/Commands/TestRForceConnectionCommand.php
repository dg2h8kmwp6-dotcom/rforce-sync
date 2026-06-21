<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RForce\Exceptions\RForceException;
use App\Services\RForce\RForceQueryExecutor;
use Illuminate\Console\Command;
use Throwable;

class TestRForceConnectionCommand extends Command
{
    protected $signature = 'rforce:test-connection
        {--soql= : Override the default smoke test SOQL.}';

    protected $description = 'Verify that we can reach the MuleSoft Data Gateway and execute SOQL against rForce.';

    public function handle(RForceQueryExecutor $executor): int
    {
        $soql = (string) ($this->option('soql') ?: 'SELECT Name FROM Account LIMIT 1');

        $this->line('Pinging rForce gateway: '.config('rforce.base_url'));
        $this->line('  SOQL: '.$soql);

        try {
            $result = $executor->execute($soql);
        } catch (RForceException $e) {
            $this->error('Gateway error: '.$e->getMessage());
            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Unexpected error: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Gateway reachable. totalSize=%d, returned=%d, done=%s',
            $result->totalSize,
            count($result->records),
            $result->done ? 'true' : 'false',
        ));

        return self::SUCCESS;
    }
}

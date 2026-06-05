<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RForce\Contracts\RForceConnector;
use Illuminate\Console\Command;

class TestRForceConnectionCommand extends Command
{
    protected $signature = 'rforce:test-connection';

    protected $description = 'Verify that we can authenticate against the rForce gateway.';

    public function handle(RForceConnector $connector): int
    {
        $this->line('Pinging rForce gateway...');

        if ($connector->ping()) {
            $this->info('rForce reachable, credentials accepted.');
            return self::SUCCESS;
        }

        $this->error('Failed to reach rForce. Check RFORCE_* env vars and gateway URL.');
        return self::FAILURE;
    }
}

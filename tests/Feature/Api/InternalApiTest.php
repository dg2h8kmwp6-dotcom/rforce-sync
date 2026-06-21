<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\NormalizedAccount;
use App\Models\NormalizedOpportunity;
use App\Models\QueryDefinition;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_alias_returns_same_payload_as_legacy_accounts(): void
    {
        $source = (new SyncRunManager())->defaultSource();
        NormalizedAccount::query()->create([
            'external_source_id' => $source->id,
            'external_id'        => 'A1',
            'name'               => 'Acme',
            'industry'           => 'Manufacturing',
        ]);

        $customers = $this->getJson('/api/internal/customers')->json('data');
        $accounts  = $this->getJson('/api/internal/datasets/accounts')->json('data');

        $this->assertSame($customers, $accounts);
        $this->assertSame('Acme', $customers[0]['name']);
    }

    public function test_sales_endpoint_aliases_opportunities(): void
    {
        $source = (new SyncRunManager())->defaultSource();
        NormalizedOpportunity::query()->create([
            'external_source_id' => $source->id,
            'external_id'        => 'O1',
            'name'               => 'Big deal',
            'stage'              => 'Closed Won',
            'amount'             => 50000,
        ]);

        $this->getJson('/api/internal/sales')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'O1');
    }

    public function test_query_definitions_endpoint_lists_saved_queries(): void
    {
        $source = (new SyncRunManager())->defaultSource();
        QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.all',
            'soql'               => 'SELECT Id, Name FROM Account',
            'target_dataset'     => 'accounts',
            'enabled'            => true,
            'schedule'           => '0 * * * *',
        ]);

        $this->getJson('/api/internal/query-definitions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'accounts.all')
            ->assertJsonPath('data.0.target_dataset', 'accounts')
            ->assertJsonPath('data.0.schedule', '0 * * * *');
    }

    public function test_sync_runs_endpoint_surfaces_new_counters(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();
        $run     = $manager->start($source, 'accounts');
        $manager->incrementReceived($run, 50);
        $manager->incrementInserted($run, 20);
        $manager->incrementChanged($run, 10);
        $manager->incrementSkipped($run, 20);

        $this->getJson("/api/internal/sync-runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.records.received', 50)
            ->assertJsonPath('data.records.inserted', 20)
            ->assertJsonPath('data.records.changed', 10)
            ->assertJsonPath('data.records.skipped', 20);
    }
}

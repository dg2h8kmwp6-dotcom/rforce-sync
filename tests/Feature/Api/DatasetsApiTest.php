<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\NormalizedAccount;
use App\Models\NormalizedContact;
use App\Models\NormalizedOpportunity;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatasetsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_endpoint_returns_seeded_rows(): void
    {
        $source = (new SyncRunManager())->defaultSource();

        NormalizedAccount::query()->create([
            'external_source_id' => $source->id,
            'external_id'        => 'A1',
            'name'               => 'Acme',
            'industry'           => 'Manufacturing',
        ]);
        NormalizedAccount::query()->create([
            'external_source_id' => $source->id,
            'external_id'        => 'A2',
            'name'               => 'Globex',
            'industry'           => 'Energy',
        ]);

        $this->getJson('/api/internal/datasets/accounts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Acme');

        // industry filter
        $this->getJson('/api/internal/datasets/accounts?industry=Energy')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'A2');
    }

    public function test_contacts_endpoint_filters_by_account_external_id(): void
    {
        $source = (new SyncRunManager())->defaultSource();

        NormalizedContact::query()->create([
            'external_source_id'  => $source->id,
            'external_id'         => 'C1',
            'account_external_id' => 'A1',
            'first_name'          => 'Alice',
            'last_name'           => 'Smith',
        ]);
        NormalizedContact::query()->create([
            'external_source_id'  => $source->id,
            'external_id'         => 'C2',
            'account_external_id' => 'A2',
            'first_name'          => 'Bob',
            'last_name'           => 'Jones',
        ]);

        $this->getJson('/api/internal/datasets/contacts?account_external_id=A2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'C2');
    }

    public function test_opportunities_endpoint_filters_by_stage(): void
    {
        $source = (new SyncRunManager())->defaultSource();

        NormalizedOpportunity::query()->create([
            'external_source_id' => $source->id,
            'external_id'        => 'O1',
            'name'               => 'Big deal',
            'stage'              => 'Closed Won',
            'amount'             => 50000,
        ]);
        NormalizedOpportunity::query()->create([
            'external_source_id' => $source->id,
            'external_id'        => 'O2',
            'name'               => 'Pending',
            'stage'              => 'Prospecting',
        ]);

        $this->getJson('/api/internal/datasets/opportunities?stage=Closed%20Won')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'O1');
    }
}

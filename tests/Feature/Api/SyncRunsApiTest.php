<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\SyncStatus;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncRunsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_recent_runs(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();

        $manager->start($source, 'accounts');
        $manager->start($source, 'contacts');

        $response = $this->getJson('/api/internal/sync-runs');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', SyncStatus::Running->value);
    }

    public function test_show_returns_run_with_errors(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();
        $run     = $manager->start($source, 'accounts');
        $manager->recordError($run, 'boom', 'transform');

        $response = $this->getJson("/api/internal/sync-runs/{$run->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonPath('data.errors.0.message', 'boom');
    }

    public function test_index_filters_by_target_dataset(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();
        $manager->start($source, 'accounts');
        $manager->start($source, 'contacts');

        $this->getJson('/api/internal/sync-runs?target_dataset=contacts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_dataset', 'contacts');
    }
}

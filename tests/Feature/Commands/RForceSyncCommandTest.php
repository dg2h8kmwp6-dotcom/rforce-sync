<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\SyncStatus;
use App\Models\QueryDefinition;
use App\Models\SyncRun;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\QueryResult;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RForceSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_inline_run_completes_a_sync_run(): void
    {
        $this->app->instance(RForceConnector::class, new class implements RForceConnector {
            public function ping(): bool { return true; }
            public function executeSoql(string $soql, ?string $cursor = null, ?int $pageSize = null): QueryResult
            {
                return new QueryResult(records: [['Id' => 'A1', 'Name' => 'Acme']], nextCursor: null);
            }
        });

        $source = app(SyncRunManager::class)->defaultSource();
        $def = QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.all',
            'soql'               => 'SELECT Id, Name FROM Account',
            'target_object'      => 'Account',
            'enabled'            => true,
        ]);

        $this->artisan('rforce:sync', ['query' => $def->name, '--inline' => true])
            ->assertSuccessful();

        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->records_received);
    }

    public function test_dry_run_does_not_persist_raw_rows(): void
    {
        $this->app->instance(RForceConnector::class, new class implements RForceConnector {
            public function ping(): bool { return true; }
            public function executeSoql(string $soql, ?string $cursor = null, ?int $pageSize = null): QueryResult
            {
                return new QueryResult(records: [['Id' => 'A1']], nextCursor: null);
            }
        });

        $source = app(SyncRunManager::class)->defaultSource();
        $def = QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.all',
            'soql'               => 'SELECT Id, Name FROM Account',
            'target_object'      => 'Account',
            'enabled'            => true,
        ]);

        $this->artisan('rforce:sync', [
            'query'     => $def->name,
            '--inline'  => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, \App\Models\RawRforceRecord::query()->count());
        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertTrue($run->meta['dry_run'] ?? false);
    }

    public function test_unknown_query_returns_failure(): void
    {
        $this->artisan('rforce:sync', ['query' => 'does.not.exist'])
            ->assertFailed();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\SyncStatus;
use App\Models\QueryDefinition;
use App\Models\RawRforceRecord;
use App\Models\SyncRun;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\RForceQueryResult;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RForceSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_inline_run_query_completes_a_sync_run(): void
    {
        $this->bindFakeConnector([
            new RForceQueryResult(1, true, null, [['Id' => 'A1', 'Name' => 'Acme']]),
        ]);

        $source = app(SyncRunManager::class)->defaultSource();
        $def = QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.smoke',
            'soql'               => 'SELECT Name FROM Account LIMIT 100',
            'target_dataset'     => 'accounts',
            'enabled'            => true,
        ]);

        $this->artisan('rforce:run-query', ['query' => $def->name, '--inline' => true])
            ->assertSuccessful();

        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->records_received);
    }

    public function test_dry_run_does_not_persist_raw_rows(): void
    {
        $this->bindFakeConnector([
            new RForceQueryResult(1, true, null, [['Id' => 'A1']]),
        ]);

        $source = app(SyncRunManager::class)->defaultSource();
        $def = QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.smoke',
            'soql'               => 'SELECT Name FROM Account LIMIT 100',
            'target_dataset'     => 'accounts',
            'enabled'            => true,
        ]);

        $this->artisan('rforce:run-query', [
            'query'     => $def->name,
            '--inline'  => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, RawRforceRecord::query()->count());
        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertTrue($run->meta['dry_run'] ?? false);
    }

    public function test_unknown_query_returns_failure(): void
    {
        $this->artisan('rforce:run-query', ['query' => 'does.not.exist'])
            ->assertFailed();
    }

    public function test_sync_runs_all_enabled_queries_inline(): void
    {
        $this->bindFakeConnector([
            new RForceQueryResult(1, true, null, [['Id' => 'A1']]),
            new RForceQueryResult(1, true, null, [['Id' => 'B1']]),
        ]);

        $source = app(SyncRunManager::class)->defaultSource();
        QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.smoke',
            'soql'               => 'SELECT Name FROM Account LIMIT 100',
            'target_dataset'     => 'accounts',
            'enabled'            => true,
        ]);
        QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'orders.incremental',
            'soql'               => 'SELECT FIELDS(ALL) FROM Order LIMIT 100',
            'target_dataset'     => 'orders',
            'enabled'            => true,
        ]);

        $this->artisan('rforce:sync', ['--inline' => true])->assertSuccessful();

        $this->assertSame(2, SyncRun::query()->count());
    }

    /** @param  array<int, RForceQueryResult>  $pages */
    private function bindFakeConnector(array $pages): void
    {
        $this->app->instance(RForceConnector::class, new class($pages) implements RForceConnector {
            /** @param  array<int, RForceQueryResult>  $pages */
            public function __construct(private array $pages, private int $cursor = 0) {}
            public function ping(): bool { return true; }
            public function query(string $soql): RForceQueryResult
            {
                if ($this->cursor >= count($this->pages)) {
                    throw new \RuntimeException('Fake connector exhausted.');
                }
                return $this->pages[$this->cursor++];
            }
            public function queryMore(string $nextRecordsUrl): RForceQueryResult
            {
                if ($this->cursor >= count($this->pages)) {
                    throw new \RuntimeException('Fake connector exhausted.');
                }
                return $this->pages[$this->cursor++];
            }
        });
    }
}

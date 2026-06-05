<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\SyncStatus;
use App\Jobs\RunQueryDefinitionJob;
use App\Models\NormalizedAccount;
use App\Models\QueryDefinition;
use App\Models\RawRforceRecord;
use App\Models\SyncRun;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\QueryResult;
use App\Services\RForce\Exceptions\RForceException;
use App\Services\RForce\RForceSyncManager;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RunQueryDefinitionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_pipeline_writes_raw_and_normalized_and_marks_succeeded(): void
    {
        $this->bindFakeConnector([
            new QueryResult(
                records: [
                    ['Id' => 'A1', 'Name' => 'Acme',   'Industry' => 'Manufacturing', 'LastModifiedDate' => '2026-05-01T00:00:00Z'],
                    ['Id' => 'A2', 'Name' => 'Globex', 'Industry' => 'Energy',        'LastModifiedDate' => '2026-05-02T00:00:00Z'],
                ],
                nextCursor: 'p2',
            ),
            new QueryResult(
                records: [
                    ['Id' => 'A3', 'Name' => 'Initech', 'Industry' => 'Software', 'LastModifiedDate' => '2026-05-03T00:00:00Z'],
                ],
                nextCursor: null,
            ),
        ]);

        $definition = $this->seedDefinition();

        (new RunQueryDefinitionJob($definition->id))->handle(
            app(RForceSyncManager::class),
            app(SyncRunManager::class),
        );

        $run = SyncRun::query()->latest('id')->firstOrFail();

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(3, $run->records_received);
        $this->assertSame(3, $run->records_inserted);
        $this->assertSame(0, $run->records_changed);
        $this->assertSame(0, $run->records_skipped);
        $this->assertNotNull($run->duration_ms);

        $this->assertSame(3, RawRforceRecord::query()->count());
        $this->assertSame(3, NormalizedAccount::query()->count());

        $a1 = NormalizedAccount::query()->where('external_id', 'A1')->firstOrFail();
        $this->assertSame('Acme', $a1->name);
        $this->assertNotNull($a1->last_synced_at);
    }

    public function test_rerun_buckets_changes_correctly(): void
    {
        $definition = $this->seedDefinition();

        $this->bindFakeConnector([
            new QueryResult([['Id' => 'A1', 'Name' => 'Acme'], ['Id' => 'A2', 'Name' => 'Globex']], null),
        ]);
        (new RunQueryDefinitionJob($definition->id))->handle(
            app(RForceSyncManager::class),
            app(SyncRunManager::class),
        );

        $this->bindFakeConnector([
            new QueryResult([
                ['Id' => 'A1', 'Name' => 'Acme Updated'],  // changed
                ['Id' => 'A2', 'Name' => 'Globex'],        // unchanged
                ['Id' => 'A3', 'Name' => 'Initech'],       // new
            ], null),
        ]);
        (new RunQueryDefinitionJob($definition->id))->handle(
            app(RForceSyncManager::class),
            app(SyncRunManager::class),
        );

        $second = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(3, $second->records_received);
        $this->assertSame(1, $second->records_inserted);
        $this->assertSame(1, $second->records_changed);
        $this->assertSame(1, $second->records_skipped);
    }

    public function test_gateway_failure_records_error_and_marks_failed(): void
    {
        $this->app->instance(RForceConnector::class, new class implements RForceConnector {
            public function ping(): bool { return true; }
            public function executeSoql(string $soql, ?string $cursor = null, ?int $pageSize = null): QueryResult
            {
                throw new RForceException('HTTP 503 from gateway');
            }
        });

        $definition = $this->seedDefinition();

        try {
            (new RunQueryDefinitionJob($definition->id))->handle(
                app(RForceSyncManager::class),
                app(SyncRunManager::class),
            );
            $this->fail('Expected RForceException');
        } catch (RForceException $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertSame(1, $run->records_failed);
        $this->assertCount(1, $run->errors);
        $this->assertSame('gateway', $run->errors->first()->category);
    }

    public function test_dry_run_streams_pages_but_does_not_persist(): void
    {
        $this->bindFakeConnector([
            new QueryResult([['Id' => 'A1', 'Name' => 'Acme']], null),
        ]);

        $definition = $this->seedDefinition();

        (new RunQueryDefinitionJob($definition->id, dryRun: true))->handle(
            app(RForceSyncManager::class),
            app(SyncRunManager::class),
        );

        $this->assertSame(0, RawRforceRecord::query()->count());
        $this->assertSame(0, NormalizedAccount::query()->count());

        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->records_received);
        $this->assertTrue(($run->meta['dry_run'] ?? false));
    }

    public function test_paginated_query_walks_all_pages(): void
    {
        // 3 pages of 2 records each.
        $this->bindFakeConnector([
            new QueryResult([['Id' => 'A1'], ['Id' => 'A2']], 'p2'),
            new QueryResult([['Id' => 'A3'], ['Id' => 'A4']], 'p3'),
            new QueryResult([['Id' => 'A5'], ['Id' => 'A6']], null),
        ]);

        $definition = $this->seedDefinition();

        (new RunQueryDefinitionJob($definition->id))->handle(
            app(RForceSyncManager::class),
            app(SyncRunManager::class),
        );

        $this->assertSame(6, RawRforceRecord::query()->count());
        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(6, $run->records_received);
    }

    public function test_missing_transformer_marks_run_failed_without_blowing_up(): void
    {
        $source = app(SyncRunManager::class)->defaultSource();
        $definition = QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'unknown.thing',
            'soql'               => 'SELECT Id FROM UnknownObject__c',
            'target_object'      => 'UnknownObject__c',
            'enabled'            => true,
        ]);

        $this->bindFakeConnector([
            new QueryResult([['Id' => 'X1']], null),
        ]);

        (new RunQueryDefinitionJob($definition->id))->handle(
            app(RForceSyncManager::class),
            app(SyncRunManager::class),
        );

        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertSame(1, $run->errors->where('category', 'config')->count());
    }

    private function seedDefinition(): QueryDefinition
    {
        $source = app(SyncRunManager::class)->defaultSource();
        return QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.all',
            'soql'               => 'SELECT Id, Name FROM Account',
            'target_object'      => 'Account',
            'enabled'            => true,
        ]);
    }

    /** @param  array<int, QueryResult>  $pages */
    private function bindFakeConnector(array $pages): void
    {
        $this->app->instance(RForceConnector::class, new class($pages) implements RForceConnector {
            /** @param  array<int, QueryResult>  $pages */
            public function __construct(private array $pages, private int $cursor = 0) {}
            public function ping(): bool { return true; }
            public function executeSoql(string $soql, ?string $cursor = null, ?int $pageSize = null): QueryResult
            {
                if ($this->cursor >= count($this->pages)) {
                    throw new RuntimeException('Fake connector exhausted.');
                }
                return $this->pages[$this->cursor++];
            }
        });
    }
}

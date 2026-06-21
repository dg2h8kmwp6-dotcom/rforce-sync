<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\SyncStatus;
use App\Jobs\RunQueryDefinitionJob;
use App\Jobs\TransformRForceRecordsJob;
use App\Models\QueryDefinition;
use App\Models\SyncRun;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\RForceQueryResult;
use App\Services\RForce\RForceSyncManager;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class RunQueryDefinitionJobFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_transform_job_success_finishes_the_run(): void
    {
        Queue::fake();
        $this->bindFakeConnector([
            new RForceQueryResult(1, true, null, [['Id' => 'A1', 'Name' => 'Acme']]),
        ]);

        $definition = $this->seedDefinition();

        (new RunQueryDefinitionJob($definition->id))->handle(
            app(RForceSyncManager::class),
            app(SyncRunManager::class),
        );

        // RunQueryDefinitionJob hands off to the transform job; the run is
        // still "running" until the transform job picks it up.
        Queue::assertPushed(TransformRForceRecordsJob::class);

        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(SyncStatus::Running, $run->status);

        // Simulate the transform worker actually running.
        (new TransformRForceRecordsJob($run->id))->handle(app(SyncRunManager::class));

        $run->refresh();
        $this->assertSame(SyncStatus::Succeeded, $run->status);
    }

    public function test_transform_job_failure_marks_run_failed(): void
    {
        $this->bindFakeConnector([
            new RForceQueryResult(1, true, null, [['Id' => 'A1', 'Name' => 'Acme']]),
        ]);

        $definition = $this->seedDefinition();

        // Sync the data first (with queue faked so transform job is queued).
        Bus::fake([TransformRForceRecordsJob::class]);
        (new RunQueryDefinitionJob($definition->id))->handle(
            app(RForceSyncManager::class),
            app(SyncRunManager::class),
        );

        $run = SyncRun::query()->latest('id')->firstOrFail();

        // Now simulate the transform worker hitting a transformer that explodes.
        // Swap the QueryDefinition's transformer to one that always throws.
        $definition->update(['transformer_class' => ExplodingTransformer::class]);
        $run->load('queryDefinition');

        try {
            (new TransformRForceRecordsJob($run->id))->handle(app(SyncRunManager::class));
            $this->fail('Expected the transform job to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $run->refresh();
        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertCount(1, $run->errors);
        $this->assertSame('transform', $run->errors->first()->category);
    }

    public function test_dispatch_failure_marks_run_failed_inline(): void
    {
        $this->bindFakeConnector([
            new RForceQueryResult(1, true, null, [['Id' => 'A1']]),
        ]);

        $definition = $this->seedDefinition();

        // Replace the queue manager so dispatch() throws.
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue offline'));

        try {
            (new RunQueryDefinitionJob($definition->id))->handle(
                app(RForceSyncManager::class),
                app(SyncRunManager::class),
            );
            $this->fail('Expected dispatch to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('queue offline', $e->getMessage());
        }

        $run = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(SyncStatus::Failed, $run->status);
        // The run's top-level error_message is the underlying exception; the
        // contextual "Failed to dispatch..." line lands in sync_errors.
        $this->assertSame('queue offline', $run->error_message);
        $dispatchError = $run->errors->firstWhere('category', 'dispatch');
        $this->assertNotNull($dispatchError);
        $this->assertSame('Failed to dispatch transform job: queue offline', $dispatchError->message);
    }

    public function test_failed_callback_fails_a_lingering_run_for_the_query(): void
    {
        $this->bindFakeConnector([
            new RForceQueryResult(1, true, null, [['Id' => 'A1']]),
        ]);

        $definition = $this->seedDefinition();

        // Open a run manually (as if sync() had completed but the job died
        // before the dispatch try/catch could fail it).
        $manager = app(SyncRunManager::class);
        $run = $manager->start(
            source: $definition->externalSource,
            targetDataset: $definition->target_dataset,
            queryDefinition: $definition,
            soql: $definition->soql,
        );

        $this->assertSame(SyncStatus::Running, $run->status);

        (new RunQueryDefinitionJob($definition->id))->failed(new RuntimeException('worker crashed'));

        $run->refresh();
        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertSame('worker crashed', $run->error_message);
    }

    private function seedDefinition(): QueryDefinition
    {
        $source = app(SyncRunManager::class)->defaultSource();
        return QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.all',
            'soql'               => 'SELECT Id, Name FROM Account',
            'target_dataset'     => 'accounts',
            'enabled'            => true,
        ]);
    }

    /** @param array<int, RForceQueryResult> $pages */
    private function bindFakeConnector(array $pages): void
    {
        $this->app->instance(RForceConnector::class, new class($pages) implements RForceConnector {
            /** @param array<int, RForceQueryResult> $pages */
            public function __construct(private array $pages, private int $cursor = 0) {}
            public function ping(): bool { return true; }
            public function query(string $soql): RForceQueryResult
            {
                return $this->next();
            }
            public function queryMore(string $nextRecordsUrl): RForceQueryResult
            {
                return $this->next();
            }
            private function next(): RForceQueryResult
            {
                if ($this->cursor >= count($this->pages)) {
                    throw new RuntimeException('Fake connector exhausted.');
                }
                return $this->pages[$this->cursor++];
            }
        });
    }
}

/**
 * Test transformer used to simulate a per-record exception bringing down
 * the chunk pipeline.
 */
class ExplodingTransformer
{
    public function transformBatch(iterable $records, $run, $source): array
    {
        throw new RuntimeException('boom');
    }
}

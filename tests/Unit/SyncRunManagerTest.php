<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SyncStatus;
use App\Models\QueryDefinition;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class SyncRunManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_creates_running_run_with_query_definition_link(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();
        $def     = QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.all',
            'soql'               => 'SELECT Id FROM Account',
            'target_object'      => 'Account',
        ]);

        $run = $manager->start(
            source: $source,
            objectType: 'Account',
            queryDefinition: $def,
            soql: 'SELECT Id FROM Account',
        );

        $this->assertSame(SyncStatus::Running, $run->status);
        $this->assertSame($def->id, $run->query_definition_id);
        $this->assertSame('SELECT Id FROM Account', $run->soql);
    }

    public function test_finish_derives_succeeded_when_no_failures(): void
    {
        $manager = new SyncRunManager();
        $run = $manager->start($manager->defaultSource(), 'Account');
        $manager->incrementInserted($run, 5);

        $manager->finish($run);
        $run->refresh();

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration_ms);
        $this->assertGreaterThanOrEqual(0, $run->duration_ms);
    }

    public function test_finish_derives_partial_when_some_failures(): void
    {
        $manager = new SyncRunManager();
        $run = $manager->start($manager->defaultSource(), 'Account');
        $manager->incrementInserted($run, 8);
        $manager->incrementFailed($run, 2);

        $manager->finish($run);
        $run->refresh();

        $this->assertSame(SyncStatus::Partial, $run->status);
    }

    public function test_finish_derives_failed_when_only_failures(): void
    {
        $manager = new SyncRunManager();
        $run = $manager->start($manager->defaultSource(), 'Account');
        $manager->incrementFailed($run, 3);

        $manager->finish($run);
        $run->refresh();

        $this->assertSame(SyncStatus::Failed, $run->status);
    }

    public function test_cancel_marks_run_cancelled(): void
    {
        $manager = new SyncRunManager();
        $run = $manager->start($manager->defaultSource(), 'Account');

        $manager->cancel($run, 'user aborted');
        $run->refresh();

        $this->assertSame(SyncStatus::Cancelled, $run->status);
        $this->assertSame('user aborted', $run->error_message);
        $this->assertNotNull($run->finished_at);
        $this->assertTrue($run->status->isTerminal());
    }

    public function test_record_error_increments_failed_and_persists_error(): void
    {
        $manager = new SyncRunManager();
        $run = $manager->start($manager->defaultSource(), 'Account');

        $manager->recordError(
            run:        $run,
            message:    'boom',
            category:   'transform',
            context:    ['k' => 'v'],
            externalId: 'A1',
        );

        $run->refresh();
        $this->assertSame(1, $run->records_failed);
        $this->assertCount(1, $run->errors);
        $this->assertSame('boom', $run->errors->first()->message);
        $this->assertSame('transform', $run->errors->first()->category);
    }

    public function test_increment_counters_track_received_inserted_changed_skipped(): void
    {
        $manager = new SyncRunManager();
        $run = $manager->start($manager->defaultSource(), 'Account');

        $manager->incrementReceived($run, 100);
        $manager->incrementInserted($run, 30);
        $manager->incrementChanged($run, 20);
        $manager->incrementSkipped($run, 50);

        $run->refresh();
        $this->assertSame(100, $run->records_received);
        $this->assertSame(30, $run->records_inserted);
        $this->assertSame(20, $run->records_changed);
        $this->assertSame(50, $run->records_skipped);
        // legacy fields mirrored
        $this->assertSame(100, $run->records_fetched);
        $this->assertSame(50, $run->records_upserted);
    }

    public function test_fail_marks_run_failed_with_error_message(): void
    {
        $manager = new SyncRunManager();
        $run = $manager->start($manager->defaultSource(), 'Account');

        $manager->fail($run, new RuntimeException('gateway down'));
        $run->refresh();

        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertSame('gateway down', $run->error_message);
        $this->assertNotNull($run->finished_at);
    }

    public function test_sync_status_isTerminal_covers_cancelled(): void
    {
        $this->assertTrue(SyncStatus::Cancelled->isTerminal());
        $this->assertTrue(SyncStatus::Succeeded->isTerminal());
        $this->assertFalse(SyncStatus::Running->isTerminal());
    }
}

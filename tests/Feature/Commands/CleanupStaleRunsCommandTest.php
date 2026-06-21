<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\SyncStatus;
use App\Models\SyncRun;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CleanupStaleRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_runs_older_than_threshold_as_failed_with_error_record(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();

        // Fresh run: not stale yet.
        $fresh = $manager->start($source, 'accounts');

        // Stale: started 3 hours ago, still "running".
        $stale = $manager->start($source, 'accounts');
        $stale->forceFill(['started_at' => Carbon::now()->subHours(3)])->save();

        $this->artisan('rforce:cleanup-stale-runs', ['--hours' => 2])
            ->assertSuccessful();

        $stale->refresh();
        $fresh->refresh();

        $this->assertSame(SyncStatus::Failed, $stale->status);
        $this->assertNotNull($stale->finished_at);
        $this->assertCount(1, $stale->errors);
        $this->assertSame('stale', $stale->errors->first()->category);

        // Fresh run is left alone.
        $this->assertSame(SyncStatus::Running, $fresh->status);
    }

    public function test_dry_run_reports_but_does_not_mutate(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();

        $stale = $manager->start($source, 'accounts');
        $stale->forceFill(['started_at' => Carbon::now()->subHours(5)])->save();

        $this->artisan('rforce:cleanup-stale-runs', ['--hours' => 2, '--dry-run' => true])
            ->assertSuccessful();

        $stale->refresh();
        $this->assertSame(SyncStatus::Running, $stale->status);
        $this->assertCount(0, $stale->errors);
    }

    public function test_no_action_when_no_stale_runs(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();
        $manager->start($source, 'accounts'); // fresh, will not be stale

        $this->artisan('rforce:cleanup-stale-runs', ['--hours' => 2])
            ->expectsOutputToContain('No stale runs')
            ->assertSuccessful();

        $this->assertSame(0, SyncRun::query()->where('status', SyncStatus::Failed->value)->count());
    }

    public function test_terminal_runs_are_never_touched(): void
    {
        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();

        // Already-succeeded run from 4 hours ago should NOT be re-failed.
        $done = $manager->start($source, 'accounts');
        $done->forceFill(['started_at' => Carbon::now()->subHours(4)])->save();
        $manager->finish($done, SyncStatus::Succeeded);

        $this->artisan('rforce:cleanup-stale-runs', ['--hours' => 2])
            ->assertSuccessful();

        $done->refresh();
        $this->assertSame(SyncStatus::Succeeded, $done->status);
    }
}

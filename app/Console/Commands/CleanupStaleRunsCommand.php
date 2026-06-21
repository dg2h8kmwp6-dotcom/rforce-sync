<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Models\SyncRun;
use App\Services\Sync\SyncRunManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fail SyncRuns that have been stuck in "running" longer than the configured
 * staleness window.
 *
 * Why this exists: completion of a run depends on a queue worker actually
 * picking up TransformRForceRecordsJob. If the worker dies between dispatch
 * and pickup, the run sits in "running" indefinitely — dashboards lie,
 * scheduling logic that checks last_run_at could re-fire, etc. Schedule
 * this command alongside the scheduler so the platform self-heals.
 *
 *   php artisan rforce:cleanup-stale-runs
 *   php artisan rforce:cleanup-stale-runs --hours=6 --dry-run
 */
class CleanupStaleRunsCommand extends Command
{
    protected $signature = 'rforce:cleanup-stale-runs
        {--hours= : Override the staleness threshold in hours.}
        {--dry-run : Report what would be failed without mutating any rows.}';

    protected $description = 'Mark stuck "running" SyncRuns as failed once they exceed the staleness window.';

    public function handle(SyncRunManager $runs): int
    {
        $hours = $this->option('hours') !== null
            ? max(1, (int) $this->option('hours'))
            : max(1, (int) config('rforce.sync.stale_after_hours', 2));

        $cutoff = Carbon::now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');

        $stale = SyncRun::query()
            ->where('status', SyncStatus::Running->value)
            ->where('started_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        if ($stale->isEmpty()) {
            $this->info("No stale runs (cutoff: {$cutoff->toIso8601String()}).");
            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Found %d stale run(s) older than %dh (cutoff: %s).',
            $stale->count(),
            $hours,
            $cutoff->toIso8601String(),
        ));

        foreach ($stale as $run) {
            $this->line(sprintf(
                '  - run #%d (query_definition_id=%s, started_at=%s)',
                $run->id,
                $run->query_definition_id ?? '—',
                optional($run->started_at)->toIso8601String(),
            ));

            if ($dryRun) {
                continue;
            }

            $message = sprintf('Run stuck in "running" for %dh — marked failed by cleanup.', $hours);

            $runs->recordError(
                $run,
                $message,
                'stale',
                [
                    'cutoff_at'         => $cutoff->toIso8601String(),
                    'started_at'        => optional($run->started_at)->toIso8601String(),
                    'stale_after_hours' => $hours,
                ],
            );

            $runs->fail($run, new RuntimeException($message));

            Log::warning('rforce.cleanup_stale_runs.failed_run', [
                'sync_run_id'        => $run->id,
                'query_definition_id'=> $run->query_definition_id,
                'started_at'         => optional($run->started_at)->toIso8601String(),
                'stale_after_hours'  => $hours,
            ]);
        }

        if ($dryRun) {
            $this->info(sprintf('Dry run: %d stale run(s) would be failed.', $stale->count()));
        } else {
            $this->info(sprintf('Failed %d stale run(s).', $stale->count()));
        }

        return self::SUCCESS;
    }
}

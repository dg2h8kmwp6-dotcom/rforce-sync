<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\SyncStatus;
use App\Models\ExternalSource;
use App\Models\QueryDefinition;
use App\Models\SyncRun;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Owns the lifecycle of a SyncRun row: start, increment counters, finish.
 *
 * Keeping the bookkeeping in one place means jobs/commands don't have to
 * remember the "did I set finished_at?" / "did I bump records_failed?" rules.
 *
 * Counter terminology:
 *   - received  = records read from the gateway response
 *   - inserted  = brand new raw rows
 *   - changed   = existing raw rows where payload hash differed
 *   - skipped   = existing raw rows where payload hash matched (no-op)
 *   - failed    = errors during ingest/transform
 *
 * Legacy counters (records_fetched / records_upserted) are still maintained
 * for backwards compatibility with downstream readers; new code should prefer
 * the named buckets above.
 */
class SyncRunManager
{
    public function start(
        ExternalSource $source,
        ?string $objectType = null,
        ?DateTimeInterface $since = null,
        ?int $limit = null,
        array $meta = [],
        ?QueryDefinition $queryDefinition = null,
        ?string $soql = null,
    ): SyncRun {
        return SyncRun::query()->create([
            'external_source_id'  => $source->id,
            'query_definition_id' => $queryDefinition?->id,
            'object_type'         => $objectType,
            'soql'                => $soql,
            'status'              => SyncStatus::Running,
            'since'               => $since,
            'limit'               => $limit,
            'started_at'          => Carbon::now(),
            'meta'                => $meta ?: null,
        ]);
    }

    public function incrementFetched(SyncRun $run, int $by): void
    {
        $run->increment('records_fetched', $by);
    }

    public function incrementReceived(SyncRun $run, int $by): void
    {
        if ($by <= 0) {
            return;
        }
        $run->increment('records_received', $by);
        // Mirror to the legacy field so older readers keep working.
        $run->increment('records_fetched', $by);
    }

    public function incrementInserted(SyncRun $run, int $by): void
    {
        if ($by <= 0) {
            return;
        }
        $run->increment('records_inserted', $by);
        $run->increment('records_upserted', $by);
    }

    public function incrementChanged(SyncRun $run, int $by): void
    {
        if ($by <= 0) {
            return;
        }
        $run->increment('records_changed', $by);
        $run->increment('records_upserted', $by);
    }

    public function incrementSkipped(SyncRun $run, int $by): void
    {
        if ($by <= 0) {
            return;
        }
        $run->increment('records_skipped', $by);
    }

    public function incrementUpserted(SyncRun $run, int $by): void
    {
        $run->increment('records_upserted', $by);
    }

    public function incrementFailed(SyncRun $run, int $by): void
    {
        if ($by <= 0) {
            return;
        }
        $run->increment('records_failed', $by);
    }

    public function recordError(
        SyncRun $run,
        string $message,
        ?string $category = null,
        array $context = [],
        ?string $objectType = null,
        ?string $externalId = null,
    ): void {
        $run->errors()->create([
            'object_type' => $objectType ?? $run->object_type,
            'external_id' => $externalId,
            'category'    => $category,
            'message'     => $message,
            'context'     => $context ?: null,
        ]);
        $this->incrementFailed($run, 1);
    }

    /**
     * Mark a run as finished. Status is derived from counters unless caller forces it.
     */
    public function finish(SyncRun $run, ?SyncStatus $status = null, ?string $errorMessage = null): SyncRun
    {
        $run->refresh();

        $resolved = $status ?? match (true) {
            $run->records_failed > 0 && $run->records_upserted === 0 && $run->records_received === 0 => SyncStatus::Failed,
            $run->records_failed > 0                                                                  => SyncStatus::Partial,
            default                                                                                   => SyncStatus::Succeeded,
        };

        $finishedAt = Carbon::now();
        $duration   = $run->started_at !== null ? (int) ($finishedAt->getPreciseTimestamp(3) - $run->started_at->getPreciseTimestamp(3)) : null;

        $run->update([
            'status'        => $resolved,
            'finished_at'   => $finishedAt,
            'duration_ms'   => $duration,
            'error_message' => $errorMessage ?? $run->error_message,
        ]);

        return $run;
    }

    public function fail(SyncRun $run, Throwable $e): SyncRun
    {
        return $this->finish($run, SyncStatus::Failed, $e->getMessage());
    }

    public function cancel(SyncRun $run, ?string $reason = null): SyncRun
    {
        return $this->finish($run, SyncStatus::Cancelled, $reason);
    }

    /**
     * Get-or-create the standard rforce source from config. Convenience for
     * places that don't have a QueryDefinition handy yet.
     */
    public function defaultSource(): ExternalSource
    {
        return ExternalSource::query()->firstOrCreate(
            ['name' => (string) config('rforce.sync.source_name', 'rforce')],
            [
                'label'  => 'rForce / Salesforce gateway',
                'config' => ['base_url' => config('rforce.base_url')],
                'active' => true,
            ],
        );
    }
}

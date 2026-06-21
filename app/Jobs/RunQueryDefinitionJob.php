<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\QueryDefinition;
use App\Models\SyncRun;
use App\Services\RForce\RForceSyncManager;
use App\Services\Sync\SyncRunManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Run a saved SOQL query end-to-end:
 *   1. Open SyncRun (via RForceSyncManager.sync())
 *   2. Stream pages from the gateway and upsert raw rows with change detection
 *   3. Hand off to TransformRForceRecordsJob for normalization
 *
 * Idempotent: re-running the same query just refreshes timestamps and
 * re-writes payloads only if their hashes changed.
 *
 * Lifecycle guarantees on this layer:
 *   - sync() throws → the manager has already failed the run, then re-throws.
 *   - dispatch() throws → we explicitly fail the run, then re-throw.
 *   - any unhandled throw before sync() returns → failed() callback fails
 *     the most recent non-terminal run for this query definition.
 *   - queue worker never picks up the transform job → run stays "running"
 *     until rforce:cleanup-stale-runs marks it failed (operational safety net).
 */
class RunQueryDefinitionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $queryDefinitionId,
        public readonly ?string $since = null,
        public readonly ?int $limit = null,
        public readonly bool $dryRun = false,
        public readonly array $meta = [],
    ) {
        $this->onQueue((string) config('rforce.sync.queue', 'default'));
    }

    public function handle(RForceSyncManager $sync, SyncRunManager $runs): void
    {
        /** @var QueryDefinition $definition */
        $definition = QueryDefinition::query()->with('externalSource')->findOrFail($this->queryDefinitionId);

        $since = $this->since !== null ? new \DateTimeImmutable($this->since) : null;

        $run = $sync->sync(
            definition: $definition,
            since:      $since,
            limit:      $this->limit,
            dryRun:     $this->dryRun,
            meta:       $this->meta,
        );

        if ($this->dryRun) {
            $runs->finish($run);
            return;
        }

        // Normalization runs in a separate job so it can be retried
        // without re-fetching from the gateway. Wrap the dispatch so a
        // queue outage doesn't leave the run stuck in "running" — better
        // to fail loudly than to silently lose a run.
        //
        // Caveat: with QUEUE_CONNECTION=sync, dispatch() executes the
        // transform inline and any transform-side failure rethrows here.
        // In that case the transform job has already failed the run; we
        // must not overwrite its terminal state with a misleading
        // "dispatch failed" error.
        try {
            TransformRForceRecordsJob::dispatch($run->id);
        } catch (Throwable $e) {
            $run->refresh();
            if (! $run->status?->isTerminal()) {
                $runs->recordError(
                    $run,
                    'Failed to dispatch transform job: '.$e->getMessage(),
                    'dispatch',
                    ['exception' => $e::class],
                );
                $runs->fail($run, $e);
            }
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $latest = SyncRun::query()
            ->where('query_definition_id', $this->queryDefinitionId)
            ->latest('id')
            ->first();
        if ($latest !== null && ! $latest->status?->isTerminal()) {
            app(SyncRunManager::class)->fail($latest, $e);
        }
    }
}

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
        // without re-fetching from the gateway.
        TransformRForceRecordsJob::dispatch($run->id);
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

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\RawRforceRecord;
use App\Models\SyncRun;
use App\Services\Sync\SyncRunManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Normalize raw payloads written by the matching sync job into the
 * normalized_* tables. Only operates on rows last touched by this run.
 *
 * Resolves the transformer in this order:
 *   1. QueryDefinition.transformer_class (if set on the SyncRun's query)
 *   2. config('rforce.datasets.{target_dataset}.transformer')
 *
 * If neither resolves to a real class, the run finishes successfully with no
 * transformed rows — the raw data is still safely in raw_rforce_records.
 * Add a transformer later and replay against the same SyncRun.
 */
class TransformRForceRecordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $syncRunId,
    ) {
        $this->onQueue((string) config('rforce.sync.queue', 'default'));
    }

    public function handle(SyncRunManager $runs): void
    {
        /** @var SyncRun $run */
        $run = SyncRun::query()
            ->with(['externalSource', 'queryDefinition'])
            ->findOrFail($this->syncRunId);

        $source        = $run->externalSource;
        $targetDataset = $run->target_dataset ?? $run->queryDefinition?->target_dataset;

        $transformerCl = $run->queryDefinition?->resolvedTransformerClass();
        if ($transformerCl === null && $targetDataset !== null) {
            $cfg = config("rforce.datasets.{$targetDataset}.transformer");
            if (is_string($cfg) && class_exists($cfg)) {
                $transformerCl = $cfg;
            }
        }

        if ($transformerCl === null) {
            // No transformer is fine — raw rows are the only required output.
            // Stamp last_synced_at so downstream consumers can tell the rows
            // were handled by this run, then finish cleanly.
            RawRforceRecord::query()
                ->where('external_source_id', $source->id)
                ->where('last_sync_run_id', $run->id)
                ->update(['last_synced_at' => Carbon::now()]);
            $runs->finish($run);
            return;
        }

        $transformer = app($transformerCl);

        try {
            RawRforceRecord::query()
                ->where('external_source_id', $source->id)
                ->where('last_sync_run_id', $run->id)
                ->chunkById(500, function ($chunk) use ($transformer, $run, $source): void {
                    $transformer->transformBatch($chunk, $run, $source);

                    RawRforceRecord::query()
                        ->whereIn('id', $chunk->pluck('id')->all())
                        ->update(['last_synced_at' => Carbon::now()]);
                });

            $runs->finish($run);
        } catch (Throwable $e) {
            $runs->recordError($run, $e->getMessage(), 'transform', ['exception' => $e::class]);
            $runs->fail($run, $e);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $run = SyncRun::query()->find($this->syncRunId);
        if ($run !== null && ! $run->status?->isTerminal()) {
            app(SyncRunManager::class)->fail($run, $e);
        }
    }
}

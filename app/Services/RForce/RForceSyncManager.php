<?php

declare(strict_types=1);

namespace App\Services\RForce;

use App\Models\ExternalSource;
use App\Models\QueryDefinition;
use App\Models\RawRforceRecord;
use App\Models\SyncRun;
use App\Services\Sync\SyncRunManager;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Orchestrates a single end-to-end sync for a saved query.
 *
 * Inputs: a QueryDefinition (or ad-hoc SOQL) + optional since/limit/dry-run.
 *
 * What it does, in order:
 *   1. Open a SyncRun (status = running) tagged with the query + SOQL.
 *   2. Stream pages from the executor.
 *   3. For each page, compare payload hashes against the existing raw rows
 *      to bucket records into new/changed/unchanged.
 *   4. Upsert raw rows, bumping last_seen_at on every hit and last_synced_at
 *      on changes; first_seen_at only sets on insert.
 *   5. Update SyncRun counters (received/inserted/changed/skipped).
 *   6. Hand off to the transformer (downstream concern: TransformRForceRecordsJob).
 *   7. Finish the SyncRun (status derived from counters).
 *
 * Idempotent: re-running the same SOQL is safe, just refreshes timestamps.
 */
class RForceSyncManager
{
    public function __construct(
        private readonly RForceQueryExecutor $executor,
        private readonly SyncRunManager $runs,
    ) {}

    public function start(
        ExternalSource $source,
        ?QueryDefinition $definition,
        string $soql,
        ?string $objectType = null,
        ?DateTimeInterface $since = null,
        ?int $limit = null,
        array $meta = [],
    ): SyncRun {
        return $this->runs->start(
            source:        $source,
            objectType:    $objectType,
            since:         $since,
            limit:         $limit,
            meta:          $meta,
            queryDefinition: $definition,
            soql:          $soql,
        );
    }

    /**
     * Run a full sync for the given QueryDefinition.
     *
     * If $dryRun is true, the connector is invoked but raw rows are NOT
     * written and the run is finished with status=succeeded and a meta flag.
     * Caller still gets accurate received/changed/inserted/skipped counts.
     */
    public function sync(
        QueryDefinition $definition,
        ?DateTimeInterface $since = null,
        ?int $limit = null,
        bool $dryRun = false,
        array $meta = [],
    ): SyncRun {
        $source = $definition->externalSource ?? $this->runs->defaultSource();
        $soql   = RForceQueryExecutor::buildSoql($definition, $since, $limit);

        $run = $this->start(
            source:     $source,
            definition: $definition,
            soql:       $soql,
            objectType: $definition->target_object,
            since:      $since,
            limit:      $limit,
            meta:       array_merge($meta, ['dry_run' => $dryRun]),
        );

        try {
            $pageSize = (int) config('rforce.sync.page_size', 500);

            foreach ($this->executor->streamPages($soql, $pageSize) as $page) {
                $records = $page->records;
                $this->runs->incrementReceived($run, count($records));

                if ($dryRun) {
                    continue;
                }

                $diff = $this->upsertWithChangeDetection(
                    source:     $source,
                    definition: $definition,
                    run:        $run,
                    objectType: $definition->target_object ?? 'Unknown',
                    records:    $records,
                );

                $this->runs->incrementInserted($run, $diff['inserted']);
                $this->runs->incrementChanged($run, $diff['changed']);
                $this->runs->incrementSkipped($run, $diff['unchanged']);
                if ($diff['malformed'] > 0) {
                    $this->runs->incrementFailed($run, $diff['malformed']);
                }
            }

            $definition->forceFill(['last_run_at' => Carbon::now()])->save();

            // Transform handoff is the job's responsibility; SyncManager
            // returns the run after raw writes so the job can dispatch the
            // transformer. If the caller wants synchronous transformation,
            // they should call $transformer->transformBatch separately.
            return $run;
        } catch (Throwable $e) {
            $this->runs->recordError($run, $e->getMessage(), 'gateway', ['exception' => $e::class]);
            $this->runs->fail($run, $e);
            throw $e;
        }
    }

    /**
     * Upsert a batch of records into raw_rforce_records, bucketing by hash:
     *   - new       → insert, first_seen_at = last_seen_at = now
     *   - changed   → update payload + hash, last_seen_at = now
     *   - unchanged → touch last_seen_at only
     *   - malformed → record has no usable external id; skipped
     *
     * @param  array<int, array<string,mixed>>  $records
     * @return array{inserted:int, changed:int, unchanged:int, malformed:int}
     */
    public function upsertWithChangeDetection(
        ExternalSource $source,
        ?QueryDefinition $definition,
        SyncRun $run,
        string $objectType,
        array $records,
    ): array {
        $diff = ['inserted' => 0, 'changed' => 0, 'unchanged' => 0, 'malformed' => 0];

        if ($records === []) {
            return $diff;
        }

        $prepared = [];
        foreach ($records as $record) {
            $externalId = self::extractExternalId($record);
            if ($externalId === null) {
                $diff['malformed']++;
                continue;
            }

            $payloadJson = json_encode($record, JSON_THROW_ON_ERROR);
            $prepared[$externalId] = [
                'record'  => $record,
                'json'    => $payloadJson,
                'hash'    => hash('sha256', $payloadJson),
                'modified' => self::extractModified($record),
            ];
        }

        if ($prepared === []) {
            return $diff;
        }

        // Look up existing hashes in one query.
        $existing = RawRforceRecord::query()
            ->where('external_source_id', $source->id)
            ->where('object_type', $objectType)
            ->whereIn('external_id', array_keys($prepared))
            ->get(['external_id', 'payload_hash', 'first_seen_at'])
            ->keyBy('external_id');

        $now = Carbon::now();
        $rows = [];

        foreach ($prepared as $externalId => $p) {
            /** @var RawRforceRecord|null $existingRow */
            $existingRow = $existing->get($externalId);

            if ($existingRow === null) {
                $diff['inserted']++;
                $rows[] = [
                    'external_source_id'   => $source->id,
                    'last_sync_run_id'     => $run->id,
                    'query_definition_id'  => $definition?->id,
                    'object_type'          => $objectType,
                    'external_id'          => $externalId,
                    'payload'              => $p['json'],
                    'payload_hash'         => $p['hash'],
                    'external_modified_at' => $p['modified'],
                    'fetched_at'           => $now,
                    'first_seen_at'        => $now,
                    'last_seen_at'         => $now,
                    'last_synced_at'       => null,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];
                continue;
            }

            if ($existingRow->payload_hash === $p['hash']) {
                $diff['unchanged']++;
                $rows[] = [
                    'external_source_id'   => $source->id,
                    'last_sync_run_id'     => $run->id,
                    'query_definition_id'  => $definition?->id,
                    'object_type'          => $objectType,
                    'external_id'          => $externalId,
                    'payload'              => $p['json'],
                    'payload_hash'         => $p['hash'],
                    'external_modified_at' => $p['modified'],
                    'fetched_at'           => $now,
                    'first_seen_at'        => $existingRow->first_seen_at ?? $now,
                    'last_seen_at'         => $now,
                    'last_synced_at'       => null,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];
                continue;
            }

            $diff['changed']++;
            $rows[] = [
                'external_source_id'   => $source->id,
                'last_sync_run_id'     => $run->id,
                'query_definition_id'  => $definition?->id,
                'object_type'          => $objectType,
                'external_id'          => $externalId,
                'payload'              => $p['json'],
                'payload_hash'         => $p['hash'],
                'external_modified_at' => $p['modified'],
                'fetched_at'           => $now,
                'first_seen_at'        => $existingRow->first_seen_at ?? $now,
                'last_seen_at'         => $now,
                'last_synced_at'       => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }

        if ($rows !== []) {
            RawRforceRecord::query()->upsert(
                values: $rows,
                uniqueBy: ['external_source_id', 'object_type', 'external_id'],
                update: [
                    'last_sync_run_id',
                    'query_definition_id',
                    'payload',
                    'payload_hash',
                    'external_modified_at',
                    'fetched_at',
                    'last_seen_at',
                    'updated_at',
                ],
            );
        }

        return $diff;
    }

    /**
     * @param  array<string,mixed>  $record
     */
    public static function extractExternalId(array $record): ?string
    {
        foreach (['Id', 'id', 'external_id', 'externalId'] as $key) {
            if (isset($record[$key]) && is_scalar($record[$key]) && (string) $record[$key] !== '') {
                return (string) $record[$key];
            }
        }
        return null;
    }

    /**
     * @param  array<string,mixed>  $record
     */
    public static function extractModified(array $record): ?Carbon
    {
        foreach (['LastModifiedDate', 'lastModifiedDate', 'updated_at', 'modifiedAt'] as $key) {
            if (! empty($record[$key])) {
                try {
                    return Carbon::parse((string) $record[$key]);
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        return null;
    }
}

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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates a single end-to-end sync for a saved query.
 *
 * Inputs: a QueryDefinition (or ad-hoc SOQL) + optional since/limit/dry-run.
 *
 * What it does, in order:
 *   1. Open a SyncRun (status = running) tagged with the query + SOQL.
 *   2. Stream pages from the executor (one query() + N queryMore() calls).
 *   3. For each page, compare payload hashes against the existing raw rows
 *      to bucket records into new/changed/unchanged.
 *   4. Upsert raw rows, bumping last_seen_at on every hit and first_seen_at
 *      only on insert.
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

    /**
     * Run a full sync for the given QueryDefinition.
     *
     * If $dryRun is true, the connector is invoked but raw rows are NOT
     * written and the run is finished with status=succeeded and a meta flag.
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

        $run = $this->runs->start(
            source:          $source,
            targetDataset:   $definition->target_dataset,
            since:           $since,
            limit:           $limit,
            meta:            array_merge($meta, ['dry_run' => $dryRun]),
            queryDefinition: $definition,
            soql:            $soql,
        );

        try {
            foreach ($this->executor->executePaginated($soql) as $page) {
                $records = $page->records;

                // Pagination telemetry. Never logs credentials, SOQL bodies,
                // or record payloads — only pointers and counts.
                Log::info('rforce.pagination.page', [
                    'sync_run_id'         => $run->id,
                    'query_definition_id' => $definition->id,
                    'page_number'         => $page->pageNumber,
                    'records'             => count($records),
                    'has_next'            => $page->nextRecordsUrl !== null,
                    'done'                => $page->done,
                ]);

                $this->runs->incrementReceived($run, count($records));

                if ($dryRun) {
                    continue;
                }

                $diff = $this->upsertWithChangeDetection(
                    source:     $source,
                    definition: $definition,
                    run:        $run,
                    records:    $records,
                );

                $this->runs->incrementInserted($run, $diff['inserted']);
                $this->runs->incrementChanged($run, $diff['changed']);
                $this->runs->incrementSkipped($run, $diff['unchanged']);
                if ($diff['malformed'] > 0) {
                    $this->runs->recordError(
                        $run,
                        "Skipped {$diff['malformed']} record(s) with no usable Id",
                        'malformed',
                        ['count' => $diff['malformed']],
                    );
                }
            }

            $definition->forceFill(['last_run_at' => Carbon::now()])->save();

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
                'object_type' => self::extractObjectType($record),
                'source_url'  => self::extractSourceUrl($record),
                'external_id' => $externalId,
                'json'        => $payloadJson,
                'hash'        => hash('sha256', $payloadJson),
            ];
        }

        if ($prepared === []) {
            return $diff;
        }

        // Look up existing hashes in one query.
        $existing = RawRforceRecord::query()
            ->where('external_source_id', $source->id)
            ->whereIn('external_id', array_keys($prepared))
            ->get(['external_id', 'payload_hash', 'first_seen_at'])
            ->keyBy('external_id');

        $now  = Carbon::now();
        $rows = [];

        foreach ($prepared as $externalId => $p) {
            /** @var RawRforceRecord|null $existingRow */
            $existingRow = $existing->get($externalId);

            if ($existingRow === null) {
                $diff['inserted']++;
                $rows[] = $this->buildRow($source, $run, $definition, $p, firstSeen: $now, lastSeen: $now);
                continue;
            }

            if ($existingRow->payload_hash === $p['hash']) {
                $diff['unchanged']++;
                $rows[] = $this->buildRow(
                    $source,
                    $run,
                    $definition,
                    $p,
                    firstSeen: $existingRow->first_seen_at ?? $now,
                    lastSeen:  $now,
                );
                continue;
            }

            $diff['changed']++;
            $rows[] = $this->buildRow(
                $source,
                $run,
                $definition,
                $p,
                firstSeen: $existingRow->first_seen_at ?? $now,
                lastSeen:  $now,
            );
        }

        if ($rows !== []) {
            RawRforceRecord::query()->upsert(
                values: $rows,
                uniqueBy: ['external_source_id', 'external_id'],
                update: [
                    'last_sync_run_id',
                    'query_definition_id',
                    'source_object_type',
                    'source_url',
                    'payload',
                    'payload_hash',
                    'last_seen_at',
                    'updated_at',
                ],
            );
        }

        return $diff;
    }

    /**
     * @param  array<string,mixed>  $p
     * @return array<string,mixed>
     */
    private function buildRow(
        ExternalSource $source,
        SyncRun $run,
        ?QueryDefinition $definition,
        array $p,
        Carbon $firstSeen,
        Carbon $lastSeen,
    ): array {
        return [
            'external_source_id'  => $source->id,
            'last_sync_run_id'    => $run->id,
            'query_definition_id' => $definition?->id,
            'source_object_type'  => $p['object_type'],
            'source_url'          => $p['source_url'],
            'external_id'         => $p['external_id'],
            'payload'             => $p['json'],
            'payload_hash'        => $p['hash'],
            'first_seen_at'       => $firstSeen,
            'last_seen_at'        => $lastSeen,
            'last_synced_at'      => null,
            'created_at'          => $lastSeen,
            'updated_at'          => $lastSeen,
        ];
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
     * record.attributes.type per Salesforce REST shape.
     *
     * @param  array<string,mixed>  $record
     */
    public static function extractObjectType(array $record): ?string
    {
        $attrs = $record['attributes'] ?? null;
        if (is_array($attrs) && isset($attrs['type']) && is_string($attrs['type']) && $attrs['type'] !== '') {
            return $attrs['type'];
        }
        return null;
    }

    /**
     * record.attributes.url per Salesforce REST shape.
     *
     * @param  array<string,mixed>  $record
     */
    public static function extractSourceUrl(array $record): ?string
    {
        $attrs = $record['attributes'] ?? null;
        if (is_array($attrs) && isset($attrs['url']) && is_string($attrs['url']) && $attrs['url'] !== '') {
            return $attrs['url'];
        }
        return null;
    }
}

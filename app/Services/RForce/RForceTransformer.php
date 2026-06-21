<?php

declare(strict_types=1);

namespace App\Services\RForce;

use App\Models\ExternalSource;
use App\Models\RawRforceRecord;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Base class for object-specific transformers (Account/Contact/Opportunity/...).
 *
 * A transformer's job: take a single raw payload (gateway response shape) and
 * write a normalized row. Each concrete transformer declares its target model
 * and column mapping.
 *
 * Promoted columns are explicit. Anything not mapped lands in `extra` so we
 * never lose data and can promote new columns later by migration.
 */
abstract class RForceTransformer
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /**
     * Map a payload to the normalized table's column => value array.
     * Do NOT include id, external_source_id, external_id, timestamps, extra —
     * the base class handles those.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    abstract protected function map(array $payload): array;

    /**
     * Keys from the payload that have been promoted to first-class columns.
     * Anything not in this list goes into `extra`.
     *
     * @return array<int,string>
     */
    abstract protected function promotedKeys(): array;

    /**
     * Transform and persist a single raw record. Returns the normalized model.
     */
    public function transform(RawRforceRecord $raw, SyncRun $run): Model
    {
        $payload = $raw->payload ?? [];

        $extra = array_diff_key($payload, array_flip($this->promotedKeys()));

        /** @var class-string<Model> $modelClass */
        $modelClass = $this->model();

        $attributes = array_merge($this->map($payload), [
            'external_source_id' => $raw->external_source_id,
            'external_id'        => $raw->external_id,
            'extra'              => $extra ?: null,
            'last_synced_at'     => Carbon::now(),
        ]);

        /** @var Model $model */
        $model = $modelClass::query()->updateOrCreate(
            attributes: [
                'external_source_id' => $raw->external_source_id,
                'external_id'        => $raw->external_id,
            ],
            values: $attributes,
        );

        return $model;
    }

    /**
     * Transform a batch. Returns [transformed, failed].
     *
     * @param  iterable<RawRforceRecord>  $records
     * @return array{0:int,1:int}
     */
    public function transformBatch(iterable $records, SyncRun $run, ExternalSource $source): array
    {
        $ok = 0;
        $fail = 0;
        foreach ($records as $raw) {
            try {
                $this->transform($raw, $run);
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $run->errors()->create([
                    'object_type' => $raw->source_object_type,
                    'external_id' => $raw->external_id,
                    'category'    => 'transform',
                    'message'     => $e->getMessage(),
                    'context'     => ['exception' => $e::class],
                ]);
            }
        }

        return [$ok, $fail];
    }

    /**
     * Parse an ISO-ish date string, returning null on any failure.
     */
    protected function parseDate(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

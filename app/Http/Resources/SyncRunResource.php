<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SyncRun */
class SyncRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'external_source_id'  => $this->external_source_id,
            'query_definition_id' => $this->query_definition_id,
            'object_type'         => $this->object_type,
            'soql'                => $this->soql,
            'status'              => $this->status?->value,
            'since'               => optional($this->since)->toIso8601String(),
            'limit'               => $this->limit,
            'records' => [
                'received' => $this->records_received,
                'inserted' => $this->records_inserted,
                'changed'  => $this->records_changed,
                'skipped'  => $this->records_skipped,
                'failed'   => $this->records_failed,
                // legacy aggregate; kept for older consumers
                'upserted' => $this->records_upserted,
                'fetched'  => $this->records_fetched,
            ],
            'started_at'          => optional($this->started_at)->toIso8601String(),
            'finished_at'         => optional($this->finished_at)->toIso8601String(),
            'duration_ms'         => $this->duration_ms,
            'error_message'       => $this->error_message,
            'meta'                => $this->meta,
            'errors'              => SyncErrorResource::collection($this->whenLoaded('errors')),
        ];
    }
}

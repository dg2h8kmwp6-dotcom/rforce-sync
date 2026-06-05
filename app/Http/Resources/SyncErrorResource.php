<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SyncError;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SyncError */
class SyncErrorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'sync_run_id' => $this->sync_run_id,
            'object_type' => $this->object_type,
            'external_id' => $this->external_id,
            'category'    => $this->category,
            'message'     => $this->message,
            'context'     => $this->context,
            'created_at'  => optional($this->created_at)->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NormalizedOpportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NormalizedOpportunity */
class OpportunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'external_id'          => $this->external_id,
            'account_external_id'  => $this->account_external_id,
            'name'                 => $this->name,
            'stage'                => $this->stage,
            'type'                 => $this->type,
            'amount'               => $this->amount,
            'probability'          => $this->probability,
            'close_date'           => optional($this->close_date)->toDateString(),
            'is_won'               => $this->is_won,
            'is_closed'            => $this->is_closed,
            'forecast_category'    => $this->forecast_category,
            'owner_external_id'    => $this->owner_external_id,
            'external_modified_at' => optional($this->external_modified_at)->toIso8601String(),
            'last_synced_at'       => optional($this->last_synced_at)->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NormalizedAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NormalizedAccount */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'external_id'         => $this->external_id,
            'name'                => $this->name,
            'account_number'      => $this->account_number,
            'industry'            => $this->industry,
            'type'                => $this->type,
            'website'             => $this->website,
            'phone'               => $this->phone,
            'billing'             => [
                'street'      => $this->billing_street,
                'city'        => $this->billing_city,
                'state'       => $this->billing_state,
                'postal_code' => $this->billing_postal_code,
                'country'     => $this->billing_country,
            ],
            'annual_revenue'      => $this->annual_revenue,
            'employee_count'      => $this->employee_count,
            'owner_external_id'   => $this->owner_external_id,
            'external_modified_at' => optional($this->external_modified_at)->toIso8601String(),
            'last_synced_at'      => optional($this->last_synced_at)->toIso8601String(),
        ];
    }
}

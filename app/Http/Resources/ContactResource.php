<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NormalizedContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NormalizedContact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'external_id'          => $this->external_id,
            'account_external_id'  => $this->account_external_id,
            'first_name'           => $this->first_name,
            'last_name'            => $this->last_name,
            'title'                => $this->title,
            'email'                => $this->email,
            'phone'                => $this->phone,
            'mobile_phone'         => $this->mobile_phone,
            'mailing'              => [
                'street'      => $this->mailing_street,
                'city'        => $this->mailing_city,
                'state'       => $this->mailing_state,
                'postal_code' => $this->mailing_postal_code,
                'country'     => $this->mailing_country,
            ],
            'owner_external_id'    => $this->owner_external_id,
            'external_modified_at' => optional($this->external_modified_at)->toIso8601String(),
            'last_synced_at'       => optional($this->last_synced_at)->toIso8601String(),
        ];
    }
}

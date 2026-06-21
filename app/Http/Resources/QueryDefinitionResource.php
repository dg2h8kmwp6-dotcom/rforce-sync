<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\QueryDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QueryDefinition */
class QueryDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'description'       => $this->description,
            'soql'              => $this->soql,
            'target_dataset'    => $this->target_dataset,
            'transformer_class' => $this->transformer_class,
            'enabled'           => $this->enabled,
            'schedule'          => $this->schedule,
            'last_run_at'       => optional($this->last_run_at)->toIso8601String(),
            'created_at'        => optional($this->created_at)->toIso8601String(),
            'updated_at'        => optional($this->updated_at)->toIso8601String(),
        ];
    }
}

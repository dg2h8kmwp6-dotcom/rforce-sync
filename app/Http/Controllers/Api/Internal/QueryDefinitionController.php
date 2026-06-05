<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Resources\QueryDefinitionResource;
use App\Models\QueryDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class QueryDefinitionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'enabled'       => ['nullable', 'boolean'],
            'target_object' => ['nullable', 'string', 'max:64'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = QueryDefinition::query()->orderBy('name');

        if (array_key_exists('enabled', $validated)) {
            $query->where('enabled', (bool) $validated['enabled']);
        }
        if (! empty($validated['target_object'])) {
            $query->where('target_object', $validated['target_object']);
        }

        return QueryDefinitionResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 50)),
        );
    }

    public function show(QueryDefinition $queryDefinition): JsonResource
    {
        return new QueryDefinitionResource($queryDefinition);
    }
}

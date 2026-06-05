<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpportunityResource;
use App\Models\NormalizedOpportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OpportunityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q'                  => ['nullable', 'string', 'max:200'],
            'account_external_id' => ['nullable', 'string', 'max:64'],
            'stage'              => ['nullable', 'string', 'max:100'],
            'is_won'             => ['nullable', 'boolean'],
            'is_closed'          => ['nullable', 'boolean'],
            'modified_since'     => ['nullable', 'date'],
            'per_page'           => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = NormalizedOpportunity::query()->orderByDesc('close_date');

        if (! empty($validated['q'])) {
            $query->where('name', 'like', '%'.$validated['q'].'%');
        }
        if (! empty($validated['account_external_id'])) {
            $query->where('account_external_id', $validated['account_external_id']);
        }
        if (! empty($validated['stage'])) {
            $query->where('stage', $validated['stage']);
        }
        if (array_key_exists('is_won', $validated)) {
            $query->where('is_won', (bool) $validated['is_won']);
        }
        if (array_key_exists('is_closed', $validated)) {
            $query->where('is_closed', (bool) $validated['is_closed']);
        }
        if (! empty($validated['modified_since'])) {
            $query->where('external_modified_at', '>=', $validated['modified_since']);
        }

        return OpportunityResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 50)),
        );
    }
}

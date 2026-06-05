<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Models\NormalizedAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q'           => ['nullable', 'string', 'max:200'],
            'industry'    => ['nullable', 'string', 'max:100'],
            'modified_since' => ['nullable', 'date'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = NormalizedAccount::query()->orderBy('name');

        if (! empty($validated['q'])) {
            $query->where('name', 'like', '%'.$validated['q'].'%');
        }
        if (! empty($validated['industry'])) {
            $query->where('industry', $validated['industry']);
        }
        if (! empty($validated['modified_since'])) {
            $query->where('external_modified_at', '>=', $validated['modified_since']);
        }

        return AccountResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 50)),
        );
    }
}

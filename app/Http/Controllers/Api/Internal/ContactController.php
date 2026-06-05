<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\NormalizedContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q'                  => ['nullable', 'string', 'max:200'],
            'account_external_id' => ['nullable', 'string', 'max:64'],
            'email'              => ['nullable', 'string', 'max:200'],
            'modified_since'     => ['nullable', 'date'],
            'per_page'           => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = NormalizedContact::query()->orderBy('last_name');

        if (! empty($validated['q'])) {
            $q = '%'.$validated['q'].'%';
            $query->where(function ($builder) use ($q): void {
                $builder->where('first_name', 'like', $q)
                    ->orWhere('last_name', 'like', $q)
                    ->orWhere('email', 'like', $q);
            });
        }
        if (! empty($validated['account_external_id'])) {
            $query->where('account_external_id', $validated['account_external_id']);
        }
        if (! empty($validated['email'])) {
            $query->where('email', $validated['email']);
        }
        if (! empty($validated['modified_since'])) {
            $query->where('external_modified_at', '>=', $validated['modified_since']);
        }

        return ContactResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 50)),
        );
    }
}

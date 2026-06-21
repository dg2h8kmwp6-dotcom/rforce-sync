<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncRunResource;
use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SyncRunController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'target_dataset' => ['nullable', 'string', 'max:64'],
            'status'         => ['nullable', 'string', 'max:32'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = SyncRun::query()->latest('started_at');

        if (! empty($validated['target_dataset'])) {
            $query->where('target_dataset', $validated['target_dataset']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return SyncRunResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 25)),
        );
    }

    public function show(SyncRun $syncRun): SyncRunResource
    {
        $syncRun->load('errors');
        return new SyncRunResource($syncRun);
    }
}

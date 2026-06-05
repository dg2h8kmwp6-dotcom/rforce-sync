<!DOCTYPE html>
<html lang="en" class="bg-slate-50">
<head>
    <meta charset="UTF-8">
    <title>rForce Sync — Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- CDN keeps the dashboard build-step-free; swap for Vite once we care about prod weight. --}}
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="text-slate-800">

<header class="bg-white border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">rForce Sync</h1>
            <p class="text-sm text-slate-500">Internal data platform admin</p>
        </div>
        <div class="text-sm text-slate-500">
            MySQL is the source of truth.
        </div>
    </div>
</header>

<main class="max-w-6xl mx-auto px-6 py-8 space-y-10">

    @if (session('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    {{-- Sync Health --}}
    <section>
        <div class="flex items-baseline justify-between mb-3">
            <h2 class="text-lg font-semibold">Sync Health</h2>
            <span class="text-xs text-slate-500">Last 7 days</span>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            @php
                $statusOrder = ['succeeded', 'partial', 'failed', 'cancelled'];
                $statusColor = [
                    'succeeded' => 'bg-emerald-100 text-emerald-800',
                    'partial'   => 'bg-amber-100 text-amber-800',
                    'failed'    => 'bg-rose-100 text-rose-800',
                    'cancelled' => 'bg-slate-100 text-slate-700',
                    'running'   => 'bg-sky-100 text-sky-800',
                    'pending'   => 'bg-slate-100 text-slate-700',
                ];
            @endphp
            <div class="bg-white p-4 rounded-md border border-slate-200 col-span-2 md:col-span-1">
                <div class="text-xs uppercase tracking-wide text-slate-500">Success rate</div>
                <div class="text-3xl font-semibold mt-1">
                    {{ $successRate === null ? '—' : $successRate.'%' }}
                </div>
            </div>
            @foreach ($statusOrder as $key)
                <div class="bg-white p-4 rounded-md border border-slate-200">
                    <div class="text-xs uppercase tracking-wide text-slate-500">{{ $key }}</div>
                    <div class="text-2xl font-semibold mt-1">{{ $statusCounts[$key] ?? 0 }}</div>
                </div>
            @endforeach
        </div>

        <div class="bg-white rounded-md border border-slate-200 overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 text-slate-600 text-left">
                    <tr>
                        <th class="px-4 py-2">#</th>
                        <th class="px-4 py-2">Query</th>
                        <th class="px-4 py-2">Object</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2 text-right">Received</th>
                        <th class="px-4 py-2 text-right">Inserted</th>
                        <th class="px-4 py-2 text-right">Changed</th>
                        <th class="px-4 py-2 text-right">Failed</th>
                        <th class="px-4 py-2 text-right">Duration</th>
                        <th class="px-4 py-2">Started</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($recentRuns as $run)
                    <tr>
                        <td class="px-4 py-2 font-mono text-slate-500">#{{ $run->id }}</td>
                        <td class="px-4 py-2">{{ $run->queryDefinition?->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $run->object_type ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $statusColor[$run->status?->value] ?? 'bg-slate-100 text-slate-700' }}">
                                {{ $run->status?->value }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $run->records_received }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $run->records_inserted }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $run->records_changed }}</td>
                        <td class="px-4 py-2 text-right tabular-nums {{ $run->records_failed > 0 ? 'text-rose-700' : '' }}">
                            {{ $run->records_failed }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums text-slate-500">
                            {{ $run->duration_ms !== null ? number_format($run->duration_ms / 1000, 2).'s' : '—' }}
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ optional($run->started_at)->diffForHumans() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-6 text-center text-slate-400">No sync runs yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Query Definitions --}}
    <section>
        <div class="flex items-baseline justify-between mb-3">
            <h2 class="text-lg font-semibold">Saved SOQL Queries</h2>
            <span class="text-xs text-slate-500">Edit via API or seeder; toggle here.</span>
        </div>
        <div class="bg-white rounded-md border border-slate-200 overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 text-slate-600 text-left">
                    <tr>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">Target</th>
                        <th class="px-4 py-2">Schedule</th>
                        <th class="px-4 py-2">Last run</th>
                        <th class="px-4 py-2">Enabled</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($queries as $q)
                    <tr>
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ $q->name }}</div>
                            <div class="text-xs text-slate-500">{{ $q->description }}</div>
                            <code class="text-xs text-slate-500 block mt-1">{{ \Illuminate\Support\Str::limit($q->soql, 120) }}</code>
                        </td>
                        <td class="px-4 py-2">{{ $q->target_object ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $q->schedule ?? 'manual' }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ optional($q->last_run_at)->diffForHumans() ?? 'never' }}</td>
                        <td class="px-4 py-2">
                            @if ($q->enabled)
                                <span class="text-emerald-700 text-xs">● enabled</span>
                            @else
                                <span class="text-slate-400 text-xs">○ disabled</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <form method="POST" action="{{ route('admin.queries.toggle', $q) }}">
                                @csrf
                                <button type="submit" class="text-xs px-3 py-1 rounded border border-slate-300 hover:bg-slate-100">
                                    {{ $q->enabled ? 'Disable' : 'Enable' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">No saved queries yet — run <code>php artisan db:seed --class=QueryDefinitionSeeder</code>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Errors --}}
    <section>
        <div class="flex items-baseline justify-between mb-3">
            <h2 class="text-lg font-semibold">Recent Errors</h2>
            <span class="text-xs text-slate-500">Last 20</span>
        </div>
        <div class="bg-white rounded-md border border-slate-200 overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 text-slate-600 text-left">
                    <tr>
                        <th class="px-4 py-2">When</th>
                        <th class="px-4 py-2">Run</th>
                        <th class="px-4 py-2">Object</th>
                        <th class="px-4 py-2">Category</th>
                        <th class="px-4 py-2">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($errors as $err)
                    <tr>
                        <td class="px-4 py-2 text-slate-500">{{ optional($err->created_at)->diffForHumans() }}</td>
                        <td class="px-4 py-2 font-mono text-slate-500">#{{ $err->sync_run_id }}</td>
                        <td class="px-4 py-2">{{ $err->object_type ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs bg-rose-100 text-rose-800">{{ $err->category ?? 'error' }}</span>
                        </td>
                        <td class="px-4 py-2">{{ \Illuminate\Support\Str::limit($err->message, 160) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">No errors. Carry on.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Data metrics --}}
    <section>
        <h2 class="text-lg font-semibold mb-3">Data Metrics</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div class="bg-white p-4 rounded-md border border-slate-200">
                <div class="text-xs uppercase tracking-wide text-slate-500">Accounts</div>
                <div class="text-2xl font-semibold mt-1 tabular-nums">{{ number_format($metrics['totals']['accounts']) }}</div>
            </div>
            <div class="bg-white p-4 rounded-md border border-slate-200">
                <div class="text-xs uppercase tracking-wide text-slate-500">Contacts</div>
                <div class="text-2xl font-semibold mt-1 tabular-nums">{{ number_format($metrics['totals']['contacts']) }}</div>
            </div>
            <div class="bg-white p-4 rounded-md border border-slate-200">
                <div class="text-xs uppercase tracking-wide text-slate-500">Opportunities</div>
                <div class="text-2xl font-semibold mt-1 tabular-nums">{{ number_format($metrics['totals']['opportunities']) }}</div>
            </div>

            <div class="bg-white p-4 rounded-md border border-slate-200">
                <div class="text-xs uppercase tracking-wide text-slate-500">Received today</div>
                <div class="text-2xl font-semibold mt-1 tabular-nums">{{ number_format($metrics['today']['received']) }}</div>
            </div>
            <div class="bg-white p-4 rounded-md border border-slate-200">
                <div class="text-xs uppercase tracking-wide text-slate-500">Inserted today</div>
                <div class="text-2xl font-semibold mt-1 tabular-nums">{{ number_format($metrics['today']['inserted']) }}</div>
            </div>
            <div class="bg-white p-4 rounded-md border border-slate-200">
                <div class="text-xs uppercase tracking-wide text-slate-500">Changed today</div>
                <div class="text-2xl font-semibold mt-1 tabular-nums">{{ number_format($metrics['today']['changed']) }}</div>
            </div>
        </div>
    </section>
</main>

<footer class="max-w-6xl mx-auto px-6 py-6 text-xs text-slate-500">
    Built on Laravel. Auth is intentionally absent on this surface — gate it before deploying.
</footer>

</body>
</html>

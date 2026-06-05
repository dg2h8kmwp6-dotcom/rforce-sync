<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Models\NormalizedAccount;
use App\Models\NormalizedContact;
use App\Models\NormalizedOpportunity;
use App\Models\QueryDefinition;
use App\Models\SyncError;
use App\Models\SyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $recentRuns = SyncRun::query()
            ->with('queryDefinition')
            ->latest('started_at')
            ->limit(15)
            ->get();

        $statusCounts = SyncRun::query()
            ->where('started_at', '>=', Carbon::now()->subDays(7))
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $successRate = $this->successRate($statusCounts);

        $queries = QueryDefinition::query()->orderBy('name')->get();

        $errors = SyncError::query()
            ->with('syncRun')
            ->latest('id')
            ->limit(20)
            ->get();

        $metrics = [
            'totals' => [
                'accounts'      => NormalizedAccount::query()->count(),
                'contacts'      => NormalizedContact::query()->count(),
                'opportunities' => NormalizedOpportunity::query()->count(),
            ],
            'today' => [
                'changed'  => SyncRun::query()->whereDate('started_at', Carbon::today())->sum('records_changed'),
                'inserted' => SyncRun::query()->whereDate('started_at', Carbon::today())->sum('records_inserted'),
                'received' => SyncRun::query()->whereDate('started_at', Carbon::today())->sum('records_received'),
            ],
        ];

        return view('admin.dashboard', [
            'recentRuns'   => $recentRuns,
            'statusCounts' => $statusCounts,
            'successRate'  => $successRate,
            'queries'      => $queries,
            'errors'       => $errors,
            'metrics'      => $metrics,
        ]);
    }

    public function toggle(QueryDefinition $queryDefinition, Request $request): RedirectResponse
    {
        $queryDefinition->update(['enabled' => ! $queryDefinition->enabled]);
        return redirect()->route('admin.dashboard')
            ->with('status', "Query '{$queryDefinition->name}' is now ".($queryDefinition->enabled ? 'enabled' : 'disabled').'.');
    }

    /**
     * @param  array<string,int>  $statusCounts
     */
    private function successRate(array $statusCounts): ?float
    {
        $total = array_sum($statusCounts);
        if ($total === 0) {
            return null;
        }
        $ok = $statusCounts[SyncStatus::Succeeded->value] ?? 0;
        return round($ok / $total * 100, 1);
    }
}

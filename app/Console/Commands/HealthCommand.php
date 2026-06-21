<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Models\QueryDefinition;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Deployment diagnostic. Verifies the moving parts the sync depends on:
 *   - database connectivity (raw SELECT 1)
 *   - queue connectivity (probe via Queue::size on the configured queue)
 *   - configured gateway URL + pagination mode
 *   - enabled QueryDefinition count
 *   - last successful sync
 *   - stale ("running" past the configured window) run count
 *
 * Never makes an actual gateway call (that's rforce:test-connection) — this
 * command is safe to run on a misconfigured environment without burning
 * MuleSoft quota.
 */
class HealthCommand extends Command
{
    protected $signature = 'rforce:health
        {--json : Emit JSON instead of human-readable output.}';

    protected $description = 'Diagnostic snapshot of rForce sync dependencies and recent state.';

    public function handle(): int
    {
        $checks = [
            'database'        => $this->checkDatabase(),
            'queue'           => $this->checkQueue(),
            'gateway_config'  => $this->checkGatewayConfig(),
            'query_defs'      => $this->checkQueryDefinitions(),
            'last_success'    => $this->checkLastSuccess(),
            'stale_runs'      => $this->checkStaleRuns(),
        ];

        $ok = ! array_filter($checks, static fn (array $c): bool => ($c['status'] ?? 'ok') === 'fail');

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok'     => $ok,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $name => $check) {
            $status = $check['status'] ?? 'ok';
            $icon = match ($status) {
                'ok'   => '<info>[ ok ]</info>',
                'warn' => '<comment>[warn]</comment>',
                default=> '<error>[fail]</error>',
            };
            $this->line(sprintf('%s %-15s %s', $icon, $name, $check['message'] ?? ''));
            foreach (($check['detail'] ?? []) as $k => $v) {
                $this->line(sprintf('         %-13s %s', $k.':', is_scalar($v) || $v === null ? (string) $v : json_encode($v)));
            }
        }

        $this->line('');
        $this->line($ok ? '<info>Overall: healthy</info>' : '<error>Overall: failing</error>');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{status:string, message:string, detail?: array<string,mixed>}
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('select 1 as ok');
            $ms = (int) round((microtime(true) - $start) * 1000);
            return [
                'status'  => 'ok',
                'message' => 'connected',
                'detail'  => [
                    'driver'    => config('database.default'),
                    'latency_ms'=> $ms,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'fail',
                'message' => 'unreachable: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{status:string, message:string, detail?: array<string,mixed>}
     */
    private function checkQueue(): array
    {
        $connection = (string) config('queue.default');
        $queue      = (string) config('rforce.sync.queue', 'default');

        try {
            // Most drivers (database, redis, sqs) implement size(); 'sync'
            // returns 0 and that's still a positive signal.
            $size = Queue::connection($connection)->size($queue);
            return [
                'status'  => 'ok',
                'message' => 'reachable',
                'detail'  => [
                    'connection' => $connection,
                    'queue'      => $queue,
                    'pending'    => $size,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'fail',
                'message' => 'unreachable: '.$e->getMessage(),
                'detail'  => [
                    'connection' => $connection,
                    'queue'      => $queue,
                ],
            ];
        }
    }

    /**
     * @return array{status:string, message:string, detail: array<string,mixed>}
     */
    private function checkGatewayConfig(): array
    {
        $baseUrl     = (string) config('rforce.base_url', '');
        $environment = (string) config('rforce.environment', 'stage');
        $sfVersion   = (string) config('rforce.sf_version', 'v60.0');
        $mode        = (string) config('rforce.pagination.mode', 'header');

        $missingCreds = array_filter([
            'proxy_client_id'     => config('rforce.proxy_client_id'),
            'proxy_client_secret' => config('rforce.proxy_client_secret'),
            'sf_client_id'        => config('rforce.sf_client_id'),
            'sf_client_secret'    => config('rforce.sf_client_secret'),
        ], static fn ($v): bool => $v === null || $v === '');

        $status = $baseUrl === '' ? 'fail' : ($missingCreds !== [] ? 'warn' : 'ok');
        $message = match ($status) {
            'fail' => 'RFORCE_BASE_URL not configured',
            'warn' => 'missing credentials: '.implode(', ', array_keys($missingCreds)),
            default=> 'configured',
        };

        return [
            'status'  => $status,
            'message' => $message,
            'detail'  => [
                'environment'     => $environment,
                'base_url'        => $baseUrl,
                'sf_version'      => $sfVersion,
                'pagination_mode' => $mode,
            ],
        ];
    }

    /**
     * @return array{status:string, message:string, detail: array<string,mixed>}
     */
    private function checkQueryDefinitions(): array
    {
        try {
            $enabled = QueryDefinition::query()->where('enabled', true)->count();
            $total   = QueryDefinition::query()->count();
            $status  = $enabled === 0 ? 'warn' : 'ok';
            return [
                'status'  => $status,
                'message' => "{$enabled} enabled of {$total}",
                'detail'  => ['enabled' => $enabled, 'total' => $total],
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'fail',
                'message' => 'cannot read query_definitions: '.$e->getMessage(),
                'detail'  => [],
            ];
        }
    }

    /**
     * @return array{status:string, message:string, detail: array<string,mixed>}
     */
    private function checkLastSuccess(): array
    {
        try {
            /** @var SyncRun|null $latest */
            $latest = SyncRun::query()
                ->where('status', SyncStatus::Succeeded->value)
                ->latest('finished_at')
                ->first();

            if ($latest === null) {
                return [
                    'status'  => 'warn',
                    'message' => 'no successful run on record',
                    'detail'  => [],
                ];
            }

            $finishedAt = $latest->finished_at;
            $ageHours = $finishedAt !== null
                ? (int) Carbon::instance($finishedAt)->diffInHours(Carbon::now())
                : null;

            return [
                'status'  => 'ok',
                'message' => optional($finishedAt)->toIso8601String() ?? 'unknown',
                'detail'  => [
                    'sync_run_id'         => $latest->id,
                    'query_definition_id' => $latest->query_definition_id,
                    'age_hours'           => $ageHours,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'fail',
                'message' => 'cannot read sync_runs: '.$e->getMessage(),
                'detail'  => [],
            ];
        }
    }

    /**
     * @return array{status:string, message:string, detail: array<string,mixed>}
     */
    private function checkStaleRuns(): array
    {
        try {
            $hours = max(1, (int) config('rforce.sync.stale_after_hours', 2));
            $cutoff = Carbon::now()->subHours($hours);
            $count = SyncRun::query()
                ->where('status', SyncStatus::Running->value)
                ->where('started_at', '<', $cutoff)
                ->count();

            $status = $count > 0 ? 'warn' : 'ok';
            $message = $count > 0
                ? "{$count} run(s) stuck in running > {$hours}h — run rforce:cleanup-stale-runs"
                : "none stuck > {$hours}h";

            return [
                'status'  => $status,
                'message' => $message,
                'detail'  => [
                    'stale_after_hours' => $hours,
                    'count'             => $count,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'fail',
                'message' => 'cannot read sync_runs: '.$e->getMessage(),
                'detail'  => [],
            ];
        }
    }
}

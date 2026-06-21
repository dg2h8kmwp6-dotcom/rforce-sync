<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\SyncStatus;
use App\Models\QueryDefinition;
use App\Services\Sync\SyncRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_reports_ok_when_everything_is_wired(): void
    {
        config([
            'rforce.proxy_client_id'     => 'set',
            'rforce.proxy_client_secret' => 'set',
            'rforce.sf_client_id'        => 'set',
            'rforce.sf_client_secret'    => 'set',
            'rforce.base_url'            => 'https://example/api/rforce/control-layer/query',
        ]);

        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();
        QueryDefinition::query()->create([
            'external_source_id' => $source->id,
            'name'               => 'accounts.smoke',
            'soql'               => 'SELECT Name FROM Account LIMIT 1',
            'target_dataset'     => 'accounts',
            'enabled'            => true,
        ]);

        $run = $manager->start($source, 'accounts');
        $manager->finish($run, SyncStatus::Succeeded);

        $this->artisan('rforce:health')->assertSuccessful();
    }

    public function test_health_warns_on_missing_credentials_but_is_still_runnable(): void
    {
        config([
            'rforce.proxy_client_id'     => '',
            'rforce.proxy_client_secret' => '',
            'rforce.sf_client_id'        => 'set',
            'rforce.sf_client_secret'    => 'set',
            'rforce.base_url'            => 'https://example/api/rforce/control-layer/query',
        ]);

        // Warnings don't flip overall to fail.
        $this->artisan('rforce:health')->assertSuccessful();
    }

    public function test_health_fails_when_base_url_missing(): void
    {
        config(['rforce.base_url' => '']);

        $this->artisan('rforce:health')->assertFailed();
    }

    public function test_health_warns_when_stale_runs_exist(): void
    {
        config([
            'rforce.proxy_client_id'     => 'set',
            'rforce.proxy_client_secret' => 'set',
            'rforce.sf_client_id'        => 'set',
            'rforce.sf_client_secret'    => 'set',
            'rforce.base_url'            => 'https://example/api/rforce/control-layer/query',
            'rforce.sync.stale_after_hours' => 2,
        ]);

        $manager = new SyncRunManager();
        $source  = $manager->defaultSource();
        $stale   = $manager->start($source, 'accounts');
        $stale->forceFill(['started_at' => Carbon::now()->subHours(5)])->save();

        $this->artisan('rforce:health')
            ->expectsOutputToContain('stuck in running')
            ->assertSuccessful();
    }

    public function test_health_json_mode_emits_parseable_output(): void
    {
        config([
            'rforce.proxy_client_id'     => 'set',
            'rforce.proxy_client_secret' => 'set',
            'rforce.sf_client_id'        => 'set',
            'rforce.sf_client_secret'    => 'set',
            'rforce.base_url'            => 'https://example/api/rforce/control-layer/query',
        ]);

        \Illuminate\Support\Facades\Artisan::call('rforce:health', ['--json' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $payload = json_decode(trim($output), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('ok', $payload);
        $this->assertArrayHasKey('checks', $payload);
        $this->assertArrayHasKey('database', $payload['checks']);
        $this->assertArrayHasKey('queue', $payload['checks']);
        $this->assertArrayHasKey('gateway_config', $payload['checks']);
    }
}

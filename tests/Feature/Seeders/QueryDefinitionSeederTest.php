<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\QueryDefinition;
use Database\Seeders\QueryDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueryDefinitionSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        'accounts.smoke',
        'orders.incremental',
        'accounts.incremental',
        'contacts.incremental',
        'order_products.incremental',
        'products.incremental',
        'sales_appointments.incremental',
    ];

    public function test_seeds_the_full_starter_catalog(): void
    {
        $this->seed(QueryDefinitionSeeder::class);

        $names = QueryDefinition::query()->pluck('name')->all();
        sort($names);

        $expected = self::EXPECTED;
        sort($expected);

        $this->assertSame($expected, $names);
    }

    public function test_seeder_is_idempotent_across_multiple_runs(): void
    {
        $this->seed(QueryDefinitionSeeder::class);
        $firstCount = QueryDefinition::query()->count();

        $this->seed(QueryDefinitionSeeder::class);
        $this->seed(QueryDefinitionSeeder::class);

        $this->assertSame($firstCount, QueryDefinition::query()->count());
        $this->assertSame(count(self::EXPECTED), QueryDefinition::query()->count());
    }

    public function test_seeded_queries_carry_target_dataset_and_enabled_flag(): void
    {
        $this->seed(QueryDefinitionSeeder::class);

        foreach (self::EXPECTED as $name) {
            $def = QueryDefinition::query()->where('name', $name)->firstOrFail();
            $this->assertNotNull($def->target_dataset, "{$name} should have a target_dataset");
            $this->assertTrue((bool) $def->enabled, "{$name} should be enabled by default");
            $this->assertNotEmpty($def->soql, "{$name} should have SOQL");
        }
    }

    public function test_incremental_queries_use_last_sync_at_placeholder(): void
    {
        $this->seed(QueryDefinitionSeeder::class);

        $incrementals = array_filter(
            self::EXPECTED,
            static fn (string $n): bool => str_ends_with($n, '.incremental'),
        );

        foreach ($incrementals as $name) {
            $def = QueryDefinition::query()->where('name', $name)->firstOrFail();
            $this->assertStringContainsString(
                '{{last_sync_at}}',
                $def->soql,
                "{$name} should use the {{last_sync_at}} placeholder",
            );
        }
    }
}

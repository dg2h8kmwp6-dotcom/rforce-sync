<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExternalSource;
use App\Models\QueryDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds the saved SOQL queries that ship with the platform.
 *
 * Examples mirror what the Data Gateway docs show: a small smoke test and
 * an incremental Order pull that uses the {{last_sync_at}} placeholder.
 * The platform never string-concatenates raw user input into SOQL — every
 * placeholder is resolved to a SOQL-typed literal in
 * RForceQueryExecutor::resolvePlaceholders().
 *
 * Once business users can edit these in the admin UI, the seeder is only used
 * for fresh dev installs.
 */
class QueryDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $source = ExternalSource::query()->firstOrCreate(
            ['name' => (string) config('rforce.sync.source_name', 'rforce')],
            [
                'label'  => 'rForce / Salesforce via MuleSoft Data Gateway',
                'config' => ['base_url' => config('rforce.base_url')],
                'active' => true,
            ],
        );

        $defaults = [
            [
                'name'              => 'accounts.smoke',
                'description'       => 'Smoke test query — 100 account names. Safe for rforce:test-connection.',
                'soql'              => 'SELECT Name FROM Account LIMIT 100',
                'target_dataset'    => 'accounts',
                'transformer_class' => null,
                'schedule'          => null,
            ],
            [
                'name'              => 'orders.incremental',
                'description'       => 'All Order fields modified since the last successful run. Uses {{last_sync_at}}.',
                'soql'              => 'SELECT FIELDS(ALL) FROM Order WHERE LastModifiedDate > {{last_sync_at}}',
                'target_dataset'    => 'orders',
                'transformer_class' => null,
                'schedule'          => '0 * * * *',
            ],
        ];

        foreach ($defaults as $row) {
            QueryDefinition::query()->updateOrCreate(
                ['name' => $row['name']],
                array_merge($row, [
                    'external_source_id' => $source->id,
                    'enabled'            => true,
                ]),
            );
        }
    }
}

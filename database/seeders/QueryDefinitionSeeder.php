<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExternalSource;
use App\Models\QueryDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds the saved SOQL queries that ship with the platform.
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
                'label'  => 'rForce / Salesforce gateway',
                'config' => ['base_url' => config('rforce.base_url')],
                'active' => true,
            ],
        );

        $defaults = [
            [
                'name'          => 'accounts.all',
                'description'   => 'All accounts with the columns the normalized_accounts table promotes.',
                'soql'          => 'SELECT Id, Name, AccountNumber, Industry, Type, Website, Phone, BillingStreet, BillingCity, BillingState, BillingPostalCode, BillingCountry, AnnualRevenue, NumberOfEmployees, OwnerId, CreatedDate, LastModifiedDate FROM Account',
                'target_object' => 'Account',
                'schedule'      => '0 * * * *',
            ],
            [
                'name'          => 'contacts.all',
                'description'   => 'All contacts.',
                'soql'          => 'SELECT Id, AccountId, FirstName, LastName, Title, Email, Phone, MobilePhone, MailingStreet, MailingCity, MailingState, MailingPostalCode, MailingCountry, OwnerId, CreatedDate, LastModifiedDate FROM Contact',
                'target_object' => 'Contact',
                'schedule'      => '5 * * * *',
            ],
            [
                'name'          => 'opportunities.open',
                'description'   => 'Open opportunities (IsClosed = false). Edit to include closed if needed.',
                'soql'          => 'SELECT Id, AccountId, Name, StageName, Type, Amount, Probability, CloseDate, IsWon, IsClosed, ForecastCategoryName, OwnerId, CreatedDate, LastModifiedDate FROM Opportunity WHERE IsClosed = false',
                'target_object' => 'Opportunity',
                'schedule'      => '10 * * * *',
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

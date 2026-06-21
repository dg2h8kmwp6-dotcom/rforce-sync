<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExternalSource;
use App\Models\QueryDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds the starter SOQL catalog. These queries cover the first business
 * use cases (jobs/orders, customers, products, scheduling, materials) and
 * are intended for *validation*, not production use — most of the custom
 * (`*__c`) field names are best-guesses from the integration docs and may
 * need adjustment once we see real gateway responses.
 *
 * The seeder is idempotent: re-running it updates SOQL/descriptions but
 * preserves enabled / schedule overrides that an operator might have made
 * via the admin UI.
 *
 * See docs/query-catalog.md for per-query notes.
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

        foreach ($this->catalog() as $row) {
            QueryDefinition::query()->updateOrCreate(
                ['name' => $row['name']],
                array_merge(
                    [
                        'external_source_id' => $source->id,
                        'transformer_class'  => null,
                    ],
                    $row,
                ),
            );
        }
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function catalog(): array
    {
        return [
            [
                'name'           => 'accounts.smoke',
                'description'    => 'Smoke test: one Account name. Safe for rforce:test-connection.',
                'target_dataset' => 'accounts',
                'soql'           => 'SELECT Name FROM Account LIMIT 1',
                'enabled'        => true,
                'schedule'       => null,
            ],
            [
                'name'           => 'orders.incremental',
                'description'    => 'Active/recent Orders changed since the last successful run. Drives job/order dashboards.',
                'target_dataset' => 'orders',
                'soql'           => <<<'SOQL'
SELECT
    Id,
    Name,
    AccountId,
    EffectiveDate,
    Status,
    CreatedDate,
    LastModifiedDate,
    NumberProducts__c,
    Number_of_Units__c,
    Estimated_Install_Duration__c,
    Adjusted_Arrival_Date__c,
    Balance_Due__c,
    Amount_Due__c,
    Amount_Paid__c
FROM Order
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
SOQL,
                'enabled'        => true,
                'schedule'       => '*/15 * * * *',
            ],
            [
                'name'           => 'accounts.incremental',
                'description'    => 'Customers/Accounts changed since the last successful run.',
                'target_dataset' => 'accounts',
                'soql'           => <<<'SOQL'
SELECT
    Id,
    Name,
    Phone,
    ShippingAddress,
    BillingAddress,
    Store_Number__c,
    Active__c,
    Historical__c,
    Primary_Contact__c,
    Sales_Rep_User__c,
    LastModifiedDate
FROM Account
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
SOQL,
                'enabled'        => true,
                'schedule'       => '*/15 * * * *',
            ],
            [
                'name'           => 'contacts.incremental',
                'description'    => 'Contacts changed since the last successful run.',
                'target_dataset' => 'contacts',
                'soql'           => <<<'SOQL'
SELECT
    Id,
    AccountId,
    Name,
    FirstName,
    LastName,
    Email,
    Phone,
    MobilePhone,
    HomePhone,
    MailingAddress,
    Preferred_Method_of_Contact__c,
    Primary_Contact__c,
    LastModifiedDate
FROM Contact
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
SOQL,
                'enabled'        => true,
                'schedule'       => '*/15 * * * *',
            ],
            [
                'name'           => 'order_products.incremental',
                'description'    => 'Order line items / materials. Feeds material list builder.',
                'target_dataset' => 'order_products',
                'soql'           => <<<'SOQL'
SELECT
    Id,
    OrderId,
    Product2Id,
    Quantity,
    UnitPrice,
    TotalPrice,
    Product_Type__c,
    Exterior_Color__c,
    Interior_Color__c,
    Width__c,
    Height__c,
    Location__c,
    LastModifiedDate
FROM OrderItem
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
SOQL,
                'enabled'        => true,
                'schedule'       => '*/15 * * * *',
            ],
            [
                'name'           => 'products.incremental',
                'description'    => 'Product catalog changes. Slow-moving; safe at lower frequency.',
                'target_dataset' => 'products',
                'soql'           => <<<'SOQL'
SELECT
    Id,
    Name,
    ProductCode,
    Description,
    Family,
    Product_Type__c,
    Category__c,
    Sub_Category__c,
    IsActive,
    Unit_of_Measure__c,
    Width__c,
    Height__c,
    LastModifiedDate
FROM Product2
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
SOQL,
                'enabled'        => true,
                'schedule'       => '0 * * * *',
            ],
            [
                'name'           => 'sales_appointments.incremental',
                'description'    => 'Sales appointments — feeds scheduling map / routing / crew assignment.',
                'target_dataset' => 'sales_appointments',
                'soql'           => <<<'SOQL'
SELECT
    Id,
    Name,
    Account__c,
    Sales_Order__c,
    Appointment_Date_Time__c,
    Appointment_Day__c,
    Appointment_Time__c,
    Assigned__c,
    Cancelled__c,
    Sales_Territory__c,
    Store__c,
    LastModifiedDate
FROM Sales_Appointment__c
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
SOQL,
                'enabled'        => true,
                'schedule'       => '*/15 * * * *',
            ],
        ];
    }
}

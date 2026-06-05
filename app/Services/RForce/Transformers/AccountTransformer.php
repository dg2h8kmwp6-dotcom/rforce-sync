<?php

declare(strict_types=1);

namespace App\Services\RForce\Transformers;

use App\Models\NormalizedAccount;
use App\Services\RForce\RForceTransformer;

/**
 * Field mapping is a best-guess at the gateway's response shape (which mirrors
 * Salesforce REST). Update once we see real payloads.
 */
class AccountTransformer extends RForceTransformer
{
    protected function model(): string
    {
        return NormalizedAccount::class;
    }

    protected function map(array $payload): array
    {
        return [
            'name'                 => $payload['Name'] ?? null,
            'account_number'       => $payload['AccountNumber'] ?? null,
            'industry'             => $payload['Industry'] ?? null,
            'type'                 => $payload['Type'] ?? null,
            'website'              => $payload['Website'] ?? null,
            'phone'                => $payload['Phone'] ?? null,
            'billing_street'       => $payload['BillingStreet'] ?? null,
            'billing_city'         => $payload['BillingCity'] ?? null,
            'billing_state'        => $payload['BillingState'] ?? null,
            'billing_postal_code'  => $payload['BillingPostalCode'] ?? null,
            'billing_country'      => $payload['BillingCountry'] ?? null,
            'annual_revenue'       => isset($payload['AnnualRevenue']) ? (float) $payload['AnnualRevenue'] : null,
            'employee_count'       => isset($payload['NumberOfEmployees']) ? (int) $payload['NumberOfEmployees'] : null,
            'owner_external_id'    => $payload['OwnerId'] ?? null,
            'external_created_at'  => $this->parseDate($payload['CreatedDate'] ?? null),
            'external_modified_at' => $this->parseDate($payload['LastModifiedDate'] ?? null),
        ];
    }

    protected function promotedKeys(): array
    {
        return [
            'Id',
            'Name',
            'AccountNumber',
            'Industry',
            'Type',
            'Website',
            'Phone',
            'BillingStreet',
            'BillingCity',
            'BillingState',
            'BillingPostalCode',
            'BillingCountry',
            'AnnualRevenue',
            'NumberOfEmployees',
            'OwnerId',
            'CreatedDate',
            'LastModifiedDate',
        ];
    }
}

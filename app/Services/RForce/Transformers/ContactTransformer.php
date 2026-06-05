<?php

declare(strict_types=1);

namespace App\Services\RForce\Transformers;

use App\Models\NormalizedContact;
use App\Services\RForce\RForceTransformer;

class ContactTransformer extends RForceTransformer
{
    protected function model(): string
    {
        return NormalizedContact::class;
    }

    protected function map(array $payload): array
    {
        return [
            'account_external_id'  => $payload['AccountId'] ?? null,
            'first_name'           => $payload['FirstName'] ?? null,
            'last_name'            => $payload['LastName'] ?? null,
            'title'                => $payload['Title'] ?? null,
            'email'                => $payload['Email'] ?? null,
            'phone'                => $payload['Phone'] ?? null,
            'mobile_phone'         => $payload['MobilePhone'] ?? null,
            'mailing_street'       => $payload['MailingStreet'] ?? null,
            'mailing_city'         => $payload['MailingCity'] ?? null,
            'mailing_state'        => $payload['MailingState'] ?? null,
            'mailing_postal_code'  => $payload['MailingPostalCode'] ?? null,
            'mailing_country'      => $payload['MailingCountry'] ?? null,
            'owner_external_id'    => $payload['OwnerId'] ?? null,
            'external_created_at'  => $this->parseDate($payload['CreatedDate'] ?? null),
            'external_modified_at' => $this->parseDate($payload['LastModifiedDate'] ?? null),
        ];
    }

    protected function promotedKeys(): array
    {
        return [
            'Id',
            'AccountId',
            'FirstName',
            'LastName',
            'Title',
            'Email',
            'Phone',
            'MobilePhone',
            'MailingStreet',
            'MailingCity',
            'MailingState',
            'MailingPostalCode',
            'MailingCountry',
            'OwnerId',
            'CreatedDate',
            'LastModifiedDate',
        ];
    }
}

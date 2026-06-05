<?php

declare(strict_types=1);

namespace App\Services\RForce\Transformers;

use App\Models\NormalizedOpportunity;
use App\Services\RForce\RForceTransformer;

class OpportunityTransformer extends RForceTransformer
{
    protected function model(): string
    {
        return NormalizedOpportunity::class;
    }

    protected function map(array $payload): array
    {
        return [
            'account_external_id'  => $payload['AccountId'] ?? null,
            'name'                 => $payload['Name'] ?? null,
            'stage'                => $payload['StageName'] ?? null,
            'type'                 => $payload['Type'] ?? null,
            'amount'               => isset($payload['Amount']) ? (float) $payload['Amount'] : null,
            'probability'          => isset($payload['Probability']) ? (int) $payload['Probability'] : null,
            'close_date'           => $this->parseDate($payload['CloseDate'] ?? null)?->toDateString(),
            'is_won'               => isset($payload['IsWon']) ? (bool) $payload['IsWon'] : null,
            'is_closed'            => isset($payload['IsClosed']) ? (bool) $payload['IsClosed'] : null,
            'forecast_category'    => $payload['ForecastCategoryName'] ?? null,
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
            'Name',
            'StageName',
            'Type',
            'Amount',
            'Probability',
            'CloseDate',
            'IsWon',
            'IsClosed',
            'ForecastCategoryName',
            'OwnerId',
            'CreatedDate',
            'LastModifiedDate',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\RForce\DTO;

/**
 * The full SOQL query result, mirroring the Salesforce REST response shape
 * the Data Gateway forwards:
 *
 *     {
 *       "totalSize": 10000,
 *       "done": false,
 *       "nextRecordsUrl": "/services/data/v60.0/query/01g...-2000",
 *       "records": [ ... ]
 *     }
 *
 * `done = true` and `nextRecordsUrl = null` signal there are no more pages
 * to fetch. `rawResponse` is the decoded body in case a caller needs
 * fields outside the canonical four.
 */
final class RForceQueryResult
{
    /**
     * @param  array<int, array<string,mixed>>  $records
     * @param  array<string,mixed>              $rawResponse
     */
    public function __construct(
        public readonly int $totalSize,
        public readonly bool $done,
        public readonly ?string $nextRecordsUrl,
        public readonly array $records,
        public readonly array $rawResponse = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function hasMore(): bool
    {
        return $this->done === false && $this->nextRecordsUrl !== null && $this->nextRecordsUrl !== '';
    }

    /**
     * @param  array<string,mixed>  $body
     */
    public static function fromResponse(array $body): self
    {
        $records        = (array) ($body['records'] ?? []);
        $totalSize      = isset($body['totalSize']) ? (int) $body['totalSize'] : count($records);
        $done           = array_key_exists('done', $body) ? (bool) $body['done'] : true;
        $nextRecordsUrl = isset($body['nextRecordsUrl']) && is_string($body['nextRecordsUrl']) && $body['nextRecordsUrl'] !== ''
            ? $body['nextRecordsUrl']
            : null;

        return new self(
            totalSize:      $totalSize,
            done:           $done,
            nextRecordsUrl: $nextRecordsUrl,
            records:        array_values($records),
            rawResponse:    $body,
        );
    }
}

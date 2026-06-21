<?php

declare(strict_types=1);

namespace App\Services\RForce\DTO;

/**
 * One page of records from a paginated SOQL execution.
 *
 * Same shape as RForceQueryResult, but emitted by the iterator returned from
 * RForceQueryExecutor::executePaginated(): callers iterate pages instead of
 * combining them in memory.
 */
final class RForceRecordPage
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
        public readonly int $pageNumber,
        public readonly array $rawResponse = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public static function fromResult(RForceQueryResult $result, int $pageNumber): self
    {
        return new self(
            totalSize:      $result->totalSize,
            done:           $result->done,
            nextRecordsUrl: $result->nextRecordsUrl,
            records:        $result->records,
            pageNumber:     $pageNumber,
            rawResponse:    $result->rawResponse,
        );
    }
}

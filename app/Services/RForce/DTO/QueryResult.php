<?php

declare(strict_types=1);

namespace App\Services\RForce\DTO;

/**
 * A single page of records from the gateway.
 *
 * `nextCursor` is null when the gateway signals it has no more pages. The
 * exact cursor format is opaque — clients only round-trip it back.
 */
final class QueryResult
{
    /**
     * @param  array<int, array<string,mixed>>  $records
     * @param  array<string,mixed>              $meta
     */
    public function __construct(
        public readonly array $records,
        public readonly ?string $nextCursor = null,
        public readonly ?int $totalSize = null,
        public readonly array $meta = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}

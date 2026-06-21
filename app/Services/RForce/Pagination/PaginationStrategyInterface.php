<?php

declare(strict_types=1);

namespace App\Services\RForce\Pagination;

use Illuminate\Http\Client\PendingRequest;

/**
 * Encapsulates how a follow-up request encodes the previous response's
 * `nextRecordsUrl`. The gateway docs (PDF + screenshots) show the value
 * sent as an HTTP header; an environment variant could plausibly want it
 * as a query string parameter instead. Both shapes live behind this seam
 * so the assumption is testable and swappable without touching transport.
 */
interface PaginationStrategyInterface
{
    /**
     * The configuration name for this strategy (matches RFORCE_PAGINATION_MODE).
     */
    public function name(): string;

    /**
     * Apply the next-page pointer to an outbound PendingRequest. May return
     * either the original request (if mutation is in-place via withHeaders())
     * or a new PendingRequest from the builder.
     *
     * Returns a [PendingRequest, array<string,mixed>] tuple — the second
     * element is the query-string array the caller should pass to ->get().
     * Strategies that don't need query params return [].
     *
     * @return array{0: PendingRequest, 1: array<string,mixed>}
     */
    public function applyTo(PendingRequest $request, string $nextRecordsUrl): array;
}

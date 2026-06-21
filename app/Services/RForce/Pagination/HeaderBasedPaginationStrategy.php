<?php

declare(strict_types=1);

namespace App\Services\RForce\Pagination;

use Illuminate\Http\Client\PendingRequest;

/**
 * Sends the `nextRecordsUrl` value as an HTTP request header. This is the
 * default mode and matches the Renewal by Andersen MuleSoft Data Gateway
 * docs as inferred from the integration PDF + screenshots.
 *
 *     GET {base_url}
 *     nextRecordsUrl: /services/data/v60.0/query/01g4000000ABCDe-2000
 */
final class HeaderBasedPaginationStrategy implements PaginationStrategyInterface
{
    public function __construct(
        private readonly string $headerName = 'nextRecordsUrl',
    ) {}

    public function name(): string
    {
        return 'header';
    }

    public function applyTo(PendingRequest $request, string $nextRecordsUrl): array
    {
        return [
            $request->withHeaders([$this->headerName => $nextRecordsUrl]),
            [],
        ];
    }
}

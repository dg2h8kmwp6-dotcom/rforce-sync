<?php

declare(strict_types=1);

namespace App\Services\RForce\Pagination;

use Illuminate\Http\Client\PendingRequest;

/**
 * Sends the `nextRecordsUrl` value as a query-string parameter. Provided
 * as a fallback in case a gateway variant rejects the header form.
 *
 *     GET {base_url}?nextRecordsUrl=/services/data/v60.0/query/01g4000000ABCDe-2000
 */
final class QueryParameterPaginationStrategy implements PaginationStrategyInterface
{
    public function __construct(
        private readonly string $paramName = 'nextRecordsUrl',
    ) {}

    public function name(): string
    {
        return 'query_parameter';
    }

    public function applyTo(PendingRequest $request, string $nextRecordsUrl): array
    {
        return [
            $request,
            [$this->paramName => $nextRecordsUrl],
        ];
    }
}

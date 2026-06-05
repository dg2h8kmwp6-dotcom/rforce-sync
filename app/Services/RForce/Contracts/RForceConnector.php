<?php

declare(strict_types=1);

namespace App\Services\RForce\Contracts;

use App\Services\RForce\DTO\QueryResult;

/**
 * Low-level seam to the rForce/Salesforce gateway.
 *
 * Implementations are the *only* place that knows how to speak to the gateway —
 * URLs, auth headers, retry policy, JSON shape. Anything above this layer
 * (QueryExecutor, SyncManager, jobs) talks SOQL strings and QueryResult DTOs,
 * not HTTP.
 *
 * A fake connector is used in tests; another implementation could be dropped
 * in if the gateway is replaced.
 */
interface RForceConnector
{
    /**
     * Confirm credentials + reachability. MUST NOT throw on auth failure —
     * return false. Throw only for unexpected/transport errors.
     */
    public function ping(): bool;

    /**
     * Execute a SOQL query. Pagination is opaque to the caller: pass back the
     * previous result's `nextCursor` to fetch the next page.
     *
     * The connector is responsible for translating `$cursor` into whatever
     * shape the gateway expects (Salesforce-style `nextRecordsUrl`, an opaque
     * page token, etc.).
     */
    public function executeSoql(string $soql, ?string $cursor = null, ?int $pageSize = null): QueryResult;
}

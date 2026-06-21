<?php

declare(strict_types=1);

namespace App\Services\RForce\Contracts;

use App\Services\RForce\DTO\RForceQueryResult;

/**
 * Low-level seam to the MuleSoft Data Gateway that fronts rForce/Salesforce.
 *
 * Implementations are the *only* place that knows how to speak to the gateway —
 * URL, headers, retry policy, JSON shape. Anything above this layer
 * (QueryExecutor, SyncManager, jobs) talks SOQL strings and DTOs, not HTTP.
 *
 * The contract intentionally mirrors the Salesforce REST query response:
 *   - `query()` runs a fresh SOQL string (sent as `?q=`).
 *   - `queryMore()` follows a `nextRecordsUrl` returned by a previous call
 *     (sent as the `nextRecordsUrl` HTTP header per the gateway docs).
 *
 * A fake connector is used in tests; another implementation could be dropped
 * in if the gateway is replaced.
 */
interface RForceConnector
{
    /**
     * Confirm credentials + reachability by running a minimal SOQL query.
     * MUST NOT throw on auth failure — return false. Throw only for
     * unexpected/transport errors.
     */
    public function ping(): bool;

    /**
     * Run a fresh SOQL statement. The connector is responsible for sending
     * the proper MuleSoft headers and the `q` query parameter.
     */
    public function query(string $soql): RForceQueryResult;

    /**
     * Follow a Salesforce-style `nextRecordsUrl` from a previous result.
     * The gateway expects this value via the `nextRecordsUrl` header — no
     * `q` parameter is sent.
     */
    public function queryMore(string $nextRecordsUrl): RForceQueryResult;
}

<?php

declare(strict_types=1);

namespace App\Services\RForce;

use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\QueryResult;
use App\Services\RForce\Exceptions\RForceAuthException;
use App\Services\RForce\Exceptions\RForceException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * HTTP-based connector to the rForce gateway.
 *
 * Pure transport. Knows: URLs, auth header, retries, pagination response shape.
 * Does NOT know: which SOQL to run, what to do with the records, how to
 * orchestrate a sync. All of that lives upstack in QueryExecutor/SyncManager.
 *
 * Validate against the real gateway:
 *  - Auth header (`Authorization: Bearer ...`). If the gateway uses
 *    `X-Api-Key`, change the request builder below.
 *  - Endpoint path. SOQL is POSTed to `/query` as `{"q": "..."}` here.
 *    Salesforce REST uses GET `/services/data/vXX.X/query?q=...`; tweak
 *    `executeSoql()` if the gateway prefers that.
 *  - Pagination. Salesforce returns `nextRecordsUrl`; gateways often expose
 *    a `nextPageToken`. `parseQueryResponse()` accepts both.
 *  - 401 handling: we force-refresh the token once and retry.
 */
class RForceClient implements RForceConnector
{
    /**
     * @param  array{
     *     timeout:int,
     *     retry_attempts:int,
     *     retry_sleep:int,
     *     retry_when?:array<int,int>,
     *  }  $httpConfig
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly RForceAuthenticator $auth,
        private readonly string $baseUrl,
        private readonly array $httpConfig,
    ) {}

    public function ping(): bool
    {
        try {
            $this->auth->token();
            return true;
        } catch (RForceAuthException) {
            return false;
        }
    }

    public function executeSoql(string $soql, ?string $cursor = null, ?int $pageSize = null): QueryResult
    {
        $body = array_filter([
            'q'        => $soql,
            'cursor'   => $cursor,
            'pageSize' => $pageSize,
        ], static fn ($v): bool => $v !== null && $v !== '');

        $response = $this->sendWithAuthRetry(fn (PendingRequest $req): Response =>
            $req->post($this->url('/query'), $body)
        );

        if (! $response->successful()) {
            throw new RForceException(
                "rForce SOQL query failed (status={$response->status()}): ".$response->body(),
            );
        }

        return $this->parseQueryResponse($response);
    }

    /**
     * Centralized request execution: applies auth, configures retry/timeout,
     * refreshes the token on 401, and retries once on transient HTTP statuses.
     *
     * @param  callable(PendingRequest):Response  $send
     */
    private function sendWithAuthRetry(callable $send): Response
    {
        $attempt = function (bool $forceRefresh) use ($send): Response {
            $token = $this->auth->token(forceRefresh: $forceRefresh);

            $request = $this->http
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout($this->httpConfig['timeout'])
                ->retry(
                    times:             max(1, $this->httpConfig['retry_attempts']),
                    sleepMilliseconds: $this->httpConfig['retry_sleep'],
                    when:              fn (Throwable $e): bool => $e instanceof \Illuminate\Http\Client\ConnectionException,
                    throw:             false,
                );

            return $send($request);
        };

        $response = $attempt(false);

        if ($response->status() === 401) {
            $response = $attempt(true);
        }

        if (in_array($response->status(), $this->httpConfig['retry_when'] ?? [], true)) {
            usleep($this->httpConfig['retry_sleep'] * 1000);
            $response = $attempt(false);
        }

        return $response;
    }

    private function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    private function parseQueryResponse(Response $response): QueryResult
    {
        $data = (array) $response->json();

        $records = (array) ($data['records'] ?? []);
        $cursor  = $data['nextPageToken'] ?? $data['nextRecordsUrl'] ?? null;
        $cursor  = is_string($cursor) && $cursor !== '' ? $cursor : null;
        $totalSize = isset($data['totalSize']) ? (int) $data['totalSize'] : null;

        return new QueryResult(
            records:    array_values($records),
            nextCursor: $cursor,
            totalSize:  $totalSize,
            meta:       array_diff_key($data, array_flip(['records', 'nextPageToken', 'nextRecordsUrl', 'totalSize'])),
        );
    }
}

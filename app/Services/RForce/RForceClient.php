<?php

declare(strict_types=1);

namespace App\Services\RForce;

use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\RForceQueryResult;
use App\Services\RForce\Exceptions\RForceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * HTTP transport to the Renewal by Andersen MuleSoft Data Gateway.
 *
 * The gateway exposes a single SOQL query endpoint:
 *
 *     GET  {base_url}?q=<SOQL>
 *
 * Authentication is *not* handled here — MuleSoft owns the Salesforce OAuth
 * dance internally. The client only forwards proxy + Salesforce credentials
 * as HTTP headers and lets the gateway do the rest.
 *
 * Response shape mirrors the Salesforce REST query API:
 *
 *     { totalSize, done, nextRecordsUrl?, records: [...] }
 *
 * Pagination uses the `nextRecordsUrl` from the previous response, sent back
 * as a header (no `q` parameter) per the gateway docs.
 */
class RForceClient implements RForceConnector
{
    /**
     * @param  array{
     *     proxy_client_id?: ?string,
     *     proxy_client_secret?: ?string,
     *     sf_client_id?: ?string,
     *     sf_client_secret?: ?string,
     *     sf_version?: ?string,
     *     sf_environment_url?: ?string,
     *     timeout?: int,
     *     retry_attempts?: int,
     *     retry_sleep?: int,
     *     retry_when?: array<int,int>,
     *  }  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly array $config,
    ) {}

    public function ping(): bool
    {
        try {
            $this->query('SELECT Name FROM Account LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function query(string $soql): RForceQueryResult
    {
        $response = $this->send(fn (PendingRequest $req): Response =>
            $req->get($this->baseUrl, ['q' => $soql])
        );

        return $this->parse($response, context: ['soql' => $soql]);
    }

    public function queryMore(string $nextRecordsUrl): RForceQueryResult
    {
        $response = $this->send(fn (PendingRequest $req): Response =>
            $req->withHeaders(['nextRecordsUrl' => $nextRecordsUrl])->get($this->baseUrl)
        );

        return $this->parse($response, context: ['nextRecordsUrl' => $nextRecordsUrl]);
    }

    /**
     * Build a PendingRequest with the standard MuleSoft headers, run it
     * through Laravel's retry helper, and bubble transport errors up as
     * RForceException.
     *
     * @param  callable(PendingRequest):Response  $send
     */
    private function send(callable $send): Response
    {
        $timeout       = (int) ($this->config['timeout'] ?? 300);
        $retryAttempts = max(1, (int) ($this->config['retry_attempts'] ?? 3));
        $retrySleep    = (int) ($this->config['retry_sleep'] ?? 10000);
        $retryWhen     = $this->config['retry_when'] ?? [408, 425, 429, 500, 502, 503, 504];

        $request = $this->http
            ->withHeaders($this->headers())
            ->acceptJson()
            ->timeout($timeout)
            ->retry(
                times:             $retryAttempts,
                sleepMilliseconds: $retrySleep,
                when:              static fn (Throwable $e): bool => $e instanceof ConnectionException,
                throw:             false,
            );

        try {
            $response = $send($request);
        } catch (ConnectionException $e) {
            throw new RForceException('Data Gateway request failed: '.$e->getMessage(), 0, $e);
        }

        // Manual retry on transient HTTP statuses (Http::retry only retries on
        // transport exceptions when `throw=false`, not on bad statuses).
        $attempts = 1;
        while ($attempts < $retryAttempts && in_array($response->status(), $retryWhen, true)) {
            usleep($retrySleep * 1000);
            try {
                $response = $send($request);
            } catch (ConnectionException $e) {
                throw new RForceException('Data Gateway request failed: '.$e->getMessage(), 0, $e);
            }
            $attempts++;
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'proxy_client_id'     => (string) ($this->config['proxy_client_id'] ?? ''),
            'proxy_client_secret' => (string) ($this->config['proxy_client_secret'] ?? ''),
            'sf_client_id'        => (string) ($this->config['sf_client_id'] ?? ''),
            'sf_client_secret'    => (string) ($this->config['sf_client_secret'] ?? ''),
            'sf_version'          => (string) ($this->config['sf_version'] ?? 'v60.0'),
        ];

        $envUrl = (string) ($this->config['sf_environment_url'] ?? '');
        if ($envUrl !== '') {
            $headers['sf_environment_url'] = $envUrl;
        }

        return $headers;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function parse(Response $response, array $context = []): RForceQueryResult
    {
        if (! $response->successful()) {
            throw new RForceException(sprintf(
                'Data Gateway returned HTTP %d: %s',
                $response->status(),
                $this->snippet($response->body()),
            ));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RForceException(
                'Data Gateway returned a non-JSON response: '.$this->snippet($response->body()),
            );
        }

        return RForceQueryResult::fromResponse($body);
    }

    private function snippet(string $body, int $max = 500): string
    {
        $body = trim($body);
        return strlen($body) > $max ? substr($body, 0, $max).'…' : $body;
    }
}

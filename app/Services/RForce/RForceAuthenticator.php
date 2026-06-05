<?php

declare(strict_types=1);

namespace App\Services\RForce;

use App\Services\RForce\Exceptions\RForceAuthException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Acquires bearer tokens for the rForce gateway.
 *
 * Three strategies are sketched out:
 *   - oauth_client_credentials: standard server-to-server
 *   - oauth_password:           username/password grant (legacy Salesforce flow)
 *   - static_token:             gateway issues a long-lived bearer
 *
 * TODO: confirm which strategies the gateway actually supports and prune the rest.
 *       Field names below are best-guesses; verify against gateway docs.
 */
class RForceAuthenticator
{
    private const CACHE_KEY = 'rforce.access_token';

    /**
     * @param  array<string,mixed>  $config  config('rforce.auth')
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
        private readonly array $config,
        private readonly int $httpTimeout = 30,
    ) {}

    /**
     * Return a valid bearer token, refreshing if cache is empty or near expiry.
     *
     * @throws RForceAuthException
     */
    public function token(bool $forceRefresh = false): string
    {
        if ($forceRefresh) {
            $this->cache->forget(self::CACHE_KEY);
        }

        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->fetchFreshToken();
        // Cache for slightly less than the token's lifetime to avoid edge-of-expiry races.
        $ttl = max(60, (int) ($token['expires_in'] ?? 3600) - (int) ($this->config['refresh_skew'] ?? 60));
        $this->cache->put(self::CACHE_KEY, $token['access_token'], $ttl);

        return $token['access_token'];
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array{access_token: string, expires_in?: int}
     *
     * @throws RForceAuthException
     */
    private function fetchFreshToken(): array
    {
        $strategy = (string) ($this->config['strategy'] ?? 'oauth_client_credentials');

        if ($strategy === 'static_token') {
            $token = (string) ($this->config['static_token'] ?? '');
            if ($token === '') {
                throw new RForceAuthException('RFORCE_STATIC_TOKEN is not set.');
            }

            return ['access_token' => $token, 'expires_in' => 86400];
        }

        $url = (string) ($this->config['url'] ?? '');
        if ($url === '') {
            throw new RForceAuthException('RFORCE_AUTH_URL is not set.');
        }

        $payload = match ($strategy) {
            'oauth_client_credentials' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => (string) ($this->config['client_id'] ?? ''),
                'client_secret' => (string) ($this->config['client_secret'] ?? ''),
            ],
            'oauth_password' => [
                'grant_type'    => 'password',
                'client_id'     => (string) ($this->config['client_id'] ?? ''),
                'client_secret' => (string) ($this->config['client_secret'] ?? ''),
                'username'      => (string) ($this->config['username'] ?? ''),
                'password'      => (string) ($this->config['password'] ?? ''),
            ],
            default => throw new RForceAuthException("Unknown auth strategy: {$strategy}"),
        };

        try {
            $response = $this->http
                ->asForm()
                ->timeout($this->httpTimeout)
                ->acceptJson()
                ->post($url, $payload);
        } catch (Throwable $e) {
            throw new RForceAuthException('rForce auth request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            Log::warning('rForce auth rejected', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RForceAuthException("rForce auth returned HTTP {$response->status()}.");
        }

        $data = (array) $response->json();
        if (! isset($data['access_token']) || ! is_string($data['access_token'])) {
            throw new RForceAuthException('rForce auth response missing access_token.');
        }

        return [
            'access_token' => $data['access_token'],
            'expires_in'   => isset($data['expires_in']) ? (int) $data['expires_in'] : 3600,
        ];
    }
}

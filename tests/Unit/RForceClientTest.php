<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RForce\RForceAuthenticator;
use App\Services\RForce\RForceClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RForceClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function client(int $retryAttempts = 1): RForceClient
    {
        $auth = Mockery::mock(RForceAuthenticator::class);
        $auth->shouldReceive('token')->andReturn('test-token');

        return new RForceClient(
            http:       app(HttpFactory::class),
            auth:       $auth,
            baseUrl:    'https://gw.example/rforce',
            httpConfig: [
                'timeout'        => 5,
                'retry_attempts' => $retryAttempts,
                'retry_sleep'    => 1,
                'retry_when'     => [503],
            ],
        );
    }

    public function test_execute_soql_posts_query_with_bearer_token(): void
    {
        Http::fake([
            'https://gw.example/rforce/query' => Http::response([
                'records'       => [['Id' => 'A1'], ['Id' => 'A2']],
                'nextPageToken' => 'page-2',
                'totalSize'     => 100,
            ], 200),
        ]);

        $result = $this->client()->executeSoql('SELECT Id FROM Account', null, 250);

        $this->assertSame(['A1', 'A2'], array_column($result->records, 'Id'));
        $this->assertSame('page-2', $result->nextCursor);
        $this->assertSame(100, $result->totalSize);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            return $request->url() === 'https://gw.example/rforce/query'
                && $request->method() === 'POST'
                && ($request->header('Authorization')[0] ?? '') === 'Bearer test-token'
                && ($body['q'] ?? null) === 'SELECT Id FROM Account'
                && ($body['pageSize'] ?? null) === 250;
        });
    }

    public function test_execute_soql_accepts_salesforce_style_next_records_url(): void
    {
        Http::fake([
            'https://gw.example/rforce/query' => Http::response([
                'records'        => [['Id' => 'A1']],
                'nextRecordsUrl' => '/services/data/v60.0/query/01g4000000ABCDe-2000',
                'totalSize'      => 5000,
            ], 200),
        ]);

        $result = $this->client()->executeSoql('SELECT Id FROM Account');

        $this->assertSame('/services/data/v60.0/query/01g4000000ABCDe-2000', $result->nextCursor);
    }

    public function test_execute_soql_refreshes_token_on_401_and_retries(): void
    {
        Http::fakeSequence('https://gw.example/rforce/query')
            ->push(['error' => 'unauthorized'], 401)
            ->push(['records' => [], 'nextPageToken' => null], 200);

        $auth = Mockery::mock(RForceAuthenticator::class);
        $auth->shouldReceive('token')->twice()->andReturn('stale', 'fresh');

        $client = new RForceClient(
            http:    app(HttpFactory::class),
            auth:    $auth,
            baseUrl: 'https://gw.example/rforce',
            httpConfig: ['timeout' => 5, 'retry_attempts' => 1, 'retry_sleep' => 1, 'retry_when' => []],
        );

        $result = $client->executeSoql('SELECT Id FROM Account');
        $this->assertSame([], $result->records);
    }

    public function test_execute_soql_retries_on_transient_5xx(): void
    {
        Http::fakeSequence('https://gw.example/rforce/query')
            ->push(['error' => 'busy'], 503)
            ->push(['records' => [['Id' => 'A1']], 'nextPageToken' => null], 200);

        $result = $this->client()->executeSoql('SELECT Id FROM Account');
        $this->assertSame([['Id' => 'A1']], $result->records);
    }

    public function test_execute_soql_throws_on_persistent_failure(): void
    {
        Http::fake([
            'https://gw.example/rforce/query' => Http::response(['error' => 'kaboom'], 500),
        ]);

        $this->expectException(\App\Services\RForce\Exceptions\RForceException::class);
        $this->client()->executeSoql('SELECT Id FROM Account');
    }
}

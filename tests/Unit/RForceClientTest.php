<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RForce\Exceptions\RForceException;
use App\Services\RForce\RForceClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RForceClientTest extends TestCase
{
    private const URL = 'https://gw.example/api/rforce/control-layer/query';

    private function client(array $overrides = []): RForceClient
    {
        $config = array_merge([
            'proxy_client_id'     => 'PROXY_ID',
            'proxy_client_secret' => 'PROXY_SECRET',
            'sf_client_id'        => 'SF_ID',
            'sf_client_secret'    => 'SF_SECRET',
            'sf_version'          => 'v60.0',
            'sf_environment_url'  => null,
            'timeout'             => 5,
            'retry_attempts'      => 1,
            'retry_sleep'         => 1,
            'retry_when'          => [503],
        ], $overrides);

        return new RForceClient(
            http:    app(HttpFactory::class),
            baseUrl: self::URL,
            config:  $config,
        );
    }

    public function test_query_sends_soql_as_q_parameter_with_mulesoft_headers(): void
    {
        Http::fake([
            self::URL.'*' => Http::response([
                'totalSize'      => 2,
                'done'           => true,
                'records'        => [
                    ['Id' => 'A1', 'Name' => 'Acme'],
                    ['Id' => 'A2', 'Name' => 'Globex'],
                ],
            ], 200),
        ]);

        $result = $this->client()->query('SELECT Name FROM Account LIMIT 10');

        $this->assertSame(2, $result->totalSize);
        $this->assertTrue($result->done);
        $this->assertNull($result->nextRecordsUrl);
        $this->assertSame(['A1', 'A2'], array_column($result->records, 'Id'));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), self::URL)
                && $request->data() === ['q' => 'SELECT Name FROM Account LIMIT 10']
                && $request->header('proxy_client_id')[0] === 'PROXY_ID'
                && $request->header('proxy_client_secret')[0] === 'PROXY_SECRET'
                && $request->header('sf_client_id')[0] === 'SF_ID'
                && $request->header('sf_client_secret')[0] === 'SF_SECRET'
                && $request->header('sf_version')[0] === 'v60.0';
        });
    }

    public function test_sf_environment_url_header_is_omitted_when_blank(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(['totalSize' => 0, 'done' => true, 'records' => []], 200),
        ]);

        $this->client()->query('SELECT Name FROM Account LIMIT 1');

        Http::assertSent(static fn (Request $r): bool =>
            $r->header('sf_environment_url') === [],
        );
    }

    public function test_sf_environment_url_header_is_sent_when_configured(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(['totalSize' => 0, 'done' => true, 'records' => []], 200),
        ]);

        $this->client(['sf_environment_url' => 'https://my-sf.example'])
            ->query('SELECT Name FROM Account LIMIT 1');

        Http::assertSent(static fn (Request $r): bool =>
            ($r->header('sf_environment_url')[0] ?? null) === 'https://my-sf.example',
        );
    }

    public function test_query_more_sends_next_records_url_header_without_q_parameter(): void
    {
        Http::fake([
            self::URL.'*' => Http::response([
                'totalSize'      => 5000,
                'done'           => true,
                'records'        => [['Id' => 'A100']],
            ], 200),
        ]);

        $this->client()->queryMore('/services/data/v60.0/query/01g4000000ABCDe-2000');

        Http::assertSent(static function (Request $r): bool {
            return $r->method() === 'GET'
                && ($r->header('nextRecordsUrl')[0] ?? null) === '/services/data/v60.0/query/01g4000000ABCDe-2000'
                && $r->data() === [];
        });
    }

    public function test_query_returns_pagination_state_when_more_pages_exist(): void
    {
        Http::fake([
            self::URL.'*' => Http::response([
                'totalSize'      => 10000,
                'done'           => false,
                'nextRecordsUrl' => '/services/data/v60.0/query/01g...-2000',
                'records'        => [['Id' => 'A1']],
            ], 200),
        ]);

        $result = $this->client()->query('SELECT Name FROM Account');

        $this->assertFalse($result->done);
        $this->assertSame('/services/data/v60.0/query/01g...-2000', $result->nextRecordsUrl);
        $this->assertTrue($result->hasMore());
    }

    public function test_non_2xx_response_throws_rforce_exception(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(['error' => 'invalid SOQL'], 400),
        ]);

        $this->expectException(RForceException::class);
        $this->expectExceptionMessageMatches('/HTTP 400/');
        $this->client()->query('SELECT FROM Account');
    }

    public function test_non_json_response_throws_rforce_exception(): void
    {
        Http::fake([
            self::URL.'*' => Http::response('<html>503</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(RForceException::class);
        $this->expectExceptionMessageMatches('/non-JSON/');
        $this->client()->query('SELECT Name FROM Account LIMIT 1');
    }

    public function test_retries_on_transient_5xx(): void
    {
        Http::fakeSequence(self::URL.'*')
            ->push(['error' => 'busy'], 503)
            ->push(['totalSize' => 1, 'done' => true, 'records' => [['Id' => 'A1']]], 200);

        $result = $this->client(['retry_attempts' => 2])->query('SELECT Name FROM Account');

        $this->assertSame([['Id' => 'A1']], $result->records);
    }

    public function test_sf_version_defaults_to_v60_when_not_provided(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(['totalSize' => 0, 'done' => true, 'records' => []], 200),
        ]);

        // Wipe sf_version; the client should still send the default.
        $client = $this->client(['sf_version' => null]);
        $client->query('SELECT Name FROM Account');

        Http::assertSent(static fn (Request $r): bool => $r->header('sf_version')[0] === 'v60.0');
    }
}

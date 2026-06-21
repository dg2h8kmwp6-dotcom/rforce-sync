<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RForce\Pagination\HeaderBasedPaginationStrategy;
use App\Services\RForce\Pagination\QueryParameterPaginationStrategy;
use App\Services\RForce\RForceClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaginationStrategyTest extends TestCase
{
    private const URL = 'https://gw.example/api/rforce/control-layer/query';

    public function test_header_strategy_returns_correct_name(): void
    {
        $this->assertSame('header', (new HeaderBasedPaginationStrategy())->name());
    }

    public function test_query_parameter_strategy_returns_correct_name(): void
    {
        $this->assertSame('query_parameter', (new QueryParameterPaginationStrategy())->name());
    }

    public function test_header_strategy_sends_next_records_url_as_header(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(['totalSize' => 0, 'done' => true, 'records' => []], 200),
        ]);

        $client = $this->client(new HeaderBasedPaginationStrategy());
        $client->queryMore('/services/data/v60.0/query/01g...-2000');

        Http::assertSent(static function (Request $r): bool {
            return $r->method() === 'GET'
                && ($r->header('nextRecordsUrl')[0] ?? null) === '/services/data/v60.0/query/01g...-2000'
                && $r->data() === [];
        });
    }

    public function test_query_parameter_strategy_sends_next_records_url_as_query_param(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(['totalSize' => 0, 'done' => true, 'records' => []], 200),
        ]);

        $client = $this->client(new QueryParameterPaginationStrategy());
        $client->queryMore('/services/data/v60.0/query/01g...-2000');

        Http::assertSent(static function (Request $r): bool {
            return $r->method() === 'GET'
                && $r->header('nextRecordsUrl') === []
                && ($r->data()['nextRecordsUrl'] ?? null) === '/services/data/v60.0/query/01g...-2000';
        });
    }

    public function test_header_strategy_respects_custom_header_name(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(['totalSize' => 0, 'done' => true, 'records' => []], 200),
        ]);

        $client = $this->client(new HeaderBasedPaginationStrategy('X-Next-Page'));
        $client->queryMore('/abc');

        Http::assertSent(static fn (Request $r): bool => ($r->header('X-Next-Page')[0] ?? null) === '/abc');
    }

    public function test_query_parameter_strategy_respects_custom_param_name(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(['totalSize' => 0, 'done' => true, 'records' => []], 200),
        ]);

        $client = $this->client(new QueryParameterPaginationStrategy('next'));
        $client->queryMore('/abc');

        Http::assertSent(static fn (Request $r): bool => ($r->data()['next'] ?? null) === '/abc');
    }

    public function test_initial_query_is_unaffected_by_pagination_strategy(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(['totalSize' => 0, 'done' => true, 'records' => []], 200),
        ]);

        $client = $this->client(new QueryParameterPaginationStrategy());
        $client->query('SELECT Name FROM Account LIMIT 1');

        Http::assertSent(static function (Request $r): bool {
            return $r->method() === 'GET'
                && ($r->data()['q'] ?? null) === 'SELECT Name FROM Account LIMIT 1'
                && ! isset($r->data()['nextRecordsUrl'])
                && $r->header('nextRecordsUrl') === [];
        });
    }

    private function client(\App\Services\RForce\Pagination\PaginationStrategyInterface $strategy): RForceClient
    {
        return new RForceClient(
            http:    app(HttpFactory::class),
            baseUrl: self::URL,
            config:  [
                'proxy_client_id'     => 'PROXY_ID',
                'proxy_client_secret' => 'PROXY_SECRET',
                'sf_client_id'        => 'SF_ID',
                'sf_client_secret'    => 'SF_SECRET',
                'sf_version'          => 'v60.0',
                'timeout'             => 5,
                'retry_attempts'      => 1,
                'retry_sleep'         => 1,
                'retry_when'          => [],
            ],
            pagination: $strategy,
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\QueryDefinition;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\RForceQueryResult;
use App\Services\RForce\DTO\RForceRecordPage;
use App\Services\RForce\RForceQueryExecutor;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RForceQueryExecutorTest extends TestCase
{
    public function test_execute_returns_single_page_result(): void
    {
        $connector = new FakeConnector([
            new RForceQueryResult(totalSize: 2, done: true, nextRecordsUrl: null, records: [['Id' => 'A1'], ['Id' => 'A2']]),
        ]);

        $result = (new RForceQueryExecutor($connector))->execute('SELECT Id FROM Account');

        $this->assertSame(2, $result->totalSize);
        $this->assertTrue($result->done);
        $this->assertCount(2, $result->records);
        $this->assertSame(['SELECT Id FROM Account'], $connector->queryCalls);
        $this->assertSame([], $connector->queryMoreCalls);
    }

    public function test_execute_paginated_walks_next_records_url_chain(): void
    {
        $connector = new FakeConnector([
            new RForceQueryResult(totalSize: 4, done: false, nextRecordsUrl: '/q/p2', records: [['Id' => 'A1']]),
            new RForceQueryResult(totalSize: 4, done: false, nextRecordsUrl: '/q/p3', records: [['Id' => 'A2'], ['Id' => 'A3']]),
            new RForceQueryResult(totalSize: 4, done: true,  nextRecordsUrl: null,    records: [['Id' => 'A4']]),
        ]);

        /** @var array<int, RForceRecordPage> $pages */
        $pages = iterator_to_array((new RForceQueryExecutor($connector))->executePaginated('SELECT Id FROM Account'));

        $this->assertCount(3, $pages);
        $this->assertSame([1, 2, 3], array_map(fn (RForceRecordPage $p): int => $p->pageNumber, $pages));
        $this->assertSame(['SELECT Id FROM Account'], $connector->queryCalls);
        $this->assertSame(['/q/p2', '/q/p3'], $connector->queryMoreCalls);
        $this->assertSame(['A1', 'A2', 'A3', 'A4'], array_merge(
            ...array_map(fn (RForceRecordPage $p): array => array_column($p->records, 'Id'), $pages),
        ));
    }

    public function test_stream_records_stops_at_max(): void
    {
        $connector = new FakeConnector([
            new RForceQueryResult(4, false, '/q/p2', [['Id' => 'A1'], ['Id' => 'A2']]),
            new RForceQueryResult(4, true,  null,    [['Id' => 'A3'], ['Id' => 'A4']]),
        ]);
        $executor = new RForceQueryExecutor($connector);

        $ids = [];
        foreach ($executor->streamRecords('SELECT Id FROM Account', maxRecords: 3) as $record) {
            $ids[] = $record['Id'];
        }

        $this->assertSame(['A1', 'A2', 'A3'], $ids);
    }

    public function test_build_soql_injects_since_predicate(): void
    {
        $soql = RForceQueryExecutor::buildSoql(
            'SELECT Id FROM Account',
            since: Carbon::parse('2026-05-01T00:00:00Z'),
        );

        $this->assertStringContainsString('WHERE LastModifiedDate > 2026-05-01T00:00:00', $soql);
    }

    public function test_build_soql_appends_predicate_with_AND_when_where_exists(): void
    {
        $soql = RForceQueryExecutor::buildSoql(
            "SELECT Id FROM Account WHERE Industry = 'Energy'",
            since: Carbon::parse('2026-05-01T00:00:00Z'),
        );

        $this->assertStringContainsString("WHERE Industry = 'Energy' AND (LastModifiedDate > 2026-05-01T00:00:00", $soql);
    }

    public function test_build_soql_preserves_order_by_and_limit_position(): void
    {
        $soql = RForceQueryExecutor::buildSoql(
            'SELECT Id FROM Account ORDER BY Name DESC',
            since: Carbon::parse('2026-05-01T00:00:00Z'),
            limit: 25,
        );

        $wherePos = strpos($soql, 'WHERE');
        $orderPos = strpos($soql, 'ORDER BY');
        $limitPos = strpos($soql, 'LIMIT 25');

        $this->assertNotFalse($wherePos);
        $this->assertNotFalse($orderPos);
        $this->assertNotFalse($limitPos);
        $this->assertLessThan($orderPos, $wherePos);
        $this->assertLessThan($limitPos, $orderPos);
    }

    public function test_build_soql_does_not_override_author_specified_limit(): void
    {
        $soql = RForceQueryExecutor::buildSoql('SELECT Id FROM Account LIMIT 10', limit: 5000);

        $this->assertStringContainsString('LIMIT 10', $soql);
        $this->assertStringNotContainsString('LIMIT 5000', $soql);
    }

    public function test_build_soql_resolves_last_sync_at_placeholder_from_since(): void
    {
        $since = Carbon::parse('2026-06-01T00:00:00Z');
        $soql  = RForceQueryExecutor::buildSoql(
            'SELECT FIELDS(ALL) FROM Order WHERE LastModifiedDate > {{last_sync_at}}',
            since: $since,
        );

        $this->assertStringNotContainsString('{{last_sync_at}}', $soql);
        $this->assertStringContainsString('LastModifiedDate > 2026-06-01T00:00:00', $soql);
    }

    public function test_build_soql_resolves_last_sync_at_from_query_definition_last_run_at(): void
    {
        $def = new QueryDefinition();
        $def->soql        = 'SELECT FIELDS(ALL) FROM Order WHERE LastModifiedDate > {{last_sync_at}}';
        $def->last_run_at = Carbon::parse('2026-05-15T08:30:00Z');

        $soql = RForceQueryExecutor::buildSoql($def);

        $this->assertStringNotContainsString('{{last_sync_at}}', $soql);
        $this->assertStringContainsString('2026-05-15T08:30:00', $soql);
    }

    public function test_build_soql_accepts_query_definition(): void
    {
        $def = new QueryDefinition();
        $def->soql = 'SELECT Id, Name FROM Account';

        $soql = RForceQueryExecutor::buildSoql($def, limit: 100);

        $this->assertSame('SELECT Id, Name FROM Account LIMIT 100', $soql);
    }
}

final class FakeConnector implements RForceConnector
{
    public array $queryCalls = [];
    public array $queryMoreCalls = [];

    /** @param array<int, RForceQueryResult> $pages */
    public function __construct(private array $pages, private int $cursor = 0) {}

    public function ping(): bool
    {
        return true;
    }

    public function query(string $soql): RForceQueryResult
    {
        $this->queryCalls[] = $soql;
        return $this->next();
    }

    public function queryMore(string $nextRecordsUrl): RForceQueryResult
    {
        $this->queryMoreCalls[] = $nextRecordsUrl;
        return $this->next();
    }

    private function next(): RForceQueryResult
    {
        if ($this->cursor >= count($this->pages)) {
            throw new \RuntimeException('FakeConnector exhausted.');
        }
        return $this->pages[$this->cursor++];
    }
}

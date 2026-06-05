<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\QueryDefinition;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\QueryResult;
use App\Services\RForce\RForceQueryExecutor;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RForceQueryExecutorTest extends TestCase
{
    public function test_stream_pages_walks_cursor_chain_to_completion(): void
    {
        $executor = new RForceQueryExecutor(new FakeConnector([
            new QueryResult([['Id' => 'A1']], nextCursor: 'p2'),
            new QueryResult([['Id' => 'A2'], ['Id' => 'A3']], nextCursor: 'p3'),
            new QueryResult([['Id' => 'A4']], nextCursor: null),
        ]));

        $pages = iterator_to_array($executor->streamPages('SELECT Id FROM Account'));

        $this->assertCount(3, $pages);
        $this->assertSame(['A1', 'A2', 'A3', 'A4'], array_merge(
            ...array_map(fn ($p) => array_column($p->records, 'Id'), $pages),
        ));
    }

    public function test_stream_records_stops_at_max(): void
    {
        $executor = new RForceQueryExecutor(new FakeConnector([
            new QueryResult([['Id' => 'A1'], ['Id' => 'A2']], nextCursor: 'p2'),
            new QueryResult([['Id' => 'A3'], ['Id' => 'A4']], nextCursor: 'p3'),
        ]));

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
            'SELECT Id FROM Account WHERE Industry = \'Energy\'',
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

        // WHERE must come before ORDER BY.
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
        $soql = RForceQueryExecutor::buildSoql(
            'SELECT Id FROM Account LIMIT 10',
            limit: 5000,
        );

        $this->assertStringContainsString('LIMIT 10', $soql);
        $this->assertStringNotContainsString('LIMIT 5000', $soql);
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
    /** @param array<int, QueryResult> $pages */
    public function __construct(private array $pages, private int $cursor = 0) {}

    public function ping(): bool
    {
        return true;
    }

    public function executeSoql(string $soql, ?string $cursor = null, ?int $pageSize = null): QueryResult
    {
        if ($this->cursor >= count($this->pages)) {
            throw new \RuntimeException('FakeConnector exhausted.');
        }
        return $this->pages[$this->cursor++];
    }
}

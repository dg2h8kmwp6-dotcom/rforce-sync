<?php

declare(strict_types=1);

namespace App\Services\RForce;

use App\Models\QueryDefinition;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\QueryResult;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Carbon;

/**
 * Runs SOQL queries through the connector, taking care of pagination and the
 * incremental / limit conveniences callers reach for.
 *
 * Sits above RForceClient (transport) and below RForceSyncManager
 * (orchestration). Has no opinion about raw_rforce_records or sync state —
 * its only job is "give me the records this SOQL produces".
 */
class RForceQueryExecutor
{
    public function __construct(
        private readonly RForceConnector $connector,
    ) {}

    /**
     * Walk all pages for a SOQL statement, yielding one QueryResult per page.
     *
     * @return Generator<int, QueryResult>
     */
    public function streamPages(string $soql, ?int $pageSize = null): Generator
    {
        $cursor = null;
        do {
            $page = $this->connector->executeSoql($soql, $cursor, $pageSize);
            yield $page;
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    /**
     * Flatten pages into a single stream of records, optionally capped.
     *
     * @return Generator<int, array<string,mixed>>
     */
    public function streamRecords(string $soql, ?int $maxRecords = null, ?int $pageSize = null): Generator
    {
        $emitted = 0;
        foreach ($this->streamPages($soql, $pageSize) as $page) {
            foreach ($page->records as $record) {
                if ($maxRecords !== null && $emitted >= $maxRecords) {
                    return;
                }
                yield $record;
                $emitted++;
            }
        }
    }

    /**
     * Run a single page, no pagination. Useful for `--dry-run`: caller gets
     * totalSize plus the first batch of records to eyeball.
     */
    public function firstPage(string $soql, ?int $pageSize = null): QueryResult
    {
        return $this->connector->executeSoql($soql, null, $pageSize);
    }

    /**
     * Resolve a QueryDefinition into the SOQL string that should actually run,
     * after applying optional `since` (LastModifiedDate filter) and `limit`.
     *
     * Adding clauses by string concatenation is intentionally conservative; we
     * only touch SOQL that doesn't already declare the clause. If a saved
     * query already specifies LIMIT or WHERE LastModifiedDate, we leave it
     * alone so the author's intent wins.
     */
    public static function buildSoql(QueryDefinition|string $source, ?DateTimeInterface $since = null, ?int $limit = null): string
    {
        $soql = trim($source instanceof QueryDefinition ? $source->soql : $source);
        // Strip trailing semicolon if present — SOQL doesn't require it but humans add it.
        $soql = rtrim($soql, ';');

        if ($since !== null) {
            $iso = Carbon::instance($since)->toIso8601String();
            if (! preg_match('/\bLastModifiedDate\s*[><=]/i', $soql)) {
                $soql = self::injectWhere($soql, "LastModifiedDate > {$iso}");
            }
        }

        if ($limit !== null && $limit > 0 && ! preg_match('/\bLIMIT\s+\d+/i', $soql)) {
            $soql .= " LIMIT {$limit}";
        }

        return $soql;
    }

    /**
     * Append a predicate to the WHERE clause (or create one) without disturbing
     * an existing ORDER BY / LIMIT.
     */
    private static function injectWhere(string $soql, string $predicate): string
    {
        // Find the position of the first ORDER BY or LIMIT, if any — predicate
        // must land before them.
        $tail = '';
        if (preg_match('/\b(ORDER\s+BY|LIMIT|OFFSET)\b/i', $soql, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1];
            $tail   = ' '.trim(substr($soql, $offset));
            $soql   = rtrim(substr($soql, 0, $offset));
        }

        if (preg_match('/\bWHERE\b/i', $soql)) {
            $soql .= " AND ({$predicate})";
        } else {
            $soql .= " WHERE {$predicate}";
        }

        return $soql.$tail;
    }
}

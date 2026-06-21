<?php

declare(strict_types=1);

namespace App\Services\RForce;

use App\Models\QueryDefinition;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\DTO\RForceQueryResult;
use App\Services\RForce\DTO\RForceRecordPage;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Carbon;

/**
 * Runs SOQL queries through the connector, taking care of pagination and a
 * handful of placeholder/SOQL conveniences callers reach for.
 *
 * Sits above RForceClient (HTTP transport) and below RForceSyncManager
 * (orchestration). Has no opinion about raw_rforce_records or sync state —
 * its only job is "give me the records this SOQL produces".
 *
 * Pagination follows Salesforce's `nextRecordsUrl` shape: when a result has
 * `done = false`, the next call goes through queryMore() with the URL the
 * gateway returned. The connector decides how to encode that on the wire.
 */
class RForceQueryExecutor
{
    /**
     * Tokens we recognize in QueryDefinition SOQL. Resolved at run time;
     * never interpolated as raw user input — see resolvePlaceholders().
     */
    public const PLACEHOLDER_LAST_SYNC_AT = '{{last_sync_at}}';
    public const PLACEHOLDER_NOW          = '{{now}}';

    public function __construct(
        private readonly RForceConnector $connector,
    ) {}

    /**
     * Execute a single SOQL statement and return the gateway's response as a
     * RForceQueryResult. Does NOT follow `nextRecordsUrl` — use
     * executePaginated() if you want every page.
     */
    public function execute(string $soql): RForceQueryResult
    {
        return $this->connector->query($soql);
    }

    /**
     * Walk all pages for a SOQL statement, yielding one RForceRecordPage per
     * page. The first page comes from query(); subsequent pages come from
     * queryMore() with the `nextRecordsUrl` returned by the previous page.
     *
     * @return iterable<int, RForceRecordPage>
     */
    public function executePaginated(string $soql): iterable
    {
        $pageNumber = 1;
        $result     = $this->connector->query($soql);
        yield RForceRecordPage::fromResult($result, $pageNumber);

        while ($result->hasMore()) {
            $pageNumber++;
            /** @var string $nextRecordsUrl — guaranteed non-null by hasMore() */
            $nextRecordsUrl = $result->nextRecordsUrl;
            $result = $this->connector->queryMore($nextRecordsUrl);
            yield RForceRecordPage::fromResult($result, $pageNumber);
        }
    }

    /**
     * Flatten pages into a single stream of records, optionally capped.
     *
     * @return Generator<int, array<string,mixed>>
     */
    public function streamRecords(string $soql, ?int $maxRecords = null): Generator
    {
        $emitted = 0;
        foreach ($this->executePaginated($soql) as $page) {
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
     * Resolve a QueryDefinition into the SOQL string that should actually run:
     * substitute supported `{{...}}` placeholders, append optional `since`
     * (LastModifiedDate filter) and `limit` clauses.
     *
     * Placeholder substitution is value-typed (ISO timestamps, integers) —
     * raw user input is never spliced into SOQL.
     */
    public static function buildSoql(
        QueryDefinition|string $source,
        ?DateTimeInterface $since = null,
        ?int $limit = null,
    ): string {
        $definition = $source instanceof QueryDefinition ? $source : null;
        $soql       = trim($source instanceof QueryDefinition ? $source->soql : $source);
        // Strip trailing semicolon if present — SOQL doesn't require it but humans add it.
        $soql = rtrim($soql, ';');

        $soql = self::resolvePlaceholders($soql, $definition, $since);

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
     * Replace recognized placeholders with SOQL-safe literal values.
     *
     * Supported:
     *   {{last_sync_at}} → ISO-8601 timestamp; falls back to the explicit
     *                      $since arg, then to QueryDefinition.last_run_at,
     *                      then to a far-past sentinel.
     *   {{now}}          → ISO-8601 timestamp at the moment of resolution.
     *
     * Unknown placeholders are left untouched so the SOQL fails loudly at the
     * gateway rather than silently stripping content.
     */
    public static function resolvePlaceholders(
        string $soql,
        ?QueryDefinition $definition = null,
        ?DateTimeInterface $since = null,
    ): string {
        if (! str_contains($soql, '{{')) {
            return $soql;
        }

        $lastSync = $since
            ?? $definition?->last_run_at
            ?? Carbon::createFromTimestamp(0);
        $lastSyncIso = Carbon::instance($lastSync)->toIso8601String();
        $nowIso      = Carbon::now()->toIso8601String();

        return strtr($soql, [
            self::PLACEHOLDER_LAST_SYNC_AT => $lastSyncIso,
            self::PLACEHOLDER_NOW          => $nowIso,
        ]);
    }

    /**
     * Append a predicate to the WHERE clause (or create one) without disturbing
     * an existing ORDER BY / LIMIT.
     */
    private static function injectWhere(string $soql, string $predicate): string
    {
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

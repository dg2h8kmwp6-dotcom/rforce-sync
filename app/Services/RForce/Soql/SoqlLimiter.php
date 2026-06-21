<?php

declare(strict_types=1);

namespace App\Services\RForce\Soql;

/**
 * Adjusts the `LIMIT` clause on a SOQL string without disturbing the rest
 * of the query.
 *
 * Used by rforce:validate-queries to cap starter queries to a tiny page for
 * dry-run validation, and anywhere else a fixed upper bound is needed.
 *
 *   SoqlLimiter::apply('SELECT Id FROM Account', 10)
 *     → 'SELECT Id FROM Account LIMIT 10'
 *
 *   SoqlLimiter::apply('SELECT Id FROM Account LIMIT 500', 10)
 *     → 'SELECT Id FROM Account LIMIT 10'
 *
 *   SoqlLimiter::apply('SELECT Id FROM Account WHERE LastModifiedDate > {{last_sync_at}}', 10)
 *     → 'SELECT Id FROM Account WHERE LastModifiedDate > {{last_sync_at}} LIMIT 10'
 *
 * Placeholders are left untouched — this helper never resolves `{{...}}`,
 * which is RForceQueryExecutor::resolvePlaceholders()' job.
 *
 * SOQL also has an `OFFSET` clause that conventionally follows `LIMIT`. If
 * one is present we splice the new limit in just before it so the offset
 * survives unchanged.
 */
final class SoqlLimiter
{
    /**
     * Apply (replace or append) a LIMIT clause to a SOQL string.
     *
     * Returns the original string when $limit is null or non-positive — the
     * caller's contract is "no smaller than this", and "no limit" means "no
     * change".
     */
    public static function apply(string $soql, ?int $limit): string
    {
        if ($limit === null || $limit <= 0) {
            return $soql;
        }

        $trimmed = rtrim(rtrim($soql), ';');

        if (self::hasLimit($trimmed)) {
            return self::replaceLimit($trimmed, $limit);
        }

        // No LIMIT — append. If an OFFSET is present (it shouldn't be without
        // a LIMIT in standard SOQL, but be defensive) insert before it.
        if (preg_match('/\bOFFSET\b/i', $trimmed, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1];
            $head   = rtrim(substr($trimmed, 0, $offset));
            $tail   = ' '.trim(substr($trimmed, $offset));
            return $head." LIMIT {$limit}".$tail;
        }

        return $trimmed." LIMIT {$limit}";
    }

    public static function hasLimit(string $soql): bool
    {
        return (bool) preg_match('/\bLIMIT\s+\d+\b/i', $soql);
    }

    /**
     * Lower the LIMIT to at most $cap. Used when "validate" wants to be sure
     * a query won't pull a full page even if the saved LIMIT is larger.
     */
    public static function cap(string $soql, int $cap): string
    {
        if ($cap <= 0) {
            return $soql;
        }
        if (! self::hasLimit($soql)) {
            return self::apply($soql, $cap);
        }
        return preg_replace_callback(
            '/\bLIMIT\s+(\d+)\b/i',
            static fn (array $m): string => 'LIMIT '.min((int) $m[1], $cap),
            $soql,
            limit: 1,
        ) ?? $soql;
    }

    private static function replaceLimit(string $soql, int $limit): string
    {
        return preg_replace(
            '/\bLIMIT\s+\d+\b/i',
            "LIMIT {$limit}",
            $soql,
            limit: 1,
        ) ?? $soql;
    }
}

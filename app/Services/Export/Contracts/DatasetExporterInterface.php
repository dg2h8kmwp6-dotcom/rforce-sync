<?php

declare(strict_types=1);

namespace App\Services\Export\Contracts;

/**
 * Generic dataset export sink.
 *
 * MySQL is the source of truth; this interface is the seam through which
 * normalized rows can be pushed to *any* downstream system without coupling
 * the rest of the codebase to that system's SDK.
 *
 *      MySQL ─→ DatasetExporterInterface ─→ Google Sheets | S3 | BI tool | ...
 *
 * Google Sheets is the first (and currently only) concrete target, but the
 * interface stays vendor-neutral so the admin UI / scheduler / job layer
 * never need to know what's on the other side.
 *
 * Implementations MUST be idempotent: re-running an export with the same
 * rows should produce the same target state, not append duplicates.
 */
interface DatasetExporterInterface
{
    /**
     * Replace the contents of a logical dataset target with the given rows.
     *
     * @param  string                                $dataset  Logical name (e.g. "customers").
     * @param  array<int, array<string,mixed>>       $rows     Each row is an assoc array; keys become headers.
     * @param  array<string,mixed>                   $options  Sink-specific options (spreadsheet id, range, ...).
     */
    public function export(string $dataset, array $rows, array $options = []): void;

    /**
     * Stable identifier of this exporter (e.g. "google_sheets", "noop").
     * Used by admin/health endpoints to report which target is wired up.
     */
    public function name(): string;

    /**
     * Whether the exporter is configured + ready to actually push data.
     * False means export() is a no-op.
     */
    public function isEnabled(): bool;
}

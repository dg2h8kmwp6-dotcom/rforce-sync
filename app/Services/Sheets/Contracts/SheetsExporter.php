<?php

declare(strict_types=1);

namespace App\Services\Sheets\Contracts;

use App\Services\Export\Contracts\DatasetExporterInterface;

/**
 * Sheets-flavored marker for the generic dataset exporter.
 *
 * Kept as its own interface so callers can type-hint specifically against a
 * "Sheets" sink when they care (and only when they care). Most code should
 * depend on DatasetExporterInterface instead.
 */
interface SheetsExporter extends DatasetExporterInterface
{
}

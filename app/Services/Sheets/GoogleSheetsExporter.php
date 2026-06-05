<?php

declare(strict_types=1);

namespace App\Services\Sheets;

use App\Services\Sheets\Contracts\SheetsExporter;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Placeholder Google Sheets exporter.
 *
 * Intentionally NOT wired to google/apiclient yet — credentials/auth flow is
 * still open with the customer. The interface is real, so callers (jobs,
 * commands) can be built against it today and a real implementation dropped
 * in later without changing them.
 *
 * To make this real:
 *   1. composer require google/apiclient
 *   2. Drop a service-account JSON at GOOGLE_SHEETS_CREDENTIALS_PATH
 *   3. Replace the body of export() with Google_Service_Sheets::values->update()
 *
 * Architectural note: Sheets is an *export sink*, not a database. Reads should
 * always come from MySQL via the internal API. Pushing back into MySQL from
 * Sheets is explicitly out of scope to keep the source-of-truth clear.
 */
class GoogleSheetsExporter implements SheetsExporter
{
    public function __construct(
        private readonly bool $enabled,
        private readonly ?string $spreadsheetId,
        private readonly ?string $credentialsPath,
    ) {}

    public function name(): string
    {
        return 'google_sheets';
    }

    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->spreadsheetId !== null
            && $this->spreadsheetId !== ''
            && $this->credentialsPath !== null
            && is_file($this->credentialsPath);
    }

    public function export(string $dataset, array $rows, array $options = []): void
    {
        if (! $this->enabled) {
            Log::info('GoogleSheetsExporter disabled — skipping export', [
                'dataset' => $dataset,
                'rows'    => count($rows),
            ]);
            return;
        }

        if ($this->spreadsheetId === null || $this->spreadsheetId === '') {
            throw new RuntimeException('GOOGLE_SHEETS_SPREADSHEET_ID is not set.');
        }

        if ($this->credentialsPath === null || ! is_file($this->credentialsPath)) {
            throw new RuntimeException('GOOGLE_SHEETS_CREDENTIALS_PATH is missing or unreadable.');
        }

        // TODO: implement real export.
        //   $client = new \Google_Client();
        //   $client->setAuthConfig($this->credentialsPath);
        //   $client->addScope(\Google_Service_Sheets::SPREADSHEETS);
        //   $service = new \Google_Service_Sheets($client);
        //   $body = new \Google_Service_Sheets_ValueRange(['values' => $this->shape($rows)]);
        //   $service->spreadsheets_values->update(
        //       $this->spreadsheetId,
        //       $options['range'] ?? "{$dataset}!A1",
        //       $body,
        //       ['valueInputOption' => 'RAW'],
        //   );
        throw new RuntimeException(
            'GoogleSheetsExporter is not implemented yet. See class docblock for steps.',
        );
    }
}

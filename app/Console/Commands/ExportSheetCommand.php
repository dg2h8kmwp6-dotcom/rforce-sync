<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ExportDatasetToGoogleSheetsJob;
use Illuminate\Console\Command;

class ExportSheetCommand extends Command
{
    protected $signature = 'sheets:export
        {dataset : accounts|contacts|opportunities}
        {--sync : Run inline instead of dispatching}';

    protected $description = 'Export a normalized dataset to Google Sheets.';

    public function handle(): int
    {
        $dataset = (string) $this->argument('dataset');
        $job = new ExportDatasetToGoogleSheetsJob($dataset);

        if ($this->option('sync')) {
            $job->handle(app(\App\Services\Sheets\Contracts\SheetsExporter::class));
            $this->info("Exported {$dataset}.");
        } else {
            dispatch($job);
            $this->info("Dispatched export for {$dataset}.");
        }

        return self::SUCCESS;
    }
}

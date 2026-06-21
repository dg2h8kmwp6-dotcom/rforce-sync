<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Export\Contracts\DatasetExporterInterface;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\Pagination\HeaderBasedPaginationStrategy;
use App\Services\RForce\Pagination\PaginationStrategyInterface;
use App\Services\RForce\Pagination\QueryParameterPaginationStrategy;
use App\Services\RForce\RForceClient;
use App\Services\Sheets\Contracts\SheetsExporter;
use App\Services\Sheets\GoogleSheetsExporter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaginationStrategyInterface::class, function (): PaginationStrategyInterface {
            $mode  = (string) config('rforce.pagination.mode', 'header');
            $param = (string) config('rforce.pagination.param_name', 'nextRecordsUrl');

            return match ($mode) {
                'query_parameter' => new QueryParameterPaginationStrategy($param),
                default           => new HeaderBasedPaginationStrategy($param),
            };
        });

        $this->app->singleton(RForceConnector::class, function ($app): RForceConnector {
            return new RForceClient(
                http:       $app->make(HttpFactory::class),
                baseUrl:    (string) config('rforce.base_url', ''),
                config:     (array) config('rforce'),
                pagination: $app->make(PaginationStrategyInterface::class),
            );
        });

        // Sheets is the only configured sink today; bind it as the generic
        // dataset exporter so callers don't have to know which sink is live.
        $this->app->singleton(SheetsExporter::class, function (): SheetsExporter {
            return new GoogleSheetsExporter(
                enabled:         (bool) config('services.google_sheets.enabled', false),
                spreadsheetId:   config('services.google_sheets.spreadsheet_id'),
                credentialsPath: config('services.google_sheets.credentials_path'),
            );
        });
        $this->app->alias(SheetsExporter::class, DatasetExporterInterface::class);
    }

    public function boot(): void
    {
        //
    }
}

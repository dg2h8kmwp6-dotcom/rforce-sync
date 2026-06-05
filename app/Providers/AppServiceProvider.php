<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Export\Contracts\DatasetExporterInterface;
use App\Services\RForce\Contracts\RForceConnector;
use App\Services\RForce\RForceAuthenticator;
use App\Services\RForce\RForceClient;
use App\Services\Sheets\Contracts\SheetsExporter;
use App\Services\Sheets\GoogleSheetsExporter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RForceAuthenticator::class, function ($app): RForceAuthenticator {
            return new RForceAuthenticator(
                http:        $app->make(HttpFactory::class),
                cache:       $app->make(CacheRepository::class),
                config:      (array) config('rforce.auth', []),
                httpTimeout: (int) config('rforce.http.timeout', 30),
            );
        });

        $this->app->singleton(RForceConnector::class, function ($app): RForceConnector {
            return new RForceClient(
                http:       $app->make(HttpFactory::class),
                auth:       $app->make(RForceAuthenticator::class),
                baseUrl:    (string) config('rforce.base_url', ''),
                httpConfig: (array) config('rforce.http', []),
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

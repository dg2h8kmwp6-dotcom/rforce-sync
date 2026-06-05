<?php

declare(strict_types=1);

use App\Jobs\RunQueryDefinitionJob;
use App\Models\QueryDefinition;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Per-definition cron: each enabled QueryDefinition that declares a
| `schedule` cron expression is registered dynamically. Business users can
| add/edit/disable queries in the admin UI without touching code.
|
| Wrapped in try/catch because routes/console.php is loaded for `migrate`
| too — querying the table before it exists would prevent the install from
| ever finishing.
|
| Sheets export jobs only fire if GOOGLE_SHEETS_ENABLED=true; the exporter
| no-ops otherwise so it's safe to leave them scheduled.
*/

try {
    if (Schema::hasTable('query_definitions')) {
        QueryDefinition::query()
            ->where('enabled', true)
            ->whereNotNull('schedule')
            ->get()
            ->each(function (QueryDefinition $definition): void {
                Schedule::job(new RunQueryDefinitionJob($definition->id))
                    ->cron((string) $definition->schedule)
                    ->withoutOverlapping()
                    ->name("rforce:sync:{$definition->name}")
                    ->runInBackground();
            });
    }
} catch (\Throwable) {
    // DB not reachable (e.g. during initial install). Schedule fixed jobs only.
}

Schedule::command('sheets:export accounts')
    ->dailyAt('06:00')
    ->withoutOverlapping();

Schedule::command('sheets:export contacts')
    ->dailyAt('06:10')
    ->withoutOverlapping();

Schedule::command('sheets:export opportunities')
    ->dailyAt('06:20')
    ->withoutOverlapping();

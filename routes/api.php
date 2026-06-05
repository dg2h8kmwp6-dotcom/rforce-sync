<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Internal\AccountController;
use App\Http\Controllers\Api\Internal\ContactController;
use App\Http\Controllers\Api\Internal\OpportunityController;
use App\Http\Controllers\Api\Internal\QueryDefinitionController;
use App\Http\Controllers\Api\Internal\SyncRunController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Internal API
|--------------------------------------------------------------------------
|
| Resources are exposed under business / dataset names — customers, sales,
| contacts — instead of leaking Salesforce object names. MySQL is the source
| of truth for these endpoints; consumers should never assume an upstream
| Salesforce/rForce model is involved.
|
| /datasets/* aliases are retained for backwards compatibility with anything
| that built against the original prototype.
|
| No auth middleware is wired yet — add Sanctum / an IP allowlist / etc.
| before exposing in production.
*/

Route::prefix('internal')->group(function (): void {
    // Sync visibility
    Route::get('sync-runs',           [SyncRunController::class, 'index']);
    Route::get('sync-runs/{syncRun}', [SyncRunController::class, 'show']);

    // Saved SOQL queries (read-only here; admin UI handles writes)
    Route::get('query-definitions',                    [QueryDefinitionController::class, 'index']);
    Route::get('query-definitions/{queryDefinition}',  [QueryDefinitionController::class, 'show']);

    // Business-named datasets — the public face of the platform.
    Route::get('customers',     [AccountController::class, 'index']);
    Route::get('contacts',      [ContactController::class, 'index']);
    Route::get('sales',         [OpportunityController::class, 'index']);
    Route::get('opportunities', [OpportunityController::class, 'index']); // explicit per requirements

    // Backwards-compat aliases for the original prototype paths.
    Route::get('datasets/accounts',      [AccountController::class, 'index']);
    Route::get('datasets/contacts',      [ContactController::class, 'index']);
    Route::get('datasets/opportunities', [OpportunityController::class, 'index']);
});

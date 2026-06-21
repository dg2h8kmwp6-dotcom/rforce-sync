<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| rForce / Salesforce — MuleSoft Data Gateway
|--------------------------------------------------------------------------
|
| The application does NOT authenticate against Salesforce directly. It
| sends SOQL through the Renewal by Andersen MuleSoft Data Gateway:
|
|     GET  {RFORCE_BASE_URL}?q=<SOQL>
|     Headers: proxy_client_id, proxy_client_secret,
|              sf_client_id, sf_client_secret, sf_version,
|              sf_environment_url (optional)
|
| MuleSoft handles the Salesforce OAuth handshake and returns the standard
| Salesforce REST query response. Pagination uses Salesforce-style
| `nextRecordsUrl`; on follow-up calls we send it back via the
| `nextRecordsUrl` header (no `q` parameter).
|
*/

$environments = [
    'test'  => 'https://it1apitst2.andersencorp.com/api/rforce/control-layer/query',
    'stage' => 'https://it1apistg2.andersencorp.com/api/rforce/control-layer/query',
    'prod'  => 'https://it1api2.andersencorp.com/api/rforce/control-layer/query',
];

$environment = strtolower((string) env('RFORCE_ENVIRONMENT', 'stage'));
$baseUrl     = (string) env('RFORCE_BASE_URL', $environments[$environment] ?? $environments['stage']);

return [

    'environment'   => $environment,
    'environments'  => $environments,

    // The full Data Gateway query endpoint. Defaults to whichever URL the
    // selected environment maps to. Trailing slash is meaningful here —
    // it's a single endpoint, not a base path.
    'base_url'      => $baseUrl,

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | proxy_*  → MuleSoft client credentials (authenticate to the gateway).
    | sf_*     → Salesforce-side credentials that MuleSoft forwards.
    | sf_environment_url → optional override (e.g. for a different SF org).
    |
    */

    'proxy_client_id'     => env('RFORCE_PROXY_CLIENT_ID'),
    'proxy_client_secret' => env('RFORCE_PROXY_CLIENT_SECRET'),
    'sf_client_id'        => env('RFORCE_SF_CLIENT_ID'),
    'sf_client_secret'    => env('RFORCE_SF_CLIENT_SECRET'),
    'sf_version'          => env('RFORCE_SF_VERSION', 'v60.0'),
    'sf_environment_url'  => env('RFORCE_SF_ENVIRONMENT_URL'),

    /*
    |--------------------------------------------------------------------------
    | HTTP behavior
    |--------------------------------------------------------------------------
    */

    'timeout'        => (int) env('RFORCE_TIMEOUT', 300),
    'retry_attempts' => (int) env('RFORCE_RETRY_ATTEMPTS', 3),
    // milliseconds between retries
    'retry_sleep'    => (int) env('RFORCE_RETRY_SLEEP', 10000),
    // HTTP statuses considered transient and worth retrying.
    'retry_when'     => [408, 425, 429, 500, 502, 503, 504],

    /*
    |--------------------------------------------------------------------------
    | Sync behavior
    |--------------------------------------------------------------------------
    */

    'sync' => [
        // Max records per sync run (null = unbounded). Useful for safety in dev.
        'max_per_run' => null,
        // Queue used for sync jobs.
        'queue'       => env('RFORCE_QUEUE', 'default'),
        // Identifier used to tag rows in `external_sources`.
        'source_name' => 'rforce',
        // A SyncRun is considered "stale" if it is still status=running this
        // long after started_at. The cleanup command marks stale runs as
        // failed so they don't pin counters/dashboards forever when a queue
        // worker drops a job mid-flight. Default: 2 hours.
        'stale_after_hours' => (int) env('RFORCE_STALE_AFTER_HOURS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | How the connector hands the gateway a `nextRecordsUrl` from the previous
    | page. Header mode mirrors the gateway docs (as inferred from the PDF +
    | screenshots); query_parameter mode is a fallback if the header contract
    | turns out to be wrong in some environment.
    |
    | Switch with RFORCE_PAGINATION_MODE=header | query_parameter.
    |
    */

    'pagination' => [
        'mode' => env('RFORCE_PAGINATION_MODE', 'header'),
        // Name of the header / query string key used to send the next page
        // pointer. Defaults match the gateway docs.
        'param_name' => env('RFORCE_PAGINATION_PARAM', 'nextRecordsUrl'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transformer fallback registry
    |--------------------------------------------------------------------------
    |
    | Optional. The primary way to declare how a dataset gets normalized is
    | QueryDefinition.transformer_class. This map is the fallback used when
    | transformer_class is null — the runtime looks up `target_dataset` here.
    |
    | Removing an entry does NOT break sync: raw rows still land in
    | raw_rforce_records. It just means no normalized table is populated for
    | that dataset unless the QueryDefinition names a transformer directly.
    |
    | Datasets are business-named, not Salesforce-object-named: the platform
    | is query-driven, not object-driven.
    |
    */

    'datasets' => [
        'accounts' => [
            'transformer' => \App\Services\RForce\Transformers\AccountTransformer::class,
            'model'       => \App\Models\NormalizedAccount::class,
        ],
        'contacts' => [
            'transformer' => \App\Services\RForce\Transformers\ContactTransformer::class,
            'model'       => \App\Models\NormalizedContact::class,
        ],
        'opportunities' => [
            'transformer' => \App\Services\RForce\Transformers\OpportunityTransformer::class,
            'model'       => \App\Models\NormalizedOpportunity::class,
        ],
    ],

];

<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway endpoints
    |--------------------------------------------------------------------------
    |
    | rForce/Salesforce is reached through a gateway. The exact contract is
    | still TBD; treat these values as the only place URLs should live.
    |
    */

    'base_url' => rtrim((string) env('RFORCE_BASE_URL', ''), '/'),

    'auth' => [
        // oauth_client_credentials | oauth_password | static_token
        'strategy'      => env('RFORCE_AUTH_STRATEGY', 'oauth_client_credentials'),
        'url'           => env('RFORCE_AUTH_URL'),
        'client_id'     => env('RFORCE_CLIENT_ID'),
        'client_secret' => env('RFORCE_CLIENT_SECRET'),
        'username'      => env('RFORCE_USERNAME'),
        'password'      => env('RFORCE_PASSWORD'),
        'static_token'  => env('RFORCE_STATIC_TOKEN'),
        // How long (in seconds) before token expiry to consider it stale and refresh.
        'refresh_skew'  => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP behavior
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout'        => (int) env('RFORCE_TIMEOUT', 30),
        'retry_attempts' => (int) env('RFORCE_RETRY_ATTEMPTS', 3),
        // milliseconds between retries
        'retry_sleep'    => (int) env('RFORCE_RETRY_SLEEP', 500),
        // HTTP statuses considered transient and worth retrying
        'retry_when'     => [408, 425, 429, 500, 502, 503, 504],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transformer fallback registry
    |--------------------------------------------------------------------------
    |
    | The platform is query-driven via QueryDefinitions; QueryDefinition.
    | transformer_class is the primary way to declare how rows for a given
    | saved query get normalized.
    |
    | This map is the *fallback* used when QueryDefinition.transformer_class is
    | null — the runtime looks up `target_object` here. Add a row for any new
    | Salesforce object (including custom `*__c` ones) you want auto-normalized.
    |
    | Removing an entry does NOT break sync — raw rows still land in
    | raw_rforce_records. It just means no normalized table is populated for
    | that object_type unless the QueryDefinition names a transformer directly.
    |
    */

    'objects' => [
        'Account' => [
            'transformer' => \App\Services\RForce\Transformers\AccountTransformer::class,
            'model'       => \App\Models\NormalizedAccount::class,
        ],
        'Contact' => [
            'transformer' => \App\Services\RForce\Transformers\ContactTransformer::class,
            'model'       => \App\Models\NormalizedContact::class,
        ],
        'Opportunity' => [
            'transformer' => \App\Services\RForce\Transformers\OpportunityTransformer::class,
            'model'       => \App\Models\NormalizedOpportunity::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync behavior
    |--------------------------------------------------------------------------
    */

    'sync' => [
        // Page size requested from the gateway when paginating queries.
        'page_size'      => 500,
        // Max records per sync run (null = unbounded). Useful for safety in dev.
        'max_per_run'    => null,
        // Queue used for sync jobs.
        'queue'          => env('RFORCE_QUEUE', 'default'),
        // Identifier used to tag rows in `external_sources`.
        'source_name'    => 'rforce',
    ],

];

# rForce Sync — Architecture

## System Overview

This Laravel application is an internal data platform that:

1. Sends **saved SOQL queries** (`QueryDefinition` rows) to the Renewal by
   Andersen **MuleSoft Data Gateway**, which authenticates against Salesforce
   on the app's behalf.
2. Lands raw responses in `raw_rforce_records` for auditability and replay.
3. Optionally normalizes raw rows into dataset-specific tables via
   per-`QueryDefinition` transformer classes.
4. Exposes the normalized data through an internal REST API at
   `/api/internal/*`.
5. Supports optional Google Sheets exports through a vendor-neutral
   `DatasetExporterInterface`.
6. Treats MySQL as the system of record.
7. Is deployable on Laravel Cloud.

The codebase is organized around **two principles**:

- **SOQL is first-class.** The connector exposes `query()` and `queryMore()`.
  Sync runs are tagged with the QueryDefinition that produced them and the
  actual SOQL string executed at that moment.
- **Query-driven, not object-driven.** Adding a new Salesforce object, custom
  field, or `*__c` custom object means inserting a row into
  `query_definitions` — no code change required for the ingest pipeline. A
  transformer is only needed if the records should also land in a normalized
  table.

## The MuleSoft Data Gateway

The app does NOT authenticate to Salesforce directly. Every request hits a
single Mule endpoint:

```
GET  {RFORCE_BASE_URL}?q=<SOQL>
```

Three environments are supported via `RFORCE_ENVIRONMENT`:

| env   | URL |
|-------|-----|
| test  | https://it1apitst2.andersencorp.com/api/rforce/control-layer/query |
| stage | https://it1apistg2.andersencorp.com/api/rforce/control-layer/query |
| prod  | https://it1api2.andersencorp.com/api/rforce/control-layer/query    |

Local/dev defaults to **stage**.

### Required headers

Every request to the gateway carries:

| header                 | source                       |
|------------------------|------------------------------|
| `proxy_client_id`      | `RFORCE_PROXY_CLIENT_ID`     |
| `proxy_client_secret`  | `RFORCE_PROXY_CLIENT_SECRET` |
| `sf_client_id`         | `RFORCE_SF_CLIENT_ID`        |
| `sf_client_secret`     | `RFORCE_SF_CLIENT_SECRET`    |
| `sf_version`           | `RFORCE_SF_VERSION` (default `v60.0`; `v58.0` also valid) |
| `sf_environment_url`   | `RFORCE_SF_ENVIRONMENT_URL` (optional — omitted when blank) |

MuleSoft uses the `proxy_*` credentials to authenticate the caller, then
uses the `sf_*` credentials to obtain a Salesforce session and forward the
SOQL. The Laravel app NEVER calls `/services/oauth2/token` — there is no
direct Salesforce auth handshake on this side of the wire.

### Response shape

Salesforce REST style, returned verbatim by MuleSoft:

```json
{
  "totalSize": 10000,
  "done": false,
  "nextRecordsUrl": "/services/data/v60.0/query/01g4000000ABCDe-2000",
  "records": [
    {
      "attributes": { "type": "Account", "url": "/services/data/v60.0/sobjects/Account/001.../" },
      "Id": "001ABCdef",
      "Name": "Acme"
    }
  ]
}
```

### Pagination — `nextRecordsUrl`

When `done` is `false`, the next page is fetched by hitting the same gateway
URL again, this time with the `nextRecordsUrl` from the previous response
passed along.

The on-the-wire encoding is selected by `RFORCE_PAGINATION_MODE` and lives
behind `App\Services\RForce\Pagination\PaginationStrategyInterface`. See
[Pagination Strategy](#pagination-strategy) below.

## Component Map

```
┌───────────────────────────┐    ┌──────────────────────────────┐
│ Console / Schedule        │    │ HTTP                         │
│ - rforce:test-connection  │    │ - /api/internal/customers    │
│ - rforce:query "..."      │    │ - /api/internal/sales        │
│ - rforce:run-query <q>    │    │ - /api/internal/contacts     │
│ - rforce:sync             │    │ - /api/internal/sync-runs    │
│ - QueryDefinition cron    │    │ - /api/internal/query-defs   │
└────────────┬──────────────┘    │ - /admin (Tailwind dashboard)│
             │                   └──────────────┬───────────────┘
             │                                  │
             ▼                                  ▼
┌────────────────────────────────────────────────────────────────┐
│                  Application Layer                             │
│                                                                │
│  Jobs:  RunQueryDefinitionJob  →  TransformRForceRecordsJob    │
│         ExportDatasetToGoogleSheetsJob                         │
│                                                                │
│  Services:                                                     │
│    RForce\RForceClient (HTTP transport: MuleSoft headers,      │
│                         retries, query / queryMore)            │
│    RForce\RForceQueryExecutor (execute / executePaginated,     │
│                                placeholders, SOQL helpers)     │
│    RForce\RForceSyncManager (orchestration + change detection) │
│    RForce\Transformers\* (dataset → normalized rows; optional) │
│    Sync\SyncRunManager (run lifecycle + counters)              │
│    Sheets\GoogleSheetsExporter implements                      │
│      Sheets\Contracts\SheetsExporter extends                   │
│      Export\Contracts\DatasetExporterInterface                 │
└──────────────────────────┬─────────────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────────────┐
│                  Persistence (MySQL)                           │
│                                                                │
│  external_sources                                              │
│  query_definitions                                             │
│  sync_runs   ─────── sync_errors                               │
│  raw_rforce_records                                            │
│  normalized_accounts / contacts / opportunities (optional)     │
└────────────────────────────────────────────────────────────────┘
```

## Pagination Strategy

Pagination is configurable, not hard-coded. The gateway docs (PDF +
screenshots) describe a header-based handoff, which is the default. If a
gateway environment turns out to expect a query-string parameter instead,
the strategy is swappable without touching transport code.

```
PaginationStrategyInterface
 ├── HeaderBasedPaginationStrategy   (default, RFORCE_PAGINATION_MODE=header)
 └── QueryParameterPaginationStrategy            (RFORCE_PAGINATION_MODE=query_parameter)
```

### Header mode (default)

```http
GET {RFORCE_BASE_URL}
nextRecordsUrl: /services/data/v60.0/query/01g4000000ABCDe-2000
```

### Query parameter mode

```http
GET {RFORCE_BASE_URL}?nextRecordsUrl=/services/data/v60.0/query/01g4000000ABCDe-2000
```

In both modes the parameter name defaults to `nextRecordsUrl`; override
with `RFORCE_PAGINATION_PARAM`. The first page of a query (`query()`) is
always sent as `?q=<SOQL>` regardless of mode — only subsequent
`queryMore()` calls go through the strategy.

### Pagination telemetry

`RForceSyncManager` logs one `rforce.pagination.page` info entry per page
with:

- `sync_run_id`
- `query_definition_id`
- `page_number`
- `records` (count)
- `has_next` (boolean — whether the gateway returned a nextRecordsUrl)
- `done` (boolean)

Credentials, SOQL bodies, and record payloads are never logged.

## Sync Lifecycle

A "sync" is one execution of one `QueryDefinition` against the gateway.

```
[Scheduler/CLI/API]
        │
        ▼
RunQueryDefinitionJob.handle(sync, runs)
        │
        ▼
RForceSyncManager.sync(definition, since, limit, dryRun)
        │
        ├── SyncRunManager.start()         (status = running, captures SOQL)
        │
        ├── RForceQueryExecutor.executePaginated(soql):
        │     ├── connector.query(soql)             (first page)
        │     └── connector.queryMore(nextRecordsUrl)  ×N
        │
        ├── for each page:
        │     ├── increment records_received
        │     ├── upsertWithChangeDetection():
        │     │     · extract Id, attributes.type, attributes.url
        │     │     · hash payloads
        │     │     · batch-fetch existing hashes by (source, external_id)
        │     │     · bucket: new / changed / unchanged / malformed
        │     │     · upsert raw_rforce_records, bumping first_seen_at on
        │     │       insert and last_seen_at always
        │     └── increment inserted/changed/skipped counters
        │
        ├── stamp QueryDefinition.last_run_at
        │
        ▼
TransformRForceRecordsJob.dispatch(syncRunId)
        │
        ▼
TransformRForceRecordsJob.handle(runs)
        │
        ├── resolve transformer
        │     (QueryDefinition.transformer_class ??
        │      config/rforce.datasets[target_dataset].transformer)
        │
        ├── if no transformer is registered:
        │     · stamp raw_rforce_records.last_synced_at = now
        │     · finish the run cleanly (raw rows are the only required output)
        │
        ├── otherwise chunk raw rows where last_sync_run_id = run.id
        │     for each chunk:
        │       · transformer.transformBatch(chunk, run, source)
        │       · update raw_rforce_records.last_synced_at = now()
        │
        └── SyncRunManager.finish()  (status derived; duration_ms set)
```

**Failure handling.** A SyncRun reaches a terminal state from one of four
paths:

| failure mode                       | who fails the run? |
|------------------------------------|---|
| `RForceSyncManager::sync()` throws (HTTP/timeout/SOQL/auth/pagination/etc.) | `sync()` itself records a `gateway`-category SyncError, calls `runs->fail()`, then re-throws |
| `TransformRForceRecordsJob` throws | the job records a `transform`-category SyncError, calls `runs->fail()`, then re-throws |
| `TransformRForceRecordsJob::dispatch()` throws (queue down, serialization) | `RunQueryDefinitionJob` records a `dispatch`-category SyncError, calls `runs->fail()`, then re-throws |
| Worker crashes before transform completes | `rforce:cleanup-stale-runs` (cron) catches the run after `RFORCE_STALE_AFTER_HOURS` and fails it with a `stale`-category SyncError |

The last row is the safety net for the queue dependency described below.

Transformer per-record failures stay inside `transformBatch()` and are
bucketed per-record (run is `partial`, not `failed`).

**Idempotency.** Re-running a definition is safe at every layer:

- `raw_rforce_records` is keyed `(external_source_id, external_id)` and uses
  `upsert()`. Salesforce Ids are globally unique within a source.
- Hash comparison means an unchanged payload is a `last_seen_at` touch only.
- Normalized rows use `updateOrCreate` keyed on `(source, external_id)`.

## Queue Dependency

`RunQueryDefinitionJob` does the gateway fetch + raw upsert, then
**dispatches** `TransformRForceRecordsJob`. Completion of a run is therefore
queue-dependent: if no worker picks up the transform job, the SyncRun
remains in `running` until something fails it.

What the platform does about that:

1. **Inline dispatch failures** (queue manager throws when pushing the job)
   are caught in `RunQueryDefinitionJob` — the run is failed with a
   `dispatch`-category SyncError before the exception propagates.
2. **Workers that never run the transform** (queue paused, worker crashed,
   wrong queue name) leave the run stuck. `rforce:cleanup-stale-runs`
   runs every 15 minutes via the scheduler and fails any run still in
   `running` after `RFORCE_STALE_AFTER_HOURS` (default 2h).
3. **Observability**: `rforce:health` reports the current stuck-run count
   alongside DB / queue / gateway / last-success diagnostics.

### Health-check guidance

For deployment monitors:

- Run `rforce:health --json` from your synthetic check; treat `ok: false`
  as a failed health probe.
- Run `php artisan queue:work --queue=$RFORCE_QUEUE` as a long-lived
  process. **Without a worker the platform cannot complete a sync.**
- Schedule `rforce:cleanup-stale-runs` (already wired in
  `routes/console.php`) so a worker-less window self-heals after the
  staleness window expires instead of silently rotting.
- Watch `sync_errors.category = 'stale'` — repeated entries mean a worker
  isn't picking up jobs and on-call should be paged.

## Query Lifecycle

```
[business user adds query in admin or via seeder]
        │
        ▼
query_definitions
  id, name, description, soql,
  target_dataset, transformer_class?,
  enabled, schedule, last_run_at
        │
        ├── routes/console.php registers Schedule::job() for any enabled
        │   definition with a `schedule` cron expression.
        │
        ├── rforce:query "<SOQL>"                 → ad-hoc dry-run stream to stdout
        ├── rforce:run-query <id|name>            → dispatch RunQueryDefinitionJob (single)
        ├── rforce:run-query <id|name> --inline   → run in-process
        ├── rforce:sync                           → dispatch all enabled defs
        └── rforce:test-connection                → SELECT Name FROM Account LIMIT 1
```

Saved queries are mutable — editing a definition does not retroactively
change history. Each `sync_runs` row captures the exact SOQL that ran.

### SOQL placeholders

`QueryDefinition.soql` may contain a fixed set of `{{...}}` placeholders that
are resolved at execution time to SOQL-typed literals:

| token              | resolution |
|--------------------|------------|
| `{{last_sync_at}}` | `--since` arg → QueryDefinition.last_run_at → epoch fallback, all formatted as ISO-8601 |
| `{{now}}`          | Carbon::now() at execution time, ISO-8601 |

Unknown placeholders are left untouched so the gateway fails loudly rather
than silently stripping content. **Free-form user input is never spliced
into SOQL** — only typed values produced by the placeholder resolver.

## Data Flow Diagram

```
   MuleSoft Data Gateway (fronts rForce / Salesforce)
                  │
                  │  GET {base_url}?q=<SOQL>          (first page)
                  │  GET {base_url} + nextRecordsUrl  (subsequent)
                  │  Headers: proxy_*, sf_*
                  │  ← { totalSize, done, nextRecordsUrl?, records[] }
                  ▼
       App\Services\RForce\RForceClient        (HTTP, headers, retry)
                  │
                  ▼
       App\Services\RForce\RForceQueryExecutor  (execute / executePaginated)
                  │
                  ▼
       App\Services\RForce\RForceSyncManager    (hash diff + upsert)
                  │
                  ▼
       raw_rforce_records  (payload + payload_hash + first/last_seen_at)
                  │
                  ▼
       App\Services\RForce\Transformers\*       (optional, per-dataset)
                  │
                  ▼
       normalized_* tables (only for datasets with transformers)
                  │
        ┌─────────┴─────────────┐
        ▼                       ▼
  /api/internal/* (REST)   DatasetExporterInterface
                                  │
                                  ▼
                            Google Sheets (optional)
```

## Operational Commands

| command                                | purpose | safe to schedule? |
|----------------------------------------|---------|-------------------|
| `php artisan rforce:test-connection`   | Verify gateway reachability + credentials (one real SOQL call). | no — manual probe |
| `php artisan rforce:query "<SOQL>"`    | Stream the records produced by an ad-hoc SOQL. No DB writes. | no — manual probe |
| `php artisan rforce:run-query <id\|name>` | Sync a single saved QueryDefinition. `--inline`, `--dry-run`, `--since=…`, `--limit=…` supported. | yes (per-definition) |
| `php artisan rforce:sync`              | Sync every enabled QueryDefinition. | yes |
| `php artisan rforce:cleanup-stale-runs` | Fail any SyncRun stuck in `running` past the staleness threshold. Already scheduled every 15 minutes. | yes (already cron'd) |
| `php artisan rforce:health [--json]`   | Diagnostic snapshot: DB, queue, gateway config, enabled queries, last success, stale-run count. Returns non-zero on `fail`. | yes (alerting probe) |

### `rforce:health` output

Human mode (default) shows one line per check with `[ ok ]` / `[warn]` /
`[fail]` plus a `Overall: healthy|failing` summary. `--json` emits a
machine-readable payload:

```json
{
  "ok": true,
  "checks": {
    "database":       { "status": "ok",   "message": "connected", "detail": { "driver": "mysql", "latency_ms": 3 } },
    "queue":          { "status": "ok",   "message": "reachable", "detail": { "connection": "database", "queue": "default", "pending": 0 } },
    "gateway_config": { "status": "ok",   "message": "configured", "detail": { "environment": "stage", "base_url": "...", "sf_version": "v60.0", "pagination_mode": "header" } },
    "query_defs":     { "status": "ok",   "message": "2 enabled of 2", "detail": { "enabled": 2, "total": 2 } },
    "last_success":   { "status": "ok",   "message": "2026-06-21T14:00:00+00:00", "detail": { "sync_run_id": 123, "query_definition_id": 7, "age_hours": 1 } },
    "stale_runs":     { "status": "ok",   "message": "none stuck > 2h", "detail": { "stale_after_hours": 2, "count": 0 } }
  }
}
```

The command never calls the gateway — it's safe to run on a misconfigured
environment and won't burn MuleSoft quota.

## Database Schema Overview

### `external_sources`
Stable identifier for "where did this data come from". Today there is one
row, `rforce`, but the design supports e.g. a separate `salesforce_sandbox`
source without colliding on external ids.

### `query_definitions`
The heart of the SOQL-first model.

| column              | purpose |
|---------------------|---------|
| `external_source_id`| Which source to run against |
| `name` (unique)     | Human reference (`accounts.smoke`) |
| `description`       | Free text shown in the admin UI |
| `soql`              | The query to execute (may include placeholders) |
| `target_dataset`    | Business-named bucket (`accounts`, `orders`, ...). Drives the transformer lookup |
| `transformer_class` | Optional explicit override |
| `enabled`           | Scheduler/CLI skip disabled rows |
| `schedule`          | Cron expression; null = manual only |
| `last_run_at`       | Set after each completion |

### `sync_runs`
One row per execution.

| column                | purpose |
|-----------------------|---------|
| `external_source_id`  | FK |
| `query_definition_id` | FK (nullable for ad-hoc) |
| `target_dataset`      | Mirrors QueryDefinition.target_dataset |
| `soql`                | The exact SOQL that ran (snapshot) |
| `status`              | enum: pending/running/succeeded/partial/failed/cancelled |
| `since`               | Incremental filter cursor used |
| `limit`               | Cap supplied for this run |
| `records_received`    | Read from gateway response |
| `records_inserted`    | New raw rows |
| `records_changed`     | Existing rows with different hash |
| `records_skipped`     | Existing rows with matching hash |
| `records_failed`      | See `sync_errors` |
| `started_at` / `finished_at` / `duration_ms` | Timing |
| `error_message`       | Last error summary |
| `meta`                | JSON: trigger source, dry_run flag, etc. |

### `sync_errors`
Per-record errors with classification (`gateway`/`transform`/`malformed`/`config`/...).
Every gateway failure, malformed record, missing transformer config, and
per-record transform exception lands here.

### `raw_rforce_records`
Append-on-change ledger of every payload we've ever pulled.

| column                 | purpose |
|------------------------|---------|
| `external_source_id`   | Source system |
| `last_sync_run_id`     | Most recent run that touched this row |
| `query_definition_id`  | Which saved query produced it |
| `source_object_type`   | record.attributes.type (when present) |
| `source_url`           | record.attributes.url (when present) |
| `external_id`          | record.Id |
| `payload`              | Raw JSON from the gateway |
| `payload_hash`         | SHA-256 of payload (change detection key) |
| `first_seen_at`        | When we first observed this external_id |
| `last_seen_at`         | When we most recently observed it |
| `last_synced_at`       | When the normalized row was last derived |

Unique on `(external_source_id, external_id)`. Indexes on `payload_hash`,
`(query_definition_id, last_seen_at)`, `(source_object_type, last_seen_at)`.

This shape supports **replaying transformations without re-querying
Salesforce** — point a transformer at a `last_sync_run_id` slice (or all rows)
and re-derive normalized tables.

### Optional normalized tables
`normalized_accounts`, `normalized_contacts`, `normalized_opportunities` are
examples of dataset-specific normalized projections — they exist because the
matching `AccountTransformer` / `ContactTransformer` / `OpportunityTransformer`
classes are registered in `config('rforce.datasets')`. New datasets follow
the same pattern: write a transformer + a migration + register it in config,
and any `QueryDefinition` with that `target_dataset` will flow through it.

## Google Sheets Integration (optional)

```
MySQL → DatasetExporterInterface → GoogleSheetsExporter → Google Sheet
```

Sheets is a write-only sink. The platform never reads from Sheets — MySQL is
the source of truth.

## Deployment Notes (Laravel Cloud)

- **PHP / Laravel.** PHP ≥ 8.2, Laravel 12.x (see `composer.json`).
- **MySQL.** Set `DB_CONNECTION=mysql` plus the standard `DB_*` env vars in
  the Laravel Cloud database integration UI. The schema is portable — no
  MySQL-only features beyond JSON columns.
- **Queue.** `QUEUE_CONNECTION=database` (or `redis` on Laravel Cloud's
  Redis addon). Run a queue worker; sync jobs use the `RFORCE_QUEUE` queue
  (default `default`).
- **Scheduler.** Laravel Cloud's scheduled-job runner picks up
  `routes/console.php`. Per-definition cron is registered dynamically — the
  process only needs `schedule:run` every minute.
- **Secrets.** `RFORCE_PROXY_*`, `RFORCE_SF_*`, and `DB_PASSWORD` belong in
  the Laravel Cloud secret manager — never in `.env` committed to source.
- **Per-environment URLs.** Set `RFORCE_ENVIRONMENT=stage` (or `prod`) on
  the corresponding Laravel Cloud environment so the gateway URL flips
  automatically. `RFORCE_BASE_URL` is optional — set it only when you want
  to override the default for that environment.
- **Migrations.** `php artisan migrate --force` as a Laravel Cloud release
  hook. The seeder `QueryDefinitionSeeder` is idempotent and safe to run on
  every deploy if you want SOQL changes to ship via code review.
- **Health.** `/up` is the framework's built-in health endpoint, configured
  in `bootstrap/app.php`. Point Laravel Cloud's health check at it.
- **Observability.** Sync runs + errors are first-class tables — the admin
  dashboard at `/admin` surfaces both. Pipe `sync_errors` to your alerting
  tool (a 1-line job is enough).

## Notable Decisions

1. **`RForceConnector.executeSoql(...)` became `query()` + `queryMore()`**.
   The contract intentionally mirrors the Salesforce REST shape the gateway
   forwards, which makes the wire-level behavior easy to reason about and
   the pagination semantics impossible to miswire.

2. **No client-side Salesforce auth.** MuleSoft owns the OAuth dance. The
   former `RForceAuthenticator` and its `static_token` / `oauth_*` strategies
   were removed entirely. There is one credential set per environment, all
   sent as HTTP headers.

3. **`target_dataset` instead of `target_object`.** Datasets are business-
   named buckets the platform feeds (accounts, orders, ...), decoupled from
   Salesforce object names. A single dataset can blend multiple SF objects;
   a single SF object can feed multiple datasets.

4. **`raw_rforce_records` is keyed on `(source, external_id)`.** Salesforce
   Ids are globally unique within a source, so source_object_type can be
   pure metadata. This avoids the NULL-uniqueness footguns of a 3-column key.

5. **No two-way sync.** This is a one-way reader. The gateway has no
   documented integration that writes back into rForce/Salesforce at this
   time, and the architecture is deliberately structured around that.

# rForce Sync — Architecture

## System Overview

This Laravel application is an internal data platform that:

1. Authenticates against a gateway in front of rForce/Salesforce.
2. Executes **saved SOQL queries** (`QueryDefinition` rows).
3. Lands raw responses in `raw_rforce_records` for auditability and replay.
4. Normalizes raw rows into relational tables (`normalized_accounts`,
   `normalized_contacts`, `normalized_opportunities`, and easily more).
5. Exposes the normalized data through an internal REST API at
   `/api/internal/*`.
6. Supports optional Google Sheets exports through a vendor-neutral
   `DatasetExporterInterface`.
7. Treats MySQL as the system of record.
8. Is deployable on Laravel Cloud.

The codebase is organized around **two principles**:

- **SOQL is first-class.** The connector exposes `executeSoql()`. Sync runs
  are tagged with the QueryDefinition that produced them and the actual SOQL
  string executed at that moment.
- **Query-driven, not object-driven.** Adding a new Salesforce object, custom
  field, or `*__c` custom object means inserting a row into
  `query_definitions` — no code change required for the ingest pipeline. A
  transformer is only needed if the records should also land in a normalized
  table.

## Component Map

```
┌───────────────────────────┐    ┌──────────────────────────────┐
│ Console / Schedule        │    │ HTTP                         │
│ - rforce:test-connection  │    │ - /api/internal/customers    │
│ - rforce:query            │    │ - /api/internal/sales        │
│ - rforce:sync             │    │ - /api/internal/contacts     │
│ - sheets:export           │    │ - /api/internal/sync-runs    │
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
│    RForce\RForceClient (HTTP transport, retries, 401 refresh)  │
│    RForce\RForceAuthenticator (token cache, 3 strategies)      │
│    RForce\RForceQueryExecutor (SOQL pagination + helpers)      │
│    RForce\RForceSyncManager (orchestration + change detection) │
│    RForce\Transformers\* (Account/Contact/Opportunity → normalized)
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
│  normalized_accounts                                           │
│  normalized_contacts                                           │
│  normalized_opportunities                                      │
└────────────────────────────────────────────────────────────────┘
```

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
        ├── for each page from
        │     RForceQueryExecutor.streamPages(soql):
        │       ├── increment records_received
        │       ├── upsertWithChangeDetection():
        │       │     · hash payloads
        │       │     · batch-fetch existing hashes by (source, object, external_id)
        │       │     · bucket: new / changed / unchanged / malformed
        │       │     · upsert raw_rforce_records, bumping first_seen_at on
        │       │       insert and last_seen_at always
        │       └── increment inserted/changed/skipped counters
        │
        ├── stamp QueryDefinition.last_run_at
        │
        ▼
TransformRForceRecordsJob.dispatch(syncRunId)
        │
        ▼
TransformRForceRecordsJob.handle(runs)
        │
        ├── resolve transformer (QueryDefinition.transformer_class ?? config/rforce.objects[*].transformer)
        │
        ├── chunk raw rows where last_sync_run_id = run.id
        │     for each chunk:
        │       · transformer.transformBatch(chunk, run, source)
        │       · update raw_rforce_records.last_synced_at = now()
        │
        └── SyncRunManager.finish()  (status derived; duration_ms set)
```

**Failure handling.** Any throw inside `RForceSyncManager.sync()` is captured
as a `gateway`-category `SyncError`, the run is marked `failed`, and the job
re-throws. Transformer failures are bucketed per-record (run is partial, not
failed) unless the chunk pipeline itself blows up.

**Idempotency.** Re-running a definition is safe at every layer:

- `raw_rforce_records` is keyed `(source, object_type, external_id)` and uses
  `upsert()`.
- Hash comparison means an unchanged payload is a `last_seen_at` touch only.
- Normalized rows use `updateOrCreate` keyed on `(source, external_id)`.

## Query Lifecycle

```
[business user adds query in admin or via seeder]
        │
        ▼
query_definitions
  id, name, description, soql,
  target_object, transformer_class?,
  enabled, schedule, last_run_at
        │
        ├── routes/console.php registers Schedule::job() for any enabled
        │   definition with a `schedule` cron expression.
        │
        ├── rforce:query <id|name>           → ad-hoc dry-run stream to stdout
        ├── rforce:query <id|name> --persist → equivalent to rforce:sync
        ├── rforce:sync <id|name>            → dispatch RunQueryDefinitionJob
        └── rforce:sync                      → dispatch all enabled defs
```

Saved queries are mutable — editing a definition does not retroactively
change history. Each `sync_runs` row captures the exact SOQL that ran.

## Data Flow Diagram

```
   Gateway (rForce / Salesforce REST + SOQL)
                  │
                  │  POST /query  { "q": "SELECT ...", "cursor": "..." }
                  │  ← { records, nextPageToken | nextRecordsUrl, totalSize }
                  ▼
       App\Services\RForce\RForceClient        (HTTP, auth, retry)
                  │
                  ▼
       App\Services\RForce\RForceQueryExecutor  (SOQL paging + filters)
                  │
                  ▼
       App\Services\RForce\RForceSyncManager    (hash diff + upsert)
                  │
                  ▼
       raw_rforce_records  (payload + payload_hash + first/last_seen_at)
                  │
                  ▼
       App\Services\RForce\Transformers\*       (mapping + extras → JSON)
                  │
                  ▼
       normalized_accounts / contacts / opportunities / ...
                  │
        ┌─────────┴─────────────┐
        ▼                       ▼
  /api/internal/* (REST)   DatasetExporterInterface
                                  │
                                  ▼
                            Google Sheets (future)
```

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
| `name` (unique)     | Human reference (`accounts.all`) |
| `description`       | Free text shown in the admin UI |
| `soql`              | The query to execute |
| `target_object`     | Drives transformer lookup + `raw_rforce_records.object_type` |
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
| `object_type`         | Mirrors QueryDefinition.target_object |
| `soql`                | The exact SOQL that ran (snapshot) |
| `status`              | enum: pending/running/succeeded/partial/failed/cancelled |
| `since`               | Incremental filter cursor used |
| `limit`               | Cap supplied for this run |
| `records_received`    | Read from gateway response |
| `records_inserted`    | New raw rows |
| `records_changed`     | Existing rows with different hash |
| `records_skipped`     | Existing rows with matching hash |
| `records_failed`      | See `sync_errors` |
| `records_fetched` / `records_upserted` | Legacy aggregates kept for older readers |
| `started_at` / `finished_at` / `duration_ms` | Timing |
| `error_message`       | Last error summary |
| `meta`                | JSON: trigger source, dry_run flag, etc. |

### `sync_errors`
Per-record errors with classification (`gateway`/`transform`/`config`/...).

### `raw_rforce_records`
Append-on-change ledger of every payload we've ever pulled.

| column                 | purpose |
|------------------------|---------|
| `external_source_id`   | Source system |
| `last_sync_run_id`     | Most recent run that touched this row |
| `query_definition_id`  | Which saved query produced it |
| `object_type`          | Logical bucket (Account, Contact, ...) |
| `external_id`          | Salesforce Id |
| `payload`              | Raw JSON from the gateway |
| `payload_hash`         | SHA-256 of payload (change detection key) |
| `external_modified_at` | Salesforce `LastModifiedDate` if present |
| `fetched_at`           | Legacy field, now equivalent to last_seen_at |
| `first_seen_at`        | When we first observed this external_id |
| `last_seen_at`         | When we most recently observed it |
| `last_synced_at`       | When the normalized row was last derived |

Unique on `(external_source_id, object_type, external_id)`.
Indexes on `payload_hash`, `(query_definition_id, last_seen_at)`,
`(object_type, last_seen_at)`.

This shape supports **replaying transformations without re-querying
Salesforce** — point a transformer at a `last_sync_run_id` slice (or all rows)
and re-derive normalized tables.

### `normalized_accounts` / `_contacts` / `_opportunities`
Promoted columns plus an `extra` JSON column that catches anything not yet
first-classed. Unique on `(external_source_id, external_id)`. Add a new
column by writing a migration; the transformer's `promotedKeys()` list moves
the value out of `extra` automatically.

## Future Google Sheets Integration

```
MySQL → DatasetExporterInterface → GoogleSheetsExporter → Google Sheet
```

The contract is in place today:

- `App\Services\Export\Contracts\DatasetExporterInterface` is the
  vendor-neutral seam (`export()`, `name()`, `isEnabled()`).
- `App\Services\Sheets\GoogleSheetsExporter implements SheetsExporter
  extends DatasetExporterInterface`.
- `ExportDatasetToGoogleSheetsJob` is already wired and scheduled (daily) —
  it no-ops while disabled.

To finish the Sheets integration:

1. `composer require google/apiclient`
2. Drop a service-account JSON at `GOOGLE_SHEETS_CREDENTIALS_PATH`.
3. Replace the body of `GoogleSheetsExporter::export()` with
   `Google_Service_Sheets::values->update()` (a TODO block in the class
   sketches the exact code).
4. Flip `GOOGLE_SHEETS_ENABLED=true`.

Sheets is intentionally a write-only sink. The platform never reads from
Sheets — MySQL is the source of truth. This separation is enforced at the
interface level: `DatasetExporterInterface` has no `read()` method.

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
- **Cache.** Auth tokens are cached. Laravel Cloud's Redis addon is the
  best fit; the in-memory file cache works for single-instance dev.
- **Secrets.** `RFORCE_*`, `GOOGLE_SHEETS_*`, and `DB_PASSWORD` belong in
  the Laravel Cloud secret manager — never in `.env` committed to source.
- **Migrations.** `php artisan migrate --force` as a Laravel Cloud release
  hook. The seeder `QueryDefinitionSeeder` is idempotent and safe to run on
  every deploy if you want SOQL changes to ship via code review.
- **Health.** `/up` is the framework's built-in health endpoint, configured
  in `bootstrap/app.php`. Point Laravel Cloud's health check at it.
- **Observability.** Sync runs + errors are first-class tables — the admin
  dashboard at `/admin` is sufficient for the foreseeable future. Pipe
  `sync_errors` to your alerting tool (a 1-line job is enough).

## Notable Decisions Diverging from the Prototype

1. **Removed `sync('Account')` / `sync('Contact')` commands** in favor of
   `rforce:sync <queryId>`. Object names live in `QueryDefinition`, not
   command signatures. (Old artisan signatures were a closed set.)

2. **`RForceQueryService` was split into two** — `RForceQueryExecutor`
   (pagination + SOQL helpers, no DB) and `RForceSyncManager` (orchestration,
   raw upserts, change detection). This keeps the executor reusable for
   read-only `rforce:query` dry runs.

3. **`RForceConnector.query(object, filters)` became `executeSoql(soql)`**.
   Filters are now constructed in the executor (`buildSoql`) rather than
   translated by the connector. The connector knows nothing about Salesforce
   semantics — only HTTP.

4. **`sync_runs` keeps legacy `records_fetched` / `records_upserted` columns**
   in addition to the new received/inserted/changed/skipped buckets. The
   internal API exposes both shapes (`records.received` plus
   `records.fetched`) so older consumers don't break.

5. **`raw_rforce_records.fetched_at` is preserved**, now equivalent to
   `last_seen_at`. Existing data isn't migrated; new code writes both.

6. **The admin dashboard uses Tailwind via CDN**, not Vite. The dashboard is
   internal-only and rarely viewed; a build step would be friction without
   meaningful benefit at this size. Swap for Vite if the surface grows.

7. **No auth on `/admin` or `/api/internal/*` yet.** Gating belongs to
   deployment, not the prototype. Add `auth:sanctum` middleware (and an IP
   allowlist) before the first non-developer touches it.

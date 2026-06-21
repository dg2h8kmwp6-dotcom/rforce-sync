# rForce Sync

Internal Laravel data platform that pulls SOQL-defined slices of
rForce/Salesforce through the **Renewal by Andersen MuleSoft Data Gateway**
and lands them in MySQL for downstream APIs, dashboards, and optional
Google Sheets export.

MySQL is the system of record. There is no write-back to rForce.

See [`docs/architecture.md`](docs/architecture.md) for the full design.

## Required environment variables

```ini
# Gateway environment: test | stage | prod
RFORCE_ENVIRONMENT=stage

# Optional override. Leave blank to use the environment default below.
#   test:  https://it1apitst2.andersencorp.com/api/rforce/control-layer/query
#   stage: https://it1apistg2.andersencorp.com/api/rforce/control-layer/query
#   prod:  https://it1api2.andersencorp.com/api/rforce/control-layer/query
RFORCE_BASE_URL=

# MuleSoft proxy credentials.
RFORCE_PROXY_CLIENT_ID=
RFORCE_PROXY_CLIENT_SECRET=

# Salesforce credentials the gateway forwards.
RFORCE_SF_CLIENT_ID=
RFORCE_SF_CLIENT_SECRET=

# Salesforce REST API version. Default v60.0; v58.0 also valid.
RFORCE_SF_VERSION=v60.0

# Optional. Set only if MuleSoft needs an explicit Salesforce URL.
RFORCE_SF_ENVIRONMENT_URL=

# HTTP behavior.
RFORCE_TIMEOUT=300
RFORCE_RETRY_ATTEMPTS=3
RFORCE_RETRY_SLEEP=10000

# Queue used for sync jobs.
RFORCE_QUEUE=default
```

See `.env.example` for the full set including DB, queue, and Google Sheets
variables.

## Local setup

```bash
git clone <repo> rforce-sync
cd rforce-sync

cp .env.example .env
composer install
php artisan key:generate

# Local default points at stage. Fill in RFORCE_PROXY_* and RFORCE_SF_*
# from the MuleSoft team before running anything against the gateway.
$EDITOR .env

php artisan migrate
php artisan db:seed --class=Database\\Seeders\\QueryDefinitionSeeder

# Smoke test: hits the gateway with `SELECT Name FROM Account LIMIT 1`.
php artisan rforce:test-connection

# Ad-hoc SOQL — no DB writes.
php artisan rforce:query "SELECT Name FROM Account LIMIT 10"

# Run a saved QueryDefinition end-to-end (raw upsert + transform).
php artisan rforce:run-query accounts.smoke --inline

# Run every enabled saved query.
php artisan rforce:sync --inline
```

## Commands

| command                                          | purpose |
|--------------------------------------------------|---------|
| `php artisan rforce:test-connection`             | Verify the gateway is reachable and credentials are accepted. Override with `--soql=…` for a different probe. |
| `php artisan rforce:query "<SOQL>"`              | Stream the records produced by an ad-hoc SOQL to stdout. No DB writes. |
| `php artisan rforce:run-query <id\|name>`        | Sync a single saved QueryDefinition. Add `--inline`, `--dry-run`, `--since=…`, `--limit=…` as needed. |
| `php artisan rforce:sync`                        | Sync every enabled QueryDefinition. Add `--inline` or `--dry-run`. |

## Example test query

```bash
php artisan rforce:query "SELECT Id, Name FROM Account WHERE LastModifiedDate = TODAY"
```

## SOQL placeholders

`QueryDefinition.soql` may contain a fixed set of `{{...}}` placeholders.
The runtime substitutes SOQL-typed literals — raw user input is never
interpolated.

| token              | substituted with |
|--------------------|------------------|
| `{{last_sync_at}}` | `--since` arg → `QueryDefinition.last_run_at` → epoch fallback, ISO-8601 |
| `{{now}}`          | `Carbon::now()` at execution time, ISO-8601 |

Example seed:

```sql
SELECT FIELDS(ALL) FROM Order WHERE LastModifiedDate > {{last_sync_at}}
```

## Deployment notes (Laravel Cloud)

- Set `RFORCE_ENVIRONMENT=stage` (or `prod`) per Laravel Cloud environment
  so the gateway URL flips automatically. `RFORCE_BASE_URL` is optional —
  set it only when overriding the default.
- Put `RFORCE_PROXY_*`, `RFORCE_SF_*`, and `DB_PASSWORD` in the Laravel
  Cloud secret manager. Never commit them.
- Release hook: `php artisan migrate --force`. The seeder is idempotent and
  safe to re-run if you want SOQL changes shipped via code review.
- Add a queue worker process (`php artisan queue:work`) for
  `RunQueryDefinitionJob` + `TransformRForceRecordsJob`.
- Add a `php artisan schedule:run` cron so per-`QueryDefinition` schedules
  defined in `routes/console.php` fire.
- Health check: point Laravel Cloud at `/up`.

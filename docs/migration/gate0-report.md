# Gate 0 Audit Report

> Historical migration assessment: file paths and PHP deployment details below describe the pre-cutover implementation and are retained for migration context only.

## Branch and Scope

Branch: `feat/laravel-postgres-gate0`

Gate 0 only: audit and documentation. No Laravel, MySQL, PostgreSQL or Render service operations were performed.

## Files Inspected

- Entry/routing: `api/index.php`, `api/.htaccess`, `backend/src/Application.php`, `backend/src/Http/Router.php`, `backend/src/Http/Request.php`
- Configuration: `backend/src/Config/Config.php`, `.env.example`
- Database: `backend/src/Infrastructure/Database.php`, `database/vantiq_schema.sql`, `database/migrations/*.php`
- Auth/RBAC: `backend/src/Http/AuthController.php`, `backend/src/Security/SessionManager.php`, `backend/src/Security/CsrfMiddleware.php`, `backend/src/Security/AuthMiddleware.php`, `backend/src/Security/PermissionMiddleware.php`, `backend/src/Service/AuthService.php`, `backend/src/Repository/RoleRepository.php`
- Controllers/services/repositories: `backend/src/Http/*Controller.php`, `backend/src/Service/*`, `backend/src/Repository/*`
- Storage/background: `backend/src/Service/DataWorkspaceService.php`, `backend/src/Repository/DataWorkspaceRepository.php`, `backend/src/Repository/OutboxRepository.php`, `backend/src/Service/OutboxDispatcher.php`, `backend/src/Http/EventStreamController.php`, `bin/process-outbox.php`
- Deployment/tooling: `Dockerfile`, `deploy/render/apache-vantiq.conf`, `.github/workflows/php.yml`, `composer.json`
- Tests: `tests/*.php`

## Backend Structure

`api/index.php` bootstraps `backend/bootstrap.php`, loads configuration, creates the MySQL PDO connection and dispatches `Vantiq\Application`. Routes are registered in `Application::handle()` using `Router`; thin controllers validate requests and call services. Repositories own parameterized SQL and tenant predicates. Middleware handles session auth, CSRF and RBAC. Standalone API wrapper files present as uncommitted prior work are not migration code and do not alter legacy source behavior without the Apache rewrite.

## Environment Variables

- `APP_ENV`, `APP_KEY`, `APP_URL`
- `SESSION_IDLE_TIMEOUT`, `SESSION_ABSOLUTE_TIMEOUT`, `SESSION_COOKIE_SECURE`
- `ALLOWED_ORIGINS`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`
- `MAIL_TRANSPORT`, `MAIL_FROM`, `RESET_URL`
- `PRIVATE_STORAGE_PATH`

The local `.env` is loopback-only with `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_NAME=vantiq`. No secret values were included in this report or committed files.

## Authentication

- Cookie: `VANTIQSESSID`, session lifetime browser-scoped, `path=/`, `HttpOnly=true`, `SameSite=Lax`, secure in production or configured as secure.
- CSRF: session `_csrf` 32-byte hex token, sent as `X-CSRF-Token` for POST/PUT/PATCH/DELETE; PHP session strict/cookie-only mode is enabled.
- Passwords: PHP `password_hash(..., PASSWORD_DEFAULT)` and `password_verify`; the local database has 2 `$2y$` bcrypt hashes and 0 argon2id/other hashes.
- Login throttling: HMAC account/IP and IP buckets, with lock windows and login history.
- Session rotation: `session_regenerate_id(true)` plus new CSRF token on login/password reset; session version and idle/absolute timestamps invalidate sessions.
- Password changes and reset increment or compare `session_version`.

## RBAC and Tenant Isolation

Tenant data uses `organisation_id` predicates, primarily set from `AuthMiddleware` session data and never accepted as an implicit client identity. `RoleRepository` maps role names to permissions and checks platform-wide override `platform.users.manage`. `PermissionMiddleware` grants only matching permission or platform override. Tenant-scoped models require organisation account context.

## Schema and Local Database Audit

A read-only loopback PDO audit found 51 base tables. The live schema inventory differs from `database/vantiq_schema.sql`: later workspace/outbox/document migrations have added tables and columns absent from the baseline file. Twelve `schema_migrations` records were applied: `001_authentication` through `012_document_content_fingerprints`.

The live audit found no triggers, views, stored procedures or events. It found many:

- `ENUM` columns for status, state and source fields
- `tinyint(1)` boolean fields
- unsigned integer IDs and counters
- `DECIMAL(18,2)` and `DECIMAL(5,2)` money/metric columns
- `JSON` columns
- `utf8mb4_unicode_ci` table/column comparisons

No table exceeded 1 million rows. No `0000-00-00` sentinel was found. No case-variant duplicate email pair was found in the sampled users/customers audit.

The detailed read-only `SHOW CREATE TABLE` capture and migration inventory are retained outside the repository in the session artifact folder for Gate 0 evidence.

## File Storage

Documents use `PRIVATE_STORAGE_PATH`, defaulting outside the web root under `C:\xampp\vantiq-private-storage` on this host. The database records `stored_name`, `content_sha256`, category and organisation. Imports and documents are private uploads. The measured local storage had 3 files totaling 310702 bytes. Hosting disk persistence is not known and must not be assumed.

## Background and SSE

Transactional `outbox_events` are enqueued inside business transactions. `bin/process-outbox.php` claims, retries with backoff, and dispatches through `OutboxDispatcher`; failures retain state and replay is exposed. `/events/stream` closes the PHP session lock, streams 20 seconds with heartbeats and tenant/permission filtering. No cron/scheduler configuration was found.

## MySQL-Specific Constructs

Found application SQL includes backticks, `DATE_FORMAT`, `GROUP_CONCAT`, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`, `LIMIT`, and MySQL-specific repository branches. Gate 2/4 must replace these with portable SQL or explicit portable equivalents.

## Tests and Tooling

- PHP lint: passed.
- Core tests: 32/32 passed.
- Data workspace tests: 21 checks passed.
- Outbox tests: 16 assertions passed.
- Business tests: 5 of 6 scenario groups failed due to the test SQLite schema missing `sale_items.organisation_id` and coupled tenant/report assertions. This is a baseline test defect/risk, not changed in Gate 0.
- GitHub workflow only validates Composer; it does not run a test suite.
- Docker: PHP 8.2 Apache, frontend build, Composer, `pdo_mysql`; no `pdo_pgsql`, no Laravel.
- Render: only Docker/Apache files were found; no `render.yaml` was found.

## Gap and Risk Table

| Area | Status | Gap / risk |
|---|---|---|
| Laravel skeleton | Not implemented | No Laravel application exists. |
| PostgreSQL | Not implemented | No PgSQL PDO extension or connection. |
| Live schema parity | Partially implemented | SQL file is not the live full schema. |
| API contract | Documented | Characterization fixtures are not yet captured. |
| Auth parity | Partially known | bcrypt confirmed locally; Laravel cookie/Sanctum mapping remains. |
| Tenant isolation | Implemented legacy; migration pending | Existing repository predicates need Laravel global scoping. |
| RBAC | Implemented legacy; migration pending | Existing role tables can be mapped without a package. |
| Outbox/SSE | Legacy only | Queue and scheduler mapping remains. |
| Files | Local/private; no cloud layer | Production persistent storage unresolved. |
| ETL/verification | Not implemented | No legacy-to-Postgres tooling. |
| Deployment | Partial | Docker builds legacy stack; no Render manifest/worker/cron. |
| Real production audit | Unable to verify | No production database or backup was accessed. |

## Requirements Classification

- Backend structure inventory: **Fully implemented**
- Environment variable inventory: **Fully implemented**
- Auth/session audit: **Fully implemented** for local evidence; production hash distribution **Unable to verify**
- RBAC/tenant audit: **Fully implemented**
- Endpoint inventory: **Fully implemented**
- Real database `SHOW CREATE TABLE` inventory: **Partially implemented** (loopback/local copy captured; production copy unavailable)
- Data profile: **Partially implemented** (local copy; production volume/anomalies unable)
- MySQL-specific SQL: **Fully implemented**
- File/background/deployment audit: **Fully implemented**
- Gap/risk/API/data documents: **Fully implemented**

## Remaining Risks

Owner backup/restored copy is required before meaningful production data evidence, file-volume evidence or schema parity claims. Gate 1 must not assume `vantiq_schema.sql` is complete.

## Gate 0 Data-Risk Update

See `docs/migration/data-risks.md`; critical IDs DR-001 through DR-017 remain open.

## Next Session Scope

THIS SESSION: Gate 0 only. Do not start the next gate.

The exact scope line for Gate 1 is:

`THIS SESSION: Gate 1 only. Do not start Gate 2 or later gates.`

## Restored-Copy Gate 2 Parity Method

Gate 2 characterization fixtures will be recorded against a restored copy of the local XAMPP MySQL database, not the SQLite test databases.

The legacy API will be run against the restored MySQL copy and sanitized request/response fixtures will be saved for each endpoint. Laravel will execute the same fixtures against MySQL in Gate 1/2 and PostgreSQL in later gates. Volatile IDs, timestamps and random values will be explicitly documented as normalization exclusions.

### What the SQLite tests prove

- Core PHP repository/service behavior and parameterized SQL execution.
- Password verification/reset behavior in an in-memory SQLite environment.
- Session, CSRF, RBAC, and tenant logic for their existing unit fixtures.
- Selected data-workspace, outbox, workflow and business calculations.
- `tests/business.php` now proves the stale `sale_items.organisation_id` fixture issue is resolved and 6 focused business scenarios pass.

### What the SQLite tests do not prove

- Exact MySQL `SHOW CREATE TABLE`, engine, collations, defaults or foreign-key behavior.
- Real MySQL enum, unsigned, datetime, `ON UPDATE`, composite-FK or JSON behavior.
- PostgreSQL compatibility or case-sensitive/`citext` identity behavior.
- Production-volume performance or million-row chunking.
- Restored-copy migration fidelity, data checksums or sequence values.
- Final HTTP parity for every route: the legacy MySQL fixtures, not SQLite, will drive Gate 2 characterization.

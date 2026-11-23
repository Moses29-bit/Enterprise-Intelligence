# VANTIQ Phase 3C — Environment Recovery and Validation Report

## Executive status

**PARTIAL — Java build validation passed; complete database migration readiness is still blocked.** The backup was read-only inspected and checksummed, but it contains only 13 of the 51 inventoried tables. Java tests and packaging passed. No database was imported, connected to, or modified; no deployment was attempted.

## Readiness matrix

| Area | Status | Evidence / limitation |
|---|---|---|
| Read-only SQL backup access | **PASS** | Read-only file access and hashing completed at the exact requested path. |
| SQL backup integrity | **PASS — FILE HASH ONLY** | SHA-256: `FCD213C754B3C23B58E13DEB602CEE7DAB2F98DC47AC2C4045AFCB0A5F7B7E62`; 21,287 bytes; last write `2026-11-12T20:55:19.6796658Z`. |
| Backup completeness for migration | **FAIL** | Dump contains 13 of 51 inventoried tables; 38 are missing. No restore was attempted. |
| SQL syntactic validity / restoreability | **NOT TESTED** | A dump-completed footer is present, but no SQL parser or disposable restore was run. |
| Java/Maven process launch | **PASS** | Version checks, tests, and package commands completed. No allowlist, ACL, execution-policy, or global security changes were made. |
| Java compatibility | **PARTIAL** | JDK 27 is installed; the project compiles with Java 21 release targeting. A Java 21 runtime was not detected. |
| Maven | **PASS** | Apache Maven 3.9.11; no Maven Wrapper was found under `backend\spring`. |
| `mvn -B test` | **PASS** | 5 tests; 0 failures, 0 errors, 0 skipped. |
| `mvn -B package -DskipTests` | **PASS** | Spring Boot executable JAR packaged successfully; this command skipped tests as requested. |
| `git diff --check` | **PASS** | No whitespace errors found in the worktree diff. |
| Original MariaDB preservation | **PASS** | An XAMPP `mysqld.exe` process (PID 10884) was observed and left untouched. No connection or data-directory operation was made. |
| Disposable MariaDB | **BLOCKED** | Existing MariaDB 10.4.32 binaries are present, but no isolated data directory/instance was initialized or connection verified. `VANTIQ_PHASE3C_MARIADB_ROOT_PASSWORD` is not present locally; no test credential was invented. |
| Disposable PostgreSQL 15+ | **BLOCKED** | User approved PostgreSQL installation. Winget identifies the official PostgreSQL 17.12-1 package, but the current Windows token is not an administrator token. No installation or elevation was attempted. |
| Flyway/PostgreSQL validation | **NOT TESTED** | No disposable PostgreSQL connection was available. |
| Migration utility integration | **NOT TESTED** | Two utility unit tests passed; no database dry-run/apply or row reconciliation was performed. |
| Authentication/tenant integration | **NOT TESTED** | No protected Java business endpoints or cross-tenant HTTP integration test exist. |

## Permission recovery

- `Get-ExecutionPolicy -List` returned `Undefined` at all scopes. The checked ACLs allowed read/execute for the requested file and executable paths.
- `C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql` was read and hashed successfully.
- `C:\Program Files\Java\jdk-27\bin\java.exe` and `C:\Users\USER\AppData\Local\Programs\apache-maven-3.9.11\apache-maven-3.9.11\bin\mvn.cmd` launched successfully.
- The cause of the earlier `Access is denied` was not isolated; it did not reproduce. No policy or permission was broadened.
- The current Windows token is not a member of the local Administrators group. This is the concrete blocker to running the approved PostgreSQL installer in this session.

## SQL dump inspection

The file is `C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql`. It was not edited, executed, or imported. Structural inspection found:

- 13 `CREATE TABLE` and 13 `DROP TABLE IF EXISTS` statements.
- 10 `INSERT` statements; inserted row counts were not enumerated.
- 13 primary-key definitions, 27 secondary `KEY`/`UNIQUE KEY` clauses, and 14 named foreign-key constraints.
- A MariaDB dump-completed footer. This reduces concern about obvious truncation but does not prove syntax validity or restoreability.
- Tables: `audit_logs`, `branches`, `departments`, `login_attempts`, `login_history`, `organisations`, `password_reset_tokens`, `permissions`, `role_permissions`, `roles`, `schema_migrations`, `user_roles`, `users`.
- 38 missing inventory tables: `product_categories`, `products`, `customers`, `suppliers`, `sales`, `sale_items`, `expense_categories`, `expenses`, `invoices`, `invoice_items`, `credit_notes`, `credit_note_items`, `invoice_refunds`, `payments`, `stock_movements`, `purchase_orders`, `purchase_order_items`, `intelligence_insights`, `risk_alerts`, `forecasts`, `forecast_results`, `model_versions`, `decision_scenarios`, `scenario_assumptions`, `scenario_results`, `imported_files`, `import_errors`, `notifications`, `employees`, `ledger_accounts`, `ledger_entries`, `data_import_batches`, `data_import_rows`, `documents`, `integrations`, `integration_sync_runs`, `outbox_events`, `event_notifications`.

The dump has 72 columns. It uses unsigned auto-increment IDs, three ENUM fields, DATETIME values without timezone metadata, and LONGTEXT plus a JSON-validity check for `audit_logs.details`. It contains no finance/decimal tables. The `branches` and `departments` definitions lack `created_by` and `updated_at` found in the later target schema. Full column, type, key, and relationship details are in [VANTIQ_DATABASE_MIGRATION_INVENTORY.md](./VANTIQ_DATABASE_MIGRATION_INVENTORY.md). No credentials, password hashes, tokens, or record values are included in these reports.

## Build and tests

`mvn -B test` passed all five tests:

- Health application-context/endpoint test: 1.
- Migrator unit tests: 2.
- Legacy bcrypt-prefix test: 1.
- Tenant guard helper test: 1.

`mvn -B package -DskipTests` also passed. The test target ran under Java 27 while compiling with `--release 21`. JDK/Maven deprecation and dynamic-agent warnings were non-fatal.

The prior bcrypt test pair did not verify in the local PHP runtime. The test now creates a bcrypt hash in memory and applies the PHP-compatible `$2y$` prefix; this validates the prefix-conversion path but is not an independent PHP-generated hash fixture. Full legacy PHP hash interoperability remains unverified. Likewise, the tenant test is helper-level and does not prove protected endpoint or repository isolation.

## Database readiness and safe boundaries

- The XAMPP MariaDB 10.4.32 binaries exist. An XAMPP server process was observed running; it was not started, stopped, or contacted. Do not reuse `C:\xampp\mysql\data`.
- No isolated MariaDB instance has been created. A safe next attempt may use the existing binaries with a confirmed-new data directory and loopback-only port 3307, without touching the original process or data.
- PostgreSQL 15+ and Docker/Podman are unavailable. The user approved installation via the approved winget package source, but administrator privileges are required; no software was installed.
- No Flyway migration, database integration test, source export, record import, deployment, or production operation was performed.

## Files changed in Phase 3C

- Updated recovery/checksum/readiness, migration inventory/report, security, test, and roadmap documentation.
- Updated `HealthControllerTest` to assert the runtime-reported JVM feature version.
- Updated `LegacyPhpPasswordEncoderTest` to use an in-memory generated bcrypt hash with a `$2y$` prefix rather than an invalid stored fixture.
- Created this Phase 3C report.

## Required next actions

1. Provide or approve a complete source export covering all inventoried tables, then checksum it and restore only to a disposable MariaDB instance. The existing 13-table dump must not be used as the full migration source.
2. Set `VANTIQ_PHASE3C_MARIADB_ROOT_PASSWORD` locally (do not send the value in chat) so an isolated MariaDB instance can be initialized with a new data directory and port 3307.
3. Have an administrator run the approved PostgreSQL 17 installer with an isolated data directory and port 5433, or provide an existing disposable PostgreSQL endpoint. The package selected is `PostgreSQL.PostgreSQL.17` version 17.12-1.
4. Execute the actual Flyway migration against that disposable PostgreSQL instance; verify schema, indexes, constraints, sequences, and migration history.
5. Test the data migrator between the two disposable databases using synthetic fixtures, including failure, retry, and final reconciliation behavior.
6. Validate PHP-generated bcrypt compatibility without exposing any production hash, then implement and test the Java authentication, authorization, and tenant-scoped API flows before frontend cutover.

# VANTIQ Database Migration Report — Phase 2

## Result

**Status: BLOCKED — no live database migration or PostgreSQL execution was performed.** Java compilation, the available unit tests, and Spring packaging passed; disposable PostgreSQL validation remains unavailable.

The discovered SQL dump was inspected read-only and hashed, but it contains only 13 of the 51 inventoried source tables. The supplied Neon URI was pooled and had no password, so it was not used. Neon API access is blocked by a CLI execution-policy restriction and an MCP OAuth challenge; project/branch state could not be verified. The Spring migration SQL was inspected statically only; it was not run through Flyway or a PostgreSQL parser. Do not treat this report as evidence that data has been migrated or that the schema has passed runtime validation.

## Phase 3D Neon readiness

- The existing Neon project and the `vantiq-phase3d-test` branch have **not** been verified or created.
- No production branch, database, or role was queried or changed.
- No direct, unpooled JDBC URL was available. No database connection, initial-state capture, Flyway run, Spring integration test, or migrator database test was performed.
- Continue only after Neon MCP OAuth or a local `NEON_API_KEY` is available and a direct, unpooled branch connection is injected locally. Prefer a schema-only test branch to avoid copying parent rows; verify whether the requested branch already exists before creating it.

## Production target decision

The user designated the existing Neon `neondb` database on the production/default branch as the intended main application database, with this step limited to configuration. The application environment template now documents pooled runtime connectivity; the application defaults to `DATABASE_URL`, and Flyway startup is disabled unless explicitly enabled. Flyway has an explicit local direct-URL fallback so it cannot silently inherit the pooled runtime datasource when `DATABASE_URL_UNPOOLED` is absent. `DATABASE_URL_UNPOOLED` is reserved for a separately approved direct-connection migration command. No production connection, schema change, data migration, or deployment was performed in this configuration step.

## Repository schema reconciliation

- Source inventory: 51 business/technical tables.
- Flyway target: all 51 source table names are present, plus the utility-only `migration_checkpoints` table (52 target tables total).
- Available repository source DDL: `database/vantiq_schema.sql` and migrations 001–012. Migrations 001–007 are PHP scripts; migration 009 exists but is empty.
- Missing source evidence: a verified logical export, readable live migration history, and the live database's final DDL/metadata. An old SQL dump exists but was only partially inspected; raw `.frm`/`.ibd` files are not a verified backup.
- Migration 012’s `documents.content_sha256` column and `(organisation_id, content_sha256)` unique constraint are present in Flyway.
- Migration 011’s `idx_outbox_org_sequence` index was missing from Flyway and has now been added.
- Flyway adds tenant-scoped composite foreign keys and enum/check constraints. These strengthen the target schema but may reject legacy rows with cross-tenant references or undocumented enum values; source data has not been tested against them.
- The source schema uses `utf8mb4_unicode_ci`; PostgreSQL’s default unique/text comparisons are case-sensitive and differ in accent handling. PHP lowercases email input, but slug/code/name/SKU/invoice uniqueness semantics still require review before sign-off.
- The JDBC utility interprets source `DATETIME`/`TIMESTAMP` values as UTC and the generated target stores timezone-aware values. Confirm the legacy server/application timezone to prevent a wall-clock shift.
- PostgreSQL column-list `ON DELETE SET NULL (column)` syntax is used for composite tenant keys and requires PostgreSQL 15 or later. Neon SQL execution is still required to validate the migration.
- Flyway’s `flyway_schema_history` is separate from the legacy `schema_migrations` table, which is retained as source data.

### Available backup coverage and schema observations

The file `C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql` is 21,287 bytes, has SHA-256 `FCD213C754B3C23B58E13DEB602CEE7DAB2F98DC47AC2C4045AFCB0A5F7B7E62`, and includes a dump-completed footer. It contains 13 `CREATE TABLE` definitions and 10 `INSERT` statements. Its tables are `audit_logs`, `branches`, `departments`, `login_attempts`, `login_history`, `organisations`, `password_reset_tokens`, `permissions`, `role_permissions`, `roles`, `schema_migrations`, `user_roles`, and `users`. It is missing 38 of the 51 inventoried source tables and is not suitable as a complete migration source.

Read-only inspection found 72 columns, 13 primary-key definitions, 27 secondary key definitions, and 14 named foreign-key constraints. The schema includes unsigned auto-increment IDs, three ENUM fields, a JSON-validity check on `audit_logs.details` stored as LONGTEXT, and DATETIME timestamps. No finance/decimal tables are present. `branches` and `departments` lack `created_by` and `updated_at` found in the later target schema. Exact table/column details are in [VANTIQ_DATABASE_MIGRATION_INVENTORY.md](./VANTIQ_DATABASE_MIGRATION_INVENTORY.md).

The footer is evidence against obvious truncation, not proof of SQL syntax validity or restoreability. Exact inserted row counts and data values were not extracted. No migration statement in the dump was executed; its `DROP TABLE IF EXISTS` statements make execution against the original or a shared database unsafe.

### Table-by-table name crosswalk

“Present” means the table name was found in the repository source DDL/migrations and in the generated Flyway migration. It does **not** mean all columns, indexes, constraints, or records have been proven equal. The source schema files listed above are incomplete relative to the claimed 001–012 migration history.

| Source table | Source DDL evidence | Flyway target |
|---|---|---|
| `schema_migrations` | `database/vantiq_schema.sql` | Present |
| `organisations` | `database/vantiq_schema.sql` | Present |
| `users` | `database/vantiq_schema.sql` | Present |
| `roles` | `database/vantiq_schema.sql` | Present |
| `permissions` | `database/vantiq_schema.sql` | Present |
| `user_roles` | `database/vantiq_schema.sql` | Present |
| `role_permissions` | `database/vantiq_schema.sql` | Present |
| `branches` | Base DDL; amended by 008 | Present |
| `departments` | Base DDL; amended by 008 | Present |
| `audit_logs` | `database/vantiq_schema.sql` | Present |
| `password_reset_tokens` | `database/vantiq_schema.sql` | Present |
| `login_attempts` | `database/vantiq_schema.sql` | Present |
| `login_history` | `database/vantiq_schema.sql` | Present |
| `product_categories` | Base DDL; amended by 008 | Present |
| `products` | Base DDL; amended by 008 | Present |
| `customers` | Base DDL; amended by 008 | Present |
| `suppliers` | Base DDL; amended by 008 | Present |
| `sales` | Base DDL; amended by 008 | Present |
| `sale_items` | Base DDL; amended by 008 | Present |
| `expense_categories` | Base DDL; amended by 008 | Present |
| `expenses` | Base DDL; amended by 008 | Present |
| `invoices` | Base DDL; amended by 008 | Present |
| `invoice_items` | Base DDL; amended by 008 | Present |
| `credit_notes` | Base DDL; amended by 008 | Present |
| `credit_note_items` | Base DDL; amended by 008 | Present |
| `invoice_refunds` | Base DDL; amended by 008 | Present |
| `payments` | Base DDL; amended by 008 | Present |
| `stock_movements` | Base DDL; amended by 008 | Present |
| `purchase_orders` | Base DDL; amended by 008 | Present |
| `purchase_order_items` | Base DDL; amended by 008 | Present |
| `intelligence_insights` | Base DDL; amended by 008 | Present |
| `risk_alerts` | Base DDL; amended by 008 | Present |
| `forecasts` | Base DDL; amended by 008 | Present |
| `forecast_results` | Base DDL; amended by 008 | Present |
| `model_versions` | Base DDL; amended by 008 | Present |
| `decision_scenarios` | Base DDL; amended by 008 | Present |
| `scenario_assumptions` | Base DDL; amended by 008 | Present |
| `scenario_results` | Base DDL; amended by 008 | Present |
| `imported_files` | Base DDL; amended by 008 | Present |
| `import_errors` | Base DDL; amended by 008 | Present |
| `notifications` | Base DDL; amended by 008 | Present |
| `employees` | Migration 008 | Present |
| `ledger_accounts` | Migration 008 | Present |
| `ledger_entries` | Migration 008 | Present |
| `data_import_batches` | Migration 010 | Present |
| `data_import_rows` | Migration 010 | Present |
| `documents` | Migrations 010 and 012 | Present |
| `integrations` | Migration 010 | Present |
| `integration_sync_runs` | Migration 010 | Present |
| `outbox_events` | Migration 011 | Present |
| `event_notifications` | Migration 011 | Present |

`migration_checkpoints` is an additional target-only technical table, not a legacy source table.

## Column, type, and integrity checks

The migration utility now emits, per source column: source/target SQL type, nullability, default, declared size, numeric scale, and an identified conversion. Before importing a row it checks destination nullability, integer range, numeric precision/scale, and bounded text length. It canonicalizes JSON object keys for row hashing while preserving array order and hashes failed PKs in the report rather than exposing their raw values.

The following checks remain **unverified** until a recovered source copy and disposable target database are available:

- Every column and default against the final live source schema, including the missing migration history.
- Decimal precision/scale and unsigned maxima using actual source values.
- Primary-key, unique-key, and foreign-key definition parity.
- Actual foreign-key compatibility, enum/check values, and cross-tenant relationships.
- Index definition parity and performance relevance.
- Flyway execution and target PostgreSQL version behavior.
- Counts and row hashes for every business, authentication, financial, inventory, audit, and historical record.
- Identity sequence values after importing real IDs.

PostgreSQL constraints are enforced during an apply run and unvalidated constraints are rejected at completion. The utility does not yet introspect the source database’s complete FK/index definitions for an automatic source-to-target definition comparison. A read-only dry-run cannot prove that inserts will pass destination FK/unique constraints; its JSON report states this limitation.

## Migration utility verification

The current utility connects to MariaDB/MySQL and PostgreSQL over JDBC; it does not parse a `.sql` dump. It defaults to `--dry-run`, requires `--apply` to write, verifies the expected 51 source table names, preserves primary keys, hashes rows, commits/checkpoints per table, can recheck committed tables idempotently, resets `id` identity sequences, and emits a JSON table/column reconciliation report.

Two migration utility unit tests **passed** with `mvn -B test`. These are unit-level tests only; they do not prove source/destination database behavior. No database was connected and no records were imported. The complete result is in [VANTIQ_TEST_REPORT.md](./VANTIQ_TEST_REPORT.md).

## Required next steps

1. Follow [VANTIQ_SOURCE_DATABASE_RECOVERY.md](./VANTIQ_SOURCE_DATABASE_RECOVERY.md) to obtain a complete source export or recover a protected copy. Do not start, stop, or reuse the original data directory.
2. Read the actual `schema_migrations` rows and compare the live DDL against all repository migrations. Migrations 001–007 are present; migration 009 is empty.
3. Compile and run the utility in an approved environment; use a read-only source account and a disposable PostgreSQL destination first.
4. After Neon project/branch authorization, create or inspect the dedicated schema-only test branch, capture its initial schema/data state, then run Flyway against its direct unpooled connection.
5. Resolve every failed row/relationship without deleting or altering the source.
6. Require complete table counts, zero missing/failed PKs, hash reconciliation, sequence checks, and data-owner approval before any production migration.

# VANTIQ Database Migration Inventory

## Source

The repository inventory identifies 51 distinct MySQL/MariaDB tables. The source of truth is the live MariaDB/MySQL database; SQL files are schema evidence and must not replace live records.

No live source database export or database connection was available for Phase 2. Migration status is **NOT STARTED**.

Repository evidence currently available:

- `database/vantiq_schema.sql`
- `database/migrations/001_authentication.php` through `007_invoice_credits.php`
- `database/migrations/008_enterprise_data_repository.sql` through `012_document_content_fingerprints.sql`; migration 009 is present but zero bytes.
- The original `C:\xampp\mysql\data\vantiq` directory contains raw MariaDB table files. An old `C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql` dump was found and hashed read-only, but it contains only 13 of the 51 inventoried tables and has not been restore-tested.
- The Spring Flyway migration defines all 51 source table names plus the utility-only `migration_checkpoints` table (52 target tables total).
- `C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql` was read-only inspected and hashed: 21,287 bytes, SHA-256 `FCD213C754B3C23B58E13DEB602CEE7DAB2F98DC47AC2C4045AFCB0A5F7B7E62`. It contains only 13 of the 51 inventoried tables and is not a complete migration source. It has not been executed, restored, or imported.
- This is a repository-DDL inventory, not proof that the live source has the same columns, constraints, migration history, or records. See [VANTIQ_SOURCE_DATABASE_RECOVERY.md](./VANTIQ_SOURCE_DATABASE_RECOVERY.md), [VANTIQ_SOURCE_DATABASE_CHECKSUM_REPORT.md](./VANTIQ_SOURCE_DATABASE_CHECKSUM_REPORT.md), and [VANTIQ_MISSING_MIGRATIONS_REPORT.md](./VANTIQ_MISSING_MIGRATIONS_REPORT.md).

## Table inventory

| Table | Purpose |
|---|---|
| `schema_migrations` | Applied migration versions and timestamps |
| `organisations` | Tenant/organisation records |
| `users` | Authentication accounts and organisation membership |
| `roles` | Organisation/system roles |
| `permissions` | Permission codes |
| `user_roles` | User-to-role assignments |
| `role_permissions` | Role-to-permission grants |
| `branches` | Organisation locations |
| `departments` | Organisation departments and branch associations |
| `audit_logs` | Security and business audit history |
| `password_reset_tokens` | Hashed, expiring, single-use reset tokens |
| `login_attempts` | Throttling state |
| `login_history` | Login success/failure history |
| `product_categories` | Product grouping |
| `products` | Tenant inventory products and price/cost/stock fields |
| `customers` | Tenant customers |
| `suppliers` | Tenant suppliers |
| `sales` | Posted sales transactions |
| `sale_items` | Sale line items and sale-time cost snapshots |
| `expense_categories` | Expense classification |
| `expenses` | Expense transactions |
| `invoices` | Customer invoices and receivables |
| `invoice_items` | Invoice line items |
| `credit_notes` | Invoice credit note headers |
| `credit_note_items` | Credit note line details |
| `invoice_refunds` | Invoice refund/cash reversal records |
| `payments` | Invoice and customer payment records |
| `stock_movements` | Inventory receipts, adjustments, transfers, sales and returns |
| `purchase_orders` | Supplier purchase-order headers |
| `purchase_order_items` | Purchase-order lines |
| `intelligence_insights` | Stored enterprise intelligence findings |
| `risk_alerts` | Operational/financial risk records and reviews |
| `forecasts` | Forecast executions and metadata |
| `forecast_results` | Period forecast/actual values |
| `model_versions` | Analytics/model version records |
| `decision_scenarios` | Decision scenario headers |
| `scenario_assumptions` | Scenario assumptions |
| `scenario_results` | Scenario projected/actual metrics |
| `imported_files` | Legacy import file history |
| `import_errors` | Legacy import row errors |
| `notifications` | User/organisation notifications |
| `employees` | Tenant employee records |
| `ledger_accounts` | Tenant chart-of-account records |
| `ledger_entries` | Tenant ledger journal entries |
| `data_import_batches` | Data import batch lifecycle |
| `data_import_rows` | Batch row payload and validation state |
| `documents` | Uploaded document versions and extraction state |
| `integrations` | External integration configuration |
| `integration_sync_runs` | Integration sync attempts |
| `outbox_events` | Transactional domain event queue |
| `event_notifications` | Materialized in-app event notifications |

All 51 names above are present in the Flyway migration. The `migration_checkpoints` table is migration-control metadata and is not one of the source tables. Table presence does not establish column, constraint, index, or data parity; see [VANTIQ_DATABASE_MIGRATION_REPORT.md](./VANTIQ_DATABASE_MIGRATION_REPORT.md).

## Read-only inventory of the available SQL dump

The dump contains 13 tables, 72 columns, 13 primary-key definitions, 27 secondary `KEY`/`UNIQUE KEY` clauses, and 14 named foreign-key constraints. It contains 10 `INSERT` statements. Exact inserted row counts were not parsed. A dump-completed footer is present, but no SQL parser or restore test was run; syntactic validity and restoreability remain unverified. `DROP TABLE IF EXISTS` statements make execution against the original or any shared database unsafe.

| Dump table | Columns and principal types | Keys/relationships |
|---|---|---|
| `audit_logs` | `id` BIGINT UNSIGNED AUTO_INCREMENT; nullable `organisation_id`, `user_id` BIGINT UNSIGNED; `action_type`, `entity_name` VARCHAR(100); nullable `details` LONGTEXT with JSON-validity check; `created_at` DATETIME | PK `id`; indexes on organisation/time and user/time; nullable organisation/user FKs use `ON DELETE SET NULL` |
| `branches` | `id`, `organisation_id` BIGINT UNSIGNED; `name` VARCHAR(160); `code` VARCHAR(30); `created_at` DATETIME | PK `id`; unique organisation/code and organisation/id; organisation index; organisation FK cascades |
| `departments` | `id`, `organisation_id` BIGINT UNSIGNED; nullable `branch_id` BIGINT UNSIGNED; `name` VARCHAR(120); `code` VARCHAR(30); `created_at` DATETIME | PK `id`; unique organisation/code; organisation/branch indexes; composite branch and organisation FKs |
| `login_attempts` | `bucket_hash` CHAR(64); `failed_attempts` SMALLINT UNSIGNED default 0; `window_started_at`, nullable `locked_until`, `updated_at` DATETIME | PK `bucket_hash`; indexes on lock expiry and update time |
| `login_history` | `id` BIGINT UNSIGNED AUTO_INCREMENT; nullable organisation/user IDs; `email` VARCHAR(254); `status` ENUM(`success`,`failed`); `ip_hash` CHAR(64); `created_at` DATETIME | PK `id`; indexes by user/time, organisation/time, email/time; nullable organisation/user FKs use `ON DELETE SET NULL` |
| `organisations` | `id` BIGINT UNSIGNED AUTO_INCREMENT; `name` VARCHAR(180); `slug` VARCHAR(120); `status` ENUM(`active`,`inactive`,`suspended`) default `active`; `created_at`, `updated_at` DATETIME | PK `id`; unique slug; status index |
| `password_reset_tokens` | `id` BIGINT UNSIGNED AUTO_INCREMENT; `user_id` BIGINT UNSIGNED; `token_hash` CHAR(64); `expires_at`, nullable `used_at`, `created_at` DATETIME | PK `id`; unique token hash; user/expiry index; user FK cascades |
| `permissions` | `id` BIGINT UNSIGNED AUTO_INCREMENT; `code` VARCHAR(100); `description` VARCHAR(255); `created_at` DATETIME | PK `id`; unique permission code |
| `role_permissions` | `id`, `role_id`, `permission_id` BIGINT UNSIGNED; `created_at` DATETIME | PK `id`; unique role/permission; permission index; both FKs cascade |
| `roles` | `id` BIGINT UNSIGNED AUTO_INCREMENT; nullable `organisation_id`; `name` VARCHAR(120); nullable `description` VARCHAR(255); `is_system` TINYINT(1) default 0; `created_at` DATETIME | PK `id`; unique organisation/name; organisation index and cascading FK |
| `schema_migrations` | `version` VARCHAR(100); `applied_at` DATETIME | PK `version` |
| `user_roles` | `id`, `user_id`, `role_id` BIGINT UNSIGNED; `created_at` DATETIME | PK `id`; unique user/role; role index; both FKs cascade |
| `users` | `id` BIGINT UNSIGNED AUTO_INCREMENT; nullable `organisation_id`; `email` VARCHAR(254); `password_hash` VARCHAR(255); `full_name` VARCHAR(180); `status` ENUM(`active`,`inactive`,`suspended`); unsigned `session_version`; nullable `last_login_at`; `created_at`, `updated_at` DATETIME | PK `id`; unique email; organisation/status index; organisation FK cascades |

All temporal fields in this dump are MariaDB `DATETIME` (no time-zone metadata). The dump has no finance/decimal columns. `branches` and `departments` do not contain `created_by` or `updated_at`, fields present in the later target schema. The source server/application timezone must be established before converting DATETIME values to PostgreSQL `TIMESTAMPTZ`. These findings apply only to this dump and do not establish the live database schema.

## MySQL-specific conversion requirements

- `BIGINT UNSIGNED`/`INT UNSIGNED` become PostgreSQL `BIGINT`/`INTEGER` with database checks where negative values are invalid.
- `AUTO_INCREMENT` becomes `BIGINT GENERATED ALWAYS AS IDENTITY`.
- `TINYINT(1)` becomes `BOOLEAN` only where semantics are boolean.
- `DECIMAL(18,2)` and all other decimal amounts become `NUMERIC(...)`, never float/double.
- `ENUM` becomes PostgreSQL enum types or checked text columns. Checked text is preferred for migration compatibility unless type semantics justify named enums.
- `JSON` becomes `JSONB`.
- `TEXT` and `LONGTEXT` become `TEXT`.
- `CHAR(n)` remains fixed-width only where hashes or IDs require it.
- Source `DATETIME` values are interpreted as UTC by the JDBC migrator and written to the target `TIMESTAMPTZ` columns; verify the legacy application/server timezone before applying this conversion. The conversion must not shift wall-clock values.
- `DATE` becomes `DATE`.
- `ON UPDATE CURRENT_TIMESTAMP` becomes application/JPA timestamps or PostgreSQL triggers only where existing semantics require them.
- MariaDB/MySQL unsigned hashes and identifiers require range and overflow verification.
- The source schema uses `utf8mb4_unicode_ci`; PostgreSQL’s default text comparisons/unique constraints are case-sensitive and do not have identical accent behavior. The existing PHP validator lowercases email input, but text uniqueness/collation parity for slugs, codes, names, SKUs and invoice identifiers still needs an explicit compatibility decision and data/schema review.

## Authentication data

Existing password hashes are PHP `password_hash()` values. Spring Security's BCrypt verifier can verify compatible bcrypt hashes; hash cost and algorithm metadata must be preserved. No passwords should be reset or removed during migration.

## Exact legacy database recovery and export checklist

1. Confirm the authoritative XAMPP/MariaDB instance, database name, host/port, server version, and migration history. Migrations 001–012 are present in the repository; 009 is empty. Do not initialize a replacement database or run migrations against the original.
2. Obtain a read-only account with `SELECT`, `SHOW VIEW`, `TRIGGER`, `EVENT`, and routine metadata access as applicable. Never place its password in the command line or a checked-in file.
3. Record the server version, table list, table engines, `SHOW CREATE TABLE` output, and row counts. Confirm business tables use InnoDB so `--single-transaction` provides a consistent snapshot.
4. Export schema and data from the original database on the MariaDB machine:

```powershell
mysqldump --host=127.0.0.1 --port=3306 --user=<source_user> --password `
  --single-transaction --routines --triggers --events --hex-blob `
  --default-character-set=utf8mb4 --databases vantiq `
  --result-file=vantiq-mariadb-backup.sql
```

5. Also create a schema-only dump and record metadata/history:

```powershell
mysqldump --host=127.0.0.1 --port=3306 --user=<source_user> --password `
  --no-data --routines --triggers --events --default-character-set=utf8mb4 `
  --databases vantiq --result-file=vantiq-mariadb-schema.sql
mysql --host=127.0.0.1 --port=3306 --user=<source_user> --password `
  --database=vantiq --execute="SHOW FULL TABLES; SELECT VERSION();"
Get-FileHash .\vantiq-mariadb-backup.sql -Algorithm SHA256
Get-FileHash .\vantiq-mariadb-schema.sql -Algorithm SHA256
```

Capture each table’s `SHOW CREATE TABLE`, storage engine, exact row count, server SQL mode, and migration history. Do not treat InnoDB `information_schema.TABLES.TABLE_ROWS` estimates as exact source counts.
6. Verify both dumps exited successfully, are non-empty, can be read, and contain all 51 expected tables plus required routines/triggers/events. Record checksums and byte sizes. Store the files/checksums in access-controlled backup storage.
7. Preserve an independent, restorable copy. Restore only into a disposable MariaDB instance, for example with `mysql --host=<disposable-host> --user=<restore_user> --password < vantiq-mariadb-backup.sql`; compare its schema/table counts with the recorded source. Never restore over the original.
8. Supply the verified connection/export to the migration operator and run the converter only against a disposable PostgreSQL destination first. The current Java utility connects to MariaDB/MySQL over JDBC; it **does not parse SQL dump files**. To use a dump, restore it into a disposable MariaDB instance first.
9. Keep the source database unchanged and retain the backup until application and data-owner sign-off.

Do not pass a database password in `--password=<value>`, commit credentials, or transfer an unencrypted dump.

## Required migration utility

`backend/spring/src/main/java/io/vantiq/migration/VantiqDataMigrator.java` is a JDBC connector, not a dump parser. It defaults to read-only `--dry-run`; `--apply` is required to write. It checks the fixed 51-table source inventory, source/destination table and column presence, PK columns, destination required columns, row hashes, integer/numeric/text ranges, and emits per-column mappings and a JSON report. Applying processes tables in dependency order, uses original PKs, commits/checkpoints per table, uses idempotent conflict handling for retries, resets identity sequences, and refuses success for row mismatches or unvalidated PostgreSQL constraints.

The report distinguishes checks actually performed from those that a dry-run cannot perform. A dry-run does not execute inserts, so foreign-key/unique conflicts that PostgreSQL would reject are not proven until an apply run against a disposable destination. A failed apply table is rolled back; previously committed tables remain checkpointed and can be safely rechecked/retried, but the overall report is not a successful migration. The tool does not compare the complete live source FK/index definitions to destination definitions; this remains a schema reconciliation gate.

For an approved disposable test only, build the declared project and its runtime classpath, then run the migrator from `backend/spring/`:

```powershell
mvn -B -DskipTests package dependency:copy-dependencies
$env:VANTIQ_SOURCE_JDBC_URL = 'jdbc:mariadb://127.0.0.1:3306/vantiq'
$env:VANTIQ_SOURCE_SSL_MODE = 'DISABLE'
$env:VANTIQ_DESTINATION_JDBC_URL = 'jdbc:postgresql://<neon-host>/<database>?sslmode=require'
# Inject VANTIQ_SOURCE_DATABASE_USERNAME/PASSWORD and
# VANTIQ_DESTINATION_DATABASE_USERNAME/PASSWORD via an approved secret manager.
java -cp 'target\classes;target\dependency\*' io.vantiq.migration.VantiqDataMigrator `
  --dry-run --report=target\vantiq-migration-report.json
```

Set `VANTIQ_SOURCE_SSL_MODE` explicitly to a MariaDB Connector/J mode (`DISABLE`, `TRUST`, `VERIFY_CA`, or `VERIFY_FULL`): use `DISABLE` only for a trusted local MariaDB connection and `VERIFY_FULL` for a remote source where the certificate/hostname can be verified. Keep credentials in the process environment/secret manager, not in shell history or reports. This command is a template and was not run. Use `--apply` only after review and only against a disposable PostgreSQL destination in this phase.

## Integrity report

The utility report includes source/destination row counts, matched and newly inserted records, failed/missing counts, source fingerprint, PK columns and validation, constraint status, target-only columns, per-column source/target types/nullability/default/size/scale/conversion, failed-key digests, and warnings. Key values are not written to the report.

For each table, every source primary-key row must match a destination row by deterministic content hash. A complete apply requires zero missing/failed rows and all checked destination constraints validated. Reconcile source counts and authoritative business totals independently; do not treat a matching row count alone as integrity proof.

## Blocking dependency

The actual MariaDB database or a verified dump restored to a disposable MariaDB instance, the missing migration history, and Neon credentials are required. No connection or import was attempted. Render and Neon must not connect directly to XAMPP.

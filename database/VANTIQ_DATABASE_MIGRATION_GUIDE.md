# VANTIQ Database Migration Guide

## Status and safety

The original MariaDB has not been started, connected to, copied, or migrated. `C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql` was found but is an unverified pre-schema dump; its full contents and checksum are unavailable. Do not use Neon production, the original MariaDB data directory, or any live destination for initial validation.

The Java utility connects to MariaDB and PostgreSQL over JDBC; it does not parse a SQL dump. Restore a verified export into a disposable MariaDB instance before using the utility. See [VANTIQ_SOURCE_DATABASE_RECOVERY.md](./VANTIQ_SOURCE_DATABASE_RECOVERY.md) and [docs/migration/backup-runbook.md](./docs/migration/backup-runbook.md).

## 1. Recover and export the source

First verify the discovered dump using the recovery report. If it is incomplete, preserve a complete cold copy of the XAMPP data directory, recover only that copy using the matching MariaDB 10.4.32 runtime, and create a logical dump. Do not start the original data directory. Hash and test-restore the dump before migration.

## 2. Prepare a disposable PostgreSQL destination

Use PostgreSQL 15+ because the Flyway schema uses column-list `ON DELETE SET NULL` for composite tenant constraints. Use a disposable database/branch, not production. Use a direct (non-pooled) PostgreSQL URL for Flyway and migration operations. Require TLS for Neon with `sslmode=require`.

The Spring application reads:

```text
DATABASE_URL=jdbc:postgresql://<direct-host>/<database>?sslmode=require
DATABASE_USERNAME=<database-role>
DATABASE_PASSWORD=<secret>
```

Provide credentials through an approved secret manager or local process environment; do not put real values in source control or command history.

## 3. Validate Flyway locally

From `backend\spring`, first run the build/test commands in [VANTIQ_TEST_REPORT.md](./VANTIQ_TEST_REPORT.md). Point `DATABASE_URL`, `DATABASE_USERNAME`, and `DATABASE_PASSWORD` only at the disposable PostgreSQL database, then start Spring Boot so its configured Flyway integration applies the migration:

```powershell
Set-Location 'C:\xampp\htdocs\Enterprise Intelligence\backend\spring'
mvn -B clean test
mvn -B -DskipTests package
mvn -B spring-boot:run
```

Do not run the last command until disposable database credentials have been injected. Check Flyway's result, all 51 source table names, indexes, constraints, and identity sequences. Runtime validation has not yet been performed.

## 4. Run the migrator against disposable databases only

Build the project and copy runtime dependencies:

```powershell
Set-Location 'C:\xampp\htdocs\Enterprise Intelligence\backend\spring'
mvn -B -DskipTests package dependency:copy-dependencies
```

Inject these variables from a secret manager or approved local environment mechanism; do not type real passwords in commands:

```text
VANTIQ_SOURCE_JDBC_URL=jdbc:mariadb://127.0.0.1:3307/vantiq
VANTIQ_SOURCE_DATABASE_USERNAME=<disposable-source-user>
VANTIQ_SOURCE_DATABASE_PASSWORD=<secret>
VANTIQ_SOURCE_SSL_MODE=DISABLE
VANTIQ_DESTINATION_JDBC_URL=jdbc:postgresql://<direct-host>/<database>?sslmode=require
VANTIQ_DESTINATION_DATABASE_USERNAME=<disposable-destination-user>
VANTIQ_DESTINATION_DATABASE_PASSWORD=<secret>
```

`DISABLE` is only for a trusted local MariaDB test instance. Use a certificate-verifying MariaDB SSL mode for remote sources. Then start with a read-only dry run:

```powershell
java -cp 'target\classes;target\dependency\*' io.vantiq.migration.VantiqDataMigrator `
  --dry-run --report=target\vantiq-migration-report.json
```

Review the report and resolve every schema/value mismatch. Only after a reviewed dry run, disposable destination reset/recreation, and explicit approval for that test, use `--apply` instead of `--dry-run`. The utility is not authorized for production by this guide. Failed records or incomplete reconciliation are a failed run, not a successful migration.

## 5. Reconcile and retain

Compare exact table and row counts, source/destination row hashes, primary keys, foreign keys, financial totals, inventory totals, audit history, authentication records, and sequence values. Confirm the source `schema_migrations` history and timezone semantics. Keep the original unchanged and retain the verified backup until data-owner sign-off.

Never log or export plaintext passwords. Existing PHP password hashes must be preserved and verified by the compatible authentication implementation; do not reset users during data migration.

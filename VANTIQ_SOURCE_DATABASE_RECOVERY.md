# VANTIQ Source Database Recovery Report

## Result

**Recovery status: PARTIAL / NOT VERIFIED.** The SQL file was read and hashed without executing it. It contains only 13 of the 51 tables in the repository inventory, so it is not a complete migration source. No database was connected to, copied, imported, or modified.

## Evidence found

- `C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql` was read-only inspected. It is 21,287 bytes, last modified `2026-11-12T20:55:19.6796658Z`, SHA-256 `FCD213C754B3C23B58E13DEB602CEE7DAB2F98DC47AC2C4045AFCB0A5F7B7E62`.
- Structural inspection found 13 `CREATE TABLE` and 13 `DROP TABLE IF EXISTS` statements, 10 `INSERT` statements, 27 secondary `KEY`/`UNIQUE KEY` clauses, 13 primary-key clauses, and 14 named foreign-key constraints. A MariaDB dump-completed footer is present. No record values, password hashes, or tokens are included in this report.
- The dump tables are `audit_logs`, `branches`, `departments`, `login_attempts`, `login_history`, `organisations`, `password_reset_tokens`, `permissions`, `role_permissions`, `roles`, `schema_migrations`, `user_roles`, and `users`.
- The repository inventory has 51 tables, so 38 are absent from this dump. It is an old auth/admin-focused export, not a complete source for the financial, inventory, analytics, risk, decision, import, document, integration, or outbox domains. The dump's row counts and exact restorability were not tested.
- A completion footer and terminated table/data sections indicate no obvious truncation, but do not prove syntactic validity or restoreability. The dump was not executed or imported because it contains destructive `DROP TABLE IF EXISTS` statements.
- Read-only file access and Java/Maven process launch succeeded in this phase. `Get-ExecutionPolicy -List` reported `Undefined` at all scopes; the inspected file/executable ACLs permitted the operations. No policy, ACL, or global security setting was changed.
- No other logical dump or database backup archive was verified in the inspected project/archive. `frontend.zip` contains application files, not a database export.
- XAMPP's data directory contains a `vantiq` directory with MariaDB `.frm` and `.ibd` table files. These are raw physical database files, not a verified backup or a consistent logical export.
- `vantiq_restore_test` contains only `db.opt`; it is not a restored database.
- An XAMPP `mysqld.exe` process was observed running (PID 10884, executable under `C:\xampp\mysql\bin`). It was not started, stopped, or contacted. Do not treat it as a disposable server.
- The raw directory may be recoverable, but its clean-shutdown state, completeness, InnoDB redo/system tablespace consistency, and records have not been validated. Starting the original directory could change it, so it was not started.
- Service-manager and MariaDB error-log inspection were blocked by the command sandbox. No attempt was made to bypass those restrictions.

## Safe recovery procedure

Do not point a server at `C:\xampp\mysql\data` and do not run migrations against the original. A physical recovery requires preserving the complete MariaDB data directory, not just `data\vantiq`, because InnoDB system tablespaces and redo files may be shared. That directory may also contain databases outside VANTIQ; inspect and authorize the scope before making a copy.

1. Select an access-controlled backup location outside the repository and web root. Do not put database contents in Git.
2. Preserve the observed checksum and keep this dump read-only. Do not execute it against the original or a shared database: it contains `DROP TABLE IF EXISTS` and is missing 38 inventoried tables.
3. If the dump contains the complete schema and records, test-restore it into a separate disposable MariaDB instance on a different port and loopback-only bind. Check exact table/row counts, schema, migration history, and representative business totals. Hash and verify the test restore. A dump is not verified until this succeeds.
4. If physical recovery is required, coordinate with the database owner before any planned shutdown or copy. The original XAMPP process was running during this phase; this agent did not stop it or access its data directory.
5. Make a non-destructive, timestamped cold copy of the complete `C:\xampp\mysql\data` directory. Do not use `/MIR`, overwrite an existing backup, or copy only the VANTIQ subdirectory. Preserve the source directory unchanged; first inspect/authorize scope because the directory can contain other databases.
6. Generate a SHA-256 manifest for the copied files and verify the copy against the source. Store the manifest with the backup, not in the repository if absolute source paths are sensitive.
7. Recover only the copy using the matching MariaDB 10.4.32 binaries, an isolated configuration, a different port, and a loopback-only bind. If recovery requires `innodb_force_recovery`, stop and document the selected level before proceeding; use the least invasive level and never write recovered changes back to the source.
8. Once the clone starts, create a logical `vantiq` dump using `--single-transaction --routines --triggers --events --hex-blob`. Keep credentials out of command arguments and shell history; use the interactive password prompt or an approved protected option-file/secret mechanism.
9. Hash the new dump and test-restore it into a second disposable MariaDB instance. Retain the untouched original and verified backup until data-owner sign-off, then continue only to disposable PostgreSQL testing.

## Recovery blockers

- The discovered SQL dump is named `pre_schema_backup`; it is incomplete against the 51-table inventory and has not been restore-tested.
- No verified destination was selected for a full physical copy. The full XAMPP data directory can contain unrelated databases, so it was not copied.
- The XAMPP MariaDB process is running, but no connection was made. Service health, error logs, and live migration history have not been verified.
- No complete logical dump, exact live table/column metadata, source row counts, or readable `schema_migrations` history is available.
- The database credentials and Neon credentials are not available for a source/destination migration test.

See [VANTIQ_DATABASE_CHECKSUM_REPORT.md](./VANTIQ_DATABASE_CHECKSUM_REPORT.md) and [VANTIQ_SOURCE_DATABASE_CHECKSUM_REPORT.md](./VANTIQ_SOURCE_DATABASE_CHECKSUM_REPORT.md) for checksum status, and [VANTIQ_MISSING_MIGRATIONS_REPORT.md](./VANTIQ_MISSING_MIGRATIONS_REPORT.md) for migration-file findings.

# VANTIQ Database Checksum and Structural Inspection Report — Phase 3C

## Result

**Dump-file SHA-256: VERIFIED. Backup completeness/restorability: NOT VERIFIED.**

Path: `C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql`  
Size: 21,287 bytes  
Last modified (UTC): `2026-11-12T20:55:19.6796658Z`  
SHA-256: `FCD213C754B3C23B58E13DEB602CEE7DAB2F98DC47AC2C4045AFCB0A5F7B7E62`

The file was scanned read-only. No SQL was executed or imported, and no record values, password hashes, tokens, or credentials are reproduced.

## Permission outcomes

| Target | Phase 3C outcome |
|---|---|---|
| SQL backup path above | Read and hashed successfully | Read-only access verified; file unchanged |
| `C:\Program Files\Java\jdk-27\bin\java.exe` | Version check and Maven compilation/test launch succeeded | Process launch verified |
| `C:\Users\USER\AppData\Local\Programs\apache-maven-3.9.11\apache-maven-3.9.11\bin\mvn.cmd` | Maven 3.9.11 test and package commands completed | Process launch verified |

`Get-ExecutionPolicy -List` returned `Undefined` at all scopes. The checked ACLs granted the required read/execute access. No allowlist, ACL, execution policy, or global security setting was changed. The exact cause of the earlier `Access is denied` is not established; it no longer reproduced in this phase.

## Backup assessment

- The dump contains 13 tables, 10 `INSERT` statements, 13 `DROP TABLE IF EXISTS` statements, 13 primary-key definitions, 27 secondary key definitions, and 14 named foreign-key constraints.
- Present tables: `audit_logs`, `branches`, `departments`, `login_attempts`, `login_history`, `organisations`, `password_reset_tokens`, `permissions`, `role_permissions`, `roles`, `schema_migrations`, `user_roles`, `users`.
- 38 of the repository's 51 inventoried source tables are absent. The dump is therefore insufficient for the complete migration, regardless of its footer.
- A MariaDB dump-completed footer is present and the SQL appears structurally complete at the file end; syntax validity and restoreability have not been tested. The dump's destructive `DROP TABLE IF EXISTS` statements preclude any restore except into a clean disposable database.
- Exact inserted row counts and source data totals were not calculated. No destination database was available, so no restore or data-integrity comparison was made.
- The original XAMPP `mysqld.exe` process was observed running and was not started, stopped, or contacted. The physical data directory was not copied or modified.

Once the effective policy explicitly allows read-only file access, calculate SHA-256 and inspect the complete file without executing it. Compare all tables and DDL against the 51-table source inventory and migrations 001–012. Report only schema/table metadata and aggregate counts; suppress personal records and authentication material.

See [VANTIQ_SOURCE_DATABASE_RECOVERY.md](./VANTIQ_SOURCE_DATABASE_RECOVERY.md) for recovery boundaries and [VANTIQ_DISPOSABLE_DATABASE_READINESS.md](./VANTIQ_DISPOSABLE_DATABASE_READINESS.md) for test-instance status.

# VANTIQ Phase 3D — Neon PostgreSQL Validation Report

## Executive status

**PARTIAL / CORE DATABASE VALIDATION PASSED — Direct Neon JDBC startup and Flyway are verified; Neon synthetic integration and source migration remain uncompleted.**

## Production database decision (configuration only)

The user designated the existing `neondb` database on the production/default branch as the intended main application database. The application template now uses pooled `DATABASE_URL` for runtime traffic and keeps Flyway startup disabled by default. This is a target configuration only: no production connection, query, migration, data import, or deployment was performed. Actual production host credentials must be injected through the hosting secret store, not committed to `.env.example`.

## Acceptance matrix

| Acceptance item | Status | Evidence |
|---|---|---|
| Existing VANTIQ Neon project verified | **PASS** | Project `vantiq` (`odd-scene-03863534`) is PostgreSQL 18. |
| `vantiq-phase3d-test` branch created | **PASS** | Branch ID `br-steep-tooth-besyvy9k`; ready and non-primary/non-default. |
| Branch confirmed dedicated and non-production | **PASS** | Child of `production` branch `br-super-mouse-bevtt5n9`; no reset/delete performed. |
| Initial branch schema and data state recorded | **PASS — INITIAL STATE ONLY** | Read-only SQL found PostgreSQL 18.7, `neondb_owner`, empty public VANTIQ schema, no Flyway history, inherited Neon Auth tables. |
| Direct unpooled connection acquired | **PASS** | The prior validation record states that Neon CLI returned a direct host without the `-pooler` suffix and with TLS properties; the hostname is intentionally not recorded here. |
| Database connection verified through Spring | **PASS** | Spring JAR started with Hikari; Hibernate reported PostgreSQL 18.7; `/api/health` returned HTTP 200 with `database.connected=true`. |
| Flyway migration executed | **PASS** | `mvn -B flyway:migrate` applied V1 to `vantiq` successfully. |
| Schema and Flyway history validated | **PASS** | History has schema creation row and V1 row with success=true and checksum 1531327758. Catalog found 51 source tables, 124 foreign keys, 41 unique constraints, 36 checks, 49 identity columns, 183 defaulted columns, 169 indexes, and 110 TIMESTAMPTZ columns. |
| Spring integration tests passed on Neon | **NOT TESTED** | A gated synthetic JDBC integration test was added, but no successful Neon-run result was completed. Default Maven suite skipped it. |
| Synthetic migrator test on Neon | **NOT TESTED** | Migrator unit tests passed; no source/destination synthetic database apply executed. |
| Full source-data migration validated | **NOT STARTED** | The available MariaDB dump contains only 13 of 51 tables and is not the complete source. |
| Production safety | **PASS** | The production branch was only read; no migration or destructive operation was run. |
| Local PostgreSQL installation | **NOT USED** | Explicitly prohibited by the Phase 3D directive. |

## Access and connection findings

- Neon MCP project, branch, schema, and read-only SQL operations were authenticated and completed.
- The Neon CLI context was set to project `odd-scene-03863534` and branch `br-steep-tooth-besyvy9k`.
- No local database credential or direct unpooled URL is reproduced in this report.
- Pooled MCP connection data was not used for Flyway.
- Spring reportedly used a direct URL with `sslmode=require` and `channel binding` injected in process memory; no URL, host, username, or password is included here.
- The Spring JAR was started with production profile and validated Flyway at startup.

## Branch safety decision

The existing test branch is a normal copy-on-write branch and inherited Neon Auth data. VANTIQ objects were isolated in `vantiq`; Neon Auth remains in `neon_auth`. No schema-only branch was required because synthetic VANTIQ tests do not use or modify Neon Auth data.

Official references:

- [Neon branching](https://neon.com/docs/introduction/branching)
- [Schema-only branches](https://neon.com/docs/guides/branching-schema-only)
- [Neon CLI branch command](https://neon.com/docs/cli/branches)

## Source MariaDB boundary

The original XAMPP MariaDB process was left untouched. No disposable MariaDB source was established. The prior SQL dump remains an incomplete 13-table export; it was not imported or treated as a full source. Obtain and validate a complete source export separately before any full data migration.

## Files updated

- [VANTIQ_DISPOSABLE_DATABASE_READINESS.md](./VANTIQ_DISPOSABLE_DATABASE_READINESS.md)
- [VANTIQ_DATABASE_MIGRATION_REPORT.md](./VANTIQ_DATABASE_MIGRATION_REPORT.md)
- [VANTIQ_TEST_REPORT.md](./VANTIQ_TEST_REPORT.md)
- [VANTIQ_IMPLEMENTATION_ROADMAP.md](./VANTIQ_IMPLEMENTATION_ROADMAP.md)
- Created [VANTIQ_FLYWAY_VALIDATION_REPORT.md](./VANTIQ_FLYWAY_VALIDATION_REPORT.md)
- Created [VANTIQ_MIGRATION_UTILITY_VALIDATION_REPORT.md](./VANTIQ_MIGRATION_UTILITY_VALIDATION_REPORT.md)
- Created this report

## Required next actions

1. Authenticate Neon MCP in VS Code, or set `NEON_API_KEY` locally for Neon REST API access. Do not paste credentials into chat.
2. Inject a direct, unpooled Neon JDBC URL and credentials locally. Confirm the URL is not pooled and uses TLS.
3. List the existing VANTIQ project and branches. Inspect the requested branch if it already exists; do not reset or delete it.
4. If needed, create `vantiq-phase3d-test` as a schema-only branch in the existing project after confirming its parent. Record initial schema, table row counts, database, role, and PostgreSQL version before any migration.
5. Run Flyway and Spring integration tests only on the verified test branch. Verify every expected table/column/index/constraint and `flyway_schema_history`.
6. Establish a separate disposable MariaDB source and run the migrator with synthetic records. Do not perform full migration until the original source is fully recovered and reconciled.

No secrets, connection strings, credentials, or database record values are included in this report.

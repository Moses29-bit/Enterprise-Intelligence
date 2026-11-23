# VANTIQ Database Readiness — Phase 3D

## Result

**PARTIAL — The Neon test branch was created, direct Spring JDBC passed, and Flyway passed; synthetic Neon integration and source migration remain untested.** The local PostgreSQL installation path is not being used, per the Phase 3D directive.

## Readiness matrix

| Requirement | Status | Evidence |
|---|---|---|
| Existing VANTIQ Neon project verified | **PASS** | Project `vantiq` (`odd-scene-03863534`) is PostgreSQL 18 in the authenticated organization. |
| Neon API credential available | **PASS** | Neon MCP project and branch operations were authenticated. |
| Test branch `vantiq-phase3d-test` exists | **PASS** | Branch ID `br-steep-tooth-besyvy9k`; ready and non-primary. |
| Branch confirmed separate from production | **PASS** | Child of `production` branch `br-super-mouse-bevtt5n9`; no reset or deletion was performed. |
| Database/schema/data state recorded | **PARTIAL** | Read-only SQL confirmed `neondb`, role `neondb_owner`, PostgreSQL 18.7, inherited Neon Auth tables, empty public VANTIQ schema, and no Flyway history. |
| Direct, unpooled JDBC URL available | **HOST VERIFIED** | Neon CLI returned a direct host with no `-pooler` suffix and `sslmode=require`/channel binding properties. Application/JDBC startup did not complete. |
| Database connection verified | **PARTIAL** | Neon SQL MCP connection passed; direct JDBC application connection did not complete. |
| Local PostgreSQL | **NOT USED** | Explicitly excluded by the Phase 3D directive. |
| MariaDB disposable source | **BLOCKED** | XAMPP 10.4.32 binaries exist, but no isolated instance was created. The original process (PID 10884) was left untouched. |
| Original source export | **INCOMPLETE** | Available dump has 13/51 inventoried tables and must not be used as the full source. |

## Neon access diagnostics

- Neon MCP project, branch, and read-only SQL operations were authenticated and completed.
- The normal test branch clone inherited the production branch state, including Neon Auth tables. VANTIQ tests must not read or modify inherited auth records.
- MCP pooled connection data was not used for Flyway or printed in reports. Direct JDBC application startup did not become reachable and was stopped.

## Safe next steps

The user has designated the existing Neon project's `neondb` database on its production/default branch as the intended main application database. The workspace now documents pooled `DATABASE_URL` for runtime traffic and keeps automatic Flyway startup disabled by default; `DATABASE_URL_UNPOOLED` is reserved for an explicitly approved migration. This does not mean production credentials are configured or that a production connection was tested.

1. Authenticate Neon MCP in VS Code, or set `NEON_API_KEY` locally for the Neon REST API. Do not paste credentials into chat.
2. Provide `DATABASE_URL_UNPOOLED` (direct, non-pooler) and its database username/password through local environment injection. For Spring, use JDBC form `jdbc:postgresql://...`; for the migrator, use `VANTIQ_DESTINATION_JDBC_URL` and `VANTIQ_DESTINATION_DATABASE_USERNAME` / `VANTIQ_DESTINATION_DATABASE_PASSWORD`. Use TLS.
3. Identify the existing VANTIQ project and inspect its branches. Check whether `vantiq-phase3d-test` already exists; if it does, inspect it without resetting or deleting it.
4. Create a dedicated schema-only test branch only after project/parent verification. Schema-only branching is a Neon Beta feature. It avoids copying table rows from the parent; verify the new branch's initial schema and confirm tables contain no source rows before migration.
5. Record project, branch, database, role, PostgreSQL version, schema inventory, table row counts, and initial Flyway history. Never run migration SQL on the production branch.
6. Keep the original XAMPP MariaDB process and data directory untouched. Recover a complete source export separately; the available 13-table dump is insufficient.

No table/data values, connection strings, credentials, or password hashes are included in this report.

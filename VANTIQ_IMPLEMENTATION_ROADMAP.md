# VANTIQ Implementation Roadmap

## Phase 1 — Audit: COMPLETE (repository evidence)

Status:

- [x] Repository structure inspected.
- [x] Frontend structure and routing identified.
- [x] Existing PHP API and 71 route registrations identified.
- [x] Database migration files and 51 distinct tables identified.
- [x] Authentication, sessions, RBAC and tenant mechanisms identified.
- [x] Functional and partial modules identified.
- [x] Automated TypeScript dependency assessment completed.
- [ ] Live MariaDB source database unavailable.
- [ ] Neon project authentication and direct, unpooled test-branch credentials unavailable.
- [ ] Render/Vercel deployment credentials unavailable.

## Phase 2 — Backend and migration readiness: INCOMPLETE

Completed in the repository:

- [x] Java 25 set in Maven and Docker build.
- [x] Flyway PostgreSQL schema contains the 51 source table names and a migration-only checkpoint table.
- [x] Read-only-by-default JDBC migrator, explicit `--apply`, per-table commits/checkpoints, row hashes, identity reset, and JSON report.
- [x] Added column value range checks and migration utility unit tests (five total tests passed in Phase 3C; database integration remains untested).
- [x] Legacy PHP bcrypt verification compatibility helper.
- [x] Found and added the migration 011 outbox organisation-sequence index.
- [x] Updated migration, security, API, test, and roadmap documentation to distinguish implemented from blocked work.
- [x] Maven compilation, unit tests, and Spring Boot packaging now execute locally; five tests passed and the package command succeeded.
- [x] Read-only inspection and SHA-256 of the available SQL dump completed.

Blocked / incomplete:

- [ ] Neon/Flyway syntax/runtime validation: the Neon CLI shim is blocked by PowerShell script policy and Neon MCP requires OAuth; no branch/database connection was made.
- [ ] Final source schema: the dump has only 13 of the 51 inventoried tables; live MariaDB DDL/data and applied migration history are unavailable.
- [ ] Automatic source/target FK and full index definition parity comparison.
- [ ] Full application authentication/JWT/refresh flow, tenant-scoped repositories/endpoints, and business module ports.
- [ ] Migration utility database dry-run/apply validation with synthetic MariaDB source data and Neon test destination.
- [ ] Direct Neon test-branch credentials and Render/Vercel deployment verification.
- [x] Designate Neon `neondb` on the production/default branch as the intended application database; configure pooled runtime URL placeholders and keep startup Flyway disabled. No production connection or migration was performed.

Exit criteria remain unmet: recover a complete source, validate Flyway on disposable PostgreSQL, and produce a successful full reconciliation on a restored source copy.

## Phase 3 — Recovery and migration foundation: PARTIAL / BLOCKED

- [x] Searched workspace SQL, archives, backups, and recovery documentation.
- [x] Located raw XAMPP MariaDB table files under `C:\xampp\mysql\data\vantiq`; an XAMPP `mysqld.exe` process was observed later and left untouched.
- [x] Corrected the migration inventory: PHP migrations 001–007 exist; migration 009 is empty.
- [x] Read-only checksum and structural inspection of the pre-schema dump: 13 tables, 38 fewer than the 51-table repository inventory.
- [x] Maven test and package commands completed successfully: 5 tests passed; executable JAR packaged.
- [ ] Create a protected cold copy of the complete MariaDB data directory after owner approval of scope and destination. Do not start, stop, or overwrite the original.
- [ ] Recover a complete source export and verify a restore into a separate disposable MariaDB instance; the found dump is incomplete for migration.
- [ ] Read actual applied migration history and reconcile live DDL/columns/constraints against Flyway.
- [ ] Provision a disposable MariaDB instance in a new data directory.
- [x] Identify the existing VANTIQ Neon project and create `vantiq-phase3d-test`; verify it is ready, non-primary, non-default, and separate from production.
- [x] Verify direct Neon TCP/JDBC connectivity and Spring Hikari startup.
- [x] Execute and validate Flyway V1 in dedicated `vantiq` schema.
- [ ] Complete gated synthetic Neon integration tests and migration utility database apply/dry-run tests.

No database connection/import was attempted. An XAMPP `mysqld.exe` process was observed running and was left untouched. No source directory was copied or modified. Read-only backup access and Java/Maven execution succeeded without changing host policy or ACLs. See [VANTIQ_SOURCE_DATABASE_RECOVERY.md](./VANTIQ_SOURCE_DATABASE_RECOVERY.md), [VANTIQ_DATABASE_CHECKSUM_REPORT.md](./VANTIQ_DATABASE_CHECKSUM_REPORT.md), and [VANTIQ_DISPOSABLE_DATABASE_READINESS.md](./VANTIQ_DISPOSABLE_DATABASE_READINESS.md).

## Phase 3 — Spring foundation: PARTIAL

Existing `backend/spring/` foundation is present with Java 25 configuration:

- Spring Boot 3.
- Spring Data JPA/Hibernate.
- PostgreSQL driver.
- Flyway.
- Spring Security.
- JWT and refresh-token support.
- Jakarta Validation.
- OpenAPI.
- Actuator health endpoint.
- Centralized exception handling.
- Environment-only secrets.
- Docker and Render configuration.

Spring compilation and tests pass locally under JDK 25 with Java 25 release targeting. Authentication endpoints/JWT and business APIs remain unimplemented; PostgreSQL/Flyway execution is unverified. Do not deploy this foundation.

## Phase 4 — Business modules

Implement in vertical slices:

1. Identity and organisation.
2. RBAC and tenant isolation.
3. Finance and inventory.
4. Reporting and analytics.
5. Risk and decisions.
6. Forecasting.
7. Import/integration.
8. Documents.
9. Audit and outbox.

Preserve transactional integrity and existing API field names where practical.

## Phase 5 — Data migration: NOT STARTED

Execute only after:

- Source database backup/checksum is complete.
- Existing Neon project is authenticated and a dedicated test branch is verified.
- Direct, unpooled Neon JDBC credentials are injected locally.
- PostgreSQL schema passes Flyway.
- Migration utility has been tested on a restored/staged source.
- Backup and rollback procedures are confirmed.

Do not claim success until reconciliation passes.

## Phase 6 — Frontend integration: NOT STARTED

- Centralize `VITE_API_BASE_URL`.
- Update authentication only as needed.
- Preserve existing UI components and routes.
- Retain loading, empty and error states.
- Add exact CORS configuration.
- Verify login, refresh, module APIs and SSE behavior.

## Phase 7 — Testing: PARTIAL

Maven `test` passed (5 tests) and `package -DskipTests` succeeded. PostgreSQL/Flyway tests, endpoint-level security/tenant isolation, migrator database integration tests, and this phase's frontend rebuild remain outstanding. Live Neon/Render/Vercel tests remain blocked until isolated endpoints and credentials exist.

## Phase 8 — Cloud deployment: NOT STARTED

Create Neon database, Render Web Service and Vercel environment configuration. Keep passwords and tokens outside Git.

## Phase 9 — Final verification: NOT STARTED

Required evidence:

- Implemented code.
- Passing tests.
- Docker build.
- Neon/Flyway migration.
- Source/destination reconciliation.
- Tenant isolation.
- Live login and module workflows.
- Vercel-to-Render-to-Neon HTTPS connectivity.
- Deployment status and rollback evidence.

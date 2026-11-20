# VANTIQ Flyway Validation Report — Phase 3D

## Result

**PASS — Flyway migration and Spring JDBC startup completed.** The Neon project/test branch were verified and V1 was applied once to the dedicated `vantiq` schema.

## Validation matrix

| Requirement | Status | Evidence |
|---|---|---|
| Existing Neon project identified | **PASS** | Project `vantiq` (`odd-scene-03863534`) identified. |
| Dedicated test branch verified | **PASS** | `vantiq-phase3d-test` (`br-steep-tooth-besyvy9k`) ready and non-primary/non-default. |
| Initial schema/data state recorded | **PASS — INITIAL STATE ONLY** | PostgreSQL 18.7, `neondb_owner`, public VANTIQ tables absent, no Flyway history, inherited Neon Auth tables present. |
| Direct unpooled JDBC URL | **PASS** | Neon CLI direct host had no `-pooler` suffix; TCP 5432 succeeded and JDBC used TLS. |
| Connection verified | **PASS** | Spring Hikari created a PostgreSQL 18.7 connection and `/api/health` reported connected=true. |
| Flyway migration executed | **PASS** | `mvn -B flyway:migrate` applied V1 once. |
| Flyway history verified | **PASS** | `vantiq.flyway_schema_history` contains SCHEMA creation and V1 success; checksum 1531327758. |
| Tables, columns, indexes, constraints verified at runtime | **PARTIAL/PASS** | 51 source tables plus 2 metadata tables; 124 FK, 41 unique, 36 check, 49 identity, 183 defaulted, 169 index objects, 110 TIMESTAMPTZ columns. Detailed source/live column parity remains blocked. |
| Spring integration tests against Neon | **NOT TESTED** | Gated synthetic JDBC test exists but did not produce a successful Neon run. |
| Production safety | **PASS** | Production branch was not migrated or modified. |

## Static project configuration reviewed

`backend/spring/pom.xml` declares Spring Boot 3.4.12, Flyway Core, `flyway-database-postgresql`, and the PostgreSQL JDBC driver.

`backend/spring/src/main/resources/application.yml` currently:

- Enables Flyway, with `baseline-on-migrate: false` and `validate-on-migrate: true`.
- Uses `spring.jpa.hibernate.ddl-auto: validate`.
- Reads the datasource URL, username, and password from `DATABASE_URL`, `DATABASE_USERNAME`, and `DATABASE_PASSWORD`.
- Has a localhost JDBC URL as the fallback; it must not be mistaken for a verified Neon connection.

The supplied PostgreSQL URI was not usable for this validation: it specified Neon's pooled endpoint and contained no password. Flyway schema work must use a direct/unpooled JDBC URL with TLS, injected locally. Do not copy the pooled URI into Spring configuration.

The repository's Flyway migration statically declares the 51 inventoried source-table names plus a utility-only migration checkpoint table. This is not proof of SQL compatibility, schema completeness, or runtime constraints.

## Required safe sequence

1. Authenticate with Neon MCP in VS Code or provide a local `NEON_API_KEY` for the Neon REST API. Do not paste credentials into chat.
2. Verify the existing project and inspect branches; confirm whether `vantiq-phase3d-test` already exists. Do not reset/delete an existing branch.
3. If creation is needed, use a schema-only branch in the existing project after verifying its parent. Schema-only branching is documented by Neon as Beta and should be confirmed to contain no source rows.
4. Record the branch ID, parent, database, role, PostgreSQL version, initial schema, table row counts, and Flyway history before migration.
5. Inject the direct, unpooled JDBC URL and credentials locally. Confirm hostname has no `-pooler` suffix and that SSL/TLS is required.
6. Run Flyway only on the verified test branch. Capture exact command output and migration history without logging credentials.
7. Query `information_schema` and PostgreSQL catalogs to reconcile every expected table, column, index, primary/foreign/unique key, check, default, and sequence.
8. Run Spring integration tests against this same test branch, then record each test's actual result.

No secrets, connection strings, user data, or production records are included in this report.

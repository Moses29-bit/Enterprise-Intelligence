# VANTIQ Test and Validation Report — Phase 3C

## Overall result

**Java build and local tests: PASS. Database migration validation: BLOCKED.** The application compiled and packaged; five local tests passed. No source database was connected to, no SQL was executed or imported, and no deployment was attempted.

## Execution environment

| Check | Result | Evidence |
|---|---|---|
| Java | PASS | OpenJDK 27; project compiles with Maven `--release 21`. JDK 21 itself was not detected. |
| Maven | PASS | Apache Maven 3.9.11. |
| Maven Wrapper | NOT PRESENT | No `mvnw.cmd` was found under `backend\spring`. |
| Process launch | PASS | Java and Maven version checks, test, and package commands completed. No execution policy or ACL was changed. |

## Backend validation

| Command | Result | Actual outcome |
|---|---|---|
| `mvn -B test` | **PASS** | 5 tests run, 0 failures, 0 errors, 0 skipped. |
| `mvn -B package -DskipTests` | **PASS** | Spring Boot executable JAR packaged successfully; tests were intentionally skipped in this command. |

Test breakdown:

- `HealthControllerTest`: 1 passed; Spring application context and health endpoint ran against in-memory H2, not PostgreSQL.
- `VantiqDataMigratorTest`: 2 passed; unit-level behavior only, with no MariaDB/PostgreSQL connection.
- `LegacyPhpPasswordEncoderTest`: 1 passed; exercises `$2y$` prefix conversion using an in-memory generated bcrypt hash.
- `TenantAccessGuardTest`: 1 passed; helper-level test only, not a protected HTTP endpoint or repository integration test.

Two test corrections were made:

- Health API test now checks the reported JVM feature version rather than hardcoding 21. The application still targets Java 21 bytecode.
- The prior bcrypt test pair did not verify successfully with the local PHP `password_verify`; it was replaced with a generated bcrypt hash using the PHP-compatible `$2y$` prefix. This verifies prefix conversion, but is not an independently PHP-generated interoperability fixture. A genuine non-personal PHP-generated fixture still needs validation before claiming full legacy hash compatibility.

Maven/JDK emitted deprecation, dynamic-agent, and Mockito warnings; these did not fail the build. No generated development credential or authentication material is included in this report.

## Database, Flyway, and migrator

| Check | Result | Evidence |
|---|---|---|
| Original MariaDB connection | **NOT TESTED** | The XAMPP `mysqld.exe` process was left untouched and not contacted. |
| Disposable MariaDB | **BLOCKED** | MariaDB 10.4.32 binaries exist, but no separate data directory or instance was initialized. |
| Disposable PostgreSQL 15+ | **BLOCKED** | PostgreSQL, Docker, and Podman were not detected. |
| Flyway migration execution | **NOT TESTED** | No PostgreSQL endpoint was available. |
| PostgreSQL schema validation | **NOT TESTED** | Static SQL inspection is not execution evidence. |
| Migration utility unit tests | **PASS** | Two unit tests passed as part of Maven test; no database connections were used. |
| Data migration dry-run/apply | **NOT TESTED** | No source/destination database was connected; no records were imported. |
| Retry/checkpoint/constraint integration | **NOT TESTED** | Requires verified disposable MariaDB and PostgreSQL instances. |

The read-only dump at `C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql` has SHA-256 `FCD213C754B3C23B58E13DEB602CEE7DAB2F98DC47AC2C4045AFCB0A5F7B7E62`. It contains 13 tables versus the 51-table repository inventory and is not a complete migration source. Details and limitations are in [VANTIQ_DATABASE_CHECKSUM_REPORT.md](./VANTIQ_DATABASE_CHECKSUM_REPORT.md) and [VANTIQ_SOURCE_DATABASE_RECOVERY.md](./VANTIQ_SOURCE_DATABASE_RECOVERY.md).

## Security and API validation

- The Java bcrypt prefix conversion and tenant helper have local unit tests, but legacy PHP hash interoperability is not yet independently verified with a PHP-generated fixture.
- No Java login, refresh, reset, role/permission, or protected business API is implemented. No cross-tenant HTTP integration test exists.
- The existing PHP session/CSRF flow remains the frontend compatibility baseline; do not switch the frontend to JWT.
- The compatibility inventory identifies 71 PHP routes; only the Java health endpoint is implemented.
- No route or database behavior is considered production-verified.

## Frontend

The Phase 2 TypeScript typecheck and production build were reported passing. They were not rerun in Phase 3C.

## Next validation gates

1. Recover a complete MariaDB source export, and use only a disposable source instance; never reuse `C:\xampp\mysql\data`.
2. Authenticate Neon access, inspect the existing project/branches, and verify or create `vantiq-phase3d-test` as a schema-only branch.
3. Execute Flyway on the verified Neon test branch using a direct, unpooled JDBC URL; verify schema, indexes, constraints, and identity sequences.
4. Run migrator tests with synthetic fixtures across the disposable MariaDB source and Neon test branch, including failed-row and retry checks.
5. Add endpoint-level authentication and cross-tenant integration tests when real protected APIs are implemented.
6. Validate PHP-generated bcrypt compatibility without exposing any production hashes; then rerun the tests and `git diff --check`.

## Phase 3D Neon update

- PASS: Direct Neon TCP 5432 and direct host without `-pooler` verified.
- PASS: Spring JAR started with Hikari on PostgreSQL 18.7.
- PASS: `/api/health` returned HTTP 200 with `status=ok`, `database.connected=true`.
- PASS: `mvn -B flyway:migrate` applied V1 with checksum 1531327758.
- PASS: The latest `mvn -B test` run discovered 6 tests: 5 passed, 0 failed, 0 errored, and 1 gated Neon integration test skipped because `VANTIQ_NEON_INTEGRATION=true` was not injected in this process.
- PASS: `mvn -B package -DskipTests` packaged successfully.
- NOT TESTED: Gated Neon synthetic commit/rollback/JSON/decimal/constraint test.

After the production DB configuration change, the latest local `mvn -B test` run reported 6 tests, 0 failures, 0 errors, 1 gated Neon test skipped, using Java 27. The H2 health test also checks that runtime datasource selection uses `DATABASE_URL` rather than `DATABASE_URL_UNPOOLED`; this does not connect to or verify the production Neon database. Java 21 execution remains unverified.

See [VANTIQ_DISPOSABLE_DATABASE_READINESS.md](./VANTIQ_DISPOSABLE_DATABASE_READINESS.md) and [VANTIQ_PHASE_3D_NEON_VALIDATION_REPORT.md](./VANTIQ_PHASE_3D_NEON_VALIDATION_REPORT.md).

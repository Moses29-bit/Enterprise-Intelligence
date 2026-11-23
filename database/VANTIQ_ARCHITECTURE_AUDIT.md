# VANTIQ Architecture Audit

> Historical audit: this report describes the PHP implementation before its removal. Retain it only as a database and migration reference.

## Audit scope

Inspected the repository root, React client, PHP API, backend domain classes, database schema and migrations, tests, deployment files, environment samples, and existing documentation. This audit is evidence-based and distinguishes implemented behavior from incomplete or unverified functionality.

## Repository structure

- `frontend/`: React 18 + TypeScript 5 + Vite application.
- `src/` and `frontend/src/`: The supported application is `frontend/src/`; the root `src/main.tsx` is not part of the audited frontend entry point.
- `api/index.php`: Public PHP API entry point.
- `backend/bootstrap.php`: Composer/PSR-4 bootstrap and fallback autoloader.
- `backend/src/Application.php`: Central PHP route composition and request security.
- `backend/src/Http/`: Controllers and HTTP primitives.
- `backend/src/Service/`: Authentication, account, business, ledger, workflow, import and data-workspace services.
- `backend/src/Repository/`: PDO repositories.
- `backend/src/Security/`: Sessions, CSRF, authentication and permission middleware.
- `database/vantiq_schema.sql`: Base MariaDB/MySQL schema.
- `database/migrations/`: Ordered migrations 001 through 012; 001–007 are PHP scripts and 009 is an empty SQL file.
- `tests/`: Custom PHP test harness.
- `deploy/`, `Dockerfile`, `infinityfree.htaccess`: Existing PHP hosting configuration.

## Frontend

The React application uses `BrowserRouter`, a permission-filtered workspace shell, lazy-loaded document/import/integration pages, React state, Axios, Recharts, Radix Dialog and custom bento components.

Primary areas:

- Authentication, registration, password change/reset and account session handling.
- Command overview.
- Finance.
- Operations.
- Forecast.
- Risk.
- Decisions.
- Reports.
- Connect/import center.
- Document Vault.
- Integration Hub.
- Control and administration.
- Design-system preview.

The existing UI implementation and branding will be preserved. API integration is centered in `frontend/src/services/api.ts`.

## Existing API

The PHP API uses `/api/*`, a stable JSON envelope, cookie sessions, CSRF protection, login throttling and server-side permission checks. The route inventory is in `VANTIQ_API_COMPATIBILITY_INVENTORY.md`.

Authentication currently uses PHP/PDO sessions rather than JWT. It does not yet use refresh tokens. The Spring target must preserve compatibility and, unless the frontend is cleanly migrated with the same contract, serve compatible session/JWT behavior explicitly.

## PHP backend

The existing backend is more than a prototype:

- Real organization registration and owner provisioning.
- Password hashes, login history and reset tokens.
- Tenant-scoped finance and operations repositories.
- Atomic sale, invoice, stock movement and purchase-order transactions.
- Invoice payments, credit notes and refunds.
- Risk alert review.
- Decision scenario records.
- CSV import preview and data-workspace workflows.
- Documents, integration configuration and an outbox/SSE implementation.
- Audit logging and permission middleware.

The README and implementation checklist document several partial areas. The migrated backend must not claim automated forecasting, PDF reporting or generic integrations unless those workflows are implemented from real data.

## Authentication and authorization

- Password storage uses PHP `password_hash()` with the PHP default bcrypt-compatible algorithm.
- Passwords are 12–72 bytes under the existing implementation.
- Sessions are HttpOnly cookies with CSRF protection.
- Session version revocation exists for password and account-status changes.
- Login attempts use HMAC-derived bucket hashes.
- Role and permission tables are database-backed.
- Tenant checks are distributed through repositories and permission middleware.

The Java implementation will preserve existing password hashes. Spring Security will use BCrypt-compatible verification and a migration plan for any incompatible future hash format.

## Database state

The repository contains a complete SQL/PHP migration definition, but no verified live source database dump or live MariaDB connection was available during this audit.

Verified repository/runtime evidence:

- The project contains a MariaDB base schema and migrations 001–012; migration 009 is empty.
- XAMPP MariaDB 10.4.32 executables and raw `C:\xampp\mysql\data\vantiq` `.frm`/`.ibd` files are present.
- No MariaDB process was running during inspection. The raw files are not a verified backup and have not been opened or modified.
- An old `vantiq_pre_schema_backup.sql` exists, but its full contents, completeness, and checksum could not be verified. No verified backup, source row counts, or readable migration history is available.
- The Spring Boot/Flyway foundation now exists under `backend/spring/`, but its PostgreSQL schema has not been executed.
- No Neon credentials or active Neon project configuration are available in the workspace.

Therefore:

- Repository schema evidence: AVAILABLE; live schema parity: NOT VERIFIED.
- Source record counts: NOT VERIFIED.
- Source database backup: NOT AVAILABLE.
- Data migration: BLOCKED until a protected copy is recovered and verified.
- Neon/Flyway migration: NOT VERIFIED.

## Functional implementation status

### Implemented or substantially implemented

- Organisation registration.
- Login/logout/current-user/session/account administration.
- Password change/reset-token data model.
- Finance summary and ledger workflows.
- Sales and stock deductions.
- Expenses.
- Invoices, payments, credit notes and refunds.
- Customers and suppliers.
- Products and stock movements.
- Purchase orders.
- Administration views and audit logs.
- Import preview/history.
- Document and integration data models.
- Transactional outbox and notifications.

### Partially implemented

- Forecasting.
- Risk detection.
- Decision approvals/outcome tracking.
- PDF/Excel reporting.
- Generic external integrations.
- Cross-system analytics.

### Not yet safely claimable

- Complete live end-to-end database verification.
- PostgreSQL migration with real record reconciliation.
- Full tenant-isolation proof across every endpoint.
- Production Vercel/Render/Neon deployment.
- Actual PDF export and report families.
- Automated forecast generation and accuracy measurements.

## Deployment configuration

- Frontend: Vite and Vercel-compatible SPA configuration.
- Existing backend: Apache/PHP.
- Existing database: XAMPP MySQL/MariaDB or documented external PHP hosting.
- No Java/Maven/Spring Boot project exists in the workspace.
- No Dockerfile for Java exists.
- No Render manifest exists.

## Security findings

Positive controls include CSRF, HttpOnly session cookies, exact CORS configuration, secure error envelopes, login throttling and permission middleware.

The new Spring implementation must retain:

- Server-side authorization.
- Tenant predicates on every tenant-scoped query.
- Short-lived access tokens and controlled refresh lifecycle.
- Secret-free source control.
- Restrictive CORS.
- Secure headers.
- Bounded uploads.
- Central exception handling.
- Audit logging for security and business events.

## Target architecture

The target will be a Java 21/Spring Boot 3 modular monolith:

`Vercel React → HTTPS → Render Spring Boot → Neon PostgreSQL`

Business modules: identity, organisation, finance, operations, analytics, forecasting, risk, decisions, reporting, audit, integration and migration.

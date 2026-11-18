# VANTIQ implementation plan

## Repository scan summary

The repository is a working PHP + React + TypeScript enterprise platform already partially implemented. The key project areas are:

- `backend/` — PHP application, controllers, services, repositories, infrastructure, security, and bootstrap logic.
- `api/` — Apache request entrypoint that boots the PHP application.
- `database/` — canonical schema plus numbered migrations in `database/migrations/`.
- `frontend/` — Vite React client with the main app shell and workspace UI.
- `tests/` — PHP regression tests for auth, business logic, and workflow behavior.
- `docs/` — API contract and implementation tracking docs.
- `bin/` — utility scripts including migration handling.

Current implementation evidence:

- Secure auth/session flow is present and covered by tests.
- Role-based access and tenant enforcement exist in the shared controller/security layer.
- API response envelopes are consistent across the backend.
- Finance and operations logic already covers sales, invoices, payments, purchase orders, refunds, and stock movement workflows.
- A React workspace shell is present with multi-module navigation, permission-aware views, and a Command dashboard.
- Import preview/history is present for the `connect` module and reads from database-backed tables.
- The repository already includes a richer enterprise schema, including audit, roles, permissions, employees, and ledger tables added via migration 008.

## Architecture and technical constraints

- Backend stack: PHP 8.2, PDO/MySQL, object-oriented controllers/services/repositories.
- Frontend stack: React + TypeScript + Vite.
- Deployment target: XAMPP + Apache + MariaDB/MySQL.
- Multi-tenant model: all business data should be scoped by `organisation_id` and protected by RBAC.
- Consistency requirement: JSON envelopes are `{ success, data, error }`, and all mutation flows should add audit entries.

## Gaps identified during the scan

The existing codebase is not a blank slate; it is a partial enterprise platform. The major remaining gaps are not “new app” problems but incomplete enterprise features:

1. Full Phase 2+ modules still need stronger end-to-end integration and production hardening.
2. Import center and integration hub are partial, not full validation/commit/rollback pipelines.
3. Document vault and OCR/extraction workflows are not implemented.
4. Forecasting, risk, reports, and decision-support logic still need deeper repository-backed validation and data lineage.
5. Security, migration, and deployment hardening still require live DB verification beyond the SQLite-backed test suite.

## Phase-by-phase execution plan

### Phase 1 — Foundation and design system
Status: in progress, currently backed by the shared controller, RBAC, audit, migration, response-envelope, and app-shell work already present in the repository.

Planned work:
- verify DB bootstrap, config loading, and migration runner behavior.
- finish migration safety and re-runnable schema coverage.
- ensure design-system tokens and app shell remain consistent and accessible.
- keep all modules tenant-aware and permission-aware.

### Phase 2 — Authentication, users and administration
Planned work:
- complete user access management and audit log viewer.
- verify strong session and brute-force protections.
- add admin views for org settings, roles, departments, and branches with full validation.

### Phase 3 — Finance and operations
Planned work:
- keep the data backbone live and consistent across finance and operations.
- verify CRUD, validation, export, and search/filter behavior.
- drive all modules from the Enterprise Data Repository.

### Phase 4 — Data Import Center
Planned work:
- implement the full file upload + validation + preview + commit + rollback lifecycle.
- add mapped-column validation and re-runnable import history.
- keep file storage outside the web root and enforce upload rules.

### Phase 5 — Integration Hub
Planned work:
- implement connector registry, encrypted credentials, mapping service, sync jobs, and webhook receiver.
- keep incremental sync and retry/backoff logic in place.
- ensure data lineage from external sources is visible in dashboards.

### Phase 6 — Document Vault
Planned work:
- add secure upload, extraction, and review flows.
- support document versioning, search, and confirmation before any figure is promoted to the repository.

### Phase 7 — Intelligence modules
Planned work:
- complete command dashboard, forecasting, risk, and scenario support using real data.
- enforce insufficient-history safeguards and data-quality indicators.

### Phase 8 — Reporting, notifications and production readiness
Planned work:
- complete notifications, report export, DB indexes, and production security hardening.
- verify headers, CSRF, upload rules, tenant isolation, and deployment config.

### Phase 9 — Final completeness audit
Planned work:
- verify each role can use every module without dead endpoints or placeholders.
- test migrations and seed data on a fresh database.
- confirm all tests and builds pass.
- produce the final completion report and known limitations list.

## Assumptions

- We continue from the existing partially implemented codebase rather than replacing it.
- MariaDB/MySQL is the target database for runtime validation, while SQLite-backed tests remain useful for fast regression checks.
- The app remains in a XAMPP Apache environment and keeps the current PHP/PDO/React/Vite architecture.

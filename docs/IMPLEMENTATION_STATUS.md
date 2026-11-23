# VANTIQ Implementation Checklist

> Archived checklist for the retired PHP implementation. Its PHP test commands and implementation claims are historical and do not describe the current Spring Boot backend. See [README.md](../README.md) for current status.

This checklist records repository evidence and validation from the PHP completion pass. A feature is not marked verified merely because its schema or UI exists.

## Repository and current scope

- [x] Inspected repository structure, README, API contract, schema, ordered migrations, backend composition/authentication, frontend module wiring, and available tests.
- [x] Preserved the existing React/TypeScript + PHP/PDO + MariaDB architecture.
- [x] Preserved pre-existing worktree edits in `frontend/src/styles.css`, `frontend/src/views/WorkspaceModule.tsx`, and the untracked `frontend.zip`.
- [x] Corrected the stale invoice stock rollback assertion to compare inventory before and after the rejected operation.
- [x] Bounded request-body capture, made header lookup case-insensitive, and enforced exact JSON media-type parsing.
- [x] Updated setup/API documentation for the existing invoice-credit migration and request-body contract.
- [x] Added shared controller/RBAC helpers, a tenant-predicate repository helper, structured before/after audit logging, and a consistent API envelope.
- [x] Added migration 008 for source/quality metadata, tenant-scoped child rows, employees, and ledger accounts/entries; added the ordered PHP/SQL migration runner.
- [x] Added focused tests for the response envelope, tenant query guard, permission middleware, and audit before/after payloads.
- [x] Updated XAMPP migration instructions and documented the stable API envelope.

## Product implementation

| Area | Status | Evidence / remaining work |
|---|---|---|
| Registration, login, sessions, CSRF, password reset, roles, and tenant-scoped account administration | IMPLEMENTED — TESTING REQUIRED | Auth/permission/tenant tests pass on isolated SQLite; live database/API integration remains blocked by the stopped MariaDB service. |
| Workspace shell, navigation, and responsive design | IMPLEMENTED — CLIENT WORKSPACE | Added a permission-filtered collapsible/mobile shell, organisation context, global header, account controls, keyboard search shortcut, responsive styling, and reusable sortable/filterable/paginated tables. Persistent dashboard preferences and live background-task status are not available. |
| Global search and business alerts | PARTIALLY IMPLEMENTED | Search queries existing role-gated customer, invoice, transaction, product, supplier, risk, scenario, and import endpoints. Documents and reports are not indexed; alerts display current risk records only and are not persisted as notifications. |
| Finance and inventory workflows | PARTIALLY IMPLEMENTED | Sales, expenses, invoices, payments, credits/refunds, stock movements, and purchase-order workflow have isolated service tests. Broader CRUD, receivables/payables coverage, and live database verification remain. |
| Command executive workspace and cross-system analytics | PARTIALLY IMPLEMENTED | Added tenant-scoped finance, inventory, branch, risk, activity, and forecast summaries/charts using existing APIs, with permission-aware empty states and no comparison values when unavailable. Cross-system analysis, document/integration/workforce intelligence, and evidence-linked recommendations remain. |
| Document management and intelligence | NOT IMPLEMENTED | No private document store, authenticated download lifecycle, extraction pipeline, or evidence-linked analysis workflow. |
| External Integration Hub | PARTIALLY IMPLEMENTED | CSV import preview/history exist; generic connectors, credential encryption, connection tests, sync jobs, and webhooks remain. |
| Unified enterprise data layer | NOT IMPLEMENTED | No source mapping, external identifiers, import deduplication, or data-quality/retry layer. |
| Forecasting and scenario analysis | PARTIALLY IMPLEMENTED | Forecast summary/schema and decision scenarios exist; forecast generation from history, validation metrics, scenario comparison, and measured outcomes remain. |
| Risk detection and management | PARTIALLY IMPLEMENTED | Existing risk review/status workflows exist; configurable evidence-based detection rules and fuller assignment/history workflows remain. |
| Decisions and approvals | PARTIALLY IMPLEMENTED | Scenario creation and results exist; evidence-linked decision records, approvals, implementation tracking, and outcome measurement remain. |
| Reports and exports | PARTIALLY IMPLEMENTED | Financial reporting and CSV export exist; broader report families, PDF export, and full source/period metadata remain. |
| Background processing | NOT IMPLEMENTED | No database-backed job queue/worker for document processing, imports, report generation, or scheduled synchronization. |
| Security and governance | IMPLEMENTED — TESTING REQUIRED | Existing isolated tests cover password/session/CSRF/authorization and tenant boundaries. Shared RBAC and audit helpers are in place; live database, browser, and broader tenant-isolation verification remain. |
| Documentation and deployment readiness | PARTIALLY IMPLEMENTED | README and API documentation exist and were corrected. Dedicated architecture, database, security, integration, document-intelligence, testing, and deployment guides remain. |

## Verification run

- [x] `C:\xampp\php\php.exe tests\lint.php` — passed.
- [x] `C:\xampp\php\php.exe tests\run.php` — 28 tests, 0 failures.
- [x] `C:\xampp\php\php.exe tests\business.php` — 6 tests, 0 failures.
- [x] `C:\xampp\php\php.exe tests\workflows.php` — 8 tests, 0 failures.
- [x] `npm.cmd run typecheck` — passed.
- [x] `npm.cmd run build` — passed.
- [x] `git diff --check` — passed.
- [x] Browser smoke test of the permission-limited, empty-data Command workspace — shell, onboarding states, navigation, search popover, and keyboard shortcut worked; no horizontal overflow at tested widths (1920, 1440, 1366, 1024, 768, and 390 CSS pixels).
- [ ] Live authenticated browser/API verification — not completed; the browser smoke test used an empty-data API fixture and did not verify MariaDB-backed records or account workflows.
- [ ] `C:\xampp\php\php.exe tests\mysql_integration.php` — not completed; the local MariaDB/MySQL service is not running.
- [ ] Apply/test migration 008 against MariaDB/MySQL — not completed because no local database service is running.
- [ ] PHPUnit execution — not configured or available; the repository's existing custom PHP harness was extended instead.

## Next required implementation stages

- [ ] Add secure tenant-scoped document storage, supported-format extraction, evidence-linked analysis, and lifecycle APIs/UI.
- [ ] Add generic integration configuration, encrypted secrets, real connection tests, read-only synchronization, and retryable job history.
- [ ] Add normalized source mappings, external identifiers, deduplication, and data-quality reporting.
- [ ] Generate statistical forecasts from sufficient real history; add model evaluation and scenario comparison.
- [ ] Expand configurable risk detection, decision approval/outcome workflows, and report/export coverage.
- [ ] Add background workers, additive migrations, migration verification, and full tenant-isolation/API/browser tests.
- [ ] Complete dedicated installation, architecture, database, security, integrations, document-intelligence, testing, and deployment documentation.

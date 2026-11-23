# VANTIQ API Compatibility Inventory

> The endpoint matrix below is the pre-cutover PHP API contract, retained as a migration reference. The PHP runtime has been removed. Spring currently implements health and authentication endpoints; business-module parity is not complete. Do not treat legacy routes in this inventory as currently available.

## Current Spring endpoints

The current Spring application exposes `GET /api/health` and the authentication routes implemented by `AuthController`: CSRF token retrieval, registration, login, logout, current-user lookup, password changes, password reset requests, and password reset. Business and administration routes from the legacy matrix are not implemented in Spring.

The frontend remains cookie/session-based and uses CSRF protection. Its local Vite proxy targets Spring at `http://127.0.0.1:8080`; deployed builds must set `VITE_API_BASE_URL` to the Spring API URL.

## Response contract

Existing clients expect:

```json
{
  "success": true,
  "data": {},
  "error": null
}
```

The Spring API should preserve this envelope for compatibility, including structured error objects and stable error codes.

## Base configuration

- Existing value: `VITE_API_URL`, default `/api`.
- Frontend now prefers `VITE_API_BASE_URL`, falls back to `VITE_API_URL`, then `/api`.
- Existing authentication: HttpOnly session cookie plus CSRF.
- Spring currently uses server-side sessions and CSRF. JWT/refresh remains a target design only and is not used by the frontend.

## Health

| Method | Existing path | Spring target |
|---|---|---|
| `GET` | `/api/health` | Implemented; envelope and database connectivity check exist. PHP `php_version` became Java `java_version`, and the frontend type was updated. Runtime behavior is not verified by Maven tests. |

## Authentication

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/auth/csrf` | Compatibility endpoint or remove only after frontend migration |
| `POST` | `/api/auth/register` | Create tenant and owner in one transaction |
| `POST` | `/api/auth/login` | Preserve user response shape and security behavior |
| `POST` | `/api/auth/logout` | Revoke session/refresh state |
| `GET` | `/api/auth/me` | Current user, organisation, roles and permissions |
| `POST` | `/api/auth/change-password` | Rotate password and revoke tokens/sessions |
| `POST` | `/api/auth/forgot-password` | Anti-enumeration response |
| `POST` | `/api/auth/reset-password` | Single-use expiring token |
| `PATCH` | `/api/auth/accounts/{id}/status` | Tenant-scoped |
| `PUT` | `/api/auth/accounts/{id}/roles` | Tenant-scoped, prevent last-owner removal |

## Modules

| Domain | Existing paths |
|---|---|
| Finance | `/api/finance/summary`, `/api/finance/sales`, `/api/finance/expenses`, `/api/finance/invoices`, `/api/finance/customers` |
| Operations | `/api/operations/summary`, `/api/operations/products`, `/api/operations/suppliers`, `/api/operations/purchase-orders` |
| Reports | `/api/reports/summary`, `/api/reports/financial` |
| Forecast | `/api/forecast/summary` |
| Risk | `/api/risk/alerts` |
| Decisions | `/api/decisions/scenarios` |
| Connect | `/api/connect/imports` |
| Administration | `/api/admin/overview`, users, branches, departments, roles, audit logs and outbox |
| Documents | `/api/documents`, `/api/documents/{id}/extraction`, `/api/documents/{id}/review` |
| Imports | `/api/imports` with upload, template, validate, commit and rollback |
| Integrations | `/api/integrations` and sync lifecycle |

## Frontend API integration

The main integration file is `frontend/src/services/api.ts`. It currently:

- Defaults to `/api`.
- Uses `withCredentials: true`.
- Fetches CSRF tokens for mutating requests.
- Handles session-expiry events.
- Maps server API errors into `ApiError`.
- Expects nested `data` and `error` envelopes.

It must be updated centrally to use `VITE_API_BASE_URL` and JWT/refresh behavior if sessions are replaced.

## Compatibility requirements

- Preserve route names and response envelope where practical.
- Keep permission names unchanged initially.
- Preserve organisation fields and role arrays in `/auth/me`.
- Preserve snake_case request and response fields where frontend code depends on them.
- Do not expose database entities directly.
- Update one centralized frontend API client rather than arbitrary component changes.
- Restrict production CORS to exact Vercel and approved local origins.

## Historical Phase 2 endpoint-by-endpoint matrix

This matrix captured the pre-cutover endpoint inventory. Its Java implementation-status entries are historical and superseded by the current endpoint summary above. The legacy router had 71 registered method/path pairs; the PHP application also handled `GET /api/health` and `GET /api/events/stream`. The matrix remains a migration reference for the expected request and response contracts.

| Method | Existing endpoint | Legacy request / response notes | Java endpoint/status | Frontend changes |
|---|---|---|---|---|
| GET | `/api/auth/csrf` | Cookie session; returns CSRF token in envelope | Not implemented | Keep current client |
| POST | `/api/auth/register` | `organisation_name`, `name`, `email`, `password`; returns user and CSRF token | Not implemented | Keep current client |
| POST | `/api/auth/login` | `email`, `password`; returns user and CSRF token | Not implemented | Keep current client |
| POST | `/api/auth/logout` | Cookie session + CSRF; returns logged-out state | Not implemented | Keep current client |
| GET | `/api/auth/me` | Returns user, organisation, roles, permissions | Not implemented | Keep current client |
| POST | `/api/auth/change-password` | `current_password`, `new_password`; returns changed state/CSRF | Not implemented | Keep current client |
| POST | `/api/auth/forgot-password` | `email`; anti-enumeration response | Not implemented | Keep current client |
| POST | `/api/auth/reset-password` | `token`, `password`; single-use reset | Not implemented | Keep current client |
| PATCH | `/api/auth/accounts/{id}/status` | `status`; tenant-scoped | Not implemented | Keep current client |
| PUT | `/api/auth/accounts/{id}/roles` | `role_ids`; tenant-scoped assignment | Not implemented | Keep current client |
| GET | `/api/finance/summary` | Returns summary metrics | Not implemented | No change until port exists |
| GET | `/api/finance/sales` | `limit`, `offset`; returns sales and pagination | Not implemented | No change until port exists |
| POST | `/api/finance/sales` | `items`, optional `discount`, `tax`; returns posted totals | Not implemented | No change until port exists |
| POST | `/api/finance/sales/{id}/refund` | `reason`; returns refund/sale status | Not implemented | No change until port exists |
| GET | `/api/finance/expenses` | `limit`, `offset`; returns expenses and pagination | Not implemented | No change until port exists |
| POST | `/api/finance/expenses` | Amount/description; returns created record | Not implemented | No change until port exists |
| GET | `/api/finance/invoices` | Returns invoice list and balances | Not implemented | No change until port exists |
| POST | `/api/finance/invoices` | Customer, dates, tax and item lines; returns invoice | Not implemented | No change until port exists |
| GET | `/api/finance/invoices/{id}` | Returns invoice, item lines, credit notes | Not implemented | No change until port exists |
| POST | `/api/finance/invoices/{id}/payments` | Payment fields; returns payment/balance result | Not implemented | No change until port exists |
| GET | `/api/finance/invoices/{id}/credit-notes` | Returns credit notes | Not implemented | No change until port exists |
| POST | `/api/finance/invoices/{id}/credit-notes` | Credit note/reason and line details; returns credit record | Not implemented | No change until port exists |
| GET | `/api/finance/customers` | Returns customer list | Not implemented | No change until port exists |
| POST | `/api/finance/customers` | Name and optional email; returns customer | Not implemented | No change until port exists |
| GET | `/api/operations/summary` | Returns operations metrics | Not implemented | No change until port exists |
| GET | `/api/operations/products` | Returns products and stock/category fields | Not implemented | No change until port exists |
| POST | `/api/operations/products` | Product and pricing fields; returns created product/stock | Not implemented | No change until port exists |
| POST | `/api/operations/products/{id}/movements` | Movement type, quantity and optional notes | Not implemented | No change until port exists |
| GET | `/api/operations/suppliers` | Returns suppliers | Not implemented | No change until port exists |
| POST | `/api/operations/suppliers` | Supplier fields; returns created supplier | Not implemented | No change until port exists |
| GET | `/api/operations/purchase-orders` | Returns purchase orders | Not implemented | No change until port exists |
| POST | `/api/operations/purchase-orders` | Supplier, branch and item lines; returns order totals/status | Not implemented | No change until port exists |
| POST | `/api/operations/purchase-orders/{id}/submit` | No body; submits order | Not implemented | No change until port exists |
| POST | `/api/operations/purchase-orders/{id}/receive` | No body; receives stock and returns item count | Not implemented | No change until port exists |
| GET | `/api/reports/summary` | Returns report metrics | Not implemented | No change until port exists |
| GET | `/api/reports/financial` | `from`, `to`; returns financial report | Not implemented | No change until port exists |
| GET | `/api/forecast/summary` | Returns forecast summary | Not implemented | No change until port exists |
| GET | `/api/risk/alerts` | Returns risk alerts | Not implemented | No change until port exists |
| PATCH | `/api/risk/alerts/{id}/status` | `status`, optional reviewer and resolution notes | Not implemented | No change until port exists |
| GET | `/api/decisions/scenarios` | Returns scenarios | Not implemented | No change until port exists |
| POST | `/api/decisions/scenarios` | Scenario payload; returns created scenario | Not implemented | No change until port exists |
| GET | `/api/decisions/scenarios/{id}` | Returns scenario and results | Not implemented | No change until port exists |
| GET | `/api/connect/imports` | Returns legacy import history | Not implemented | No change until port exists |
| POST | `/api/connect/imports/preview` | File name and CSV text; returns preview | Not implemented | No change until port exists |
| GET | `/api/admin/overview` | Returns tenant administration summary | Not implemented | No change until port exists |
| GET | `/api/admin/users` | Returns tenant users | Not implemented | No change until port exists |
| GET | `/api/admin/branches` | Returns branches | Not implemented | No change until port exists |
| GET | `/api/admin/departments` | Returns departments | Not implemented | No change until port exists |
| GET | `/api/admin/roles` | Returns roles and permissions | Not implemented | No change until port exists |
| GET | `/api/admin/audit-logs` | Returns tenant audit history | Not implemented | No change until port exists |
| GET | `/api/admin/outbox/failures` | Returns failed outbox events | Not implemented | No change until port exists |
| POST | `/api/admin/outbox/events/{eventId}/replay` | Replays a UUID event | Not implemented | No change until port exists |
| POST | `/api/admin/branches` | Name/code; returns created branch | Not implemented | No change until port exists |
| POST | `/api/admin/departments` | Name/code/optional branch; returns department | Not implemented | No change until port exists |
| GET | `/api/documents` | Search/category query; returns documents/categories | Not implemented | No change until port exists |
| POST | `/api/documents/upload` | Multipart document; returns metadata/extraction status | Not implemented | Keep multipart contract |
| GET | `/api/documents/versions/{group}` | Returns document versions | Not implemented | No change until port exists |
| GET | `/api/documents/{id}/extraction` | Returns extraction text/status | Not implemented | No change until port exists |
| POST | `/api/documents/{id}/review` | No body; marks document reviewed | Not implemented | No change until port exists |
| GET | `/api/imports` | Returns import batches/history | Not implemented | No change until port exists |
| POST | `/api/imports/upload` | Multipart CSV/XLSX; returns parsed preview | Not implemented | Keep multipart contract |
| GET | `/api/imports/templates/{type}` | Returns a downloadable blob/template | Not implemented | Keep blob response |
| POST | `/api/imports/{id}/validate` | Mapping payload; returns row/error preview | Not implemented | No change until port exists |
| POST | `/api/imports/{id}/commit` | Commits validated batch; returns import count/status | Not implemented | No change until port exists |
| POST | `/api/imports/{id}/rollback` | Rolls back batch; returns status | Not implemented | No change until port exists |
| GET | `/api/integrations/organisations` | Returns available organisations | Not implemented | No change until port exists |
| GET | `/api/integrations` | Optional organisation query; returns integrations | Not implemented | No change until port exists |
| POST | `/api/integrations` | Connector/config payload; returns integration ID/status | Not implemented | No change until port exists |
| POST | `/api/integrations/test` | Connector test payload; returns connection result | Not implemented | No change until port exists |
| POST | `/api/integrations/{id}/activate` | Optional organisation query; activates/syncs | Not implemented | No change until port exists |
| POST | `/api/integrations/{id}/sync` | Optional organisation query; starts sync | Not implemented | No change until port exists |
| GET | `/api/events/stream` | Server-sent events; supports last-event resume | Not implemented | Keep SSE client until ported |

Health is the sole implemented Java route. Its response is `{success,data,error}` with `java_version` and database connectivity; the frontend health type was updated accordingly. No business or authentication endpoint has Java parity yet. The Java security chain currently rejects unauthenticated access to all non-public routes, so this is not a runnable replacement for the PHP API.

## Compatibility decision

The former PHP workflow has been removed. Keep production traffic off the Spring service until its implemented endpoints, security behavior, database migration, and required business-feature parity have been reviewed and verified.

## Prioritized Java API implementation plan

The router contains 71 registered method/path pairs; health and the SSE stream add two surfaces for 73 total. This plan groups every route in the matrix above. Java should implement real behavior in tested vertical slices; empty controllers and success-shaped placeholders are not parity.

| Priority | Domain | Existing route scope | Dependencies and exit criteria |
|---|---|---|---|
| P0 | Identity | CSRF, register, login, logout, current user, password change/reset, account status/role assignments (10 routes) | Preserve legacy password hashes and PHP session/CSRF behavior until the replacement lifecycle, throttling, login history, role enforcement, and integration tests pass. |
| P0 | Organisation and access administration | Admin overview, users, branches, departments, roles (7 administration routes plus the identity account/role operations above) | Derive tenant from authenticated identity; test cross-tenant reads/writes and last-owner protection. |
| P0 | Audit and outbox | Audit-log query, outbox failure query/replay (3 routes) | Preserve append-only audit semantics; authorize replay; make replay idempotent and observable. |
| P1 | Finance | Summary, sales/refunds, expenses, invoices/payments/credits, customers (14 routes) | Port transaction boundaries and calculations; verify money precision, balances, stock effects, tenant scoping, and rollback behavior against PHP fixtures. |
| P1 | Operations | Summary, products/movements, suppliers, purchase orders/submit/receive (10 routes) | Preserve inventory invariants and transactional receiving; verify role authorization and tenant-scoped IDs. |
| P2 | Reporting and analytics | Reports summary/financial (2 routes) | Define date/time and aggregation compatibility; compare outputs to legacy fixtures. |
| P2 | Forecasting | Forecast summary (1 route) | Port only the existing behavior; do not substitute a placeholder forecast. |
| P2 | Risk | Alert list/status (2 routes) | Preserve review/status transitions and reviewer authorization. |
| P2 | Decisions | Scenario list/create/detail (3 routes) | Preserve assumptions/results payload shape and tenant ownership. |
| P3 | Connect and imports | Legacy import history/preview (2 routes), batch list/upload/template/validate/commit/rollback (6 routes) | Keep multipart/blob contracts; validate input, row-level failures, and atomic commit/rollback. |
| P3 | Documents | List/upload/version/extraction/review (5 routes) | Define protected object-storage and metadata migration before cutover; retain upload limits and audit history. |
| P3 | Integrations | Organisation list and integration create/list/test/activate/sync (6 routes) | Preserve secret handling; never return stored credentials; implement retry/idempotency and sync status. |
| P4 | Events and health | SSE stream and health (2 non-router surfaces) | Keep health database-free for liveness where appropriate; preserve SSE event IDs/replay semantics before replacing PHP. |

P0/P1 routes are cutover blockers. P2/P3 work can proceed after identity, tenant context, and the shared response/error contract are tested. Do not switch frontend base URL or authentication mode until the required routes for the user workflow are implemented and acceptance-tested.

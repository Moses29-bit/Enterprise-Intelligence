# VANTIQ Phase 1 API

> Historical API contract, retained as a migration reference. The PHP API runtime has been removed. Spring currently implements health and authentication endpoints only; the business endpoints below are not evidence of live implementation.

## Base URL and response format

Apache rewrites `/api/...` to `api/index.php`. In local development, Vite proxies `/api` to the Apache project root. Every response is JSON using a stable envelope. Success:

```json
{ "success": true, "data": { "status": "ok" }, "error": null }
```

Error:

```json
{ "success": false, "data": null, "error": { "code": "not_found", "message": "The requested endpoint was not found.", "details": null } }
```

Unhandled server failure:

```json
{ "success": false, "data": null, "error": { "code": "internal_error", "message": "The service is temporarily unavailable.", "details": null, "reference_id": "e3b0c44298fc1c149afbf4c8996fb924" } }
```

Unhandled server failures include a `reference_id` in the error object. The same value identifies a server-side structured log entry with the operation, authenticated user and organisation when available, failure stage, exception class, SQLSTATE when available, and timestamp. Exception messages, SQL text, credentials, and file paths are not returned to the client or included in correlated exception logs. The `data` and `error` fields remain available for existing API clients.

State-changing requests must send `Content-Type: application/json` and `X-CSRF-Token`. Obtain a token first from `GET /api/auth/csrf`. The browser stores the session in an HttpOnly cookie; JavaScript never receives or stores the session ID. The React Axios client handles this exchange and emits a `vantiq:session-expired` browser event after authenticated requests return 401.

JSON request bodies must be objects and are limited to 65,536 bytes. Empty bodies are accepted only by routes that do not require fields. Malformed JSON, arrays/scalars, oversized payloads, and unsupported media types return explicit API errors.

## Endpoints

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/api/health` | Public | Checks API/database availability |
| GET | `/api/events/stream` | Authenticated tenant user with workspace read permission | Receives bounded SSE business-change notifications; reconnect with `Last-Event-ID` |
| GET | `/api/admin/outbox/failures` | `users.manage` | Lists failed outbox events for the caller's organisation without exposing event payloads |
| POST | `/api/admin/outbox/events/{eventId}/replay` | `users.manage` + CSRF | Requeues a failed notification event from the caller's organisation |
| GET | `/api/auth/csrf` | Public | Starts a session and returns a CSRF token |
| POST | `/api/auth/register` | Public + CSRF | Creates an organisation and initial owner |
| POST | `/api/auth/login` | Public + CSRF + throttle | Authenticates an account |
| POST | `/api/auth/logout` | CSRF | Invalidates the current browser session |
| GET | `/api/auth/me` | Authenticated | Returns the current user, organisation, roles, and permissions |
| POST | `/api/auth/change-password` | Authenticated + CSRF | Changes the password and invalidates other sessions |
| POST | `/api/auth/forgot-password` | Public + CSRF | Sends a reset link when delivery is configured |
| POST | `/api/auth/reset-password` | Public + CSRF | Consumes a valid, unexpired reset token once |
| PATCH | `/api/auth/accounts/{id}/status` | `users.manage` + CSRF | Activates, deactivates, or suspends an account in the caller's organisation |
| PUT | `/api/auth/accounts/{id}/roles` | `users.manage` + CSRF | Replaces an account's organisation roles; platform roles cannot be assigned and the last owner cannot be removed |
| GET | `/api/finance/summary` | `finance.read` | Returns posted revenue net of refunds, cost of goods sold, gross profit, classified operating expenses, operating profit, collected payments, branch and department totals, and receivables |
| GET | `/api/finance/sales` | `finance.read` | Lists tenant sales records with pagination |
| POST | `/api/finance/sales` | `finance.write` + CSRF | Posts a sale using server product prices, checks stock, records sale items and stock movements in one transaction |
| GET | `/api/finance/expenses` | `finance.read` | Lists tenant expense entries with pagination |
| POST | `/api/finance/expenses` | `finance.write` + CSRF | Records an expense and audit entry |
| GET | `/api/finance/customers` | `finance.read` | Lists active and inactive customers in the organisation |
| POST | `/api/finance/customers` | `finance.write` + CSRF | Creates an organisation customer and audit entry |
| GET | `/api/finance/invoices` | `finance.read` | Lists issued invoices, linked sales, payments, and current balances |
| POST | `/api/finance/invoices` | `finance.write` + CSRF | Issues an invoice, posts the sale, deducts stock, records movements, and audits atomically |
| POST | `/api/finance/invoices/{id}/payments` | `finance.write` + CSRF | Records a partial or final payment without allowing overpayment |
| GET | `/api/finance/invoices/{id}/credit-notes` | `finance.read` | Lists tenant-scoped credit notes and their line details |
| POST | `/api/finance/invoices/{id}/credit-notes` | `finance.write` + CSRF | Credits invoice lines, optionally returns goods to stock, and records refunds atomically |
| GET | `/api/operations/summary` | `operations.read` | Returns inventory health, low-stock tracking, supply orders, and stock movement summaries |
| GET | `/api/operations/products` | `finance.read` or `operations.read` | Lists tenant products and current stock balances |
| POST | `/api/operations/products` | `operations.write` + CSRF | Creates a product at zero stock; opening stock must be recorded as a movement |
| POST | `/api/operations/products/{id}/movements` | `operations.write` + CSRF | Records an inventory receipt, return, or signed adjustment with transactional balance update |
| GET | `/api/operations/suppliers` | `operations.read` | Lists organisation suppliers |
| POST | `/api/operations/suppliers` | `operations.write` + CSRF | Creates an organisation supplier and audit entry |
| GET | `/api/operations/purchase-orders` | `operations.read` | Lists purchase orders and current receipt states |
| POST | `/api/operations/purchase-orders` | `operations.write` + CSRF | Creates a draft order; does not change stock |
| POST | `/api/operations/purchase-orders/{id}/submit` | `operations.write` + CSRF | Submits a draft purchase order |
| POST | `/api/operations/purchase-orders/{id}/receive` | `operations.write` + CSRF | Receives a submitted order once, adjusts stock and weighted cost, and records movements atomically |
| GET | `/api/reports/summary` | `reports.read` | Returns live totals and available report types |
| GET | `/api/reports/financial?from=YYYY-MM-DD&to=YYYY-MM-DD` | `reports.read` | Returns daily posted revenue and recorded expenses for the requested date range |
| GET | `/api/forecast/summary` | `operations.read` | Returns forecast availability, model metadata, and forecasted values when enough data exists |
| GET | `/api/risk/alerts` | `risks.read` | Returns active risk review items and evidence for operational and financial anomalies |
| PATCH | `/api/risk/alerts/{id}/status` | `risks.manage` + CSRF | Updates alert status, reviewer assignment, and resolution notes |
| GET | `/api/decisions/scenarios` | `decisions.manage` | Returns saved decision scenarios and scenario result summaries |
| POST | `/api/decisions/scenarios` | `decisions.manage` + CSRF | Creates a scenario and stores assumptions and projected results |
| GET | `/api/decisions/scenarios/{id}` | `decisions.manage` | Returns a specific scenario with assumptions and computed result metrics |
| GET | `/api/connect/imports` | `connect.manage` | Lists tenant import history |
| POST | `/api/connect/imports/preview` | `connect.manage` + CSRF | Inspects a CSV payload and returns a bounded preview; this does not commit business records |
| GET | `/api/admin/overview` | `users.manage` | Returns tenant-level counts for people, branches, departments, and audit activity |
| GET | `/api/admin/users` | `users.manage` | Returns the organisation's user directory with assigned roles |
| GET | `/api/admin/branches` | `users.manage` | Lists organisation branches |
| GET | `/api/admin/departments` | `users.manage` | Lists organisation departments and branch assignments |
| GET | `/api/admin/roles` | `users.manage` | Lists assignable organisation roles |
| POST | `/api/admin/branches` | `users.manage` + CSRF | Creates a branch in the caller's organisation |
| POST | `/api/admin/departments` | `users.manage` + CSRF | Creates an organisation department, optionally under an owned branch |

### Sales, expenses, and inventory

Sale posting accepts product identifiers and quantities, not client-supplied prices. Prices and unit costs are snapshotted from the tenant's product records, and available stock is checked while rows are locked. The sale, line items, stock deduction, stock movement, and audit event commit atomically.

```json
{
  "sale_date": "2026-11-10",
  "discount": "5.00",
  "tax": "0.00",
  "items": [{ "product_id": 12, "quantity": 2 }]
}
```

Expense amounts must be positive and use at most two decimal places. Product creation sets the opening balance to zero. Inventory receipts and returns use positive quantities; adjustments use a signed non-zero quantity. A movement that would make stock negative is rejected. Each movement changes the balance and appends a stock movement record in a database transaction.

Financial summary definitions are explicit: net revenue includes posted sales less recorded refunds; gross profit subtracts the sale-time cost snapshot; operating profit subtracts expenses categorized as operating or administrative. Inventory/other and unclassified expenses are reported separately. Payments collected are not represented as profit or cash flow.

Invoice issuance is inventory-backed and posts a linked sale in the same transaction. The invoice balance tracks receivables; its linked sale contributes to revenue and cost of goods sold at issue time. Subsequent payments represent collections, not additional revenue. Product prices and costs come from the server-side product records, and available stock is locked and checked before invoice issue. Invoice credit notes reverse invoice balances and can record paid-amount refunds and optional stock returns atomically. Invoices currently support product lines only; service/non-catalogue invoice lines and invoice voiding are not implemented. Invoice-linked sales are rejected by the point-of-sale refund endpoint so receivables are reversed only through the credit-note workflow.

Purchase orders do not affect stock while in draft or submitted states. Receiving a submitted order applies each product's total received quantity once, recalculates its weighted-average unit cost, adds a stock movement, and changes the order state in a single transaction.

Permissions added by migration 004: `finance.write`, `operations.write`, `connect.manage`, and `risks.manage`. Existing organisations receive grants according to their standard role template; new organisations receive role grants during registration.

### Register

```json
{
  "organisation_name": "Apex Trading Ltd",
  "name": "Alex Morgan",
  "email": "alex@example.com",
  "password": "a-unique-password-of-12+"
}
```

Registration creates the Organisation Owner role and never accepts a client-supplied role or organisation ID.
Passwords must contain 12–72 bytes. The upper bound avoids bcrypt's 72-byte input truncation in the current PHP `PASSWORD_DEFAULT` implementation.

### Login

```json
{ "email": "alex@example.com", "password": "a-unique-password-of-12+" }
```

The response contains the authenticated user and the rotated CSRF token. The session cookie is HttpOnly, SameSite=Lax, and Secure in production (or when `SESSION_COOKIE_SECURE=true`).

### Change password

```json
{
  "current_password": "current-password",
  "new_password": "new-unique-password-of-12+"
}
```

### Forgot/reset password

Forgot request:
```json
{ "email": "alex@example.com" }
```

Reset:
```json
{ "token": "64-character-hex-token-from-email", "password": "new-unique-password-of-12+" }
```

Without explicit mail configuration, forgot-password returns a service-unavailable error; it does not claim to send mail. With configured delivery, the same success response is used for known and unknown accounts.

### Account status

```json
{ "status": "suspended" }
```

`status` is `active`, `inactive`, or `suspended`. An organisation owner can only affect users belonging to their own organisation; a cross-tenant ID returns not found. Platform-wide account changes require the CLI-provisioned platform permission and an explicit `organisation_id` when the target is tenant-owned.

## Roles and permissions

The platform administrator role is a system role and is only assigned by `bin/create-platform-admin.php` from CLI. Organisation registration can assign only its own Organisation Owner role. The standard tenant role templates are Organisation Owner, Executive / Director, Department Manager, Financial Analyst, and Operations Manager. Permission checks run on the server; session claims and frontend checks are not trusted as authorization.

## Operational notes

- Login throttling uses HMAC-derived email/IP buckets and a 15-minute window; five consecutive failures block the matching bucket for 15 minutes.
- Session defaults are 30 minutes idle / 12 hours absolute. Password changes and account status changes increment `session_version` to revoke existing sessions.
- Allowed browser origins must be listed exactly in `ALLOWED_ORIGINS`. Never use wildcard CORS with credentials.
- Password reset uses configured PHP `mail()` only; SMTP configuration is owned by the deployment's PHP mail transport.
- Business modules are database-backed and respect organisation ownership when querying `organisation_id`-scoped tables.
- The optional seed script at `database/seed_demo_apex.php` can populate a fictional Apex Trading Ltd dataset for testing dashboards, imports, and decision workflows.
- All API routes require a live configured MySQL connection, including health checks.

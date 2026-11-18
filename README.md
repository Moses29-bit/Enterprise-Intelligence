# VANTIQ — Enterprise Application Foundation

VANTIQ is being developed as a PHP 8.2+/MySQL REST application with a React/TypeScript client. Authentication and the first database-backed Finance/Operations workflows are implemented; the full enterprise scope is still in progress.

## What is functional

- Organisation registration creates an organisation, its standard roles, the initial owner account, and an audit record in one database transaction.
- Login, logout, current-user lookup, password changes, account activation/suspension, and password reset token handling.
- Server-enforced role permissions, tenant-scoped user status changes, session-version revocation, session expiry, CSRF, and login throttling.
- React authentication forms backed by the API; no fabricated login success or hardcoded user identity.
- Tenant-scoped product listing and creation, sale posting with server-priced items and atomic stock deduction, expense recording, inventory movement history, date-filtered financial reporting, and CSV export from actual report data.
- Role-scoped navigation and working data views for finance, inventory, risk, decisions, reports, imports, and organisation administration. Modules that do not yet have write workflows are explicitly limited rather than represented as completed.
- MySQL 8 InnoDB schema in [`database/vantiq_schema.sql`](database/vantiq_schema.sql), with ordered migrations in `database/migrations/`.
- API contract in [`docs/API.md`](docs/API.md).
- Current verified scope and remaining implementation checklist in [`docs/IMPLEMENTATION_STATUS.md`](docs/IMPLEMENTATION_STATUS.md).

## Workspace assessment

The original workspace had a static sample dashboard, a PHP login page with embedded demo credentials, and a demo JSON endpoint backed by hardcoded statistics. Those were not production authentication or database-backed business functionality. The static dashboard files have been removed; the React application in `frontend/` is the supported user interface. The legacy login is disabled, the legacy endpoint removed, and backend source/configuration paths are denied by Apache.

The local XAMPP SQL service reports MariaDB 10.4.32, not MySQL 8; schema and PDO integration were validated on that local MariaDB version, but a MySQL 8 production deployment still needs its own compatibility run.

## XAMPP setup (Windows)

1. Install/start XAMPP Apache and MySQL. Confirm Apache has `mod_rewrite` enabled and the `htdocs` directory allows `.htaccess` overrides (`AllowOverride All`).
2. Create the database in phpMyAdmin:
   ```sql
   CREATE DATABASE vantiq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Copy `.env.example` to `.env`, set database credentials, and generate a unique key:
   ```powershell
   Copy-Item .env.example .env
   & "C:\xampp\php\php.exe" -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```
   Put the resulting 64-character value into `APP_KEY`. Do not commit `.env`.
4. Install Composer for Windows (or place `composer.phar` in the project root), then from the project root run:
   ```powershell
   composer install
   ```
   Composer is required to generate the declared PSR-4 autoloader. The backend has a PSR-4 fallback autoloader for local validation before Composer is installed.
5. Apply all numbered migrations in order (including the enterprise repository migration):
   ```powershell
   & "C:\xampp\php\php.exe" bin\migrate.php
   ```
   The runner applies existing PHP migrations and numbered SQL migrations in filename order, records SQL versions in `schema_migrations`, and skips already-applied migrations. Do not edit an applied migration; add a new numbered migration for schema changes. The database must already exist and match `DB_NAME`.
6. Create the local development demo login:
   ```powershell
   & "C:\xampp\php\php.exe" bin\seed-demo-user.php
   ```
   Sign in with **demo@vantiq.test** / **DemoWorkspace2026!**. The CLI seed is disabled in production; it can also use `DEMO_USER_EMAIL` and `DEMO_USER_PASSWORD` environment variables. Do not use the published demo password outside local development.
7. Install the React client:
   ```powershell
   cd frontend
   Copy-Item .env.example .env.local
   npm.cmd install
   npm.cmd run dev
   ```
   Configure `VITE_API_PROXY_TARGET` in `frontend/.env.local` for the Apache project URL (default: `http://localhost/Enterprise%20Intelligence`). Set `ALLOWED_ORIGINS=http://localhost:5173` in the project `.env`.
7. Open the Vite URL shown in the terminal (normally `http://localhost:5173`). Requests to `/api` are proxied to Apache/PHP.

The PHP API also works directly at `http://localhost/Enterprise%20Intelligence/api/health`. Do not use the removed `login.php` demo route.

## Platform administrator

Organisation registration can grant only the Organisation Owner role. It cannot grant platform privileges. Provision a platform administrator from a local CLI after applying the schema. Set `PLATFORM_ADMIN_EMAIL`, `PLATFORM_ADMIN_NAME`, and `PLATFORM_ADMIN_PASSWORD` in the current PowerShell process (the password must be 12–72 bytes), then run:

```powershell
& "C:\xampp\php\php.exe" bin\create-platform-admin.php
```

The command is CLI-only, refuses duplicate email addresses, and stores only a PHP password hash. Passwords must be 12–72 bytes because PHP's default bcrypt hash truncates input beyond 72 bytes. Do not put credentials in source control or permanent logs.

## Password reset delivery

Reset requests intentionally return `503 mail_unavailable` until a delivery transport is configured. To enable the built-in PHP mail transport, configure `MAIL_TRANSPORT=php_mail`, `MAIL_FROM`, and `RESET_URL` in `.env`, and configure XAMPP's PHP `mail()`/sendmail settings with a real provider. PHP's `mail()` success means accepted by the configured local transport, not guaranteed inbox delivery. Reset tokens are random, stored only as SHA-256 hashes, expire after 30 minutes, and are single-use.

## Validation

Run backend tests and syntax checks:

```powershell
& "C:\xampp\php\php.exe" tests\run.php
& "C:\xampp\php\php.exe" tests\business.php
& "C:\xampp\php\php.exe" tests\workflows.php
& "C:\xampp\php\php.exe" tests\lint.php
```

For a live MySQL/API dispatch smoke test after applying the schema, run:

```powershell
& "C:\xampp\php\php.exe" tests\mysql_integration.php
```

It creates a uniquely named temporary organisation, exercises registration, role assignment, session lookup, password change, login, reset-token consumption, and logout against the configured MySQL-compatible database, and removes that test organisation afterward.

Run frontend type checking/build after `npm.cmd install`:

```powershell
cd frontend
npm.cmd run typecheck
npm.cmd run build
```

Backend auth and ledger tests use isolated in-memory SQLite databases. Run the MySQL integration test after migrations against the target local environment; migration compatibility and runtime behavior still need verification on MySQL 8 before production deployment. Migration 008 adds data provenance and quality fields, tenant-scopes child records, and creates employee and ledger tables. Its SQL statements are designed to tolerate re-running after partial application through `bin/migrate.php`.

## Current functional scope and remaining work

The current usable vertical slice includes secure authentication, customer and supplier records, product registration, traceable receipts/adjustments/returns, stock-aware sale and invoice posting, partial/final invoice payments, draft/submitted/received purchase orders, expense capture, summary KPIs, and date-filtered financial activity reports. Invoice posting is linked to a sale and stock deduction in one transaction; payments are collections and do not create additional revenue. Sale-time product costs are snapshotted so gross-profit calculations do not change when product costs are edited later.

The project is not yet a complete production enterprise system. Invoice credit notes and refunds are implemented by migration 007; invoice-linked sales remain excluded from the separate point-of-sale refund flow so receivables are reversed through credit notes instead. Full customer/supplier/product editing, finalized CSV import confirmation and rollback, generated statistical forecasts, automated risk detection, editable/comparable decision scenarios, PDF export, document management, external system connectors, and full end-to-end MySQL 8/browser verification remain to be implemented or validated. Do not treat demo seed data or summary endpoints as substitutes for these workflows.

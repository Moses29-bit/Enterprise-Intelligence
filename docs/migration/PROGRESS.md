# VANTIQ Migration Progress

## Current Gate

Gate 0 - Audit

## Status

- **Done:** repository, backend, auth, RBAC, tenant, endpoint, schema, local database, file-storage and test tooling audited.
- **Verified:** branch created; loopback MySQL database profiled read-only; PHP lint passed; core tests passed; workspace tests passed; outbox tests passed.
- **Blocked:** production database is not available for a permitted real-environment audit. No production or Render command was run.
- **Next gate:** Gate 1 only after owner confirms the audit scope and chooses whether to use a restored local copy or target staging copy.

## Decisions

- Keep the legacy PHP backend and MySQL data untouched.
- Keep all existing API paths and the JSON/session error contract for future gates.
- No Laravel skeleton or schema/code changes are included in this Gate 0 phase.
- Existing dirty standalone API files are unrelated pre-Gate-1 work and are not included in migration documentation commits unless separately staged.

## Gate 0 Checks

- `git switch -c feat/laravel-postgres-gate0` — branch created.
- `php tests/lint.php` — `PHP syntax checks passed.`
- `php tests/run.php` — `32 tests, 0 failures`
- `php tests/data_workspaces.php` — `21 data-workspace checks passed.`
- `php tests/outbox.php` — `Outbox checks passed: 16 assertions.`
- `php tests/business.php` — `6 business tests, 5 failures` due to the test SQLite sale-item schema missing `organisation_id` and coupled tenant/report assertions. Gate 0 does not modify this code.
- Read-only loopback MySQL profile — `database_connected=true`; 51 base tables; no table had more than 1 million rows; local private storage measured 3 files and 310702 bytes.

## Files

See `docs/migration/gate0-report.md`, `docs/migration/api-contract.md`, and `docs/migration/data-risks.md`.

# VANTIQ Migration Progress

## Current Gate

Gate 0 - Audit

## Status

- **Done:** repository, backend, auth, RBAC, tenant, endpoint, schema, local database, file-storage and test tooling audited.
- **Verified:** branch created; loopback MySQL database profiled read-only; PHP lint passed; core tests passed; workspace tests passed; outbox tests passed.
- **Blocked:** production database is not available for a permitted real-environment audit. No production or Render command was run.
- **Next gate:** Gate 1 only after owner confirms the audit scope and chooses whether to use a restored local copy or target staging copy.
- **Accepted source of truth:** Restored copy of the local XAMPP MySQL database.
- **Standalone API work:** preserved on `feat/standalone-api-files` at commit `f0aeee4`; unrelated to legacy baseline.
- **Legacy tag:** `legacy-baseline` points to `bfd58b5db49a23536c589fc3c80751e7bab58f6c`.

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
- `git switch -c feat/standalone-api-files` and commit `f0aeee4` — preserved standalone API work separately.
- `git tag legacy-baseline bfd58b5` — legacy baseline tag created.
- `git status --short --branch` on `feat/laravel-postgres-gate0` — clean; output only `## feat/laravel-postgres-gate0`.
- Read-only `SHOW CREATE TABLE sale_items` — real table contains `organisation_id NOT NULL` and a composite FK to `sales(organisation_id,id)`.
- `php tests/business.php` before fixture fix — `6 business tests, 5 failures`.
- Fixed only `tests/business.php` by adding `organisation_id` to the SQLite `sale_items` fixture.
- `php tests/business.php` after fixture fix — `6 business tests, 0 failures`.
- Read-only local findings: password prefixes `bcrypt=2, argon2id=0, other=0`; 0 case-only duplicate email groups; 58 ENUM columns, 0 SET columns, 3 tinyint(1) columns, 41 decimal columns, 98 foreign-key constraints checked with 0 orphan constraints.
- `docs/migration/backup-runbook.md` added; no dump or private file copy committed.
- Gate 2 fixture method recorded in `docs/migration/gate0-report.md`: restored MySQL copy is the source, with SQLite scope explicitly listed.

## Gate 0 Conditions

- **Clean baseline:** complete; unrelated standalone API files were committed on `feat/standalone-api-files`.
- **Business fixture diagnosis:** complete; fixture stale and fixed only in `tests/business.php`.
- **Additional data-risk findings:** complete in `docs/migration/data-risks.md`.
- **Backup procedure:** complete in `docs/migration/backup-runbook.md`; owner-executed.
- **Parity fixture method:** complete in `docs/migration/gate0-report.md`.
- **Gate 1 status:** not started.

## Gate 1 Prerequisite Verification

- `legacy-baseline` tag: **verified** at `bfd58b5db49a23536c589fc3c80751e7bab58f6c`.
- Working tree: **verified clean** before Gate 1 work.
- Standalone API files: **verified parked** on `feat/standalone-api-files` at commit `f0aeee4`.
- Business SQLite fixture diagnosis: **verified complete**; real `sale_items.organisation_id` confirmed and the stale fixture fixed in `tests/business.php`.
- Backup runbook: **verified present** at `docs/migration/backup-runbook.md`.
- Restored scratch database: **BLOCKED / unable to verify**.

Read-only prerequisite output:

```text
candidate_database=vantiq
table_count=51
scratch_database=missing
```

No `vantiq_restore_test`, `vantiq_restore`, or other database name beginning with `vantiq` and matching the required scratch role was found. The only candidate is the original `vantiq` database, so it was not used for writes or scratch restoration. Because the source-state prerequisite is unmet, Gate 1 was not started, no Laravel work was created, and no Gate 1 branch was created.

## Files

See `docs/migration/gate0-report.md`, `docs/migration/api-contract.md`, `docs/migration/data-risks.md`, and `docs/migration/backup-runbook.md`.

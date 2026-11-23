# VANTIQ Data Risk Register

This register is based on the repository schema plus a read-only audit of the loopback MySQL database configured by `.env`.

| ID | Severity | Evidence | Migration risk | Mitigation / Gate |
|---|---|---|---|---|
| DR-001 | Critical | 146 audit logs, 55 outbox events, plus business, import and document tables hold real-like local data. | Unapproved reset or truncate would lose data. | Gates 4-6 ETL only; target-only truncate requires explicit confirmation. Gate 1 baseline migrations must detect existing tables. |
| DR-002 | High | Local database contains 51 base tables and 12 migration records, while `database/vantiq_schema.sql` is missing later workspace/outbox/document migrations and has a narrower table list. | Laravel baseline could omit live tables or create a different schema. | Gate 1 must source migrations from live restored schema, not only `vantiq_schema.sql`. |
| DR-003 | High | Real schema has many additional provenance columns (`created_by`, `source`, `import_batch_id`, `data_quality_status`) and later tables absent from the baseline SQL. | ETL field mapping and tenant checks may be incomplete. | Inspect `SHOW CREATE TABLE` for every live table in a restored copy and generate explicit mappings. |
| DR-004 | High | 15 permissions and 15 roles; role/permission assignments use 96 join rows. | Permission mapping drift can grant or deny access. | Gate 1 preserves existing role tables and test platform plus tenant permissions. |
| DR-005 | High | `users.password_hash` has 2 `$2y$` bcrypt hashes and no argon2id hashes in the local profile. | Wrong Laravel hasher or eager rehash can break login. | Use Laravel bcrypt compatibility; verify on success only; never reset or bulk rehash passwords. |
| DR-006 | High | Emails and identifiers use MySQL `utf8mb4_unicode_ci`, which is case-insensitive. | PostgreSQL unique comparisons can diverge. | Lowercase email writes/migrated values and add unique expressions/functional indexes. |
| DR-007 | Medium | No `0000-00-00` or `0000-00-00 00:00:00` sentinel was found in audited date/datetime columns. | Sentinels may exist in a different copy or future production data. | Gate 4 ETL must detect and report, then map to NULL explicitly. |
| DR-008 | High | Many `ENUM`, `tinyint(1)`, unsigned integer and `JSON` columns exist. | PostgreSQL type mismatch and negative/overflow errors are possible. | Gate 4 mapping: strings/check constraints, booleans, widened ints, JSONB and explicit overflow handling. |
| DR-009 | High | Decimal money columns use `DECIMAL(18,2)`; code also creates totals and snapshots in PHP. | Floating-point arithmetic could corrupt money. | Preserve decimal; use string-safe arithmetic/BCMath; never floats for persisted totals. |
| DR-010 | High | Documents table stores `stored_name`, SHA-256 and private file references. Private storage has 3 files totaling 310702 bytes locally. | Migrating database rows without files or preserving paths leaves broken references. | Gate 4 copies files to persistent object storage and verifies SHA-256; retain a mapping/reject row. |
| DR-011 | Medium | Private storage defaults outside the web root, but can be local and ephemeral in hosting. | Render disk loss or regeneration breaks documents. | Deploy an S3-compatible persistent disk and make filesystem disk configurable. |
| DR-012 | High | Outbox has 55 local events and a processing/retry state. | Reprocessing or losing state can duplicate notifications or lose business events. | Copy in one ETL transaction; preserve IDs/status/locks/checkpoints; make consumers idempotent. |
| DR-013 | Medium | SSE has session closed before streaming and 20-second/1-second heartbeat loops. | PHP-FPM/Apache proxy buffering or worker timeouts can break events. | Gate 3 disables buffering, releases session lock, bounds connections and tests via Vite proxy. |
| DR-014 | High | No triggers, views, procedures or events found in the local database. | This does not prove production has none. | Production-like owner backup must run the full object inventory before cutover. |
| DR-015 | Medium | No table exceeded 1 million rows in the local profile. | Rehearsal volume may be too small to expose chunking issues. | Owner must provide copy/profile data before trusting ETL durations. |
| DR-016 | High | Local data profile found no case-variant email pairs and no `0000-00-00` sentinels, but only 2 users and limited data. | Low-volume audit is not evidence for production. | Repeat profile on a restored owner copy before Gate 5. |
| DR-017 | Resolved for fixture diagnosis | Before correction, `php tests/business.php` lacked `sale_items.organisation_id`; 5 of 6 scenarios failed. | Gate 2 parity could have used an unrealistically stale schema. | Fixture corrected in `tests/business.php`; rerun: 6 tests, 0 failures. Keep SQLite as local logic coverage only, not parity source. |

## Accepted Gate 0 Conditions

### Restored-copy source of truth

The migration source of truth is a restored copy of the local XAMPP MySQL database, not SQLite or a live production database. Production was unavailable and held no data available to audit. The restored copy must be used for Gate 1 parity/schema work, Gate 2 characterization fixtures and Gate 5 rehearsal.

### Password storage

Read-only local result: `bcrypt=2, argon2id=0, other=0`. No hash value or salt was printed. PHP uses `password_hash(..., PASSWORD_DEFAULT)` and `password_verify`. Laravel must retain bcrypt verification compatibility and must not bulk-rehash or force resets.

### Email case variants

Read-only local result: 0 case-only duplicate groups across `users.email` and `customers.email`. This is not proof for production and restored-copy owner data must be re-profiled during Gate 4.

### Date sentinels and NULLs

Read-only local date/datetime profile found no `0000-00-00` or `0000-00-00 00:00:00` occurrences, but found 120 intentional NULL values in audited nullable date/datetime columns. Gate 4 must distinguish and log zero-date sentinels separately from NULLs; each occurrence must be reviewed rather than silently dropped.

### Foreign-key orphans

Read-only local result: 98 foreign-key constraints checked and 0 constraints with orphan rows.

### Type mappings

- 58 ENUM columns in the local schema; no SET columns.
- 3 `tinyint(1)` columns identified as boolean candidates: `credit_notes.stock_returned`, `notifications.is_read`, and `roles.is_system`.
- 41 `decimal` columns use `decimal(18,2)` except percentage/score fields: `forecasts.data_coverage` and `forecasts.confidence_interval` are `decimal(5,2)`, and `intelligence_insights.score` is `decimal(5,2)`.
- Money columns found at `decimal(18,2)` include sales, expenses, invoices, invoice items, invoice refunds, payments, products, purchase orders, purchase-order items, ledger entries, credit notes, credit-note items, stock movements, forecast results and scenario results.
- PostgreSQL must use `numeric`/`decimal` for these columns and string-safe calculations; never floats.

### `sale_items` tenant scoping

The repository migration initially creates `sale_items` without `organisation_id`, then migration `008_enterprise_data_repository.sql` backfills it and adds a composite FK to `sales(organisation_id,id)`.

Actual read-only `SHOW CREATE TABLE sale_items` output confirmed:

```sql
`organisation_id` bigint(20) unsigned NOT NULL
CONSTRAINT `fk_sale_items_organisation`
FOREIGN KEY (`organisation_id`, `sale_id`)
REFERENCES `sales` (`organisation_id`, `id`) ON DELETE CASCADE
```

Queries touching `sale_items`:

| Location | Operation | Tenant scope |
|---|---|---|
| `backend/src/Repository/LedgerRepository.php:163-165` | INSERT with `organisation_id` | Direct explicit tenant value |
| `backend/src/Repository/LedgerRepository.php:234` | COUNT item rows for a sale | Scoped by parent `sales s WHERE s.organisation_id = :organisation_id`; item lookup uses `si.sale_id=s.id` |
| `backend/src/Repository/LedgerRepository.php:272` | SELECT product/quantity/cost by `sale_id` | Sale ID is tenant-derived from prior scoped sale lookup, but this query itself has no `organisation_id` predicate. **Tenant-scoping risk: add explicit organisation predicate or parent join in Laravel.** |
| `backend/src/Repository/LedgerRepository.php:274-278` | Restocks products based on sale items | Product update does not add a direct tenant predicate; caller's sale and product flow should be verified tenant-by-tenant in Gate 2. |
| `backend/src/Repository/EnterpriseWorkflowRepository.php:248-250` | INSERT with `organisation_id` | Direct explicit tenant value |
| `backend/src/Repository/BusinessRepository.php:44-47` | Aggregate sale items | Parent `sales s` is scoped by `s.organisation_id`; join is `si.sale_id=s.id` |

Migration baseline SQL and `database/vantiq_schema.sql` remain stale for `organisation_id`; the live/restored schema and migration `008` are the source of truth.

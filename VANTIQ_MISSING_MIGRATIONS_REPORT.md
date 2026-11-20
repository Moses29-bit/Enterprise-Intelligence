# VANTIQ Missing Migrations Report

## Result

Migrations `001`–`007` are present in the workspace as PHP migration scripts; the earlier reports that called these files missing were incorrect. No migration has been reconstructed.

The numbered files `001`–`012` are all present. However, `009_import_pipeline_support.sql` is zero bytes, and the live database's applied migration history is unreadable in the current environment. Thus the repository contains no known missing numbered file within 001–012, but it is not possible to prove that the live database has applied exactly these migrations or that no later migration exists.

## Migration evidence

| Version | File | Observed purpose/effect |
|---|---|---|
| 001 | `database/migrations/001_authentication.php` | Runs the base `database/vantiq_schema.sql` statements and records `001_authentication` in `schema_migrations`. |
| 002 | `database/migrations/002_login_attempt_retention.php` | Adds `idx_login_attempts_updated` when absent and records the migration. |
| 003 | `database/migrations/003_business_domains.php` | Creates the business tables, including products, sales, invoices, inventory, procurement, analytics, decisions, imports, and notifications, when absent. |
| 004 | `database/migrations/004_business_permissions.php` | Seeds four business permissions and assigns them to named non-system roles when present. |
| 005 | `database/migrations/005_sale_cost_snapshot.php` | Adds `sale_items.unit_cost DECIMAL(18,2)` when absent. |
| 006 | `database/migrations/006_invoice_sales_link.php` | Adds `invoices.sale_id`, a unique key, and a foreign key to `sales`. |
| 007 | `database/migrations/007_invoice_credits.php` | Adds invoice tax/cost fields and creates credit-note, credit-note-item, and invoice-refund tables. |
| 008 | `database/migrations/008_enterprise_data_repository.sql` | Adds enterprise data/repository fields, tenant relationships, and employee/ledger records. |
| 009 | `database/migrations/009_import_pipeline_support.sql` | Present but zero bytes; no SQL effects can be inferred or reconstructed. |
| 010 | `database/migrations/010_data_workspaces.sql` | Adds import batch/row, document, integration, and sync-run schema. |
| 011 | `database/migrations/011_transactional_outbox.sql` | Adds transactional outbox and event-notification schema and related indexes. |
| 012 | `database/migrations/012_document_content_fingerprints.sql` | Adds document content fingerprint support. |

The observed file contents are not evidence that each migration ran against the original database. In particular, migration 004's seed data and the `schema_migrations` rows must be checked on a recovered clone.

## Migration-history and reconciliation status

- The base schema defines `schema_migrations`, and raw physical files for the `vantiq` database exist, but its rows could not be read without starting/recovering a database copy.
- The current Flyway file contains the 51 inventoried source table names plus the technical `migration_checkpoints` table. Name parity does not verify columns, nullability, defaults, indexes, constraints, or data.
- The 001–012 repository DDL can guide static reconciliation. Final comparison against the live MariaDB DDL, migration history, and row data remains blocked.
- Any migration number after 012, or any local-only/untracked migration, remains unknown until the authoritative database and migration directories are inspected by the owner.

## Required action

Do not recreate migration 009 or invent another migration. Recover a disposable copy, query `schema_migrations`, export `SHOW CREATE TABLE` for every table, and compare the resulting schema to the target Flyway file before proposing SQL changes. Correct the earlier missing-migrations statements in the migration inventory, migration report, and roadmap.

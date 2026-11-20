# VANTIQ Rollback and Recovery

## Database recovery

1. Preserve the original MariaDB database until final acceptance.
2. Retain the encrypted/source backup and SHA-256 manifest.
3. Retain Neon branch/snapshot or backup evidence.
4. Never use a destructive Flyway migration to roll back data.

## Application rollback

Render keeps Docker revisions where available. Roll back to the last verified Java image without changing the PostgreSQL schema unless a backward-compatible migration is available.

## Data import rollback

The migration utility never deletes source or destination tables. It uses transactions and aborts its migration path on unresolved reconciliation failures. Restore from Neon backup/branch or restore staged data before retrying.

## Frontend rollback

Use the Vercel deployment history and restore the last known React deployment. API environment variables must match the selected backend revision.

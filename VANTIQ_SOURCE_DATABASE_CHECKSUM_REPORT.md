# VANTIQ Source Database Checksum Report

## Result

**The SQL dump file has a verified SHA-256; source database records and backup restoreability remain unverified.** The dump checksum identifies the file only and does not prove that it is complete, current, consistent, or restorable.

`C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql` is 21,287 bytes, last modified `2026-11-12T20:55:19.6796658Z`, with SHA-256 `FCD213C754B3C23B58E13DEB602CEE7DAB2F98DC47AC2C4045AFCB0A5F7B7E62`. Read-only structural inspection found 13 tables, while the repository inventory contains 51. The dump is not a complete migration source and has not been restore-tested.

The raw `C:\xampp\mysql\data\vantiq` directory contains `.frm` and `.ibd` files, but hashing those files would only fingerprint an unverified physical state; it would not prove the database is complete, consistent, or restorable. No checksum manifest was generated for that directory, and no files were changed.

## Checksum inventory

| Item | Status | Notes |
|---|---|---|
| `vantiq_pre_schema_backup.sql` | File checksum verified; backup unverified | 21,287 bytes; SHA-256 recorded above; 13 tables versus 51 expected. |
| Verified physical backup | Not found | The raw XAMPP data directory is a potential recovery source, not a backup. |
| `vantiq_restore_test` | Not a backup | Contains only `db.opt`. |
| Source table-file checksum manifest | Not generated | Raw file hashes would not establish database consistency or restoreability. |
| SQL schema/migration source checksums | Not recorded | These files describe schema, not source records; their presence does not substitute for data checksums. |
| Neon/PostgreSQL destination checksum | Not applicable | No destination was provisioned or accessed. |

## Required checksum procedure

After creating a protected cold copy and a complete logical dump on the source host:

1. Record each file's relative path, byte size, and SHA-256 value.
2. Recompute the hash after copying the backup to its off-host destination.
3. Require an exact match before declaring the file transfer intact.
4. Test-restore the dump into a disposable MariaDB instance and record table counts, schema/migration history, and selected business totals.
5. Keep source and destination manifests with the backup under restricted access. Do not commit dumps, private upload files, credentials, or manifests containing sensitive absolute paths.

The discovered file's hash and structural inventory are already recorded; do not execute or import it. Any later restore must use a separate disposable instance because the dump contains `DROP TABLE IF EXISTS` statements:

```powershell
Get-FileHash -LiteralPath 'C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql' -Algorithm SHA256
```

For a subsequently completed dump, calculate its hash separately and capture the value from actual output; do not infer or fabricate checksums.

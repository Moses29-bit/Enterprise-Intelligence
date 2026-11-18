# Local XAMPP MySQL Backup and Restore-Runbook

This runbook is for the owner. It is not executed by migration automation and must be performed outside the production environment.

## 1. Inputs

Set shell variables only locally. Never put passwords or dumps in Git.

```powershell
$MySqlBin = "C:\xampp\mysql\bin"
$DbName = "vantiq"
$DbUser = "root"
$BackupDir = "C:\vantiq-backups\YYYY-MM-DD"
$PrivateUploadDir = "C:\xampp\vantiq-private-storage"
$RestoreDbName = "vantiq_restore"
$Password = Read-Host "MySQL password" -AsSecureString
$Bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Password)
$PlainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($Bstr)
```

Use a credential prompt or MySQL option file instead of a password on the command line when possible.

## 2. Create the database dump

Create the directory outside the application and web root. Run:

```powershell
New-Item -ItemType Directory -Force $BackupDir

& "$MySqlBin\mysqldump.exe" `
  --host=127.0.0.1 --port=3306 `
  --user=$DbUser --password=$PlainPassword `
  --single-transaction --routines --triggers --events `
  --hex-blob --set-gtid-purged=OFF `
  --databases $DbName `
  --result-file="$BackupDir\vantiq.sql"
```

Required flags are `--single-transaction --routines --triggers --events`. `--hex-blob` is a safe binary representation; do not add `--skip-comments` or schema-altering flags.

## 3. Copy private uploads

Copy the private uploads folder to the backup location, preserving relative paths:

```powershell
Copy-Item -Recurse -Force $PrivateUploadDir "$BackupDir\uploads"
```

The private folder must not be copied into the repository or web root.

## 4. Generate SHA-256 checksums

```powershell
Get-FileHash "$BackupDir\vantiq.sql" -Algorithm SHA256 |
  Format-List
Get-ChildItem "$BackupDir\uploads" -Recurse -File |
  Get-FileHash -Algorithm SHA256 |
  Export-Csv "$BackupDir\uploads.sha256.csv" -NoTypeInformation
```

Store both checksum values off-box. Verify them again after the copy leaves the source host.

## 5. Restore test into a scratch database

This must use a scratch database name. Never run the restore against the live database.

```sql
CREATE DATABASE `$RestoreDbName`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

Import with the XAMPP client:

```powershell
Get-Content "$BackupDir\vantiq.sql" -Raw |
  & "$MySqlBin\mysql.exe" --host=127.0.0.1 --port=3306 --user=$DbUser --password=$PlainPassword
```

If the dump contains `USE vantiq`, verify the test database is explicitly selected and isolated. The restored copy may need to be loaded with an option to rewrite database names; do not alter the dump. Gate 1/5 tooling will verify it against the scratch copy.

## 6. Restore verification

Run only read-only checks against the scratch database:

```sql
USE `$RestoreDbName`;
SHOW TABLES;
SELECT version, applied_at FROM schema_migrations ORDER BY version;
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM outbox_events;
SELECT COUNT(*) FROM documents;
```

Verify:

- Every table in the dump exists.
- Migration rows match the source backup.
- Representative row counts match source counts.
- No dump or private uploads are in Git.
- Uploaded files exist under the restored private-storage copy.
- A sample document SHA-256 matches `uploads.sha256.csv`.

## 7. Do not commit

Do not add SQL dumps, private-upload copies, checksum exports containing absolute source paths, `.env`, credentials or database credentials to Git. `.gitignore` must keep backups outside the repository.

# Local XAMPP MySQL Backup and Restore-Runbook

This runbook is for the owner. It is not executed by migration automation and must be performed outside the production environment.

An unverified `C:\xampp\mysql\backup\vantiq_pre_schema_backup.sql` was found. Its opening portion includes `DROP TABLE IF EXISTS` and data inserts, but the file could not be fully inspected or checksummed. Never import it into the original database or a shared instance; inspect and test-restore only in a separate disposable MariaDB instance.

## 1. Inputs

Set shell variables only locally. Never put passwords or dumps in Git.

```powershell
$MySqlBin = "C:\xampp\mysql\bin"
$DbName = "vantiq"
$DbUser = "<least-privileged backup user>"
$BackupDir = "C:\vantiq-backups\YYYY-MM-DD"
$PrivateUploadDir = "C:\xampp\vantiq-private-storage"
```

Do not convert a secure string to plaintext or put a password value in an argument. Use `--password` without a value to prompt, or an approved protected option-file/secret mechanism.

## 2. Create the database dump

Create the directory outside the application and web root. Run:

```powershell
if (Test-Path -LiteralPath $BackupDir) {
  throw "Backup destination already exists; choose a new timestamped directory."
}
New-Item -ItemType Directory -Path $BackupDir

& "$MySqlBin\mysqldump.exe" `
  --host=127.0.0.1 --port=3306 `
  --user=$DbUser --password `
  --single-transaction --routines --triggers --events `
  --hex-blob `
  --databases $DbName `
  --result-file="$BackupDir\vantiq.sql"
```

Required flags are `--single-transaction --routines --triggers --events`. `--hex-blob` is a safe binary representation; do not add `--skip-comments` or schema-altering flags.

## 3. Copy private uploads

Copy the private uploads folder to the backup location, preserving relative paths:

```powershell
if (Test-Path -LiteralPath "$BackupDir\uploads") {
  throw "Upload backup destination already exists; do not overwrite it."
}
Copy-Item -Recurse $PrivateUploadDir "$BackupDir\uploads"
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

Restore into a separate disposable MariaDB instance on a different port, not into the original instance. Because the dump created with `--databases` includes its database name, use an isolated instance rather than trying to redirect the dump into a second schema on the source server.

Import with the XAMPP client pointed only at the isolated disposable instance. In Windows PowerShell, use `cmd.exe` for file input redirection:

```powershell
& $env:ComSpec /d /c "`"$MySqlBin\mysql.exe`" --host=127.0.0.1 --port=3307 --user=$DbUser --password < `"$BackupDir\vantiq.sql`""
```

The disposable instance must be bound to loopback and use a separate data directory. Confirm the port and server identity before import. Never alter the original database or start the original data directory to perform this test.

## 6. Restore verification

Run only read-only checks against the scratch database:

```sql
USE vantiq;
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

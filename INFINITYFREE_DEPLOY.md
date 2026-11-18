# InfinityFree Deployment

InfinityFree hosting is based on shared htdocs hosting, not cPanel SSH in the same way as a VPS or managed cPanel account. Upload the application through the InfinityFree File Manager or FTP, use the account PHP selector, and create a MySQL database through the control panel.

## 1. Upload package

Use the combined `vantiq-infinityfree.zip` package. Upload and extract it in `htdocs/`:

```text
htdocs/
  index.html
  assets/
  .htaccess
  api/
  backend/
  bin/
  database/
  vendor/
  .env.example
  composer.json
  composer.lock
```

Do not upload a local `.env`, SQL dump, private uploads folder, Git directory or `node_modules/`.

## 2. PHP version and extensions

Select the highest PHP version offered that is PHP 8.2 compatible. The application requires:

- `pdo_mysql`
- `mysqli`
- `mbstring`
- `json`
- `openssl`
- `zip`
- `gd`
- `xml`

Do not assume CLI Composer, SSH or cron is available. The upload package includes `vendor/`, so Composer does not need to run on InfinityFree.

## 3. Create MySQL

In the InfinityFree control panel:

1. Create a MySQL database.
2. Record the generated database name and username.
3. Set a password or use the supplied credentials.
4. Use `localhost` or the exact host shown by InfinityFree. Do not assume `127.0.0.1`.
5. Import a blank/restored database backup through phpMyAdmin.

InfinityFree does not provide a real production Laravel migration workflow here. Use only the existing PHP migration tooling if a copy of the database has been backed up and restore-tested.

## 4. Environment file

Copy `.env.example` to `.env` in `htdocs/`.

Set values like:

```text
APP_ENV=production
APP_KEY=<64-character hexadecimal value>
APP_URL=https://yourname.epizy.com
SESSION_IDLE_TIMEOUT=1800
SESSION_ABSOLUTE_TIMEOUT=43200
SESSION_COOKIE_SECURE=true
ALLOWED_ORIGINS=https://yourname.epizy.com

DB_HOST=<InfinityFree MySQL host>
DB_PORT=3306
DB_NAME=<InfinityFree database name>
DB_USER=<InfinityFree database user>
DB_PASSWORD=<InfinityFree database password>

MAIL_TRANSPORT=
MAIL_FROM=
RESET_URL=
PRIVATE_STORAGE_PATH=<absolute private path>
```

Generate a key locally:

```powershell
& "C:\xampp\php\php.exe" -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Never upload the populated `.env` by using public Git or screenshots. Keep it in `htdocs/`, but the root `.htaccess` denies direct access to it.

InfinityFree account paths and host names vary. The private-storage setting must be an absolute path outside the application root; if no private storage outside `htdocs/` is offered, document uploads should be disabled until persistent private storage is available.

## 5. Database migration

The migration requires a CLI. InfinityFree may not expose PHP CLI or long-running SSH. Do not add an HTTP route that triggers migrations automatically.

Migration options:

- Import the restore-tested SQL dump with phpMyAdmin.
- Apply migrations from a local or staging copy.
- Use an external PHP CLI host only if it connects to the database and is approved.

Never run destructive migration commands, reset commands or `migrate:fresh` equivalents.

## 6. Rewrite routing

The package root `.htaccess` sends `/api/*` to `api/index.php`. It also blocks direct PHP access outside the API and denies source, database and environment directories.

InfinityFree must allow `.htaccess`, `mod_rewrite`, and `AllowOverride` behavior. If its panel disables rewrites, the app cannot be hosted with clean `/api/*` paths without a rewrite alternative.

## 7. Sessions and CSRF

Keep the frontend and API on the same HTTPS origin. The application cookie is `VANTIQSESSID`, `HttpOnly`, `SameSite=Lax`, and secure in production.

The frontend obtains `/api/auth/csrf` and sends `X-CSRF-Token` for POST, PUT, PATCH and DELETE.

Do not set `ALLOWED_ORIGINS=*`. Set the exact public HTTPS origin.

## 8. Verification

Open:

```text
https://your-domain.example/
https://your-domain.example/api/health
```

Verify:

- The React app loads.
- `/finance` and `/data/documents` return the SPA rather than a cPanel-style 404.
- `/api/health` returns JSON.
- `/api/auth/csrf` returns JSON and sets `VANTIQSESSID`.
- Login and logout work.
- One read succeeds.
- A mutating request succeeds only with `X-CSRF-Token`.
- Cross-tenant access is denied.
- `.env` is not downloadable.
- `backend/`, `database/`, `vendor/`, and `bin/` are not publicly accessible.

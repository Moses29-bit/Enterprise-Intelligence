# cPanel PHP Backend Deployment

## 1. Upload layout

Upload the repository to a location outside `public_html` when cPanel account layout permits it. Point a domain or subdomain's document root at the frontend deployment or at the repository root for a combined deployment.

Minimum backend paths:

- `api/`
- `backend/`
- `database/`
- `bin/`
- `vendor/` after Composer install

Do not upload or expose:

- `.env`
- `.git/`
- dumps or SQL backups
- `frontend/src/` or `frontend/node_modules/`
- `tests/`
- `deploy/`
- private uploaded-document storage

The repository root `.htaccess` denies direct PHP outside `api/` and denies source/config/data directories.

## 2. PHP and extensions

Use PHP 8.2 or newer. Enable:

- `pdo`
- `pdo_mysql`
- `mbstring`
- `json`
- `openssl`
- `zip`
- `gd`
- `xml`

For document parsing, the application also requires packages in `composer.lock`: PDF, Word and Spreadsheet support.

Enable Apache `mod_rewrite` and set `AllowOverride All` for the selected document root.

## 3. Composer

Run through cPanel Terminal or SSH:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
composer run-script cpanel:preflight
```

If Composer is unavailable, install it into cPanel account storage and rerun the command. Do not commit vendor credentials or generated environment files.

## 4. Environment

Copy `.env.example` to `.env` and set real values. The `.env` file must remain outside direct web access and must never be committed.

Required values:

```text
APP_ENV=production
APP_KEY=<64-character random hex key>
APP_URL=https://example.com
SESSION_IDLE_TIMEOUT=1800
SESSION_ABSOLUTE_TIMEOUT=43200
SESSION_COOKIE_SECURE=true
ALLOWED_ORIGINS=https://example.com

DB_HOST=localhost
DB_PORT=3306
DB_NAME=<cpanel_database_name>
DB_USER=<cpanel_database_user>
DB_PASSWORD=<cpanel_database_password>

MAIL_TRANSPORT=
MAIL_FROM=
RESET_URL=
PRIVATE_STORAGE_PATH=/home/ACCOUNT/vantiq-private-storage
```

Generate `APP_KEY` with:

```sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Use cPanel's MySQL database and user tools. Grant only the application database privileges. Prefer a private absolute `PRIVATE_STORAGE_PATH` outside `public_html`.

For a subdirectory deployment, set `APP_URL` and `ALLOWED_ORIGINS` to the public HTTPS origin. Set `ALLOWED_ORIGINS` to the exact frontend origin without a trailing slash.

## 5. Database migrations

Use the existing backup and restore procedure before migration. Never run migration scripts against data that has not been backed up.

Run from the repository root:

```sh
php bin/migrate.php
composer run-script cpanel:preflight
```

The runner is additive and checks migration versions and existing tables. It must not be used to drop, truncate or reset existing data. Do not run `database/vantiq_schema.sql` as a reset.

## 6. Private uploads

Create and set:

```sh
mkdir -p /home/ACCOUNT/vantiq-private-storage
chmod 700 /home/ACCOUNT/vantiq-private-storage
```

Copy an existing private upload folder to the new location only when documented as part of the migration. Preserve `stored_name` references and verify document SHA-256 values.

Use cPanel persistent disk/storage. Do not use `public_html` for private uploads.

## 7. Sessions and cookies

Keep the same frontend and backend origin when possible. The backend uses cookie `VANTIQSESSID` with `HttpOnly`, `SameSite=Lax`, and secure mode in production.

Mutating requests require header `X-CSRF-Token`. The frontend must fetch `/api/auth/csrf` first.

If frontend and API are on different subdomains, verify CORS origin, credentialed browser requests and cookie behavior through HTTPS. Do not set a wildcard origin.

## 8. API routing

The domain root `/.htaccess` sends `/api/*` to `api/index.php`. `api/.htaccess` also dispatches the front controller.

For a combined cPanel deployment, deploy the built frontend contents to `public_html` and ensure `/.htaccess` does not replace `/api/*`.

For a subdirectory deployment, set frontend `VITE_API_URL` to the actual API path and configure equivalent rewrite rules under that subdirectory.

## 9. Verification

Before accepting traffic:

```sh
php bin/cpanel-preflight.php
curl -i https://example.com/api/health
```

Verify the preflight output:

```text
database=connected tables=<expected>
private_storage=writable <absolute-path>
schema_migrations=<count>
cPanel preflight passed.
```

Verify:

- `GET /api/health` returns JSON.
- Login succeeds with an existing copy user.
- Logout clears the session.
- One read and one write succeed.
- CSRF rejects a mutating request without `X-CSRF-Token`.
- A cross-tenant read/write is denied.
- Private document upload/download remains inside private storage.
- `/api/admin/...` routes are not publicly writable.

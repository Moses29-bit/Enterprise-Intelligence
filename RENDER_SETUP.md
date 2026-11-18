# VANTIQ PHP API on Render — Docker deployment files

## What this fixes
Render's `failed to read dockerfile: open Dockerfile: no such file or directory` means the Docker build context does not contain a file named `Dockerfile` at the configured path. This package supplies one, plus Apache hardening and a Docker build exclusion file.

## Required repository layout
These files are installed in the repository root, which contains:

- `Dockerfile` (this supplied file)
- `.dockerignore` (this supplied file)
- `api/index.php`
- `backend/`
- `composer.json` and `composer.lock`
- `database/` migrations
- existing root `.htaccess` with the VANTIQ `/api` rewrite

The Docker build installs production Composer dependencies. The supplied Apache configuration assumes the API front controller is `api/index.php`.

## Install the files
Keep `Dockerfile`, `.dockerignore`, and `RENDER_SETUP.md` at the repository root, and preserve `deploy/render/apache-vantiq.conf` at its current path. Commit and push these files to the Git branch connected to Render.

## Render settings
In Render → your Web Service → Settings → Build & Deploy:

- Environment: Docker
- Root Directory: leave blank (repository root)
- Dockerfile Path: `Dockerfile`
- Docker Build Context Directory: `.` (if shown)

If Root Directory is set to `api` or `frontend`, Render will not find the root Dockerfile. Change it to blank/repository root.

## Required Render environment variables
Set these using the exact variable names read by your VANTIQ config/bootstrap. Do not assume names without checking the existing config. Typical values include:

- `APP_ENV=production`
- `APP_KEY` (generate a new production key; never reuse a key exposed in source or chat)
- `APP_URL`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`
- `ALLOWED_ORIGINS=https://YOUR-VERCEL-DOMAIN.vercel.app`

Use the database provider's TLS settings/CA instructions. Do not use `localhost` for a remote database host.

## Important compatibility and security checks before production
1. Confirm `api/index.php` exists and inspect its include/autoload paths.
2. Confirm the API can load Composer packages from `vendor/autoload.php`.
3. Confirm the application reads database settings from environment variables rather than hard-coded localhost credentials.
4. Confirm PDO MySQL is used and the selected database accepts remote TLS connections.
5. Confirm the current `.htaccess` rewrite is preserved and tested under Apache.
6. Verify uploads are stored in object storage or another persistent service; Render's free filesystem is ephemeral.
7. Do not rely on local file sessions or local database files for durable state.
8. Run migrations against a fresh staging database first. Do not import production data until compatibility is confirmed.

## Health check
After deployment, configure Render's Health Check Path only after identifying a safe, unauthenticated health endpoint. Do not point it at an authenticated business API route.

## Important limitations
This Dockerfile is a deployment starting point. Validate the build, API routes, environment variables, database TLS, and migrations in staging before production deployment.

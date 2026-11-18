# cPanel Frontend Deployment

## Domain root

1. Configure `frontend/.env.local`:

```text
VITE_BASE_PATH=/
VITE_API_URL=/api
```

2. Build the frontend:

```powershell
cd frontend
npm.cmd ci
npm.cmd run build
```

3. Upload the complete contents of `frontend/dist/` to the cPanel domain's document root (`public_html/` or the account's addon/subdomain root).

4. Do not upload `frontend/node_modules/` or `frontend/src/`.

5. Ensure Apache `mod_rewrite` is enabled and `.htaccess` overrides are allowed. The generated `.htaccess` routes SPA paths to `index.html` while leaving `/api/*` available to the backend.

## Subdirectory deployment

For `https://example.com/vantiq/`, set:

```text
VITE_BASE_PATH=/vantiq/
VITE_API_URL=/api
```

Upload `frontend/dist/` to `public_html/vantiq/`. If the PHP API is also under `/vantiq/api`, set `VITE_API_URL=/vantiq/api`. Replace `/vantiq/` with the actual cPanel subdirectory.

The backend must remain deployed and accessible at the configured API URL. cPanel configuration for PHP/FPM, MySQL credentials and Apache rewrites is separate from this frontend build.

## Verification

After upload, verify:

- `/` returns the Vite application.
- `/finance` or another SPA route returns `index.html`, not a 404.
- `GET /api/health` returns JSON.
- Login, logout and a mutating API request work under the same browser origin.

Backend deployment instructions are in [CPANEL_BACKEND_DEPLOY.md](CPANEL_BACKEND_DEPLOY.md).

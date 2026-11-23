# cPanel Frontend Deployment

## Domain root

1. Configure `frontend/.env.local`:

```text
VITE_BASE_PATH=/
VITE_API_BASE_URL=https://<spring-backend-host>/api
```

The Spring Boot backend must be deployed separately. Set `VITE_API_BASE_URL` to its HTTPS API URL, or configure an HTTPS reverse proxy for `/api` on the frontend domain and use `VITE_API_BASE_URL=/api`.

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
VITE_API_BASE_URL=https://<spring-backend-host>/api
```

Upload `frontend/dist/` to `public_html/vantiq/`. Replace `/vantiq/` with the actual cPanel subdirectory. Configure a reverse proxy if the API should share the frontend origin.

The backend must remain deployed and accessible at the configured API URL. Configure CORS on Spring Boot to allow the exact frontend origin.

## Verification

After upload, verify:

- `/` returns the Vite application.
- `/finance` or another SPA route returns `index.html`, not a 404.
- `GET /api/health` returns JSON.
- Login, logout and a mutating API request work under the same browser origin.

Spring Boot deployment guidance is in [VANTIQ_RENDER_DEPLOYMENT.md](VANTIQ_RENDER_DEPLOYMENT.md).

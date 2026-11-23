# VANTIQ Vercel Integration

## Existing frontend

Preserve `frontend/` and the React/Vite build. Set:

```text
VITE_API_BASE_URL=https://<render-service>.onrender.com/api
```

The API client can also fall back to `VITE_API_URL` for compatibility, but `VITE_API_BASE_URL` is preferred.

## CORS

Set Render `FRONTEND_ORIGIN` to the exact Vercel HTTPS origin, for example:

```text
FRONTEND_ORIGIN=https://vantiq.vercel.app
```

Local approved origin:

```text
FRONTEND_ORIGIN=http://localhost:5173
```

Do not use wildcard CORS for authenticated endpoints.

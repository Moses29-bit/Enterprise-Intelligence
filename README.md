# VANTIQ Enterprise Intelligence

VANTIQ is a React/TypeScript frontend backed by a Java 25 and Spring Boot 3 API. PostgreSQL is the application database; Neon test branches are used for development and validation. The former PHP API and its runtime tooling have been removed.

## Current status

- The frontend and Spring Boot API are wired together through the Vite `/api` proxy for local development.
- Spring implements health checks and the current authentication endpoints.
- The Spring business modules, complete data migration, production deployment, and end-to-end production readiness are not complete. Review [VANTIQ_IMPLEMENTATION_ROADMAP.md](VANTIQ_IMPLEMENTATION_ROADMAP.md).
- The MariaDB source schema and SQL migration/recovery artifacts are retained for controlled migration work. Do not import them into a live database without review and explicit approval.

## Local development

1. Configure the Spring environment with the credentials for the approved **test** database branch. Use a secret store or local environment variables; never commit credentials.
2. Start the API:

   ```powershell
   Set-Location backend\spring
   mvn spring-boot:run
   ```

   The API listens on `http://127.0.0.1:8080` by default. Check `GET /api/health`.
3. In another terminal, start the frontend:

   ```powershell
   Set-Location frontend
   npm.cmd ci
   npm.cmd run dev
   ```

   Open `http://127.0.0.1:5173/`. The Vite proxy forwards `/api` requests to Spring at `http://127.0.0.1:8080`.

The root `.env.example` documents Spring environment variable names. Do not put Neon credentials or other secrets in that file.

## Validation

Backend tests:

```powershell
Set-Location backend\spring
mvn test
```

Frontend checks:

```powershell
Set-Location frontend
npm.cmd run typecheck
npm.cmd run build
```

## Deployment and migration

- [VANTIQ_RENDER_DEPLOYMENT.md](VANTIQ_RENDER_DEPLOYMENT.md) describes the Spring Boot service deployment.
- [CPANEL_DEPLOY.md](CPANEL_DEPLOY.md) describes static frontend hosting; the Spring API must be deployed separately and configured through `VITE_API_BASE_URL`.
- [VANTIQ_DATABASE_MIGRATION_GUIDE.md](VANTIQ_DATABASE_MIGRATION_GUIDE.md) documents the guarded MariaDB-to-PostgreSQL workflow.
- [VANTIQ_SOURCE_DATABASE_RECOVERY.md](VANTIQ_SOURCE_DATABASE_RECOVERY.md) describes database recovery safeguards.

Keep the recovered SQL dump and source schema unchanged until their contents have been verified and the data owner approves the migration.

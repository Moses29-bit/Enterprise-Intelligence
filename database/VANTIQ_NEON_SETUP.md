# VANTIQ Neon Setup

## Existing production database

VANTIQ uses the existing Neon project's `neondb` database as its intended main application database. Use the pooled connection for application traffic. Inject the actual connection details and role credentials only through the deployment host's secret environment; do not commit them or place them in `.env.example`.

The current backend and production schema are not deployment-ready. Do not deploy or enable application traffic until the production schema has been explicitly reviewed and migrated under a separate approved change. This configuration task did not connect to, query, or modify the production database.

## JDBC configuration

For Neon:

```text
jdbc:postgresql://<host>/<database>?sslmode=require
```

Use the Neon pooled host for normal application traffic. Spring's Flyway auto-run is disabled by default (`FLYWAY_ENABLED=false`). Its configured URL fails closed to a local direct PostgreSQL endpoint rather than falling back to the runtime datasource if the direct URL is absent. For a separately approved migration, run Flyway with `DATABASE_URL_UNPOOLED` (direct, non-pooler host), then verify the target branch/database before applying anything. Do not enable Flyway during normal application startup.

## Environment variables

```text
SPRING_PROFILES_ACTIVE=prod
DATABASE_URL=jdbc:postgresql://<pooled-host>/neondb?sslmode=require&channel_binding=require
DATABASE_URL_UNPOOLED=jdbc:postgresql://<direct-host>/neondb?sslmode=require&channel_binding=require
DATABASE_USERNAME=<role>
DATABASE_PASSWORD=<password>
FLYWAY_ENABLED=false
```

## Free-tier cautions

Neon scale-to-zero and storage limits can affect cold starts and availability. Keep schema and pool size modest. Neon storage is permanent for retained project data within quota; verify the plan and backup lifecycle.

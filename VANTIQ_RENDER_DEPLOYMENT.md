# VANTIQ Render Deployment

## Docker Web Service

- Root Directory: `backend/spring`
- Runtime: Docker
- Dockerfile path: `Dockerfile`
- Docker build context: `backend/spring`
- Health check: `/actuator/health`
- Port: Render-provided `PORT`

Do not use the repository PHP Dockerfile for the Spring backend.

## Environment variables

```text
SPRING_PROFILES_ACTIVE=prod
DATABASE_URL
DATABASE_USERNAME
DATABASE_PASSWORD
FLYWAY_ENABLED=false
JWT_SECRET
FRONTEND_ORIGIN
PORT
```

Generate `JWT_SECRET` securely outside source control.

`DATABASE_URL` must be the pooled Neon JDBC URL for `neondb`. Keep `FLYWAY_ENABLED=false` for the web service; do not inject `DATABASE_URL_UNPOOLED` into the runtime service. Schema migrations require a separately approved Flyway run using the direct, unpooled URL and must not run automatically during deployment. Production deployment remains blocked until the backend's production readiness and database schema are reviewed.

## JVM memory

The Dockerfile enables container support and limits heap to 75% of detected container memory. Start with a 512 MB instance only if Flyway, Hikari and dependencies fit the free-tier limits; monitor cold starts and heap usage.

## Ephemeral filesystem

Do not store durable documents or imports on Render's filesystem. Use the database or an explicitly provisioned object store. Object storage costs must be documented before enabling it.

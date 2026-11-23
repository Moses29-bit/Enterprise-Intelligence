# VANTIQ Architecture

## Runtime topology

```text
React + TypeScript + Vite
        |
        | HTTPS JSON
        v
Render Spring Boot 3 / Java 25 Docker service
        |
        | JDBC sslmode=require
        v
Neon PostgreSQL
```

The existing PHP implementation remains a migration source and compatibility reference. The Spring backend is the target production backend.

## Backend modules

- `common`: API envelope, error types and health.
- `exception`: centralized exception handling.
- `config`: security and CORS.
- `identity`: users, password lifecycle, login, JWT and refresh tokens.
- `organisation`: organisations, branches and departments.
- `finance`: sales, expenses, invoices, payments and reporting.
- `operations`: products, suppliers, stock movements and purchase orders.
- `analytics`: KPI and statistical summaries.
- `forecasting`: forecasts and accuracy records.
- `risk`: risk detection and alert workflows.
- `decisions`: scenarios, approvals and outcomes.
- `reporting`: PDF/CSV/Excel reporting.
- `audit`: audit logs and security events.
- `integration`: imports, documents and external sync.
- `migration`: controlled MariaDB-to-PostgreSQL reconciliation.

## Data access

- Spring Data JPA and Hibernate.
- Flyway owns all production schemas.
- `ddl-auto=validate` in production.
- PostgreSQL NUMERIC for money.
- JSONB for flexible JSON.
- TIMESTAMPTZ for timezone-aware timestamps.
- Organisation predicates on tenant-scoped repositories/services.

## Security

- Spring Security.
- BCrypt-compatible password verification.
- Short-lived JWT access tokens.
- Revocable refresh tokens.
- RBAC and permission authorization.
- Exact production CORS origins.
- Centralized safe errors.
- Audit logging and login throttling.

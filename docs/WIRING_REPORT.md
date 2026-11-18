# Import, document, and integration wiring report

## Destinations and access

| Feature | Page | API | Access |
|---|---|---|---|
| Import Center | `/data/import` | `/imports` | Organisation Owner, Finance Manager, Business Analyst, Financial Analyst, Operations Manager; legacy `connect.manage` and `finance.write` permissions are also accepted. |
| Document Vault | `/data/documents` | `/documents` | The same roles can upload and review. Users with `documents.read` can view and search. |
| Integration Hub | `/data/integrations` | `/integrations` | Organisation Owner (`integrations.manage`) is restricted to their organisation. Platform Super Administrator (`platform.users.manage`, the System Admin role) can select an active organisation. |

The three destinations are lazy-loaded React Router routes. They appear in the
**Data & Integrations** sidebar group, the command palette, and the Command
dashboard quick actions/onboarding checklist when the signed-in user has the
relevant permission. The collapsed sidebar exposes destination names through
tooltips.

Platform administrators select an active organisation before reading or
changing its integrations. The API checks that organisation on every request;
organisation users cannot supply a different tenant ID.

## API endpoints

All endpoints require an authenticated session. Mutating requests use the
application's CSRF protection.

### Import Center

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/imports` | Tenant-scoped import history |
| POST | `/imports/upload` | Stage CSV/XLSX rows and return headers and a preview |
| GET | `/imports/templates/{type}` | Download a CSV template |
| POST | `/imports/{id}/validate` | Map fields and validate staged rows |
| POST | `/imports/{id}/commit` | Insert valid records transactionally |
| POST | `/imports/{id}/rollback` | Remove records created by a committed batch |

Supported import types are sales, expenses, customers, suppliers, and products.

### Document Vault

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/documents` | Search and filter tenant documents |
| POST | `/documents/upload` | Upload an allowed document and create a version |
| GET | `/documents/versions/{group}` | List versions for a document |
| GET | `/documents/{id}/extraction` | Read extracted text and review status |
| POST | `/documents/{id}/review` | Mark extracted text reviewed |

The upload allowlist is DOCX, PDF, XLSX, CSV, and TXT. Uploads are limited to
15 MB, checked by extension and detected MIME type, renamed randomly, and
stored outside the web root with private file permissions. DOCX and PDF
extraction is supported; TXT and CSV contents are also surfaced for review.
XLSX files are accepted in the vault and parsed by Import Center, but XLSX
document text extraction is currently unsupported. Extraction results are
shown in the review panel.

### Integration Hub

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/integrations/organisations` | List active organisations for platform administrators |
| GET | `/integrations?organisation_id={id}` | List integration health for the selected tenant |
| POST | `/integrations` | Save an encrypted-credential integration draft |
| POST | `/integrations/test` | Test an HTTPS endpoint without saving it |
| POST | `/integrations/{id}/activate?organisation_id={id}` | Verify and activate a saved connection |
| POST | `/integrations/{id}/sync?organisation_id={id}` | Run and record a connection check |

Organisation users use their own tenant context; the optional tenant query
parameter is only needed by a platform administrator. Scheduled checks can be
run with `bin/sync-integrations.php` from an OS scheduler.

## Persistence and setup

Apply `database/migrations/010_data_workspaces.sql` after the existing
migrations. It creates document/version metadata, staged import rows and
batches, integrations, sync history, and the feature permissions. Uploaded file
contents remain in private filesystem storage rather than the database.

Install the Composer dependencies in `composer.json`. The PHP runtime needs the
ZIP extension for Office formats and must allow uploads large enough for the
15 MB application limit (including multipart overhead). By default, private
files are stored in `C:\xampp\vantiq-private-storage`; configure
`PRIVATE_STORAGE_PATH` if needed, ensuring its parent exists and the resolved
directory remains outside `htdocs`.

## Verification

| Check | Result |
|---|---|
| Frontend production build (`npm run build`) | Passed, including TypeScript compilation |
| Existing PHP suite (`tests/run.php`) | 28 tests passed |
| Workspace checks (`tests/data_workspaces.php`) | 12 checks passed, including active-tenant selection, multipart bounds, storage location, unsafe endpoint rejection, generated DOCX/PDF extraction, and generated CSV/XLSX parsing |
| Public REST endpoint smoke test | Passed against `https://jsonplaceholder.typicode.com/posts/1` with `VANTIQ_PUBLIC_CONNECTOR_SMOKE=1` (13 checks total) |
| PHP syntax checks (`tests/lint.php`) | Passed |
| Browser route at `http://127.0.0.1:5173/data/integrations` | Vite served the route and rendered the sign-in screen. Apache is running, but the API does not complete because MariaDB is not accepting client handshakes. |

The smoke tests exercise parsers and the connection service directly; they are
not authenticated application-level uploads or integration activations. The
migration runner was started but could not connect and was stopped before
reporting migration progress. The MariaDB process and port are present, but
its current startup log ends after `Server socket created` without a
`ready for connections` message; clients report a lost connection during the
initial handshake. Role-by-role browser checks, import commit/rollback,
document upload persistence, and sidebar/dashboard/palette journeys therefore
remain unverified. The previously reported `ERR_CONNECTION_REFUSED` for Vite
is no longer reproduced: the development server currently serves the route.

## Connector behavior and limitations

The gallery includes the requested ERP, CRM, HCM, SCM, POS, REST, database,
file-drop, and webhook choices. At this stage, these choices share a generic
HTTPS endpoint probe and encrypted credential storage; vendor-specific
authentication/protocol adapters are not implemented. `Sync now` records a
connection check and detected JSON response count. It does **not** ingest
remote records or write them into business tables, and field mappings are
stored but not applied during a sync. Database connection, file-drop, and
webhook transports are not implemented. These connections should not be
treated as production data synchronization until the corresponding adapters
and data-write mappings are added.

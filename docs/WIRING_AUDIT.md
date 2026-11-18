# Import, document, and integration wiring audit

## Summary

The React client is a single authenticated workspace selector; it has no
React Router and exposes ten workspace modules. None of the three requested
pages exists as an independent page or route. CSV preview and import history
are reachable through the existing Connect workspace, but there is no commit,
mapping, rollback, or template workflow. No document-management or system-
integration endpoints, persistence, services, or repositories were found.

## Feature status

| Feature | Page | API | PHP application | Database | Overall state |
|---|---|---|---|---|---|
| Import Center | Missing. Connect includes CSV preview/history, not an Import Center page. | `/connect/imports` (history) and `/connect/imports/preview` exist. Requested `/imports` routes do not. | Existing `BusinessController`/`BusinessService`/`BusinessRepository` expose history and preview. No full import pipeline service. | `imported_files` and `import_errors` exist in the base schema; migration `008_enterprise_data_repository.sql` adds tenant and batch attribution. | **Missing as a complete feature**; partial legacy CSV validation is fully reachable from Connect. |
| Document Vault | Missing; no document workspace or route. | No `/documents` endpoint. | No document controller, service, or repository. | No document or document-version tables found. | **Missing**. |
| Integration Hub | Missing; no integration workspace or route. | No `/integrations` endpoint. | No connector controller, service, or repository. | No integration, sync, or credential tables found. | **Missing**. |

No page was found in the “built but not routed” or “routed but not in sidebar”
states. The Connect workspace is already in the sidebar, but it does not
implement the requested three feature pages. The frontend module selector is
the current navigation mechanism rather than a URL router.

## Existing implementation evidence

- Frontend workspaces and navigation are defined in
  `frontend/src/views/AuthApp.tsx`; workspace content is selected in
  `frontend/src/views/WorkspaceModule.tsx`.
- Existing API methods include `moduleApi.importsHistory` and
  `moduleApi.previewImport` in `frontend/src/services/api.ts`.
- Backend routes `/connect/imports` and `/connect/imports/preview` are
  registered in `backend/src/Application.php`; both require
  `connect.manage`.
- Import history reads from `imported_files` in
  `backend/src/Repository/BusinessRepository.php`. Preview currently validates
  the uploaded CSV text but does not commit financial records.
- `database/vantiq_schema.sql` defines `imported_files` and `import_errors`.
  Migrations `008_enterprise_data_repository.sql` and
  `009_import_pipeline_support.sql` add batch attribution, data-quality, tenant
  scoping, and related-key support to existing business/import tables. They do
  not provide column mapping, staged row validation/commit, or rollback, and
  contain no document or integration schema.
- `Request::fromGlobals` captures request bodies as strings, and
  `Application::handle` rejects non-JSON mutation bodies. Multipart upload
  handling is not wired into the existing API.
- Existing seeded organisation roles are Organisation Owner, Executive /
  Director, Department Manager, Financial Analyst, and Operations Manager.
  The requested Finance Manager, Business Analyst, Org Admin, and System Admin
  names are not the current role names. Existing generic capabilities include
  `connect.manage` and `users.manage`; no document/import-specific permission
  codes or integration-management permission exist.

## Wiring gaps before this task

1. No lazy-loaded URL pages or direct links for Import Center, Document Vault,
   or Integration Hub.
2. No dedicated page permission model for document upload/import versus
   integration management, and no UI permission guards for those destinations.
3. No dashboard quick-action cards, palette destinations, or first-run
   checklist entries for these features.
4. No secure multipart upload path, private file storage configuration,
   document text extraction, import commit/rollback workflow, or connector
   credential/sync lifecycle.
5. No tables or tests for document versions, import batches/rows, integration
   configuration, encrypted credentials, or sync runs.

This audit records the initial implementation state; see
`docs/WIRING_REPORT.md` for the resulting implementation and verification
status.

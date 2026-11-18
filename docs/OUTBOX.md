# Business events and live updates

## What is implemented

The application writes a version-1 JSON outbox event in the same InnoDB
transaction as the corresponding business operation. An outbox insert failure
therefore rolls back the operation. Event payloads contain identifiers and
small operational values, not credentials or document contents.

The current event coverage is limited to implemented write paths:

- Sales: `SaleCreated`, `SaleCancelled`.
- Finance: `ExpenseCreated`, `InvoiceIssued`, `InvoicePaid`,
  `InvoicePaymentRecorded`.
- Operations: `ProductCreated`, `StockAdjusted`, `StockReceived`,
  `PurchaseOrderCreated`, `PurchaseOrderSubmitted`, `PurchaseOrderReceived`.
- Organisation records: `CustomerCreated`, `SupplierCreated`.
- Documents/imports: `DocumentUploaded`, `DocumentAnalysisCompleted`
  (successful text extraction only), `DocumentReviewed`, `ImportCommitted`.

The dispatcher converts these into tenant-scoped, low-detail notifications with
an explicit module dependency list. A unique outbox reference makes notification
creation idempotent. Workers claim rows under a database transaction and use
claim tokens; a five-minute stale claim is recoverable. Failures retry with
exponential backoff, capped at one hour, and are marked failed after eight
attempts. Server logs contain a diagnostic reference; the event table stores
that reference rather than raw exception details. Failed events can be
requeued from the CLI.

Organisation administrators with `users.manage` can also inspect failed-event
references through `GET /api/admin/outbox/failures` and requeue only their
organisation's failed events with `POST /api/admin/outbox/events/{eventId}/replay`.
Neither endpoint returns event payloads.
Organisation administrators with `users.manage` can also inspect failed-event
references through `GET /api/admin/outbox/failures` and requeue only their
organisation's failed events with `POST /api/admin/outbox/events/{eventId}/replay`.
Neither endpoint returns event payloads.

The authenticated SSE endpoint is `GET /api/events/stream`. It checks the
current session, organisation, and role permissions; releases the PHP session
lock before streaming; filters each event's module list; sends only a summary,
event type, event ID, module names, and timestamp; and supports
`Last-Event-ID` reconnection. Streams close after 20 seconds to bound Apache
worker occupancy. The browser reconnects and keeps the existing data-fetch
fallback, so event delivery is not represented as a permanent connection.

## XAMPP setup and worker

After reviewing the additive migrations, apply unapplied migrations with:

```powershell
& "C:\xampp\php\php.exe" bin\migrate.php
```

In a separate PowerShell terminal, run one dispatcher pass:

```powershell
& "C:\xampp\php\php.exe" bin\process-outbox.php --once
```

Or run the bounded-batch worker continuously:

```powershell
& "C:\xampp\php\php.exe" bin\process-outbox.php --limit=50
```

The continuous worker waits two seconds when the queue is empty. For a local
development session, keep it in a separate terminal. For Windows deployment,
run it under an approved process supervisor or configure Task Scheduler to run
the `--once` command at a short interval. Do not expose the worker as an HTTP
endpoint.

To replay a failed notification event, first inspect the server-side event
diagnostic and then run:

```powershell
& "C:\xampp\php\php.exe" bin\replay-outbox.php <failed-event-uuid>
```

Replay accepts only failed events. It does not repeat the originating business
transaction.

## Current boundaries

- The dispatcher currently materialises dashboard notifications only; it does
  not run forecast, risk, or analytical recomputation jobs.
- Integration sync remains a connectivity check and does not emit a
  `IntegrationSyncCompleted` event.
- CSV/XLSX imports use their existing validated-batch commit path. Document
  uploads are fingerprinted and text extraction remains synchronous; the
  general document approval/commit and revised-document comparison workflow
  is not implemented.
- Existing audit logs remain separate from outbox events.
- SSE uses a bounded PHP request. Production deployments with many concurrent
  clients should use a dedicated event-capable web tier or a deployment
  topology proven to support the required long-lived connection count. The
  database worker remains required to move outbox rows into the notification
  feed.

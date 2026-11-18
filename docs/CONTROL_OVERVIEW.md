# Control Overview and data freshness

The Control Overview combines existing organisation-scoped administration,
finance, operations, risk, reporting, and document APIs. Each business dataset
is requested only when the authenticated user has its corresponding permission;
the existing API endpoints remain responsible for tenant and permission
enforcement.

Health indicators are explainable conditions, not a weighted score. Financial
status uses the existing operating-profit summary. Sales direction compares
recorded revenue in two adjacent 30-day windows when the report API returns
history. Inventory status counts products at or below their reorder level.
Operations status describes only the latest purchase orders returned by its
summary. Risk and document indicators are limited to the records returned by
their existing APIs. Missing permission or missing data is shown as restricted
or unavailable, not as zero.

The revenue/expense area chart uses the daily financial report API. Expense
composition uses the server-calculated operating, other, and unclassified
expense totals. Branch and department contribution uses the existing finance
breakdowns. The inventory, procurement, exceptions, and business activity
panels use current product, stock movement, purchase order, risk, and audit
records. The business activity timeline allowlists business action types and
does not render generic authentication or technical audit events.

## Refresh and event propagation

Workspace views now receive tenant- and permission-filtered business change
notifications over authenticated Server-Sent Events. Notifications invalidate
only the affected workspace modules, which refetch their existing
permission-checked endpoints without reloading the application. The SSE
connection is bounded to 20 seconds and reconnects using event IDs; the existing
30-second visible-tab refresh remains as a recovery fallback. The visible
dataset timestamp is the client time when its API responses completed, not a
claim that the source changed at that time. The header reports live connection
status and the last event received.

Implemented business write paths now insert versioned transactional outbox
events alongside the business and audit rows. A CLI worker publishes sanitized
module notifications to a tenant-scoped feed; XAMPP worker commands, retries,
recovery, event coverage, and current boundaries are documented in
[OUTBOX.md](./OUTBOX.md). The Control Overview's activity timeline continues
to use the existing audit API; the SSE feed is a notification/invalidation
channel and does not expose raw audit or event payloads.

## Visual references and assets

- Chart API and reduced-motion behavior: [Recharts animation guide](https://recharts.github.io/en-US/guide/animations/).
- Layout patterns reviewed: [SaaSUI analytics dashboard examples](https://www.saasui.design/analytics-dashboard-design-examples).
- Icon source: [Google Material Symbols](https://fonts.google.com/icons); the existing Material Symbols Rounded implementation and accessible SVG fallback are reused.
- Recharts is MIT-licensed. No external image, Lottie, or dotLottie assets were selected or bundled, so no additional asset licences or runtime downloads apply.

## Not included in this change

Identical document content is now detected by an organisation-scoped SHA-256
fingerprint; a matching upload returns the existing document rather than
re-extracting it for new uploads and documents whose hashes have been recorded.
Historical document hashes can be backfilled with the reviewed CLI in
[OUTBOX.md](./OUTBOX.md); legacy rows stay unmatched until backfilled. A changed
file with the same name remains a new filename-based version. The current
integration “sync” endpoint still performs a connection check and does not
import or update business records. The document vault still lacks staged
business-record approval/commit and revised-document comparison.
The outbox dispatcher does not yet regenerate forecasts or recompute derived
analytics. The existing APIs do not provide budget targets, complete cash-flow
timing, customer/product contribution aggregates, or source-level drill-down
for every chart. These remain unavailable rather than being inferred in the
overview.

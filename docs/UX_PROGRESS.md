# UX progress

## Pass 1 — Typography

- Added self-hosted, variable Inter (with optical sizing), Plus Jakarta Sans,
  and JetBrains Mono font assets with `font-display: swap`; removed the external
  Google Fonts references from the React entry document and preloaded Inter.
- Added role-based type-scale custom properties and utility classes, tabular
  numerals for financial/date/table values, mono styles for raw values, and
  global focus-visible and reduced-motion defaults.
- Replaced the active React stylesheet's legacy Manrope references with the
  approved self-hosted Plus Jakarta Sans face.
- Validation: `npm.cmd --prefix frontend run build` passed. The repository has
  no frontend test script; a type-check is included in this build.
- Follow-up within this pass: legacy component selectors still declare local
  font sizes (160 declarations remain in `styles.css`). An automated
  token-only check is not yet in place.

## Pass 2 — Tokens and theming

- Added brand, neutral, semantic, and chart palettes plus spacing, radius,
  elevation, layering, motion, and breakpoint tokens with light/dark values.
- Added a user-level theme preference (System, Light, Dark) and density
  preference (Comfortable, Compact), saved in browser storage by user ID.
  A synchronous entry-document bootstrap applies saved settings before React
  renders to avoid a theme flash.
- Replaced component-level literal colors in the authentication illustration
  and Design System swatches with palette tokens. Tuned dark surfaces, form
  controls, and executive metric cards rather than simply inverting the page.
- Validation: `npm.cmd --prefix frontend run build` passed. A mocked signed-in
  browser session verified theme/density selection and persistence and found
  no horizontal overflow at 360, 768, 1024, 1280, 1536, or 1920 CSS pixels.
  Real API-backed journeys and 200% zoom remain unverified.

## Pass 3 — App shell and navigation

- Grouped workspace navigation, retained per-user sidebar collapse state, and
  added a Ctrl/Cmd+K command palette for workspace navigation, authorised
  quick actions, and organisation-record search.
- Added a `?` shortcut and a keyboard-help dialog; the dialog also has an
  accessible header trigger.
- Used cmdk inside a Radix dialog so closing the palette can hand focus to the
  selected form after its workspace renders. Verified the sale and CSV actions
  focus the correct controls, Escape closes the palette, and no browser
  exceptions occur.
- Renamed the finance quick action to “Record a sale”: the existing backend
  operation posts sales, while invoice issuance is a separate workflow.
- Validation: `npm.cmd --prefix frontend run build` passed. Browser checks
  confirmed keyboard selection, focus handoff, and palette dismissal. Full
  integration and import journeys remain constrained by existing backend
  coverage.

## Pass 4 — Dashboards and data visualisation

- Capped the executive KPI row at six by moving inventory indicators out of the
  headline row and into the lower inventory-watch panel. Kept all five finance
  indicators and the active-risk count when permissions allow.
- Added inventory-at-cost context to the inventory panel. Forecast, chart,
  drill-down, provenance, freshness, export, and comparative-period gaps still
  need product/API support or further work.
- Validation: browser verification confirmed six executive metrics and the
  inventory context in the lower panel. Frontend build passed.

## Pass 5 — Data tables and lists (partial)

- Confirmed the existing shared `DataTable` already supports row search,
  sortable columns, and pagination. Kept that behavior rather than replacing
  it with a new table dependency.
- Column management, saved views, bulk actions, export, sticky headers, and
  row-detail drawers are not implemented across the workspace tables.

## Pass 6 — Forms and workflows (partial)

- Kept existing submit/loading and server-validation behavior unchanged.
  The command palette now points to the actual sale-posting form instead of
  mislabeling it as invoice creation.
- A common inline-validation system, draft recovery, unsaved-change guards,
  upload progress, and workflow confirmation/undo are still outstanding.

## Pass 7 — States, feedback, and microcopy (partial)

- Added [CONTENT_GUIDE.md](./CONTENT_GUIDE.md) with product terminology,
  number/date conventions, and status/feedback guidance.
- Existing views expose empty and permission states, but loading, error,
  stale/partial, and first-run states are not yet standardized across every
  workspace.

## Pass 8 — Motion and interaction polish (partial)

- Respects `prefers-reduced-motion` and uses keyboard-operable command and help
  dialogs with focus management. A full motion and optimistic-update audit is
  still needed.

## Pass 9 — Accessibility, responsiveness, and performance (partial)

- Fixed contrast failures in both themes, added accessible labels for collapsed
  workspace navigation, and made mobile header targets meet the 44px target.
- Axe-core found zero serious or critical violations across Command, Finance,
  Connect, Forecast, and Risk at 360, 768, and 1536 CSS pixels in both light
  and dark themes. Compact density also had no horizontal overflow at those
  widths. Browser checks of the five workspaces found no horizontal overflow.
- Saved responsive screenshots for those five existing workspaces at all
  three widths. Lighthouse, real 200% browser zoom, and full performance
  profiling remain unverified.

## Pass 10 — UX quality gate (partial)

- The frontend build and both PHP suites pass. Axe checks for the five existing
  workspaces report zero serious/critical findings in the tested themes and
  widths. The screenshots are in `docs/ux-screenshots/`.
- The React frontend has no Document Vault or Integration Hub workspace and
  Connect only previews CSV files; those requested end-to-end journeys cannot
  be completed without new product/API support. The design-system catalog,
  full table/form state coverage, all-page token migration, Lighthouse, and
  the remaining requested journeys are still open.

# VANTIQ UX audit

## Scope and method

Reviewed the active Vite/React experience in `frontend/src`: the authentication
flows, authenticated shell, ten workspace views, shared data-table/chart/workflow
components, API-facing loading and error handling, global CSS, and entry document.
The workspace views are selected inside one client-side shell rather than
implemented as separate routes. The repository also contains a PHP application;
this audit focuses on the React + TypeScript + Vite product surface requested.

The findings below describe the current implementation before the UX passes.
“Not present” identifies a product capability that cannot be redesigned without
inventing or changing its underlying behavior.

## Findings ranked by impact

### P0 — Trust, access, and end-to-end task completion

1. **Several named journeys have no corresponding React workspace.** `Connect`
   supports local CSV inspection and import-history viewing, but does not expose
   an import commit/review flow or an integration setup flow. There is no
   Document Vault, document upload/review, or invoice detail/payment-tracking
   destination. The Finance view can issue an invoice and record a payment, but
   does not provide a dedicated invoice lifecycle screen. These are product
   coverage gaps, not cosmetic states; building them safely requires API and
   workflow support.
2. **The global search is also the only navigation shortcut, but has no
   command-action model.** Ctrl/Cmd+K opens organisation record search; users
   cannot jump to a workspace or invoke actions such as creating an invoice.
   Search requests are started on submit rather than debounced, and errors
   render in the page feedback area rather than in the search result context.
3. **Financial data needs stronger provenance and freshness context.** The
   dashboard identifies the general period and that records are actuals, but
   does not consistently show when data was updated, its source, currency
   context, or a comparable-period delta. This makes it harder to judge how
   current and comparable an executive metric is.
4. **Mutating workflows lack consistent safety and completion affordances.**
   Forms disable their submit button while busy, but destructive/irreversible
   actions and purchase-order transitions do not consistently confirm their
   consequences. Success feedback is often a sentence in the global/module
   notice area rather than a contextual next step or undo.

### P1 — Readability, hierarchy, and consistency

5. **Typography is not fully self-hosted or token-governed.** The entry HTML
   loads Inter and Manrope from Google Fonts, while CSS also contains repeated
   font declarations, hard-coded pixel and rem sizes, and ad-hoc weights.
   JetBrains Mono, the requested display face, and a role-based type scale are
   absent. External font requests add a dependency and can cause font swaps.
6. **The global stylesheet mixes foundations and component styling.** A small
   initial set of CSS variables is followed by a very large stylesheet with
   repeated literal colors, dimensions, and breakpoint-specific overrides.
   Consequently, palette and spacing changes are difficult to apply
   consistently and light/dark or comfortable/compact modes are unavailable.
7. **The authenticated shell has duplicated account affordances and an uneven
   hierarchy.** Account actions appear in both the sidebar and the top bar;
   navigation uses the same visual treatment for workspaces and does not expose
   grouped categories. Page headers repeat a breadcrumb and workspace name but
   have no consistent primary-action area.
8. **Content density and table behavior vary by workspace.** Tables are rendered
   with a local `DataTable` pattern and CSS horizontal scrolling, but there is
   no shared density preference, pagination/saved views/bulk selection, or
   consistent numeric alignment and row-level detail pattern. The Design
   System view is a partial preview rather than a catalog of the actual shared
   component states.
9. **Dashboard hierarchy is broad rather than focused.** Executive metrics can
   exceed the requested five-to-six KPI limit when all permissions are
   available; the chart and supporting panels have no drill-down links or
   comparative deltas. Some labels are uppercase and highly tracked, while
   explanatory copy and data metadata use different typographic treatments.

### P2 — Accessibility, feedback, and resilience

10. **Keyboard/focus behavior is incomplete.** Ctrl/Cmd+K is supported, but
    there is no shortcut help surface, no full command palette, and no
    consistent `:focus-visible` treatment evidenced by the shared stylesheet.
    Search/notification/account popovers do not implement a common dismissal,
    focus-return, or focus-management pattern.
11. **Status meaning is not consistently redundant.** Severity and lifecycle
    states are frequently plain text or color-coded classes; icons/labels are
    not consistently paired. The trend chart has a text alternative, but no
    accessible data-table toggle or export in the dashboard context.
12. **Data states are uneven.** Workspace loading, errors, empty collections,
    and permission-denied messaging exist, but loading is a single text state
    rather than a layout-matched skeleton. Partial/stale data, retry controls,
    upload progress, and explicit empty-state actions are not consistently
    represented.
13. **Forms are functional but not uniformly self-explanatory.** Native required
    validation is used, but there are few helper/error descriptions, no
    top-level error summary, and no common control states. File selection is a
    basic input, and long forms have no draft or unsaved-change treatment.
14. **Responsive and performance criteria are unverified.** Responsive CSS
    exists, including a mobile navigation treatment and horizontally scrollable
    tables, but the specified widths, 200% zoom, route splitting, Web Vitals,
    automated accessibility checks, and key-journey browser tests have not been
    evidenced.

## Audit notes

- Existing APIs, permission checks, authentication, and financial operations
  should remain unchanged during presentation work.
- New screens that require missing backend capabilities are out of scope for a
  presentation-only redesign; they should be reported as limitations rather
  than represented by non-functional controls.
- Validation should include the current frontend build/type-check plus
  automated and browser-based checks where the repository and environment
  support them.

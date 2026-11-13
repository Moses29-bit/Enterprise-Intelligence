# UX progress

## Pass 1 — Typography

- Added self-hosted, variable Inter (with optical sizing), Plus Jakarta Sans,
  and JetBrains Mono font assets with `font-display: swap`; removed the external
  Google Fonts references from the React entry document and preloaded Inter.
- Added role-based type-scale custom properties and utility classes, tabular
  numerals for financial/date/table values, mono styles for raw values, and
  global focus-visible and reduced-motion defaults.
- Validation: `npm.cmd --prefix frontend run build` passed. The repository has
  no frontend test script; a type-check is included in this build.
- Follow-up within this pass: legacy component selectors still declare local
  font sizes. These will be migrated to the shared scale as the related
  components are touched; an automated token-only check is not yet in place.

# VANTIQ content guide

## Voice

- Write in sentence case, with short, direct wording and active verbs.
- Explain the next step when a user cannot continue; never blame the user.
- Use the same name for a workspace or action everywhere it appears.
- Keep financial values, units, periods, and data provenance explicit.
- Distinguish missing, zero, and unavailable values; do not imply a forecast is
  an actual result.

## Product terms

| Term | Use | Avoid |
|---|---|---|
| Command | The executive intelligence workspace | Dashboard, when naming the workspace |
| Finance | The workspace for posted sales, expenses, invoices, and payments | Billing, unless referring to a specific billing process |
| Record a sale | The inventory-backed sale-posting action | New invoice, which is a separate workflow |
| Issue invoice | Create an invoice for a customer and products | Create sale, when the operation is invoice issuance |
| Record payment | Apply a payment against an open invoice | Mark paid, unless the invoice is fully settled |
| Actual | A value calculated from recorded transactions | Forecast, estimate, or projection |
| Forecast | A model-generated projection, shown separately from actuals | Actual, expected result |
| Receivables | Outstanding invoice balances owed to the organisation | Revenue, unless describing recognised sales |
| Inventory at cost | Current stock valued at its recorded unit cost | Inventory value, when the valuation basis is not stated |
| Risk alert | An evidence-backed item that needs review | Fraud, violation, or misconduct, unless verified |
| CSV preview | A validation and preview step that does not import data | Import, unless records are committed |

## Status and feedback

- Pair a status label with an icon or other non-color cue.
- Say what happened, what it means, and what the user can do next.
- Use “No … is available” for absent data and state when a feature or access
  is unavailable; do not present absence as a zero value.
- Use “Updated … ago” only when the server provides a reliable timestamp.
- Confirm destructive actions with their consequence; offer undo when the
  operation can be safely reversed.

## Numbers and dates

- Use the organisation's configured currency and show its unit beside values.
- Use tabular numerals for financial values, counts, dates, and table data.
- Keep negative values consistently signed and distinguish percentages from
  currency.
- Provide exact values when a compact value such as `1.2M` is shown.
- Use one date format throughout a workflow and include a timezone when it
  affects a deadline or transaction.

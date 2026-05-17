---
description: Spending breakdown for a period, optionally filtered to a category. Usage: /firefly-mcp:spending <period> [category]
---

The user wants a spending breakdown. `$ARGUMENTS` will look like one of:

- `october` — calendar month
- `last week` — last 7 days
- `2026-Q1` — calendar quarter
- `2026-03-01..2026-03-31` — explicit range
- `october groceries` — period + category
- `last month "dining out"` — period + multi-word category (quoted)

Steps:

1. Parse the period to ISO 8601 `start` / `end`. If unparseable, ask the user to clarify before proceeding.
2. Detect the category argument (anything after the period that isn't a date). If supplied:
   - Read `firefly-iii://user/categories` and find a fuzzy match.
   - If no match, surface the user's actual categories and ask which they meant. Don't substitute silently.
3. Call `insight-expense start=<start> end=<end>` (no category filter — the tool returns per-category breakdown).
4. If a category was requested, pluck that row. Otherwise render the full breakdown sorted by total descending.
5. Format as a markdown table: `| Category | Amount | % of total |`. Include currency column if multiple currencies appear.
6. Include the period total in a closing line.

If `insight-expense` returns an empty array, say "No expenses recorded for `<period>`" — don't fabricate.

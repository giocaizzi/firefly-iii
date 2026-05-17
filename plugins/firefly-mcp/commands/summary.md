---
description: One-shot financial summary for a period. Default = current month. Calls summary-basic + optionally insight-expense and insight-income for the requested window.
---

The user wants a financial summary for `$ARGUMENTS` (or current month if empty).

1. Parse `$ARGUMENTS` for a time period:
   - Empty → use current month (server's default — pass no args)
   - "this month" / "current month" → current month (server default)
   - "last month" → previous calendar month, exact start/end dates
   - "this year" / "ytd" → Jan 1 of current year through today
   - "Q1 2026" / "march" / explicit dates → parse to ISO 8601 start/end
2. Call `summary-basic` with the resolved start/end (omit both if current month).
3. Render the response as a compact human-readable digest:
   - Net income for the period
   - Total expenses
   - Net change in balance
   - Budgets: spent / left for each active budget
   - Bills: due / paid in the period
   - Net worth as of `end`
4. Highlight anything notable in 1-2 lines after the digest:
   - If a budget is overspent
   - If a bill is unpaid past its expected date
   - If net change is unusually large vs the user's prior pattern (only if you have prior-period data in context already — don't go fishing)

Currency-aware: rows may carry their own `currency_code`. Group by currency if multiple appear.

If the MCP server returns 404, the instance has `allow_mcp=false`. Tell the user to flip the flag in the Firefly admin UI.

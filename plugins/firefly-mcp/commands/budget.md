---
description: Budget vs actual for a period. Default = current month. Usage: /firefly-mcp:budget [period]
---

The user wants a budget vs actual comparison for `$ARGUMENTS` (or current month if empty).

1. Parse the period to ISO 8601 start/end. Default = current month.
2. Run two calls in parallel:
   - `list-budgets active=true` — for the budget names and `auto_budget_amount` where set
   - `chart-data chart_type=budget_vs_actual start=<start> end=<end>` — for actual spend per budget over the window
3. Join the two by budget id. Render a table:

```
| Budget        | Limit   | Spent   | Remaining | % used |
|---------------|---------|---------|-----------|--------|
| Groceries     | 400.00  | 312.45  | 87.55     | 78%    |
| ...           |         |         |           |        |
```

4. Flag budgets at >90% in red-text or with an explicit warning line; flag overspent budgets even more prominently.
5. If a budget has no `auto_budget_amount` (manual budget, no cap), show "—" in the Limit column and skip the % calc.

Currency-aware: if budgets span currencies, group by currency or print one row per currency for the same budget. Don't sum across.

If the period spans more than 90 days, warn the user that budget rollover may obscure the picture and offer to break it into months.

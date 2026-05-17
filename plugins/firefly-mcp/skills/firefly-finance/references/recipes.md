# Workflow recipes

Patterns that recur enough to be worth documenting. Read this when an everyday recipe in SKILL.md doesn't fit the user's intent.

## Recipe: period-over-period comparison

User: "Am I spending more on groceries than last month?"

```
1. Resolve category: read firefly-iii://user/categories → find 'Groceries' id
2. Two insight-expense calls in parallel:
   - this month:   start=<month-start> end=<today>
   - prior month:  start=<prior-month-start> end=<prior-month-end>
3. Diff the two groceries rows; report delta + percent change
```

Don't reuse `summary-basic` here — its window is fixed to current month and won't accept a custom prior-month range cleanly.

## Recipe: balance reconciliation

User: "Why doesn't my Chase Checking balance match what Chase shows?"

```
1. get-account name='Chase Checking' → current_balance, currency, last update
2. list-transactions account_name='Chase Checking' start=<2 weeks ago> limit=50
3. Sum the transactions in time order; compare with the running balance the account reports.
4. Surface pending vs settled (Firefly treats them the same — flag any in the future).
```

Often the discrepancy is a duplicate or a transaction logged on the wrong date.

## Recipe: bulk re-categorization

User has 20 transactions tagged "uncategorized" they want to spread across categories. **Use the `firefly-mcp:transaction-reviewer` agent for this** — it does the audit-and-propose flow correctly.

Manual fallback if the agent isn't available:

```
1. Pre-load categories: read firefly-iii://user/categories
2. search-transactions query='category_is:none' limit=50
3. For each transaction, propose a category from the cached list based on description/amount/source
4. Echo the full plan back to user (id → category mapping)
5. On confirm, iterate update-transaction calls
   - Each call gets idempotency_key=<txn-id>-recat-<category-id> so a retry is safe
```

Don't `bulk-create-transactions` here — that's for **new** transactions, not edits.

## Recipe: piggy-bank progression

User: "How am I doing on my emergency fund?"

```
1. list-piggy-banks
2. Locate the one matching 'emergency'
3. Surface: current_amount, target_amount, percentage, target_date if set
4. If asked "what should I save monthly to hit the target", compute (target - current) / months_remaining
```

## Recipe: recurring bills check

User: "Did rent come out this month?"

```
1. list-bills active=true → find 'Rent' (or matching name)
2. Read its expected next-occurrence date and amount
3. search-transactions query='bill_id:<id>' start=<this month start>
4. Match by amount + description against the bill spec
5. Report: matched / missing / matched but amount differs
```

## Recipe: spending by tag

User: "What did I spend on the Italy trip?"

```
1. Read firefly-iii://user/tags → find 'italy-2026' id
2. search-transactions query='tag_is:italy-2026'
3. Sum amounts grouped by source account / category if useful
```

`insight-expense` doesn't accept a tag filter — search is the right tool for tag-scoped totals.

## Recipe: importing a CSV of transactions

User pasted a CSV or pointed at a file. Parse it (Read tool on the file, NOT this skill), map columns to the schema, then:

```
1. Sanity-check: resolve account names + category names against the cached snapshots
   - For any unresolved name, decide: auto-create (expense/revenue allow it) or ask user
2. Build the array; cap at 100 items per call; chunk if needed
3. Generate a deterministic idempotency_key per item: hash(source + date + amount + description)
4. bulk-create-transactions
5. On all-or-nothing failure, surface the error.errors[] indexed list and let the user fix the offending rows
```

## Recipe: dry-run before a destructive change

User: "Delete every transaction tagged 'test'."

```
1. search-transactions query='tag_is:test' (just to surface the count + sample)
2. Echo the count and 3-5 examples to the user
3. Wait for explicit confirmation
4. Iterate delete-transaction id=<each>
   - These are NOT idempotent — a retry on the same id errors. Track which ones succeeded.
```

## Recipe: building a custom report

User: "Make me a markdown table of expenses per category for Q1."

```
1. insight-expense start=2026-01-01 end=2026-03-31 (one call)
2. Sort the rows by total descending
3. Render as markdown table; include currency-aware totals (rows can carry different currency_code if the user has multi-currency accounts)
```

Don't reach for `chart-data` if the user asked for a table — `insight-expense` is the right shape.

## Recipe: exporting account snapshots

User: "Give me a JSON dump of every account."

```
1. list-accounts limit=200 active=true → page through if necessary
2. For each account in scope, get-account id=<id> if richer detail is needed
   (otherwise the list-accounts payload is usually enough)
3. Render as JSON; preserve currency_code per account
```

## Recipe: setting up a new budget

The MCP MVP doesn't expose `create-budget` — budgets must be created in the Firefly web UI. If the user asks "set up a $400/month grocery budget", surface this limitation and offer to:

1. Check whether a 'Groceries' category already exists (`list-categories`)
2. Show what `insight-expense` reports for the prior month so they have a baseline
3. Hand off to the web UI for the create step

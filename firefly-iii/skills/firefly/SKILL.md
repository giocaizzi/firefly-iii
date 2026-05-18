---
name: firefly
description: Use this skill whenever the user asks anything about their personal finances, money, spending, income, account balances, budgets, categories, savings goals, recurring bills, transaction history, or financial reports — even if they don't say "Firefly" by name. Phrases like "how much did I spend on...", "what's my balance", "log a transaction", "how am I doing this month", "show me my expenses", "did the rent come out", "categorize these", "set up a budget" all trigger this skill. The skill teaches the assistant how to drive the Firefly III MCP server (25 tools + 8 resources) to answer finance questions and make changes. Use this skill BEFORE making generic suggestions or guessing — Firefly is the user's source of truth for personal finance data.
---

# Firefly III — operating the MCP endpoint

The user runs [Firefly III](https://firefly-iii.org/), a self-hosted personal-finance manager. This plugin exposes the user's Firefly instance to you through the `firefly-iii` MCP server (defined in `.mcp.json` at the plugin root). When the user asks a finance question, **call those tools instead of inventing answers** — Firefly is the source of truth.

## How the tool surface is organized

Five categories. Knowing the shape helps you pick the right tool fast.

| Category | Tools | When to reach for it |
|---|---|---|
| **Read — core entities** | `list-accounts`, `get-account`, `list-transactions`, `get-transaction`, `list-budgets`, `get-budget`, `list-categories`, `get-category`, `list-tags`, `get-tag` | Direct lookups: "what accounts do I have", "show that transaction from yesterday", "list my categories" |
| **Read — search** | `search-transactions` | Free-text or operator search: "find that Amazon refund", "transactions with note 'tax 2025'" |
| **Read — power-user** | `list-bills`, `list-rules`, `list-piggy-banks`, `list-webhooks` | Recurring bills, transaction rules, savings targets, integrations |
| **Read — aggregates** | `summary-basic`, `insight-expense`, `insight-income`, `chart-data` | "How am I doing this month", "spending by category", "balance over time" — **prefer these over computing aggregates yourself** from `list-transactions` |
| **Write** | `create-withdrawal`, `create-deposit`, `create-transfer`, `update-transaction`, `delete-transaction`, `bulk-create-transactions` | Logging new transactions, fixing categorization, removing duplicates, importing batches |

Resources (under URI scheme `firefly-iii://`) give grounding data without API churn:

- `firefly-iii://catalogs/{currencies,account-types,transaction-types,link-types}` — enums
- `firefly-iii://user/{accounts,tags,categories,budgets}` — user's reference data

Read a resource once at the start of a session when you'll be doing multiple operations that need account/category/budget names — it's cheaper than calling `list-*` repeatedly.

## Response shape

Every tool returns a reduced JSON envelope: flat objects with `id` + attributes, relationships hoisted as `*_id` keys, pagination preserved in `meta.pagination`. Null attributes are dropped by default. If you need the raw JSON:API document, pass `verbose: true`. If you specifically need null-valued fields, pass `include_nulls: true`. **Use these flags sparingly** — the reduced shape is what's tuned for you.

## Picking the right tool — decision rules

1. **"How am I doing" / "what did I spend on X" / "income this quarter"** → reach for an **aggregate** tool (`summary-basic`, `insight-expense`, `insight-income`, `chart-data`). Don't list transactions and sum them yourself; the aggregate tools already do that work server-side.

2. **A specific transaction the user can name** ("the Amazon one from Tuesday", "find that $43.20 charge") → `search-transactions` with a free-text query. Fold dates into the query as `date_after:YYYY-MM-DD date_before:YYYY-MM-DD` operators or pass `start`/`end` args (the server will inject them).

3. **A specific entity by id or name** ("show my Chase Checking", "category 'Groceries'") → matching `get-*` tool. If the user gives a name and not an id, pass it as `*_name` — the server resolves both.

4. **Working with a known list ("change categories on these five transactions")** → `update-transaction` per id, OR — if many — pre-load `firefly-iii://user/categories` once, then iterate. For batch work, see the recipes below.

5. **Logging a new spend/income** → `create-withdrawal` (paying out), `create-deposit` (money in), `create-transfer` (between two own accounts). Account types must match: withdrawal needs an asset source + expense destination; deposit needs revenue source + asset destination; transfer needs two asset accounts in the same currency.

## Workflow recipes

These cover ~80% of real intents. Read `references/recipes.md` for the long tail.

### Recipe: "How am I doing this month?"

```
summary-basic                              # current month is the default range
```

One call. Returns income / expense / balance / budget left / bills due / net worth in a single blob. **Don't enumerate transactions** for this — it'd burn tokens and produce a less coherent answer.

### Recipe: "How much did I spend on groceries in October?"

```
1. Read firefly-iii://user/categories  (find 'Groceries' id; cache it)
2. insight-expense start=2026-10-01 end=2026-10-31  → returns category breakdown
3. Pluck the row with category_id matching Groceries
```

If the user names a category that doesn't exist, surface the available categories from the resource — don't silently substitute.

### Recipe: "Find that Amazon refund from last week"

```
search-transactions query="amazon" start=<7 days ago> end=<today>
```

If the result set is too broad, layer operators into the query: `query="amazon amount_gt:50"`.

### Recipe: Logging a coffee purchase

```
create-withdrawal
  source_name=<asset account>        # Chase Checking, Amex, etc.
  destination_name="Coffee shop"     # auto-creates expense account if missing
  amount=5.20
  date=2026-05-17
  description="Latte"
  category_name="Coffee & tea"       # if it exists; server resolves name
```

Confirm the source account with the user before submitting if it's ambiguous (multiple asset accounts). **Don't guess.**

### Recipe: Bulk-importing transactions (e.g. from a CSV)

```
bulk-create-transactions transactions=[ {item}, {item}, ... ]
```

- All-or-nothing: a single validation failure aborts the whole batch (zero inserts). Pre-validate aggressively before submitting.
- Max 100 items per call. Chunk larger sets.
- Each item can carry its own `idempotency_key` so retries don't duplicate.

### Recipe: Idempotency on a single write

Pass `idempotency_key="<deterministic-string>"` (≤128 printable-ASCII chars) on any `create-*` call. The server stores it as `mcp_idempotency_key` meta on the journal; a repeat call with the same key returns the original transaction id without creating a duplicate. **Use this when retrying a failed call** so you don't accidentally double-post.

## Hard rules to follow

These come from the MCP server's MVP scope (see `references/gotchas.md` for the full list and rationale):

- **Cross-currency transfers are rejected.** If source and destination accounts have different currencies, `create-transfer` errors out. Tell the user to use the Firefly web UI for that case.
- **Currency is inherited from the source account.** Don't try to pass `currency_code` — it's not in the schema.
- **Dates are ISO 8601 (`YYYY-MM-DD`).** Convert natural language ("last Tuesday", "May 17th") before calling.
- **Account roles matter.** Withdrawal source=asset, destination=expense; deposit source=revenue, destination=asset; transfer both=asset.
- **The endpoint is gated by `allow_mcp`.** A 404 on every call usually means the admin turned it off, not a bug in your call.

## Destructive operations — confirm first

`update-transaction`, `delete-transaction`, and `bulk-create-transactions` are marked destructive by the server. Before calling them:

1. Echo back what you're about to do: the transaction id(s), the field(s) you're changing, the new value(s).
2. Wait for the user to confirm.
3. Then call the tool.

Exception: if the user explicitly said "go ahead" or "just do it" or already approved an identical operation moments ago in the same turn, you can proceed without re-asking.

## When to read references

The skill body above covers everyday operations. For deeper work, load on demand:

- **`references/tools.md`** — full per-tool schema (every arg, every required vs optional, default values). Read this when you're about to call a tool you haven't called before in the session, or when the user asks something that requires a less-common argument.
- **`references/recipes.md`** — extended workflow patterns: balance reconciliation, category rule construction, piggy-bank progression, period-over-period comparisons, exporting reports.
- **`references/gotchas.md`** — every known edge case: currency handling, idempotency lifecycle, bulk failure modes, account-role validation, pagination behavior, `allow_webhooks` interaction, response-shape exceptions for aggregate tools.

## What this skill does NOT do

- It doesn't run the Firefly server. The server is the user's self-hosted Firefly III instance (the MCP endpoint at `/api/v1/mcp`).
- It doesn't manage users, OAuth clients, or admin settings — those are out of scope of the MCP MVP and live in the Firefly web UI.
- It doesn't give tax or financial advice. Surface the user's data and let them decide.

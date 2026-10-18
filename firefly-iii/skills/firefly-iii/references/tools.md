# Tool reference

Full per-tool schemas with every argument. Tool names are auto-derived from class names → kebab-case (e.g. `ListAccountsTool` → `list-accounts`).

## Implicit args on every read tool

In addition to the per-tool args below, every read tool accepts:

- `verbose: bool` (default `false`) — when `true`, returns the raw JSON:API document instead of the reduced envelope. Use sparingly.
- `include_nulls: bool` (default `false`) — when `true`, preserves null and empty values. Useful when presence-vs-absence matters (audits, compliance).
- `page: int` (from 1), `limit: int` (1-200, default 25) where the tool paginates. Lists add `meta.has_more` and `meta.next_page`.

Tools with a compact default row (transactions, `list-accounts`, `list-rules`, `list-rule-groups`) also accept `compact: bool` (default `true`; `false` returns every attribute in the reduced shape). Transaction read tools (`list-transactions`, `search-transactions`, `get-transaction`) further accept:

- `fields?: string[]` — keep only these row keys (compact keys or raw split keys such as `source_id`, `iban`, `internal_reference`); the ids are always kept

Compact transaction rows use `id` = the transaction (group) id every tool accepts and add `journal_id` only when it differs; split groups are `{ group_id, title, splits }` with `journal_id` on each split.
- `description_max?: int` — description cap, default 120, `0` = no cap

Invalid arguments return `{"error":"invalid_argument","field":..,"message":..}`.

## Read — core entity tools

### `list-accounts`
- `type?: string` — `asset` | `expense` | `revenue` | `liability` | `cash`, or comma-separated raw `AccountTypeEnum` values; anything else is rejected
- `query?: string` — name contains (case-insensitive)
- `active?: bool`
- `page?: int`, `limit?: int`
- Compact row: `{ id, name, type, currency, current_balance, active }`

### `get-account`
- One of `id?: int` or `name?: string` required

### `list-transactions`
- `start?: date`, `end?: date` (ISO 8601)
- `account_id?: int` or `account_name?: string`
- `type?: enum` ∈ `{withdrawal, deposit, transfer, opening_balance, reconciliation, all, default}`
- `page?: int`, `limit?: int`
- An unknown `account_name` / `account_id` is an error (it no longer silently lists everything)

### `get-transaction`
- `id: int` (required) — the transaction id (a compact row's `id`, or `group_id` for a split group)

### `list-budgets`
- `active?: bool`
- `page?: int`, `limit?: int`

### `get-budget`
- One of `id?: int` or `name?: string` required

### `list-categories`, `list-tags`
- `page?: int`, `limit?: int`

### `get-category`
- One of `id?: int` or `name?: string` required

### `get-tag`
- One of `id?: int` or `tag?: string` required

## Read — search

### `search-transactions`
- `query: string` (required) — Firefly's search-operator grammar (`amount_more:`, `date_after:`, `category_is:`, etc.); full syntax and operator list in the `firefly-iii://help/search` resource. Unknown operators are listed in `meta.warnings`.
- `start?: date`, `end?: date` — folded into the query as `date_after:` / `date_before:` if not already present
- `page?: int`, `limit?: int`

## Read — power-user

### `list-bills`
- `active?: bool`
- `page?: int`, `limit?: int`

### `list-rules`
- `query?: string` — title contains (case-insensitive)
- `group_id?: int`
- `active?: bool`
- `page?: int`, `limit?: int`
- Compact row: `{ id, group_id, group, title, active, strict, order, trigger_count, action_count }`

### `get-rule`
- `rule_id: int` (required) — full rule with `triggers` and `actions`

### `list-rule-groups`
- `page?: int`, `limit?: int`
- Compact row: `{ id, title, description?, active, order }`

Rule titles and descriptions come back HTML-decoded (`&` rather than `&amp;`); `verbose: true` keeps the stored value.

### `list-piggy-banks`, `list-webhooks`
- `page?: int`, `limit?: int`

Note: `list-webhooks` honors the instance-wide `allow_webhooks` flag — returns 404-equivalent error if disabled.

## Read — aggregates

### `summary-basic`
- `start?: date`, `end?: date` — default = current month
- Returns: dashboard digest blob (income, expense, balance, net worth, bills due, budgets left).

### `insight-expense`
- `start: date` (required), `end: date` (required)
- `account_ids?: int[]`
- Returns: per-category expense rollup.

### `insight-income`
- `start: date` (required), `end: date` (required)
- `account_ids?: int[]`
- Returns: per-source income rollup.

### `chart-data`
- `chart_type: enum` ∈ `{account_balances, category_expenses, budget_vs_actual}` (required)
- `start: date` (required), `end: date` (required)
- Returns: chart-row array; each row carries its own `currency_code`.

## Write tools

All write tools accept:
- `idempotency_key?: string` — printable ASCII, ≤128 chars. Server stores under `mcp_idempotency_key` journal meta. A second call with the same key returns the existing transaction id and `idempotent_replay: true` — no duplicate.

### `create-withdrawal`
Source must be **asset**, destination must be **expense**.

- `source_id?: int` or `source_name?: string` (one required)
- `destination_id?: int` or `destination_name?: string` (one required; auto-creates expense account if name unknown)
- `amount: decimal` (required) — positive number
- `date: date` (required, ISO 8601)
- `description: string` (required)
- `category_id?: int` or `category_name?: string`
- `budget_id?: int` or `budget_name?: string`
- `tags?: array<string|int>` — mixed: tag ids and/or strings; server dedupes case-insensitively
- `notes?: string`
- `idempotency_key?: string`

### `create-deposit`
Source must be **revenue**, destination must be **asset**. Same schema as withdrawal.

### `create-transfer`
Both source and destination must be **asset**, **same currency**. Cross-currency transfers are rejected with an explicit error — use the Firefly web UI for those.

Same schema as withdrawal.

### `update-transaction`
- `transaction_id: int` (required) — the transaction group id; the journal id is resolved automatically for single-journal groups
- All other fields optional; same shape as create-* but every field optional. Server validates against `UpdateTransactionRequest::rules()`.

Marked `IsDestructive` + `IsIdempotent`.

### `delete-transaction`
- `id: int` (required)

Returns a plain-text confirmation. Marked `IsDestructive` (NOT idempotent — a second call returns "Transaction not found").

### `bulk-create-transactions`
- `transactions: array<TransactionSpec>` (max 100 items, required)
  - Each item: `type: enum` ∈ `{withdrawal, deposit, transfer}` + the full create-* spec
  - Each item may carry its own `idempotency_key`
- All-or-nothing inside a single DB transaction. Any validation failure aborts; zero inserts.
- Response: `{ data: { created, replayed, total, transactions: [{ index, replayed, transaction }] }, meta: { primary_currency } }` with a compact row per item; `verbose: true` returns `[{ index, replayed, document }]` with the raw JSON:API document per item.

Every transaction write tool echoes the compact row (`compact: false` / `verbose: true` for the full shapes).

## Resources

All resources reduce to flat arrays. Read once per session; cache; don't refetch unless the user signals a change.

### Static catalogs

| URI | Output shape |
|---|---|
| `firefly-iii://catalogs/currencies` | `[{ code, name, symbol, decimal_places, enabled, primary }]` — all currencies; `enabled` / `primary` are per user group |
| `firefly-iii://catalogs/account-types` | `[{ type }]` |
| `firefly-iii://catalogs/transaction-types` | `[{ type }]` |
| `firefly-iii://catalogs/link-types` | `[{ id, name, inward, outward, editable }]` |

### User snapshots (scoped to the authenticated user)

| URI | Output shape |
|---|---|
| `firefly-iii://user/accounts` | `[{ id, name, type, currency_code, current_balance, active }]` (unpaged) |
| `firefly-iii://user/accounts?page=1&limit=25&type=asset&query=text` | same rows, paged and filtered; `type` ∈ asset, expense, revenue, liability |
| `firefly-iii://user/tags` | `[{ id, tag, description }]` |
| `firefly-iii://user/categories` | `[{ id, name }]` |
| `firefly-iii://user/budgets` | `[{ id, name, active, auto_budget_amount?, auto_budget_period? }]` |

### Help

| URI | Content |
|---|---|
| `firefly-iii://help/search` | Markdown: search syntax, examples, every operator the parser accepts |

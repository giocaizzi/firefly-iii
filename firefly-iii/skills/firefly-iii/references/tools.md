# Tool reference

Full per-tool schemas with every argument. Tool names are auto-derived from class names → kebab-case (e.g. `ListAccountsTool` → `list-accounts`).

## Implicit args on every read tool

In addition to the per-tool args below, every read tool accepts:

- `verbose: bool` (default `false`) — when `true`, returns the raw JSON:API document instead of the reduced envelope. Use sparingly.
- `include_nulls: bool` (default `false`) — when `true`, preserves null and empty values. Useful when presence-vs-absence matters (audits, compliance).
- `page: int` (from 1), `limit: int` (1-200, default 25) where the tool paginates. Lists add `meta.has_more` and `meta.next_page`.

Tools with a compact default row (transactions, `list-accounts`, `list-rules`, `list-rule-groups`) also accept `compact: bool` (default `true`; `false` returns every attribute in the reduced shape). Transaction read tools (`list-transactions`, `search-transactions`, `get-transaction`) further accept:

- `fields?: string[]` — keep only these row keys (compact keys or raw split keys such as `source_id`, `iban`, `internal_reference`); the ids are always kept. `created_at` / `updated_at` (group timestamps) appear only when listed here; `updated_at` is what `if_unmodified_since` expects

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
- Aggregate args (see below)
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
- `list-tags` only: `with_stats?: bool` — adds `stats` per tag (see `get-tag`)

### `get-category`
- One of `id?: int` or `name?: string` required

### `get-tag`
- One of `id?: int` or `tag?: string` required
- `with_stats?: bool` — adds `stats: { count, first_date?, last_date?, totals? }`; `totals` as in the aggregate below

## Read — search

### `search-transactions`
- `query: string` (required) — Firefly's search-operator grammar (`amount_more:`, `date_after:`, `category_is:`, etc.); full syntax and operator list in the `firefly-iii://help/search` resource. Unknown operators are listed in `meta.warnings`.
- `start?: date`, `end?: date` — folded into the query as `date_after:` / `date_before:` if not already present
- `page?: int`, `limit?: int`
- Aggregate args (see below)

### Aggregate args (`search-transactions`, `list-transactions`)
- `aggregate?: bool` — adds `aggregate: { count, totals, group_by?, groups? }` computed over **every** match, not just the page
- `group_by?: enum` ∈ `{month, category, tag, source, destination, account, type}` — `groups: [{ key, id?, count, totals }]`; `account` = the own side (source; destination for deposits); `(none)` collects uncategorized / untagged rows
- `include_categories?: string[]`, `exclude_categories?: string[]` — category ids or names (case-insensitive); filter the aggregate only
- `rows?: bool` — `false` returns `{ aggregate, meta }` without rows
- Setting any of `group_by`, the category lists or `rows` implies `aggregate: true`
- `totals: [{ currency, count, withdrawal, deposit, transfer?, net }]` — positive sums per type in the transaction's currency; `net` = deposit − withdrawal

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

### `test-rule`
- `rule_id?: int` or `rule_group_id?: int` (the group's active rules) — one required
- `start?: date`, `end?: date`, `transaction_ids?: int[]` (group ids), `account_ids?: int[]` — narrow the dry run
- `page?: int`, `limit?: int`, transaction output args
- Compact transaction rows of every match the rule(s) would act on; `meta.count` = total matches. Changes nothing.

### `get-audit-log`
- `transaction_id: int` (required)
- `{ transaction_id, count, entries: [{ id, scope, journal_id, action, before, after, before_inferred?, changer_type, changer_id, created_at }] }`

### `list-piggy-banks`, `list-webhooks`
- `page?: int`, `limit?: int`

Note: `list-webhooks` honors the instance-wide `allow_webhooks` flag — returns 404-equivalent error if disabled.

### `health`
- No arguments (besides `include_nulls`)
- Returns `{ firefly_version, mcp: { server, build? }, user: { id, email, user_group_id }, features: { webhooks, exchange_rates, handle_debts, expression_engine, export }, can_write, token_scopes? }`. `mcp.build` comes from the `MCP_BUILD` env var, else the checkout's git commit; `can_write` reflects the user's role in their group.

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

### `account-balance`
- `account_id?: int` and/or `account_ids?: int[]` (max 50) — asset, cash or liability accounts
- `date?: date` and/or `dates?: date[]` (max 60) — default today
- Returns: `{ data: [{ account_id, name, type, currency, entries: [{ date, balances: { <code>: amount } }] }] }` — balance at the end of each day, per currency, virtual balance included (same as the account overview)

## Write tools

The create tools accept:
- `idempotency_key?: string` — printable ASCII, ≤128 chars. Server stores under `mcp_idempotency_key` journal meta. A second call with the same key returns the existing transaction id and `idempotent_replay: true` — no duplicate.

Every tool that takes account, category or tag names (create-*, `bulk-create-transactions`, `update-transaction`, `classify-transactions`) accepts:
- `create_missing?: bool` (default `true`) — unknown names are created as today and listed in `meta.created: { accounts?: [{id, name, type}], categories?: [{id, name}], tags?: [{id, tag}] }`; `false` returns `{"error":"missing_names","missing":{...}}` and writes nothing

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
- `tags` replaces the tag set; `add_tags?: string[]` / `remove_tags?: string[]` merge with it (case-insensitive)
- `clear_category?`, `clear_budget?`, `clear_bill?: bool` — unlink; cannot be combined with an id or name for the same relation
- `if_unmodified_since?: string` — the `updated_at` you read; a newer change returns `{"error":"conflict",...}` and nothing is written

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

- After a timeout, resend the same batch with the same keys: committed items replay (`replayed: true`, original transaction), nothing is duplicated.

### `classify-transactions`
Bulk classification edits; never touches amounts, dates, currencies, descriptions, asset accounts or types (any other argument is rejected).

- Targets, exactly one of: `ids?: int[]` (transaction group ids, max 200) or `query?: string` (search syntax) with `limit?: int` (default 50, max 200; more matches is an error)
- Changes (at least one): `category_id?` / `category_name?` / `clear_category?`, `budget_id?` / `budget_name?` (exact) / `clear_budget?`, `bill_id?` / `bill_name?` (exact) / `clear_bill?`, `tags?` (replace) / `add_tags?` / `remove_tags?`, `notes?` (replace, `""` clears) or `append_notes?`, `destination_name?` (expense account of withdrawals), `source_name?` (revenue account of deposits)
- `dry_run?: bool` (default `true`) — preview; `false` applies, one DB transaction per group
- `if_unmodified_since?: { "<id>": "<updated_at>" }` — per-target conflict check
- `create_missing?: bool` — see above; the dry run lists would-be creations in `meta.would_create`
- Response: `{ data: { dry_run, matched, would_change | changed, unchanged?, conflict?, error?, not_found?, rows: [{ id, status, before?, after?, message? }] }, meta? }`; split groups carry `splits: [{ journal_id, before, after }]`. `before` / `after` hold only the fields that change. Budget, bill and destination apply to withdrawals only, source to deposits only (other rows get `status: error`).

### `delete-account`
- `account_id: int` (required)
- `move_to_account_id?: int` — move its transactions there first (merge)
- `delete_transactions?: bool` — required (`true`) to delete an account that still has transactions without a move target; otherwise the call errors with the transaction count

Every transaction write tool echoes the compact row (`compact: false` / `verbose: true` for the full shapes).

## Rule tools

Trigger `type` is a search operator (see `firefly-iii://help/search`); prefix it with `-` or pass `prohibited: true` to negate. Action `type` is one of Firefly's rule actions (`set_category`, `add_tag`, `set_source_account`, …; an unknown type lists the allowed ones). Values are validated like the REST API (numbers for `amount_*`, existing budget/bill names, …).

### `create-rule`
- `title: string` (required, unique), `rule_group_id?: int` or `rule_group_title?: string` (one required, must exist)
- `triggers: [{ type, value?, prohibited?, active?, stop_processing? }]`, `actions: [{ type, value?, active?, stop_processing? }]` — at least one active each
- `strict?: bool` (default false = any trigger), `trigger?: enum` ∈ `{store-journal, update-journal, manual-activation}` (default store-journal), `active?`, `stop_processing?`, `description?`, `order?`

### `update-rule`
- `rule_id: int` (required); only passed fields change; `triggers` / `actions` replace the whole set

### `delete-rule`
- `rule_id: int` (required)

### `create-rule-group`
- `title: string` (required, unique), `description?`, `active?` (default true), `order?` (default last)

### `update-rule-group`
- `rule_group_id: int` (required), any of `title?`, `description?`, `order?`, `active?`

### `apply-rule`
- `rule_id?: int` or `rule_group_id?: int` — one required
- Scope required: `start?`, `end?`, `transaction_ids?: int[]`, `account_ids?: int[]`
- Returns `{ rule_ids, affected }` — `affected` = transactions changed. Group rules fire one by one; `stop_processing` does not stop the group (as in the REST API).

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

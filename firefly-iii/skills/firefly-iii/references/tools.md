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

### `match-transactions`
Matches rows the caller already parsed (no file parsing, no provider logic) against stored transactions. Read-only.
- `rows: array` (1-200, required) — each `{ ref?, date, amount, direction?: in|out, currency?, account?, description?, external_id? }`. `amount` is signed on the account (negative = money out); `direction` signs an unsigned amount. `account` (id or exact name) overrides the scope for that row. `description` only breaks ties.
- `account?: string`, `accounts?: string[]` — scope (ids or names); default every asset account
- `date_window_days?: int` — default 0 (same day), max 90
- `amount_tolerance?: decimal` — default 0
- `types?: string[]` — default `withdrawal, deposit, transfer`
- `reference_field?: external_id|internal_reference` — compared with the row's `external_id` (default `external_id`)
- Plus the transaction output flags (`fields`, `description_max`, `compact`, `verbose`, `include_nulls`)
- A row with a known reference matches on it (any date or amount). Otherwise it matches the account leg with the same signed amount (±tolerance) inside the window; with `currency`, the leg's currency is compared on `amount` and its foreign currency on `foreign_amount`.
- One-to-one: a transaction is claimed by at most one row. A row is `matched` only when its best candidate (closest date, then smallest amount difference, then description) is unique and no other row ranks that candidate as well; ties are `ambiguous`.
- Response: `{ data: [{ ref, status, basis?, journal_id?, transaction?, candidates?: [{ basis, transaction }], candidate_count? }], meta: { primary_currency, matched, ambiguous, unmatched } }`. `basis` is `{ by: external_id }` or `{ by: amount, account_id, date_distance_days, amount_difference?, amount_field?, description_match? }`; candidates are capped at 5 (`candidate_count` gives the total when more).

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

### `convert-transaction`
- `transaction_id: int` (required), `type: enum` ∈ `{withdrawal, deposit, transfer}` (required), plus `source_id?` / `source_name?` and `destination_id?` / `destination_name?` for the accounts the new type needs; single-split transactions only.

### `bulk-create-transactions`
- `transactions: array<TransactionSpec>` (max 100 items, required)
  - Each item: `type: enum` ∈ `{withdrawal, deposit, transfer}` + the full create-* spec
  - Each item may carry its own `idempotency_key` and `external_id`
- `dry_run?: bool` — default false. Validates and returns the plan without writing: `{ data: { dry_run: true, would_create, would_replay, would_skip, total, items: [{ index, action: create|replay|skip, transaction_id?, reason?, transaction_ids?, duplicate? }] } }`
- `skip_duplicates?: bool` — default false. Leaves out items that already exist; the response adds `skipped` and `skipped_items: [{ index, reason, transaction_ids }]`
- `duplicate_check?: external_id|amount_date_account` — default `external_id` (same external id). `amount_date_account` also flags an existing transaction with the same signed amount on the item's asset account (source for withdrawals/transfers, destination for deposits) within `date_window_days` (default 0); ties are reported as `possible_duplicate` and skipped too
- All-or-nothing inside a single DB transaction. Any validation failure aborts; zero inserts.
- Response: `{ data: { created, replayed, total, transactions: [{ index, replayed, transaction }] }, meta: { primary_currency } }` with a compact row per item; `verbose: true` returns `[{ index, replayed, document }]` with the raw JSON:API document per item. Without the import options the call behaves exactly as before.

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

### `delete-category`
- `category_id: int` (required); its transactions keep existing, without a category.

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

## Area tools (`<area>-read` / `<area>-write`)

One read and one write tool per remaining Firefly area. They run on Firefly's own REST API v1 (same validation, ownership checks and business rules), so the `data` object of a write takes the API's body fields.

Shared arguments:
- `action` (required): read `list` | `get` (plus the extra actions below); write `create` | `update` | `delete` (plus extras).
- `id`: the object for `get` / `update` / `delete`.
- Read tools: `page`, `limit`, `fields` (row keys to keep: any attribute), `compact` (default `true`; `false` = every attribute), `verbose`, `include_nulls`. Compact rows trim amounts (`"12.5"`) and day-boundary timestamps (`"2026-01-31"`). Transaction sub-lists use the compact transaction rows.
- Write tools: `data` (API body fields), `confirm`, plus the output flags; create/update echo the compact row.
- **Delete needs `confirm: true` when the object still has dependants** (transactions, limits, events, links, …): without it the tool returns `{"error":"invalid_argument","field":"confirm","dependants":{..}}` and deletes nothing.
- API validation errors come back as `{"error":"invalid_argument","field":"data.<api field>","message":..,"errors":{..all API errors..}}`.

| Tool | Extra arguments | Compact row |
|---|---|---|
| `budgets-read` | `kind` = `budget` (default) \| `limit` \| `available`; `budget_id` (limits of one budget; required to get a limit); `start`/`end` (spent per period; required for all-limits list) | budget `{id, name, active, auto_budget_*, currency_code, spent}`; limit `{id, budget_id, start, end, period, amount, currency_code, spent}`; available `{id, start, end, amount, currency_code, spent_in_budgets, spent_outside_budgets}` |
| `budgets-write` | `kind` = `budget` \| `limit`; `budget_id` for limits | as read |
| `bills-read` | `action` also `transactions`; `start`/`end` add `paid_dates` / `pay_dates` for the period | `{id, name, amount_min, amount_max, currency_code, repeat_freq, skip, active, object_group_title, next_expected_match, paid_dates, pay_dates}` |
| `bills-write` | — (delete keeps the transactions, unlinked) | as read |
| `piggy-banks-read` | `action` also `events` | `{id, name, target_amount, current_amount, left_to_save, percentage, currency_code, start_date, target_date, active, object_group_title, accounts}` |
| `piggy-banks-write` | `action` also `add` \| `remove` with `account_id` + `amount` (same checks as the web form: free balance and target for add, saved amount for remove) | as read |
| `recurrences-read` | `action` also `transactions` (`start`/`end`) | `{id, type, title, first_date, latest_date, repeat_until, nr_of_repetitions, active, repetitions (with next occurrences), transactions}` |
| `recurrences-write` | `action` also `trigger` with `date` (creates that occurrence now); updates must resend `transactions` | as read |
| `currencies-read` | `kind` = `currency` (default, `id` = code or `primary`) \| `rate` (`id` = rate id; list one pair with `from`/`to` + `start`/`end`) | currency `{id, code, name, symbol, decimal_places, enabled, primary}`; rate `{id, from_currency_code, to_currency_code, rate, date}` |
| `currencies-write` | currency (`id` = code): `create` / `delete` (instance owner only), `update`, `enable`, `disable`, `primary`; rate: `create` (`from`, `to`, `rate`, `date`), `update`, `delete` | as read |
| `attachments-read` | — (metadata only) | `{id, attachable_type, attachable_id, filename, title, mime, size}` |
| `attachments-write` | create: `content_base64` (max 512 KiB) + `data.filename`, `attachable_type` (`TransactionJournal`, `Account`, `Bill`, `Budget`, `Category`, `PiggyBank`, `Tag`), `attachable_id`; update: `filename`, `title`, `notes` | as read |
| `links-read` | `kind` = `link` (default; `journal_id` lists one journal's links) \| `type` | link `{id, link_type_id, inward_id, outward_id, notes}`; type `{id, name, inward, outward, editable}` |
| `links-write` | link: `link_type_id` or `link_type_name`, `inward_id`, `outward_id` (journal ids), `notes`; `kind: type` (owner only): `name`, `inward`, `outward` | as read |
| `object-groups-read` | `action` also `piggy-banks` \| `bills` | `{id, title, order}` |
| `object-groups-write` | `update` (`title`, `order`) \| `delete` only — groups are created via `object_group_title` on a piggy bank or bill | as read |
| `tags-write` | `id` = tag id or name; `merge` with `into` (moves A's transactions to B, then deletes A) | `{id, tag, date, description}` |
| `categories-write` | `create` \| `update` \| `merge` (`into`); delete stays `delete-category` | `{id, name, notes}` |
| `accounts-write` | `create` \| `update` \| `merge` (`into`, same account type; Firefly's delete-with-move); delete stays `delete-account` | `{id, name, type, account_role, currency_code, current_balance, iban, active}` |
| `webhooks-write` | needs `allow_webhooks`; updates must resend `triggers`, `responses`, `deliveries` | `{id, title, active, url, triggers, responses, deliveries}` (no secret; `verbose` has it) |

### `export-transactions`
- `start?`, `end?` (default the last year), `accounts?: int[]` (asset accounts, default all), `columns?: string[]` (keep these CSV columns, in this order), `max_bytes?: int` (default 100000, max 1000000)
- Returns Firefly's own CSV export as text; when cut, the last line is `# truncated: N of M rows shown …`.

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

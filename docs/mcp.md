# MCP endpoint

Firefly III exposes a [Model Context Protocol](https://modelcontextprotocol.io/) endpoint so AI agents can read and write your finance data. The endpoint reuses the existing REST API auth (Laravel Passport Personal Access Tokens), Fractal transformers, and repository scoping — no separate auth surface, no schema drift.

## Setup

1. Generate a [Personal Access Token](https://docs.firefly-iii.org/how-to/firefly-iii/features/api/) in **Options → Profile → OAuth → Personal Access Tokens**.
2. Point your MCP client at `https://<your-firefly>/api/v1/mcp` with header `Authorization: Bearer <token>` and `Accept: application/json, text/event-stream`.
3. The endpoint speaks MCP protocol version `2025-06-18`.

## Feature flag

The endpoint is gated by `allow_mcp` (default `true`). To disable it instance-wide, set the flag to `false` via the admin UI or directly in `firefly_configurations`. When disabled the route returns `404`.

## Auth

Bearer Personal Access Tokens, identical to the REST API. There is no `mcp:use` OAuth scope and no MCP-specific OAuth flow — the existing PAT model applies. Tools are scoped to the token's owner; cross-user access is impossible.

## Tools

All tools return JSON envelopes reduced from the canonical JSON:API shape (`id` + flattened attributes, relationships hoisted as `*_id`, pagination preserved). Pass `verbose: true` on any read tool to receive the raw JSON:API document; pass `include_nulls: true` to keep null-valued attributes.

### Read — core entities

- `list-accounts` — paginated accounts, optional `type` (asset / expense / revenue / liability) and `active` filters.
- `get-account` — single account by `id` or `name`.
- `list-transactions` — paginated transactions; filter by `start`, `end`, `account_id` / `account_name`, `type`.
- `get-transaction` — single transaction group by `id`.
- `list-budgets`, `get-budget` — budgets, by id or name.
- `list-categories`, `get-category` — categories, by id or name.
- `list-tags`, `get-tag` — tags, by id or tag string.

### Read — search

- `search-transactions` — free-text query against the same search backend as `/api/v1/search`. Optional `start` / `end` are folded into the query string.

### Read — power-user

- `list-bills` — recurring bills.
- `list-rules` — transaction rules.
- `list-piggy-banks` — savings targets.
- `list-webhooks` — configured webhooks (honors the `allow_webhooks` instance flag).

### Read — aggregates

- `summary-basic` — dashboard summary blob (income / expense / balance, defaults to current month if `start`/`end` omitted).
- `insight-expense`, `insight-income` — period totals with optional `account_ids` filter.
- `chart-data` — `chart_type ∈ { account_balances, category_expenses, budget_vs_actual }` with required `start` / `end`.

### Write

Every create-style write tool (`create-withdrawal`, `create-deposit`, `create-transfer`, plus per-item on `bulk-create-transactions`) accepts an optional `idempotency_key: string` (printable ASCII, ≤128 chars). When supplied, a repeat call with the same key returns the original transaction id without creating a duplicate, scoped to the calling user. `update-transaction` does *not* accept a key — it is naturally idempotent via address-by-id (re-applying the same partial update lands the same end state), which is what the `IsIdempotent` annotation communicates to clients. `delete-transaction` is intentionally non-idempotent: a second call on the same id returns not-found. Currency is inherited from the source asset account — no `currency_code` argument.

- `create-withdrawal` — source must be asset, destination must be expense. Validation reuses `StoreTransactionRequest::rules()`.
- `create-deposit` — source must be revenue, destination must be asset.
- `create-transfer` — both source and destination must be asset. Cross-currency transfers are rejected with a clear error (see *Known limitations*).
- `update-transaction` — partial update by transaction `id`. Marked `IsDestructive` + `IsIdempotent`. Naturally idempotent via address-by-id, no `idempotency_key` argument.
- `delete-transaction` — by transaction `id`. Marked `IsDestructive`; a second call on the same id returns a not-found error (not idempotent).
- `bulk-create-transactions` — up to 100 items per call, all-or-nothing inside a DB transaction. Per-item idempotency keys supported.

Every write logs a single line to the `audit` channel: `{ tool, user_id, transaction_id, idempotent_replay }`.

## Resources

Resources have no parameters and return the same reduced envelope as tools.

### Static catalogs

- `firefly-iii://catalogs/currencies` — enabled currencies.
- `firefly-iii://catalogs/account-types` — all account types.
- `firefly-iii://catalogs/transaction-types` — all transaction types.
- `firefly-iii://catalogs/link-types` — link-types catalog.

### User snapshots (scoped to the authenticated user)

- `firefly-iii://user/accounts` — active chart of accounts.
- `firefly-iii://user/tags` — tag library.
- `firefly-iii://user/categories` — categories.
- `firefly-iii://user/budgets` — active budgets.

## Known MVP limitations

- **Cross-currency transfers are not supported.** `create-transfer` rejects when source and destination accounts have different currencies. Use the REST API for cross-currency transfers.
- **English-only.** Tool descriptions, schema field descriptions, and error messages are hard-coded English (no `resources/lang/en_US/mcp.php` yet).
- **No streaming, no MCP prompts, no admin tools.** Every tool returns a single synchronous response. Admin-style operations (user management, system health) are intentionally out of scope.
- **No OAuth discovery / dynamic client registration.** Some MCP clients expect a full OAuth dance; configure them with a Bearer header instead.
- **No rate limiting on the MCP route.** The route inherits the same posture as the REST API.

## Verifying

```bash
curl -sS https://<your-firefly>/api/v1/mcp \
  -H "Authorization: Bearer $FIREFLY_PAT" \
  -H "Accept: application/json, text/event-stream" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}'
```

A successful handshake returns `result.serverInfo.name = "Firefly III MCP"`.

# MCP endpoint

Firefly III exposes a [Model Context Protocol](https://modelcontextprotocol.io/) endpoint so AI agents can read and write your finance data. The endpoint reuses the existing REST API stack — Fractal transformers, repository scoping, Passport-backed auth — wrapped in MCP-spec OAuth so any compliant client can connect with no manual token handling.

## Setup

1. Point your MCP-aware AI assistant (Claude Code, Claude Desktop, claude.ai web custom connector, or any other MCP client that supports OAuth) at `https://<your-firefly>/api/v1/mcp`.
2. On first connect, the client follows the discovery chain: the 401 response carries an `WWW-Authenticate` header pointing at `/.well-known/oauth-protected-resource`, which points at `/.well-known/oauth-authorization-server`. The client then registers itself dynamically (RFC 7591) at `/oauth/register`, redirects you to Firefly's consent screen for the `mcp:use` scope, and stores the resulting access token.
3. The endpoint speaks MCP protocol version `2025-06-18`.

No PATs, no header configuration, no service tokens. The same flow works across Claude Code (loopback redirect URI), Claude Desktop (loopback redirect URI), and claude.ai web (`https://claude.ai/...` redirect URI). The list of accepted redirect-URI hosts is configurable via `config/mcp.php` → `redirect_domains` (defaults to claude.ai + loopback).

## Feature flag

The endpoint is gated by `allow_mcp` (default `true`). To disable it instance-wide, set the flag to `false` via the admin UI or directly in `firefly_configurations`. When disabled the route returns `404`. The flag does not affect the OAuth discovery endpoints — disabling MCP only closes `/api/v1/mcp`, not the wider OAuth surface (which Firefly serves anyway via Laravel Passport).

## Auth

MCP-spec OAuth 2.0 with PKCE, backed by Laravel Passport. The server exposes:

- `/.well-known/oauth-protected-resource` (RFC 9728)
- `/.well-known/oauth-authorization-server` (RFC 8414)
- `/oauth/register` (RFC 7591 Dynamic Client Registration)
- `/oauth/authorize` + `/oauth/token` (standard Passport endpoints)

A single scope, `mcp:use`, gates the MCP route. Tools are scoped to the token's owner; cross-user access is impossible. PAT bearers issued through the existing Firefly OAuth UI continue to work at the protocol layer — they carry the user's full scope set, including `mcp:use` — but the documented and plugin-shipped client path is OAuth.

### Cloudflare Access / reverse-proxy notes

If your Firefly III host sits behind Cloudflare Zero Trust or a similar reverse-proxy auth layer, the following paths must be reachable from the AI assistant vendor's egress IPs (Anthropic for Claude clients):

- `/oauth/authorize` (browser-facing, but the redirect from claude.ai needs to land here)
- `/oauth/token` (server-to-server token exchange)
- `/oauth/register` (server-to-server DCR)
- `/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`
- `/api/v1/mcp`

The simplest approach is to drop the reverse-proxy auth layer on `firefly.<your-domain>` entirely — Firefly already enforces its own auth on every endpoint. If you keep CF Access (or equivalent), pin a bypass policy to the vendor's published egress IP ranges.

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
- **No rate limiting on the MCP route.** The route inherits the same posture as the REST API.
- **RFC 8707 audience binding not enforced.** Since the authorization server and resource server are the same host in a typical Firefly deployment, audience confusion is not exploitable. Tracked as a follow-up if Firefly ever federates auth across multiple resource servers.

## Verifying

Discovery endpoints (no token needed):

```bash
curl -sS https://<your-firefly>/.well-known/oauth-protected-resource
curl -sS https://<your-firefly>/.well-known/oauth-authorization-server
```

Both return JSON metadata listing Firefly's authorization endpoint, token endpoint, registration endpoint, and the supported scopes (`mcp:use`).

MCP handshake with an existing access token (a PAT for one-off CLI testing, or an OAuth-issued token from the assistant's keychain):

```bash
curl -sS https://<your-firefly>/api/v1/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json, text/event-stream" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}'
```

A successful handshake returns `result.serverInfo.name = "Firefly III MCP"`.

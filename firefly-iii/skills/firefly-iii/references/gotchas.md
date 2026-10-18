# Gotchas

Edge cases and known quirks. Skim once; come back when something behaves unexpectedly.

## Currency

- **Currency is inherited from the source account on every create-*.** There is no `currency_code` arg. The server uses the asset (or revenue) account's currency. This is intentional — it removes a category of "I accidentally created a USD txn on my EUR account" mistakes.
- **Cross-currency transfers are explicitly rejected.** `create-transfer` errors when source and destination accounts have different currencies. The MCP MVP doesn't support the exchange-rate side of the conversation; cross-currency belongs in the Firefly web UI.
- **Aggregate tools emit per-row currency.** `insight-expense`, `insight-income`, and `chart-data` rows each carry their own `currency_code`. Don't sum across currencies blindly — group by currency first or surface the multi-currency case to the user.

## Dates

- **All date args are ISO 8601 (`YYYY-MM-DD`).** No timestamps, no timezones. Convert natural-language inputs ("last Tuesday", "May 17", "yesterday") before calling.
- **`start` and `end` are inclusive.** A range covering one day = `start=2026-05-17 end=2026-05-17`.
- **`search-transactions` injects date operators automatically** when you pass `start`/`end` AND those operators aren't already in the query string. Don't pass them both ways.

## Idempotency

- The key is opt-in. **Always provide one on retries** of a failed `create-*` call. Without it, retries duplicate.
- Keys are scoped per-user. Reusing your key under a different user doesn't collide (Firefly never leaks cross-user data).
- Keys are stored as `mcp_idempotency_key` journal meta — they survive across MCP sessions and across server restarts.
- Max length 128 chars, must be printable ASCII. Server rejects malformed keys.
- Replay returns the original transaction's reduced envelope; the response carries `idempotent_replay: true` so you know it was a replay vs a fresh create.
- **After a timeout on `bulk-create-transactions`**, resend the identical batch with the same keys: if the first call committed, every keyed item comes back `replayed: true` with its original transaction; if it rolled back, the items are created now. Nothing is duplicated either way.
- `update-transaction` is marked `IsIdempotent` by MCP spec, but it does NOT use the idempotency-key mechanism — repeated calls just re-apply the same patch. `delete-transaction` is NOT idempotent: a second call on the same id returns "Transaction not found".

## Account roles

- **Withdrawal:** source must be `asset`, destination must be `expense`. If the destination name doesn't exist, Firefly auto-creates an expense account with that name.
- **Deposit:** source must be `revenue`, destination must be `asset`. Source auto-creation works the same way.
- **Auto-created names are reported.** Every write lists the accounts, categories and tags it created in `meta.created` (absent when nothing was created) — a typo shows up there. Pass `create_missing: false` to get a `missing_names` error instead; nothing is written. Budgets and bills are never auto-created.
- **Transfer:** both source and destination must be `asset`, **same currency**.
- Passing wrong roles surfaces a validation error from `StoreTransactionRequest::rules()`. Surface that error verbatim to the user — it's actionable.

## Concurrent edits

- `update-transaction` (`if_unmodified_since: "<updated_at>"`) and `classify-transactions` (`if_unmodified_since: {"<id>": "<updated_at>"}`) refuse to write a transaction changed after the `updated_at` you read; the error / row status is `conflict`. Get `updated_at` from any transaction read with `fields: ["updated_at"]` (not in the default row). Precision is one second.

## Deleting accounts

- `delete-account` without `move_to_account_id` on an account that has transactions errors with the transaction count unless `delete_transactions: true` is passed. Prefer `move_to_account_id` to merge duplicates.

## Pagination

- Default page size is 25, maximum 200. `limit` above 200, `page`/`limit` below 1, unknown enum values (e.g. `type=zzz`) and malformed dates are rejected with `{"error":"invalid_argument","field":..,"message":..}` — never silently coerced.
- `meta.pagination` is preserved verbatim. To page forward: when `meta.has_more` is true, refetch with `page=<meta.next_page>`.
- `search-transactions` ignores unknown or malformed operators and lists them in `meta.warnings`; check it before concluding there are no matches.
- Don't iterate every page when an aggregate tool would answer the question — see [recipes.md](recipes.md).

## Bulk-create semantics

- Hard cap: 100 items per call. Chunk larger sets.
- All-or-nothing: a single item failure rolls back the whole batch (zero inserts).
- Error response shape: `{ errors: [{ index, errors: { field: [messages] } }, ...] }` — the `index` matches the position in the input array.
- Each item can carry its own `idempotency_key`. If item 5 in a batch was successfully created in a prior partial-retry attempt (impossible under all-or-nothing, but theoretically with future server changes), its key gates the replay.

## Aggregate-tool response shape

- `summary-basic`, `insight-*`, and `chart-data` return `{ data: ..., meta: { ... } }` envelopes **directly** — NOT through the standard JsonApiReducer pipeline.
- `verbose: true` has no effect on aggregate tools (no JSON:API to fall back to); `include_nulls: true` keeps null values.
- Don't expect `meta.pagination` on aggregates — they're not paginated.

## Feature flags

- `allow_mcp` (instance-wide): when `false`, every MCP call returns `404`. If you start getting 404s on every tool, surface "the admin appears to have disabled MCP on this Firefly instance."
- `allow_webhooks` (instance-wide): when `false`, `list-webhooks` returns a `404`-style error. Other webhook-adjacent tools are unaffected.

## Authentication

- Bearer Personal Access Token. The plugin's `.mcp.json` injects `Authorization: Bearer ${user_config.firefly_token}`.
- Token expiry is per Firefly's own PAT lifetime (effectively non-expiring unless revoked). A `401` means the user revoked it or rotated their secret.
- Tools are scoped to the token owner. There is no admin / superuser surface in MCP MVP.

## Audit logging

Every write logs one line to Firefly's `audit` channel: `{ tool, user_id, transaction_id, idempotent_replay }`. The payload is NOT included. Forensic reconstruction needs DB access — surface this when the user asks "what did the AI do last week".

## Field that's NOT in the schema

The README in places references `currency_code` on create-* — **it is not in the schema** (see "Currency" above). Don't try to pass it.

The MVP doesn't expose:
- `create-budget`, `create-category`, `create-piggy-bank` (and their update/delete variants)
- Rule-management write tools
- User / admin operations
- OAuth client management

For any of those, the user has to go to the Firefly web UI.

## Known MVP limitations (documented in upstream `docs/mcp.md`)

- Cross-currency transfers — see "Currency" above
- English-only — tool descriptions, schema field descriptions, error messages all en_US
- No streaming, no MCP prompts, no admin tools
- No OAuth discovery / dynamic client registration — clients must use a Bearer header
- No rate limiting — inherits REST API posture

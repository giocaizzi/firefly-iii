# firefly-mcp

Claude Code plugin for operating a [Firefly III](https://firefly-iii.org/) personal-finance instance through its MCP endpoint.

## What it ships

- **MCP server registration** (`.mcp.json`) — HTTP transport, Bearer auth via `${user_config.firefly_token}`, URL via `${user_config.firefly_url}`.
- **Skill** (`skills/firefly-finance/`) — teaches Claude when to consult Firefly (any finance / money / spending / budget / balance / transaction question) and how to wield the 25-tool surface efficiently. Includes references for the full tool catalog, common workflow recipes, and known gotchas.
- **Slash commands** namespaced under `/firefly-mcp:`:
  - `/firefly-mcp:summary [period]` — one-shot financial digest
  - `/firefly-mcp:spending <period> [category]` — spending breakdown
  - `/firefly-mcp:budget [period]` — budget vs actual
  - `/firefly-mcp:new` — guided transaction creation
- **Agent** `firefly-mcp:transaction-reviewer` — batch categorization audit with deterministic idempotency keys.

## Requirements

A Firefly III instance with the MCP endpoint enabled (`allow_mcp=true`, default). The endpoint was added in Firefly v6.7.0; if you're on upstream `:latest` (v6.6.x or earlier) you'll need to either upgrade or run the `feat/mcp-integration` fork (see [giocaizzi/firefly-iii](https://github.com/giocaizzi/firefly-iii)).

## Install

```
/plugin marketplace add giocaizzi/firefly-iii
/plugin install firefly-mcp@giocaizzi-firefly-plugins
```

(The marketplace lives in the [`feat/mcp-integration`](https://github.com/giocaizzi/firefly-iii/tree/feat/mcp-integration) branch of the fork. If `/plugin marketplace add` doesn't pick it up from `main`, point it at the branch explicitly.)

You'll be prompted for two values:

| Field | Purpose | Example |
|---|---|---|
| **Firefly III base URL** | Base of your Firefly host. Plugin calls `<url>/api/v1/mcp`. | `https://firefly.example.com` |
| **Personal Access Token** | Created in Firefly → Options → Profile → OAuth → Personal Access Tokens. Stored in your OS keychain (`sensitive: true`). | (long opaque string) |

That's it. Restart Claude Code or open a new session — the MCP server registers automatically and the skill triggers on any finance question.

## Verify

```
curl -sS "$FIREFLY_URL/api/v1/mcp" \
  -H "Authorization: Bearer $FIREFLY_PAT" \
  -H "Accept: application/json, text/event-stream" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}'
```

Successful handshake → `result.serverInfo.name = "Firefly III MCP"`.

## License

MIT.

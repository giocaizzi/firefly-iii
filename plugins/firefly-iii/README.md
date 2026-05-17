# firefly-iii

Claude Code plugin for operating a [Firefly III](https://firefly-iii.org/) personal-finance instance. Covers the full agentic surface: read entities, run insight aggregates, create/update transactions safely, and audit categorization in batch.

## What it ships

- **MCP server registration** (`.mcp.json`) — HTTP transport, Bearer auth via `${user_config.firefly_token}`, URL via `${user_config.firefly_url}`.
- **Skill** `firefly` (`skills/firefly/`) — teaches Claude when to consult Firefly (any finance / money / spending / budget / balance / transaction question) and how to wield the 25-tool surface efficiently. Includes references for the full tool catalog, common workflow recipes, and known gotchas.
- **Slash commands** namespaced under `/firefly-iii:`:
  - `/firefly-iii:summary [period]` — one-shot financial digest
  - `/firefly-iii:spending <period> [category]` — spending breakdown
  - `/firefly-iii:budget [period]` — budget vs actual
  - `/firefly-iii:new` — guided transaction creation
- **Agent** `firefly-iii:transaction-reviewer` — batch categorization audit with deterministic idempotency keys.

## Requirements

A Firefly III instance with the MCP endpoint enabled (`allow_mcp=true`, default). The endpoint was added in Firefly v6.7.0; if you're on upstream `:latest` (v6.6.x or earlier) you'll need to either upgrade or run the `feat/mcp-integration` fork (see [giocaizzi/firefly-iii](https://github.com/giocaizzi/firefly-iii)).

## Install

```
/plugin marketplace add giocaizzi/firefly-iii
/plugin install firefly-iii@firefly-iii
```

(The marketplace lives in the [`feat/mcp-integration`](https://github.com/giocaizzi/firefly-iii/tree/feat/mcp-integration) branch of the fork. If `/plugin marketplace add` doesn't pick it up from `main`, point it at the branch explicitly.)

Before launching Claude Code, export the connection variables in your shell (typically in `~/.zshrc` or `~/.bashrc`):

```sh
# Firefly III instance
export FIREFLY_URL="https://firefly.example.com"   # base URL, no trailing slash
export FIREFLY_PAT="<your-personal-access-token>"  # from Firefly → Options → Profile → OAuth

# Cloudflare Access service-token credentials (only required if your Firefly host
# sits behind Cloudflare Zero Trust; otherwise leave unset). The FIREFLY_ prefix
# scopes the values to this service so other plugins (e.g. n8n) can use their
# own service tokens via N8N_CF_ACCESS_* etc.
export FIREFLY_CF_ACCESS_CLIENT_ID="<service-token-client-id>"
export FIREFLY_CF_ACCESS_CLIENT_SECRET="<service-token-client-secret>"
```

The plugin's `.mcp.json` references these four variables directly, so Claude Code reads them from your environment at MCP-server boot. No keychain prompt, no per-install state.

Restart Claude Code or open a new session — the MCP server registers automatically and the skill triggers on any finance question.

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

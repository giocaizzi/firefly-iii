# firefly-iii

Plugin for operating a [Firefly III](https://firefly-iii.org/) personal-finance instance from an AI assistant. Covers the full agentic surface: read entities, run insight aggregates, create/update transactions safely, and audit categorization in batch.

## What it ships

- **MCP server registration** (`.mcp.json`) — HTTP transport with MCP-spec OAuth (RFC 8414 / RFC 9728 / RFC 7591). The assistant's MCP client discovers the auth server and dynamically registers itself on first connect; you consent once in your Firefly III browser session and never touch a token by hand.
- **Skill** `firefly-iii` (`skills/firefly-iii/`) — teaches the assistant when to consult Firefly (any finance / money / spending / budget / balance / transaction question) and how to wield the 25-tool surface efficiently. Includes references for the full tool catalog, common workflow recipes, and known gotchas.
- **Slash commands** namespaced under `/firefly-iii:`:
  - `/firefly-iii:summary [period]` — one-shot financial digest
  - `/firefly-iii:spending <period> [category]` — spending breakdown
  - `/firefly-iii:budget [period]` — budget vs actual
  - `/firefly-iii:new` — guided transaction creation
- **Agent** `firefly-iii:transaction-reviewer` — batch categorization audit with deterministic idempotency keys.

## Requirements

A Firefly III instance with the MCP endpoint enabled (`allow_mcp=true`, default) and the OAuth discovery endpoints reachable. Both ship with Firefly III v6.7.0+; if you're on v6.6.x or earlier, upgrade to pick them up.

An MCP-aware AI assistant capable of loading plugin bundles (skills, commands, agents, `.mcp.json`) and following the MCP OAuth flow on first connect. Claude Code, Claude Desktop, and claude.ai (Pro/Max/Team/Enterprise custom connector) all qualify.

## Install

The bundle uses the open Claude Code plugin marketplace layout (`.claude-plugin/marketplace.json` + `firefly-iii/`). Install commands depend on your assistant client. Example (a client supporting `/plugin marketplace`):

```
/plugin marketplace add firefly-iii/firefly-iii
/plugin install firefly-iii@firefly-iii
```

Before launching the assistant, export the instance URL in your shell (typically in `~/.zshrc` or `~/.bashrc`):

```sh
# Firefly III instance base URL — no trailing slash.
export FIREFLY_URL="https://firefly.example.com"
```

That is the only environment variable the plugin reads. Auth is handled by the MCP-spec OAuth flow:

1. First call to the MCP endpoint returns 401 + `WWW-Authenticate: Bearer realm="mcp", resource_metadata="..."`.
2. The assistant's MCP client fetches `${FIREFLY_URL}/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server` to discover Firefly's auth endpoints.
3. The client dynamically registers itself at `/oauth/register` (RFC 7591) and gets a public PKCE client id.
4. The client redirects you to Firefly's `/oauth/authorize` consent screen — you log in (if not already) and approve the `mcp:use` scope.
5. The client receives an access token via the authorization-code grant and retries the original MCP call.

No PATs, no service tokens, no headers to manage. The same flow works across Claude Code (loopback redirect), Claude Desktop (loopback redirect), and claude.ai web (claude.ai redirect URI).

> **Cloudflare Access note.** If your Firefly III host is behind Cloudflare Zero Trust, the OAuth surface paths (`/oauth/authorize`, `/oauth/token`, `/oauth/register`, `/.well-known/oauth-*`, `/api/v1/mcp`) must be reachable from Anthropic's egress IP ranges. Either remove CF Access for those paths or scope the access policy to an Anthropic IP allowlist.

Restart the assistant session — the MCP server registers automatically and the skill triggers on any finance question.

## Verify

After the OAuth handshake completes once, you can probe the endpoint with a curl using a token from the assistant's keychain (or via Firefly's web UI: Options → Profile → OAuth → Personal Access Tokens, for ad-hoc CLI testing):

```
curl -sS "$FIREFLY_URL/api/v1/mcp" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json, text/event-stream" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}'
```

Successful handshake → `result.serverInfo.name = "Firefly III MCP"`.

To verify the OAuth discovery endpoints themselves (no token needed):

```
curl -sS "$FIREFLY_URL/.well-known/oauth-protected-resource"
curl -sS "$FIREFLY_URL/.well-known/oauth-authorization-server"
```

Both return JSON metadata that lists Firefly's authorization endpoint, token endpoint, and the `mcp:use` scope.

## License

MIT.

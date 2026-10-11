> ## ⚠️ This is a fork
>
> This is a fork of [firefly-iii/firefly-iii](https://github.com/firefly-iii/firefly-iii)
> that adds a **Model Context Protocol (MCP) server** — and a companion client plugin — so an
> MCP-capable AI agent can read and write your Firefly III data. See
> [MCP endpoint for AI agents](#mcp-endpoint-for-ai-agents) below for what it does.
>
> Upstream isn't merging this work, so the fork **tracks upstream automatically, on a
> best-effort basis**: every new Firefly III release is pulled in, the MCP test suite has to
> pass, and a ready-to-run Docker image is published to
> `ghcr.io/giocaizzi/firefly-iii:latest`. If a release ever breaks the MCP feature the update
> is held back until it's fixed, so what's published stays working.
>
> This file lives in `.github/` so the fork never edits upstream's `readme.md` (which would
> conflict on every release). The original Firefly III readme is at [readme.md](../readme.md).

## MCP endpoint for AI agents

Firefly III exposes a [Model Context Protocol](https://modelcontextprotocol.io/) endpoint at `/api/v1/mcp`, letting AI agents read and write your data using the same Personal Access Tokens that authenticate the REST API. The endpoint ships 25 tools (read, search, aggregate, and write) and 8 resources (static catalogs and user snapshots), gated by the `allow_mcp` feature flag (default ON). See the [client plugin README](../firefly-iii/README.md) for setup, the full tool list, and known MVP limitations.

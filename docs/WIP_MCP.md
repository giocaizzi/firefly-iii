# WIP — MCP Integration for Firefly III

Working notes for an MCP (Model Context Protocol) endpoint added to a fork of Firefly III, intended to be upstream-compatible.

**Owner:** giocaizzi · **Started:** 2026-05-17 · **Upstream commit at fork:** `e83c5b9f86`

Decisions in this document are made by the human, not the assistant. Each entry records the choice, the rationale provided, and discarded alternatives so we can reverse course without re-discovering context.

---

## 0 — Ground truth references

Source material this WIP doc is built on (research was run on 2026-05-17, summaries embedded inline below):

- Firefly III repo: `/Users/giorgiocaizzi/Documents/github/firefly-iii`
- Firefly III contribution rules: `.github/pull_request_template.md`, `agents.md`, `releases.md`, `.github/security.md`, `.github/code_of_conduct.md`, `changelog.md`, `composer.json`
- Firefly III code standards: `.ci/php-cs-fixer/.php-cs-fixer.php` (PSR-12 + risky, `declare_strict_types=true`), `.ci/phpstan.neon` (level 6), `mago.toml` (PHP 8.5 target, 160 cols, 4-space indent), `phpunit.xml`
- Firefly III architecture: `app/Api/V1/Controllers/...`, `app/Api/V1/Requests/...`, `app/Repositories/...`, `app/Transformers/...`, `app/Providers/FireflyServiceProvider.php`, `routes/api.php`, `bootstrap/app.php`
- Official Laravel MCP package: `laravel/mcp` v0.7.0 — https://laravel.com/docs/13.x/mcp, https://github.com/laravel/mcp
- MCP spec: https://modelcontextprotocol.io/specification/2025-06-18

---

## 1 — Hard constraints inherited from upstream

These are not decisions — they are rules we must follow if the goal is upstream merge eligibility.

| # | Constraint | Source |
|---|---|---|
| C1 | Every PHP file: AGPL-3.0-or-later header + `declare(strict_types=1);` immediately after `<?php` | `app/User.php` lines 3–23, `.ci/php-cs-fixer/.php-cs-fixer.php` line 51 |
| C2 | Code style: PSR-12 + risky rules; Mago formatter (PHP 8.5 target, 160 cols, 4 spaces, no trailing commas) | `.ci/php-cs-fixer/.php-cs-fixer.php`, `mago.toml` |
| C3 | PHPStan level 6 must pass | `.ci/phpstan.neon:67` |
| C4 | Tests in `tests/unit/`, `tests/integration/`, or `tests/feature/`; PHPUnit 13; `failOnRisky=true`, `failOnWarning=true` | `phpunit.xml` |
| C5 | Root namespace is `FireflyIII\` — *not* `App\` | `composer.json` lines 142–147 |
| C6 | Eloquent models live in `app/Models/`, controllers in `app/Api/V1/Controllers/...`, requests in `app/Api/V1/Requests/...`, repositories in `app/Repositories/...`, transformers in `app/Transformers/` | observed convention |
| C7 | Auth on API: Laravel Passport, middleware `auth:api`, Personal Access Tokens | `bootstrap/app.php` line 109–116, `config/passport.php` |
| C8 | API responses: JSON:API via League Fractal `JsonApiSerializer`, content type `application/vnd.api+json` | `app/Api/V1/Controllers/Controller.php` |
| C9 | Repositories scope to current user via `setUser(auth()->user())` or `UserGroupTrait` | `app/Repositories/Webhook/WebhookRepository.php` |
| C10 | Feature toggles: `FireflyConfig::get('allow_X', config('firefly.allow_X'))->data`, fallback to `NotFoundHttpException('… not enabled.')` | `app/Api/V1/Controllers/Webhook/StoreController.php:66-70` |
| C11 | Required PHP ≥ 8.5; Laravel 13 | `composer.json:68`, `composer.json:90` |
| C12 | Audit logging via `Log::channel('audit')->info(...)` for security-relevant events | `app/Api/V1/Controllers/Webhook/StoreController.php:83` |
| C13 | PR rules: target `develop` branch, reference an issue, AI disclosure form, pre-discuss any PR > 25 lines with @JC5 | `.github/pull_request_template.md` |
| C14 | AI-assisted commits include `Assisted-by: <model> via <tool>` footer; AI-agent PRs/issues may add 🍌🍌🍌 for expedited processing | `agents.md` |

---

## 2 — Decision log

Format: ID · Status · Decision · Why · Alternatives considered · Date.

### D-001 · DECIDED · Upstream discipline: strict from day 1, no upstream contact yet
- **Decision:** Build to upstream PR-ready standards from the first commit (C1–C12 above), but defer opening the issue/discussion with @JC5 until the implementation is far enough along to demo.
- **Why (user):** Strict from day 1 but without official communication and interaction with source yet.
- **Discarded:** "WIP now, polish before PR" (would force a refactor pass); "personal fork only" (loses optionality on upstreaming).
- **Implication:** Every file written from now follows C1–C12. We do not open a Firefly III issue or email `james@firefly-iii.org` yet. When we do, PR will require a referenced issue, AI disclosure, and `Assisted-by` footers.
- **Date:** 2026-05-17.

### D-002 · DECIDED · WIP doc location: `WIP_MCP.md` at repo root, visible
- **Decision:** This file lives at `WIP_MCP.md` at the repo root, not gitignored. User decides per-commit whether to stage it.
- **Why (user):** Root is easier; skip the local-ignore plumbing.
- **Discarded:** `.github/WIP_MCP.md` (less discoverable); `docs/wip/mcp.md` (new folder for one file); `.git/info/exclude` entry (silently hides from `git status`, risk of forgetting it exists).
- **Implication:** If we ever open an upstream PR, this file must NOT be in the PR diff. Add it explicitly to a pre-PR cleanup checklist.
- **Date:** 2026-05-17.

### D-003 · DECIDED · MCP namespace: `FireflyIII\Mcp\…` under `app/Mcp/`
- **Decision:** All MCP classes live under `app/Mcp/` with namespace `FireflyIII\Mcp\…` to match the project's existing autoload (C5).
- **Why (user):** Most upstream-friendly; matches existing namespace convention.
- **Discarded:** `App\Mcp\…` (would require adding a second root namespace to `composer.json` autoload, non-standard for this project); `FireflyIII\Api\Mcp\…` under `app/Api/Mcp/` (over-nests, conflates MCP with REST API namespace).
- **Implication:** `laravel/mcp`'s default `make:mcp-server`, `make:mcp-tool`, `make:mcp-resource` stub generators emit `App\Mcp\…` — we must either configure the stubs or write classes by hand. Per `laravel/mcp` docs there is no documented stub-namespace config flag, so we likely write the classes by hand or publish & edit the stubs.
- **Date:** 2026-05-17.

### D-004 · DECIDED · MVP capability surface: read-only tools + write tools (transactions) + MCP resources
- **Decision:** First iteration exposes (a) read-only tools for accounts/transactions/budgets/categories/tags, (b) write tools for transaction creation/update/delete, (c) MCP resources for relatively static data. **No MCP prompts in MVP.**
- **Why (user):** Maximum agentic capability within one well-scoped iteration.
- **Discarded:** Read-only only (too narrow for agentic finance workflows); prompts (convenience, not necessary).
- **Implication:** Write tools touch the existing `TransactionGroupFactory` / `TransactionJournalFactory`, must respect repository user-scoping (C9), must emit audit log entries (C12). Each write tool is annotated `#[IsDestructive]` (or absent `#[IsReadOnly]`) per MCP spec.
- **Date:** 2026-05-17.

### D-005 · SUPERSEDED by D-039 · Auth: existing Passport Personal Access Tokens via `auth:api`
- **Status:** Superseded on 2026-05-18 by [D-039](#d-039--decided--auth-mcp-spec-oauth-via-mcpoauthroutes--mcpuse-scope-supersedes-d-005). End-to-end testing against claude.ai (web) showed PAT-Bearer + Cloudflare Access service-token headers cannot be configured in claude.ai's custom-connector UI (OAuth-only). Sticking with PAT closes off the web client. Reversing to MCP-spec OAuth, which `laravel/mcp` v0.7.0 already supports turn-key.
- **Decision:** MCP route protected by `->middleware('auth:api')`. Clients send an existing Firefly III PAT in the `Authorization: Bearer …` header. No new OAuth client flow, no `mcp:use` scope advertisement.
- **Why (user):** Reuse what's already there; zero new auth surface.
- **Discarded:** `Mcp::oauthRoutes()` with `mcp:use` (adds OAuth discovery + dynamic-client-registration endpoints, larger upstream surface); hybrid (testing burden).
- **Implication:** Some MCP clients only support OAuth flows out-of-the-box — they will need manual Bearer-header config. Document this in user-facing docs later. Per-tool authorization continues to live in handler code (no scope granularity from OAuth layer).
- **Date:** 2026-05-17.

### D-006 · DECIDED · Route path: `/api/v1/mcp`
- **Decision:** MCP server mounts at `/api/v1/mcp`, parallel-versioned to the REST API.
- **Why (user):** Consistent with the project's existing API URL scheme.
- **Discarded:** `/mcp` (top-level, no version namespace); `/mcp/v1` (independent MCP versioning).
- **Implication:** `Mcp::web('/api/v1/mcp', FireflyServer::class)` in `routes/ai.php`. Laravel's `routes/api.php` already lives under the `/api` prefix via `withRouting(api: …)` in `bootstrap/app.php` — verify at implementation time whether `Mcp::web()` registers with an absolute path or inherits the api group prefix; adjust accordingly so the final mounted URL is exactly `/api/v1/mcp`. Future REST API version bumps (`/api/v2`) do not move the MCP route automatically — manual coordination needed.
- **Date:** 2026-05-17.

### D-007 · DECIDED · Feature flag: gated via `allow_mcp`, default ON
- **Decision:** Add `allow_mcp` to `config/firefly.php` with default `true`. Server entrypoint (or a thin middleware) checks `FireflyConfig::get('allow_mcp', config('firefly.allow_mcp'))->data`; throws `NotFoundHttpException('MCP endpoint is not enabled.')` when disabled. Matches the webhooks precedent (C10).
- **Why (user):** Admins keep a kill switch; default-on so installs that pull the new feature get it immediately.
- **Discarded:** Default OFF (slower adoption, but a more conservative upstream pitch); no flag at all (breaks Firefly precedent).
- **Implication:** Upstream maintainers may push back on default-ON because it expands attack surface for fresh installs. We may flip to default OFF before opening the upstream PR — track that as a parking-lot item.
- **Date:** 2026-05-17.

### D-008 · DECIDED · Server topology: single `FireflyServer` with all tools and resources
- **Decision:** One server class at `app/Mcp/Servers/FireflyServer.php` (namespace `FireflyIII\Mcp\Servers\FireflyServer`) declares all tools and resources. Per-user / per-capability gating happens inside each tool's `shouldRegister(Request)` rather than via separate routes.
- **Why (user):** Simplest topology; single auth boundary; matches the laravel/mcp docs' canonical pattern.
- **Discarded:** Read/Write split servers (client UX awkwardness); per-domain servers (route explosion).
- **Implication:** All tools share one route, one middleware stack. Admin-only tools (if any) must early-return an MCP error from `handle()` rather than relying on a route-level admin middleware.
- **Date:** 2026-05-17.

### D-009 · DECIDED · Response shape: reuse existing Fractal transformers (JSON:API)
- **Decision:** Every MCP tool that returns Firefly III domain data uses the matching existing `FireflyIII\Transformers\…` Fractal transformer and returns the full JSON:API envelope (`data`/`type`/`id`/`attributes`/`relationships`) wrapped via `Response::structured($array)`.
- **Why (user):** Cohesion with the REST API; zero schema drift; no new transformer layer to maintain.
- **Discarded:** Lean MCP-specific shapes (duplicate logic, drift risk); hybrid wrapper transformers (extra class per entity).
- **Implication:** Higher token cost per AI call (verbose envelope). Tools may pass `setParameters(...)` to existing transformers to drive `include`/`exclude` flags exactly as REST controllers do. The `data.type` discriminator (e.g. `"accounts"`, `"transaction_groups"`) becomes the AI's primary schema cue.
- **Date:** 2026-05-17.

### D-010 · DECIDED · Write tool granularity: typed creates + generic update/delete
- **Decision:** Five write tools in MVP: `create_withdrawal`, `create_deposit`, `create_transfer`, `update_transaction`, `delete_transaction`. Tight per-type JSON Schemas on the create path; one shared update tool taking an id + patch; one delete tool taking an id.
- **Why (user):** Cleanest schema per tool on the high-intent create path; AI matches intent to tool by name.
- **Discarded:** Single generic `create_transaction` with a type discriminator (loose schema, AI may pass invalid account-pair combos); fully typed updates/deletes (low marginal value, more tools).
- **Implication:** Each create tool internally maps to a `TransactionGroupFactory::create([...])` call with the appropriate `type` field. Account-role validation (e.g. withdrawal source must be asset, destination must be expense) belongs in each tool's `handle()` so error messages can be tailored for the AI. Reuses existing FormRequest-style validation rules where possible.
- **Date:** 2026-05-17.

### D-011 · DECIDED · Resources surface: static catalogs + user reference snapshots (no system info, no templated resources)
- **Decision:** Two resource families in MVP:
  - **Static catalogs:** currency list, account-type enum, transaction-type enum, link-type catalog. URIs like `firefly://catalogs/currencies`.
  - **User reference snapshots:** the authenticated user's chart of accounts, tag library, category list, budget list. URIs like `firefly://user/accounts`. Point-in-time snapshots; clients re-read to refresh.
- **Why (user):** Grounding data for the AI without exploding the resource surface.
- **Discarded:** System info (low value vs surface area); templated resources `firefly://accounts/{id}` and `firefly://transactions/{id}` (redundant with read tools).
- **Implication:** Static-catalog resources cache cleanly (long TTL). User-reference resources MUST scope by `auth()->user()` inside `handle()`. Resources cannot take parameters, so any filtering/pagination must happen via tools instead.
- **Date:** 2026-05-17.

### D-012 · DECIDED · Rate limiting: match existing API (none)
- **Decision:** MCP route inherits the existing API posture and carries no explicit Laravel throttle middleware.
- **Why (user):** Consistency with current Firefly III behavior; defer to upstream infrastructure for abuse mitigation.
- **Discarded:** Global `throttle:mcp` middleware; per-write throttle inside handlers.
- **Implication:** Operators relying on per-route throttling must add it in their reverse proxy. Future revisit if upstream introduces per-route throttling for the REST API as well.
- **Date:** 2026-05-17.

### D-013 · DECIDED · Read tool inventory: full surface (core + search + power-user + aggregates)
- **Decision:** All four read-tool families ship in MVP:
  - **Core list/get:** `list_accounts`, `get_account`, `list_transactions`, `get_transaction`, `list_budgets`, `get_budget`, `list_categories`, `get_category`, `list_tags`, `get_tag`.
  - **Search:** `search_transactions` (free-text query, mirrors `/api/v1/search`).
  - **Power-user:** `list_bills`, `list_rules`, `list_piggy_banks`, `list_webhooks` (plus matching `get_*` where natural).
  - **Aggregates:** `summary_basic` (dashboard digest), `insight_expense`, `insight_income`, `chart_data` (mirrors `/api/v1/chart/*` and `/api/v1/insight/*`).
- **Why (user):** Maximum agentic reach in MVP.
- **Discarded:** Read-tool subsets.
- **Implication:** ~20+ tools total. The `tools/list` discovery response will be large; AI clients pay a token cost on every session bootstrap. We mitigate by writing tight `#[Description]` strings (one-liners) on each tool. Each tool reuses an existing Firefly III repository — no new data access code unless a gap surfaces during implementation.
- **Date:** 2026-05-17.

### D-014 · DECIDED · Audit logging: mirror webhook precedent — every write logs to `audit` channel
- **Decision:** Every `create_*`, `update_transaction`, `delete_transaction` invocation calls `Log::channel('audit')->info('MCP <tool> by user <id>', $payload)` before invoking the underlying factory/repository. Matches the pattern observed in `app/Api/V1/Controllers/Webhook/StoreController.php:83`.
- **Why (user):** Strongest paper trail; matches upstream's own posture for sensitive writes.
- **Discarded:** Log-on-failure-only (weaker forensics); log-mutations-only (small log volume saving, not worth the asymmetry).
- **Implication:** Audit log will gain a new line per MCP write. Operators should rotate the `audit` channel accordingly. Payloads logged at `info` level — must not include secrets (we don't pass any). Tool failures will additionally log at `error` level with exception details, per Firefly's existing logging style.
- **Date:** 2026-05-17.

### D-015 · DECIDED · Composer pin: `laravel/mcp: ^0.7.0`
- **Decision:** `composer.json` carries `"laravel/mcp": "^0.7.0"`, accepting 0.7.x patches and future 0.x minors.
- **Why (user):** Standard Laravel convention; balances staying current with patch acceptance.
- **Discarded:** `~0.7.0` / `0.7.*` (stricter pin — safer for pre-1.0 but requires manual bumps).
- **Implication:** `composer update` could pull a breaking 0.x minor. Parking-lot item: monitor `laravel/mcp` releases; add to PR checklist that we re-test after any minor bump.
- **Date:** 2026-05-17.

### D-016 · DECIDED · Tests path: `tests/integration/Api/Mcp/…`
- **Decision:** All MCP tests live under `tests/integration/Api/Mcp/`, mirroring the existing layout under `tests/integration/Api/`. Uses PHPUnit 13 per `phpunit.xml`.
- **Why (user):** Consistent with how the REST API is tested in this repo.
- **Discarded:** `tests/feature/Mcp/…` (breaks the api/-grouping convention); unit + integration split (more files; unit coverage of pure handler logic deferred unless complexity warrants).
- **Implication:** Use `laravel/mcp`'s built-in `Server::tool(…)->assert*()` harness inside integration tests. Each test runs through the live Laravel container with SQLite in-memory DB per existing `phpunit.xml` config.
- **Date:** 2026-05-17.

### D-017 · DECIDED · Tool scoping: user-only, no admin surface in MVP
- **Decision:** Every MVP tool is per-user. No `system_health`, no `list_users`, no `audit_log_read`. The auth boundary is "you can do anything your PAT can do, scoped to your `user_group_id`".
- **Why (user):** Smallest blast radius; identical security posture to existing PAT-protected REST routes.
- **Discarded:** Per-tool admin role check; deferred admin surface (option C — effectively same as A for MVP, but with a backlog placeholder).
- **Implication:** Admins who want MCP-mediated administration of Firefly III must wait for a future iteration. We do NOT add `hasRole('admin')` checks into any tool. If admin tooling is requested later, design pass starts fresh.
- **Date:** 2026-05-17.

### D-018 · DECIDED · Validation reuse: existing FormRequest `rules()` via the Validator facade
- **Decision:** Each write tool instantiates the matching FormRequest, extracts `->rules()`, runs `Validator::make($input, $rules)->validate()` inside `handle()`, and converts any `ValidationException` into an `Response::error(...)` with a structured error payload. Single source of truth for transaction-write validation.
- **Why (user):** No drift between REST and MCP validation; max code reuse.
- **Discarded:** MCP-only JsonSchema (drift risk); hybrid JsonSchema+Validator (extra surface, two checking layers).
- **Implication:** `schema(JsonSchema $schema)` still must declare the wire-level schema for MCP clients — we'll derive it programmatically from the rules where feasible, or hand-author a thin schema mirroring the rules. Some FormRequest rules depend on the Laravel HTTP `Request` lifecycle (e.g. accessing `$this->route(...)`) — if hit, we wrap the FormRequest in a thin adapter, NOT bypass it.
- **Date:** 2026-05-17.

### D-019 · DECIDED · Pagination: reuse REST `page`/`limit` convention via JSON args
- **Decision:** Read tools accept `{ "page": int, "limit": int }` (both optional, with Firefly's existing defaults). Output is the JSON:API envelope with `meta.pagination` exactly as the REST API returns it.
- **Why (user):** Zero divergence from the REST API; reuses existing repository pagination.
- **Discarded:** MCP cursor style (new code, no benefit for Firefly's per-user data sizes); hard cap (lossy).
- **Implication:** Tool descriptions tell the AI how to follow `meta.pagination.links.next` (i.e. how to call again with `page = current_page + 1`). The discovery list payload grows slightly to accommodate these schema fields.
- **Date:** 2026-05-17.

### D-020 · DECIDED · Translation: English-only in MVP
- **Decision:** Tool descriptions, schema field descriptions, resource names, and error messages are hard-coded English in MVP. No `resources/lang/en_US/mcp.php` file in MVP.
- **Why (user):** Simpler; LLM consumption is English-anyway.
- **Discarded:** Full i18n (deferred); errors-only i18n (compromise rejected for now).
- **Implication:** A potential upstream PR review concern — Firefly III has strong i18n discipline. Parking-lot item: before opening the upstream PR, evaluate whether maintainers want `mcp.php` lang entries with Crowdin source-string sync.
- **Date:** 2026-05-17.

### D-021 · DECIDED · Tool annotations: apply MCP 2025-06-18 spec semantically
- **Decision:** Read tools carry `#[IsReadOnly]` + `#[IsIdempotent]`. `update_transaction` carries `#[IsDestructive]` + `#[IsIdempotent]`. `delete_transaction` carries `#[IsDestructive]` only (a second call errors, so not MCP-idempotent). `create_*` tools carry no annotation (write, non-destructive, non-idempotent by default — but see D-026 for explicit idempotency-key opt-in). `#[IsOpenWorld]` is never applied (Firefly III data is closed-world per user).
- **Why (user):** Conformance with the MCP spec; richest semantic signal to AI clients.
- **Discarded:** Read-only annotation only; no annotations at all.
- **Implication:** Spec-aware MCP clients can warn / require confirmation on `#[IsDestructive]` tools. Documentation must remind users that delete and update have these markers.
- **Date:** 2026-05-17.

### D-022 · DECIDED · Resource URI scheme: `firefly-iii://`
- **Decision:** Resources use the URI scheme `firefly-iii://`. Static catalogs at `firefly-iii://catalogs/<name>` (e.g. `firefly-iii://catalogs/currencies`). User reference data at `firefly-iii://user/<name>` (e.g. `firefly-iii://user/accounts`).
- **Why (user):** Matches the project's full GitHub name; avoids collision with other apps using a generic `firefly://` scheme.
- **Discarded:** `firefly://` (too generic); deployment-URL-as-scheme (tightly coupled to host).
- **Implication:** MCP client config will show these URIs verbatim. Resource registration in `FireflyServer::$resources` uses `#[Uri('firefly-iii://...')]` on each resource class.
- **Date:** 2026-05-17.

### D-023 · DECIDED · Tool naming: kebab-case via laravel/mcp auto-derivation
- **Decision:** Tool names are auto-derived from class names: `ListAccountsTool` → `list-accounts`, `CreateWithdrawalTool` → `create-withdrawal`, `BulkCreateTransactionsTool` → `bulk-create-transactions`. No `#[Name]` attributes used.
- **Why (user):** Documented default in laravel/mcp; one less attribute per class.
- **Discarded:** Explicit `#[Name('snake_case')]`; Firefly-style `camelCase`.
- **Implication:** Class names BECOME the public tool names. Renaming a class is a wire-breaking change for MCP clients — treat class names as part of the public API surface.
- **Date:** 2026-05-17.

### D-024 · DECIDED · Implementation cadence: lock all decisions, then parallel-agent sprint
- **Decision:** Continue the design pass until all material decisions are locked in this WIP doc. Then spawn a team of parallel agents to implement workstreams concurrently (composer install + skeleton, read tools, write tools, resources, tests, docs, code-quality gates). No code is written until the catalog and conventions are locked.
- **Why (user):** Sprint e2e with multiple teammates in parallel — needs a complete spec up front to avoid coordination overhead.
- **Discarded:** "Install now and design tools in code" (would require mid-sprint coordination); "open upstream issue first" (deferred to a later step per D-001).
- **Implication:** This doc IS the spec the agent team will work from. Every tool, every resource, every shape must be locked before agents are dispatched. After the sprint, a single integration review pass merges the workstreams.
- **Date:** 2026-05-17.

### D-025 · DECIDED · Streaming: no streaming in MVP, all tools synchronous
- **Decision:** Every tool `handle()` returns a single `Response` synchronously. No `Generator` returns, no progress notifications, no SSE multi-event responses.
- **Why (user):** Smallest implementation surface; Firefly's per-user data is small enough that synchronous responses are fast.
- **Discarded:** Streaming for search/insight tools (deferred); fully deferred.
- **Implication:** If a tool genuinely exceeds reasonable response time later, we revisit. SSE upgrade path in `laravel/mcp` is available without rewiring — just change return type.
- **Date:** 2026-05-17.

### D-026 · DECIDED · Bulk writes: add `bulk-create-transactions` tool
- **Decision:** One additional write tool `BulkCreateTransactionsTool` (name: `bulk-create-transactions`) accepts an array of transaction specs and creates them inside a single DB transaction (`DB::transaction(...)`). All-or-nothing: any validation error aborts the whole batch and returns an indexed error response. Each item must be a valid create_withdrawal/deposit/transfer payload, and each item may carry its own `idempotency_key`.
- **Why (user):** Useful for migration-style agents; one explicit bulk tool is enough.
- **Discarded:** No bulk (too restrictive); bulk_* for all CRUD (too broad).
- **Implication:** Tool annotated `#[IsDestructive]`. Audit log emits one line per item plus a summary line. Item-level errors include the index so the AI can correlate. Total array size capped (suggested: 100 items per call) to bound transaction time and audit-log volume — exact cap TBD when implementing.
- **Date:** 2026-05-17.

### D-027 · DECIDED · Idempotency: explicit-key on every `create_*` and `bulk-create-transactions`
- **Decision:** Every create-style tool (including the bulk variant, per-item) accepts an optional `idempotency_key: string` in its schema. When supplied:
  - Server looks up an existing transaction by that key in `TransactionJournalMeta` (name=`mcp_idempotency_key`).
  - If found AND the existing transaction belongs to the same user, return the existing transaction's transformed payload and skip creation.
  - If found but belongs to another user → treat as not-found and create new (defensive; never leak cross-user data).
  - If not found → create the transaction, then write the meta entry with the supplied key.
  When omitted: always create. AI explicitly opts in to idempotency.
- **Why (user):** Industry-standard pattern (Stripe, AWS); puts the AI in control; doesn't risk silent dedup of legitimate near-duplicates.
- **Discarded:** Implicit payload-hash dedup; hybrid.
- **Implication:** New meta key `mcp_idempotency_key` on `TransactionJournalMeta` — index it for fast lookup (potential migration). Key length capped at e.g. 128 chars and validated as printable ASCII. Audit log records whether a write was a fresh insert or an idempotent replay.
- **Date:** 2026-05-17.

### D-028 · DECIDED · Documentation: minimal three-surface (README intro + dedicated doc + tool descriptions)
- **Decision:** Three thin layers, each kept minimal but complete:
  1. **Root `readme.md`:** one short subsection introducing the MCP feature with a link to the dedicated doc.
  2. **Dedicated doc** at `docs/mcp.md` (new file): setup, auth (PAT in header), route URL, feature flag, list of tools and resources with one-line each. No exhaustive schemas (the AI gets those from `tools/list`).
  3. **Inline:** every tool/resource carries a minimal-but-complete `#[Description]` and field-level schema descriptions.
- **Why (user):** Keep surface minimal; introduce in README, deep-dive in the dedicated page, exact contract via tool descriptions.
- **Discarded:** Tool descriptions only (insufficient for human discovery); README-only (no place for setup detail); long-form prose docs (over-investment in MVP).
- **Implication:** New file `docs/mcp.md` (this directory does not exist in upstream today — verify and create). Changelog entry (`changelog.md`) under Added: "MCP endpoint at /api/v1/mcp …" added at PR time. README addition lives in the existing structure — pick a logical home (e.g. after the existing "Features" / "Tech stack" section, before "Contributing").
- **Date:** 2026-05-17.

### D-029 · DECIDED · Currency: inherit from source account, AI cannot override
- **Decision:** `create_*` and `bulk-create-transactions` tools do NOT accept a `currency_code` arg. Server uses the source account's currency on every line item.
- **Why (user):** Simplest mental model; minimum AI cognitive load; AI cannot accidentally create mismatched-currency entries.
- **Discarded:** Optional override; required explicit.
- **Implication:** Cross-currency transfers (legitimate in REST API today) are NOT possible via MCP in MVP. Note this in `docs/mcp.md` as a known MVP limitation. AI agent that needs cross-currency must use the REST API directly.
- **Date:** 2026-05-17.

### D-030 · DECIDED · Audit log shape: minimal (action + user_id + transaction_id)
- **Decision:** Each MCP write logs one line via `Log::channel('audit')->info(...)` with payload `['tool' => '<kebab-name>', 'user_id' => int, 'transaction_id' => int|null, 'idempotent_replay' => bool]`. No request body in the log entry. Failures additionally log at `error` level with the exception details (no payload).
- **Why (user):** Small log volume; transaction id is the cross-reference into the DB for forensics.
- **Discarded:** Full payload (verbose); full+stacktrace (over-rich for an audit channel).
- **Implication:** Forensic reconstruction requires DB access for the payload. Acceptable: the DB IS the source of truth and is preserved with the audit log entry pointing to it.
- **Date:** 2026-05-17.

### D-031 · DECIDED · Pre-commit gates: Mago format + Mago lint + PHPStan + unit tests, locally per commit
- **Decision:** Every commit by every teammate runs (in this order) before staging:
  1. `./vendor/bin/mago format` (auto-fixes formatting),
  2. `./vendor/bin/mago lint` (must pass clean),
  3. `vendor/bin/phpstan analyse -c .ci/phpstan.neon` (must pass clean),
  4. `composer unit-test` (must pass clean).
  If any of 2–4 fail, fix before committing. Skipping is not allowed; `--no-verify` is forbidden.
- **Why (user):** Fail-fast; commits land green; mirrors what `release.yml` enforces.
- **Discarded:** Format-only per commit; rely-on-CI.
- **Implication:** Slower iteration speed. Teammate agents must include these checks in their workflow. A pre-commit hook may be added to enforce mechanically — TBD by the scaffold teammate.
- **Date:** 2026-05-17.

### D-032 · DECIDED · Sprint shape: 5 parallel teammates with explicit dependency on scaffold first
- **Decision:** Five-teammate sprint, with dependency ordering:
  - **A (Scaffold):** composer install of `laravel/mcp:^0.7.0`, `vendor:publish --tag=ai-routes`, create `app/Mcp/Servers/FireflyServer.php` skeleton (empty `$tools`, `$resources`), mount `Mcp::web('/api/v1/mcp', FireflyServer::class)->middleware('auth:api')` in `routes/ai.php`, add `allow_mcp` to `config/firefly.php` (default `true`), implement feature-flag gate, add migration for `mcp_idempotency_key` meta key index if needed, write base abstract classes (e.g. `AbstractMcpTool` if helpful), set up pre-commit hook from D-031. **Must finish first.**
  - **B (Read tools):** all read tools per the locked catalog. Depends on A.
  - **C (Write tools):** all create_* + update + delete + bulk + idempotency-key plumbing per the catalog. Depends on A.
  - **D (Resources):** all static catalogs + user snapshots per the catalog. Depends on A.
  - **E (Tests):** integration tests under `tests/integration/Api/Mcp/` covering everything B/C/D ship. Depends on B/C/D landing; can start scaffolding test fixtures in parallel after A.
  - **Final integration pass (human + assistant):** README addition, `docs/mcp.md`, `changelog.md` entry, end-to-end gate run, cleanup.
- **Why (user):** 5 teammates, parallel-where-possible, sequential-where-necessary.
- **Discarded:** 3 teammates (less parallelism); explicit phasing (selected option had identical effect once dependencies are stated).
- **Implication:** Coordination doc = this WIP file. Each teammate works on a separate worktree (isolation: worktree) or branch to avoid file conflicts on shared files like `routes/ai.php` and `composer.json`. The scaffold teammate must NOT touch tool/resource files; the others must NOT touch scaffold files. Tests teammate may need to coordinate with B/C/D on naming conventions inside test files.
- **Date:** 2026-05-17.

---

## 3 — Open questions (next decisions needed)

These are queued; the assistant will not act on them until the user decides.

_All design questions resolved as of D-036. §4 and §5 are LOCKED. Next step: user confirms the doc as a whole, then assistant spawns the agent team per D-032._

---

### D-033 · DECIDED · Identifier strategy: accept both id and name (REST-API parity)
- **Decision:** Tools that reference accounts, categories, budgets, and tags accept `*_id` AND `*_name` fields. Server resolves: id wins if both are supplied, else lookup by name. Tags accepted as `tags: [string|int]` (mixed: tag id or tag string). This mirrors Firefly's REST transaction-create behavior in `app/Api/V1/Requests/Models/Transaction/StoreTransactionRequest.php`.
- **Why (user):** Most consistent with REST; zero new resolution code; AI can use names naturally.
- **Discarded:** Names only (ambiguity); ids only (round-trip cost).
- **Implication:** Tool schemas mark id and name fields as `oneOf` or both optional with a "at least one of source_id, source_name required" constraint expressed in `schema()` and enforced via the existing FormRequest's `rules()`. Resolution helper sits in a shared MCP support trait (TBD by Scaffold teammate A).
- **Date:** 2026-05-17.

### D-034 · DECIDED · Reducer pipeline: standard `JsonApiReducer` + `verbose` flag + per-tool override hook
- **Decision:** Single canonical response pipeline for every tool and resource:
  1. Build the JSON:API document via the matching existing Fractal transformer (D-009 stands).
  2. Pass the document through `FireflyIII\Mcp\Support\JsonApiReducer::reduce($doc, $options)`. The reducer is structural (transformer-agnostic) and applies these rules:
     - `data` (collection) → array of flat objects.
     - `data` (single item) → flat object.
     - Each item: drop `type`, merge `id` (numeric cast) + flatten `attributes`. Drop `links`. For each `relationships.X`, pull `relationships.X.data.id` (or array of ids) up as `X_id` / `X_ids` and drop the wrapper.
     - Preserve `meta.pagination` verbatim (AI uses it to follow `current_page`/`total_pages`).
     - Preserve other `meta` keys.
     - If `included` is present, recursively reduce into `_included` keyed by `type/id`.
  3. Return `Response::structured($reduced)`.
- **Flag:** Every read tool accepts an optional `verbose: bool` arg (default `false`). When `true`, the tool returns the raw JSON:API document, skipping the reducer.
- **Per-tool override:** `AbstractMcpTool` exposes `protected function reducer(): JsonApiReducer` returning the default. Bulk-byte tools (e.g. `ListTransactionsTool`, `SearchTransactionsTool`) MAY override `reducer()` to return a custom subclass that further compacts their domain shape (e.g. a `TransactionGroupCompactor` that collapses single-split groups). Override hook ships as the extension mechanism; concrete custom reducers are NOT in MVP scope unless a specific token cost demands them — TBD by teammate B.
- **Why (user):** Real ~5–10× token reduction for AI consumption; one canonical pipeline; future-extensible without revising MVP code; verbose escape hatch preserved.
- **Discarded:** Per-layer (no reducer); hand-rolled lean shapes per tool; no override hook.
- **Implication:** New file `app/Mcp/Support/JsonApiReducer.php` + `app/Mcp/Tools/AbstractMcpTool.php` + a small `tests/integration/Api/Mcp/Support/JsonApiReducerTest.php` proving structural rules. Scaffold teammate A builds these. All other teammates inherit `AbstractMcpTool`.
- **Date:** 2026-05-17.

### D-035 · DECIDED · Null handling: drop nulls by default, `include_nulls: true` arg to opt in
- **Decision:** The `JsonApiReducer` drops any attribute whose value is `null` by default. Every read tool accepts an optional `include_nulls: bool` arg (default `false`). When `true`, the reducer preserves null attributes.
- **Why (user):** Biggest token win for the typical AI workflow; explicit flag covers the audit/compliance case where presence matters.
- **Discarded:** Keep-nulls-always (smaller token win); drop-nulls-always (lossy without escape hatch).
- **Implication:** The reducer's behavior is `reduce($doc, ['include_nulls' => false])` by default. Tools pass through whatever the AI supplies. Tool descriptions must briefly mention that null-valued fields are omitted unless `include_nulls: true`. Care needed in tests: assertions on field presence must explicitly verify against the chosen mode.
- **Date:** 2026-05-17.

### D-037 · DECIDED · Teammate isolation: each agent runs in its own git worktree
- **Decision:** Every teammate Agent invocation uses `isolation: "worktree"`. A's worktree is the foundation; B/C/D each branch from A's resulting branch (their prompts must explicitly start from A's branch, not `main`). E branches from a merged B+C+D branch. Final integration happens in the main working tree.
- **Why (user):** Strongest safety; no file collisions; deliberate review/merge step between phases.
- **Discarded:** Shared workspace; hybrid (A in main, others in worktrees).
- **Implication:** Each Agent call returns its branch name. The orchestrator (assistant) propagates A's branch into B/C/D prompts and merges B/C/D before launching E. Worktrees are cleaned up automatically if a teammate makes no changes; otherwise their branches persist.
- **Date:** 2026-05-17.

### D-038 · DECIDED · Sprint outcome: auto-commit per teammate, no auto-push
- **Decision:** Each teammate commits its own files locally inside its worktree. Commits follow Conventional Commits and carry the `Assisted-by: <model> via Claude Code` footer per `agents.md`. No teammate pushes to any remote. After the sprint, the user reviews the commit log in the merged main tree and decides next steps (push, additional iteration, etc.).
- **Why (user):** Auto-commits per teammate; await user before pushing.
- **Discarded:** Show-everything-before-merging (slowest); auto-draft-PR (premature).
- **Implication:** Each agent prompt must include the commit convention + Assisted-by footer + Conventional Commit type guidance. No agent runs `git push`. The merge of teammate branches is done by the orchestrator in the main tree, not by teammates.
- **Date:** 2026-05-17.

### D-036 · DECIDED · Aggregate tools: all four with tight args + restricted ChartData enum
- **Decision:** Lock the aggregate-tool surface:
  - `SummaryBasicTool`: args `start?: date`, `end?: date`. Default range = current month if omitted.
  - `InsightExpenseTool`: args `start: date`, `end: date`, `account_ids?: int[]`.
  - `InsightIncomeTool`: args `start: date`, `end: date`, `account_ids?: int[]`.
  - `ChartDataTool`: args `chart_type: enum`, `start: date`, `end: date`. `chart_type` is restricted to exactly three values in MVP: `account_balances`, `category_expenses`, `budget_vs_actual`.
- **Why (user):** Minimal surface; covers the highest-value chart workflows; defers the chart-type explosion.
- **Discarded:** Full REST `chart_type` enum exposure; drop ChartData entirely.
- **Implication:** Teammate B locates the existing `/api/v1/chart/*` controllers, identifies the three target chart endpoints, and lifts their logic into `app/Mcp/Support/Aggregates/` support classes — NO HTTP-layer coupling. If any of the three chart_types turns out to have an awkward existing implementation, teammate B documents and we revisit (do not ship a broken chart_type).
- **Date:** 2026-05-17.

### D-039 · DECIDED · Auth: MCP-spec OAuth via `Mcp::oauthRoutes()` + `mcp:use` scope (supersedes D-005)
- **Decision:** MCP route stays on the `auth:api` guard (Passport-driven) but gains scope enforcement via `Passport\Http\Middleware\CheckToken::using('mcp:use')`. `Mcp::oauthRoutes()` is called once in `routes/ai.php` to register the RFC 8414 / RFC 9728 well-known discovery docs and the RFC 7591 Dynamic Client Registration endpoint at `/oauth/register`. The `mcp:use` scope is registered automatically by `laravel/mcp`'s `ensureMcpScope()` helper (no manual `Passport::tokensCan` needed).
- **Why (user):** Need claude.ai (web custom-connector) to work alongside Claude Desktop and Claude Code under a single auth chain. Custom connectors only support OAuth — no arbitrary auth headers — so PAT + Cloudflare Access service tokens cannot make it through that UI. OAuth is the lingua franca across all three clients.
- **Discarded:** (a) Cloudflare IP-allowlist bypass for Anthropic egress (workable but drift-prone and weakens perimeter); (b) reverse-proxy translating OAuth → CF Access service-token headers (new attack surface, new service to host); (c) keeping PAT-only and forgoing claude.ai (cuts the most ergonomic surface).
- **Implication:**
  - `routes/ai.php` gains exactly one new top-level line (`Mcp::oauthRoutes();`) and one middleware addition (`CheckToken::using('mcp:use')`) — no other server-side code changes required.
  - `config/mcp.php` must be published via `php artisan vendor:publish --tag=mcp-config` and tightened: `redirect_domains` pinned to `['https://claude.ai', 'http://localhost', 'http://127.0.0.1']`, `authorization_server` pinned to `env('APP_URL')`.
  - Firefly's existing `Passport::authorizationView('auth.oauth.authorize')` covers the consent screen — no new blade. The `mcp:use` scope shows up in the consent prompt automatically because Passport reads scope labels from `Passport::$scopes`.
  - RFC 8707 audience binding is NOT enforced in MVP (AS = RS in our single-host deployment, so audience confusion is not exploitable). Tracked as a follow-up if Firefly ever federates auth.
  - Public PKCE clients (`token_endpoint_auth_method: none`) — `laravel/mcp`'s DCR creates them this way via Passport's `ClientRepository::createAuthorizationCodeGrantClient(confidential: false, user: null)`. This matches OAuth 2.1 guidance for installed/browser apps.
- **Date:** 2026-05-18.

### D-040 · DECIDED · `config/mcp.php` redirect-domains: explicit allowlist
- **Decision:** Publish `config/mcp.php` and set:
  ```php
  'redirect_domains' => [
      'https://claude.ai',
      'http://localhost',
      'http://127.0.0.1',
  ],
  'custom_schemes'        => [],
  'authorization_server'  => env('APP_URL'),
  ```
- **Why (user):** `laravel/mcp` defaults `redirect_domains` to `['*']`. That accepts DCR registrations with any redirect URI, opening an open-redirect / token-exfiltration vector. The three entries cover claude.ai (web + Desktop + mobile) and loopback (Claude Code, local Anthropic SDK testing). Cursor / VSCode (custom schemes) are deliberately excluded until a real need arises — we re-add them via a single config line, no code change.
- **Discarded:** Default `['*']` (insecure); `claude.ai` only (cuts Claude Code which uses loopback redirects).
- **Implication:** Any future client using a non-listed redirect host fails DCR with a validation error. Operators expand the list on demand. Document the knob in `docs/mcp.md`.
- **Date:** 2026-05-18.

### D-041 · DECIDED · PAT runtime path stays open; PAT removed from docs/plugin config (soft cutover)
- **Decision:** No code is added to block PAT-Bearer requests to `/api/v1/mcp` — Passport's `auth:api` accepts any Passport-issued bearer, OAuth or PAT alike, and `CheckToken::using('mcp:use')` lets a PAT through too because PATs carry the user's full scope set. However: the plugin's `.mcp.json`, the README, `docs/mcp.md`, and the user-facing changelog all stop mentioning PAT. The plugin config drops the `FIREFLY_PAT` env var and the Cloudflare Access service-token headers. New users only see the OAuth flow; existing PAT users continue to work without action.
- **Why (user):** "Drop PAT" is a directive about user experience and the documented surface, not a runtime kill. Hard-blocking PAT would break existing installations on upgrade without any operational benefit, and reinstating PAT later (if we ever need a service-account flow) is a docs change. Keep the door open.
- **Discarded:** Hard cutover via middleware that rejects PATs (breaks existing installs, no upside); leave PAT in docs as a "legacy" option (confuses new users, fragments the install experience).
- **Implication:**
  - `plugins/firefly-iii/.mcp.json` drops the `Authorization` and `CF-Access-*` headers entirely; transport stays HTTP, URL stays env-driven. MCP clients trigger the OAuth dance on first call.
  - README and `docs/mcp.md` describe only the OAuth path. PAT removed from quick-start.
  - Plugin `version` bumps from `0.1.1` → `0.2.0` (minor; install-surface change, not a fix).
  - The CF Access service-token bypass policy on `rp5-homeserver` becomes redundant for the MCP path but harmless to leave for now. See [D-043](#d-043--decided--cf-access-deployment-delta-non-blocking).
- **Date:** 2026-05-18.

### D-042 · DECIDED · OAuth migration sprint shape: 4 teammates, no worktrees (small diff)
- **Decision:** Four teammates, all working in the main `feat/mcp-integration` worktree, with file-level non-overlap so there are no merge collisions:
  - **A (Scaffold OAuth):** `routes/ai.php` (add `Mcp::oauthRoutes()` + `CheckToken::using('mcp:use')` middleware), `config/mcp.php` (new published file, tightened per D-040), `app/Mcp/Servers/FireflyServer.php` (instruction docstring update to reflect OAuth).
  - **B (Plugin):** `plugins/firefly-iii/.mcp.json` (drop PAT + CF headers), `plugins/firefly-iii/README.md` (rewrite install section for OAuth), `plugins/firefly-iii/.claude-plugin/plugin.json` + `.claude-plugin/marketplace.json` (version bump 0.1.1 → 0.2.0).
  - **C (Tests):** `tests/integration/Api/Mcp/Auth/OAuthDiscoveryTest.php`, `tests/integration/Api/Mcp/Auth/McpScopeTest.php`, `tests/integration/Api/Mcp/Auth/DynamicClientRegistrationTest.php`. Cover: well-known docs return RFC-compliant JSON; `/oauth/register` issues a public PKCE client with `mcp:use` scope; `/api/v1/mcp` rejects no-token (401 + WWW-Authenticate), rejects no-`mcp:use`-scope token (403), accepts scope-bearing token (200); feature flag still wins over auth (404 when off).
  - **D (Docs):** `docs/mcp.md` (rewrite quick-start), `changelog.md` (add entry), `docs/WIP_MCP.md` (this very block — D-039 to D-043 already authored by orchestrator).
- **Why (user):** Diff is small enough that worktree isolation adds ceremony without speed. File-level partition between teammates is the cheap version of isolation, with no merge step.
- **Discarded:** Worktree isolation per D-037 pattern (overkill for a 1-line route + config publish + tests).
- **Implication:**
  - All teammates commit to the same branch (`feat/mcp-integration`) in order. Each commits its own scope only — no cross-teammate file touches.
  - Pre-commit hook (D-031) runs per commit. The format-spread workaround from §8 still applies: format only staged files at hook level.
  - Conventional Commits per D-031 + `Assisted-by: <model> via Claude Code` footer per D-038.
  - Final verification (after all four land): `mago format` + `mago lint` + `phpstan` + `phpunit` integration suite, then docker image build, then push + open PR.
- **Date:** 2026-05-18.

### D-043 · DECIDED · CF Access deployment delta: non-blocking, surfaced in PR body
- **Decision:** The OAuth migration does NOT include a Cloudflare Access terraform change in this repo. The required policy adjustment in `rp5-homeserver/cloud/main.tf` is described in the PR body for the user to action separately. The minimum delta: the existing `claude_mcp_bypass` policy stays (harmless leftover from the PAT era), and a new bypass set is added for the OAuth surface paths — `/oauth/authorize`, `/oauth/token`, `/oauth/register`, `/.well-known/oauth-*`, `/api/v1/mcp` — either via Anthropic egress-IP allowlist or by removing CF Access from `firefly.giocaizzi.xyz` entirely (the latter is simpler given Firefly is intentionally a public API surface).
- **Why (user):** This repo is the application, not the deployment. Mixing terraform-side perimeter changes into the application PR confuses ownership and slows merge. The deployment delta is reversible and the user controls it directly.
- **Discarded:** Bundle the terraform change in this branch (wrong repo); leave CF Access entirely in place (breaks the OAuth redirect flow for non-authenticated CF visitors during DCR).
- **Implication:**
  - PR body includes a "Deployment delta" section that quotes the required terraform changes verbatim.
  - The user merges this PR → Portainer redeploys Firefly → the OAuth-mounted MCP endpoint comes up → the user applies the terraform delta on `rp5-homeserver` before testing claude.ai's connector.
  - If the user wants to verify Claude Desktop / Claude Code first (which use loopback or service-token paths), no terraform change is required.
- **Date:** 2026-05-18.

---

## 4 — Tool catalog (LOCKED)

**Convention applied to every tool:**
- Namespace: `FireflyIII\Mcp\Tools`; one file per class under `app/Mcp/Tools/`.
- Class declared `final`. File starts with AGPL header + `declare(strict_types=1);` (C1).
- Name auto-derived from class (D-023). MCP descriptions one-liner, minimal-but-complete.
- Read tools: Fractal transformer → `JsonApiReducer` → `Response::structured(...)` (D-009, D-034). Default = reduced.
- Read tools accept these implicit args in addition to their declared schema:
  - `verbose?: bool` (default `false`; when `true` returns raw JSON:API — D-034)
  - `include_nulls?: bool` (default `false`; when `true` preserves null-valued attributes — D-035)
  - `page?: int`, `limit?: int` where the underlying endpoint paginates (D-019)
- Write tools: validation via existing FormRequest `rules()` (D-018); audit log per D-030; idempotency per D-027; currency from source account (D-029); response shape = reduced JSON:API of the created/updated resource via the standard pipeline.
- All accounts/categories/budgets accept `*_id` + `*_name` (D-033). Date args ISO 8601 (`YYYY-MM-DD`).

### 4.1 Read tools — core entity (10)

| Class | Annotations | Args | Repository / Source | Transformer |
|---|---|---|---|---|
| `ListAccountsTool` | `IsReadOnly`, `IsIdempotent` | `type?: string`, `active?: bool`, `page?: int`, `limit?: int` | `AccountRepositoryInterface::getAccounts(...)` | `AccountTransformer` |
| `GetAccountTool` | `IsReadOnly`, `IsIdempotent` | `id?: int`, `name?: string` (one required) | `AccountRepositoryInterface::find()` / `findByName()` | `AccountTransformer` |
| `ListTransactionsTool` | `IsReadOnly`, `IsIdempotent` | `start?: date`, `end?: date`, `account_id?: int`, `account_name?: string`, `type?: enum`, `page?: int`, `limit?: int` | Existing GroupCollector (see `app/Helpers/Collector/`) | `TransactionGroupTransformer` |
| `GetTransactionTool` | `IsReadOnly`, `IsIdempotent` | `id: int` | `TransactionGroupRepositoryInterface::find()` | `TransactionGroupTransformer` |
| `ListBudgetsTool` | `IsReadOnly`, `IsIdempotent` | `active?: bool`, `page?: int`, `limit?: int` | `BudgetRepositoryInterface::getBudgets()` | `BudgetTransformer` |
| `GetBudgetTool` | `IsReadOnly`, `IsIdempotent` | `id?: int`, `name?: string` | `BudgetRepositoryInterface` | `BudgetTransformer` |
| `ListCategoriesTool` | `IsReadOnly`, `IsIdempotent` | `page?: int`, `limit?: int` | `CategoryRepositoryInterface` | `CategoryTransformer` |
| `GetCategoryTool` | `IsReadOnly`, `IsIdempotent` | `id?: int`, `name?: string` | `CategoryRepositoryInterface` | `CategoryTransformer` |
| `ListTagsTool` | `IsReadOnly`, `IsIdempotent` | `page?: int`, `limit?: int` | `TagRepositoryInterface` | `TagTransformer` |
| `GetTagTool` | `IsReadOnly`, `IsIdempotent` | `id?: int`, `tag?: string` | `TagRepositoryInterface` | `TagTransformer` |

### 4.2 Read tools — search (1)

| Class | Annotations | Args | Source | Transformer |
|---|---|---|---|---|
| `SearchTransactionsTool` | `IsReadOnly`, `IsIdempotent` | `query: string`, `start?: date`, `end?: date`, `page?: int`, `limit?: int` | Existing search infrastructure backing `/api/v1/search` (see `app/Support/Search/`) | `TransactionGroupTransformer` |

### 4.3 Read tools — power-user (4)

| Class | Annotations | Args | Repository | Transformer |
|---|---|---|---|---|
| `ListBillsTool` | `IsReadOnly`, `IsIdempotent` | `active?: bool`, `page?: int`, `limit?: int` | `BillRepositoryInterface` | `BillTransformer` |
| `ListRulesTool` | `IsReadOnly`, `IsIdempotent` | `active?: bool`, `page?: int`, `limit?: int` | `RuleRepositoryInterface` | `RuleTransformer` |
| `ListPiggyBanksTool` | `IsReadOnly`, `IsIdempotent` | `page?: int`, `limit?: int` | `PiggyBankRepositoryInterface` | `PiggyBankTransformer` |
| `ListWebhooksTool` | `IsReadOnly`, `IsIdempotent` | `page?: int`, `limit?: int` | `WebhookRepositoryInterface` | `WebhookTransformer` |

### 4.4 Read tools — aggregates (4) — per D-036

| Class | Annotations | Args | Source | Output shape |
|---|---|---|---|---|
| `SummaryBasicTool` | `IsReadOnly`, `IsIdempotent` | `start?: date`, `end?: date` (default = current month) | Lift `/api/v1/summary/basic` logic into `app/Mcp/Support/Aggregates/` | Reduced JSON:API summary blob |
| `InsightExpenseTool` | `IsReadOnly`, `IsIdempotent` | `start: date`, `end: date`, `account_ids?: int[]` | Lift `/api/v1/insight/expense/*` services | Reduced JSON:API insight blob |
| `InsightIncomeTool` | `IsReadOnly`, `IsIdempotent` | `start: date`, `end: date`, `account_ids?: int[]` | Lift `/api/v1/insight/income/*` services | Reduced JSON:API insight blob |
| `ChartDataTool` | `IsReadOnly`, `IsIdempotent` | `chart_type: enum∈{account_balances,category_expenses,budget_vs_actual}`, `start: date`, `end: date` | Lift the three relevant `/api/v1/chart/*` endpoints | Reduced JSON:API chart blob |

**Note for B (Read tools teammate):** the existing aggregate controllers may not have clean repository methods. Extract logic into thin support classes under `app/Mcp/Support/Aggregates/` rather than calling controllers directly. Do NOT call HTTP-level code from a tool. If any of the three `ChartDataTool` chart_types turns out to be awkward to lift cleanly, document the blocker and we revisit — do not ship a broken chart_type.

### 4.5 Write tools (6)

| Class | Annotations | Args | Factory / Repository | Notes |
|---|---|---|---|---|
| `CreateWithdrawalTool` | none | `source_id?`, `source_name?` (one required, must be asset); `destination_id?`, `destination_name?` (one required, must be expense); `amount: decimal`; `date: date`; `description: string`; `category_id?`, `category_name?`; `budget_id?`, `budget_name?`; `tags?: array`; `notes?: string`; `idempotency_key?: string` | `TransactionGroupFactory::create()` with `type=withdrawal` | Currency inherited from source asset account (D-029). Validation reuses `StoreTransactionRequest::rules()` (D-018). Audit log per D-030. |
| `CreateDepositTool` | none | source must be revenue, destination must be asset; otherwise identical schema | `TransactionGroupFactory::create()` with `type=deposit` | Same as above. |
| `CreateTransferTool` | none | source and destination must both be asset; otherwise identical schema | `TransactionGroupFactory::create()` with `type=transfer` | Same as above. Cross-currency transfers explicitly NOT supported (D-029); reject in `handle()` with a clear error if accounts have different currencies. |
| `UpdateTransactionTool` | `IsDestructive`, `IsIdempotent` | `id: int` (required); all other fields optional (same schema as create_* but every field optional) | `TransactionGroupRepositoryInterface::update()` | Validation reuses `UpdateTransactionRequest::rules()`. Audit log per D-030. |
| `DeleteTransactionTool` | `IsDestructive` (NOT `IsIdempotent`) | `id: int` (required) | `TransactionGroupRepositoryInterface::destroy()` | Audit log per D-030. Second call on same id returns `Response::error('Transaction not found')`. |
| `BulkCreateTransactionsTool` | `IsDestructive` | `transactions: array<{type: enum, source_id?, source_name?, destination_id?, destination_name?, amount, date, description, category_id?, category_name?, budget_id?, budget_name?, tags?, notes?, idempotency_key?}>` (max 100 items) | `DB::transaction(...)` wrapping per-item `TransactionGroupFactory::create()` | All-or-nothing: any validation error aborts. Audit log emits one line per item + summary. Each item is validated against its type's FormRequest. |

### 4.6 Total tools = 25

Discovery payload size: ~25 entries × short description. Acceptable per D-013.

---

## 5 — Resource catalog (LOCKED)

**Convention applied to every resource:**
- Namespace: `FireflyIII\Mcp\Resources`; one file per class under `app/Mcp/Resources/`.
- `final` class. AGPL header + `declare(strict_types=1);`.
- URI scheme: `firefly-iii://...` (D-022).
- MIME type: `application/json`.
- Pipeline: Fractal transformer (where one exists) → `JsonApiReducer` (default options: drop nulls) → `Response::text(json_encode($reduced))`. For static catalogs lacking a Fractal transformer (e.g. `AccountType`, `LinkType`), build a thin inline transformer or feed a hand-shaped array directly through the reducer's flatten logic — pick whichever is cleaner per resource, document the choice in the class docblock.
- Resources accept NO parameters per `laravel/mcp` constraints. They are unconditionally reduced (no `verbose` opt-out at the resource layer — clients that need raw JSON:API can call the equivalent read tool with `verbose: true`).

### 5.1 Static catalogs (4)

| Class | URI | Description | Source data | Output shape |
|---|---|---|---|---|
| `CurrenciesCatalogResource` | `firefly-iii://catalogs/currencies` | "All transaction currencies known to this Firefly III instance." | `TransactionCurrency::all()` (enabled only) | Array of `{ code, name, symbol, decimal_places, default: bool }` |
| `AccountTypesCatalogResource` | `firefly-iii://catalogs/account-types` | "All account types and their roles." | `AccountType::all()` | Array of `{ type, role }` |
| `TransactionTypesCatalogResource` | `firefly-iii://catalogs/transaction-types` | "All transaction types (withdrawal, deposit, transfer, etc.)." | `TransactionType::all()` | Array of `{ type, description }` |
| `LinkTypesCatalogResource` | `firefly-iii://catalogs/link-types` | "Link types between transactions (e.g. refund, paid-off-by)." | `LinkType::all()` | Array of `{ id, name, inward, outward, editable }` |

### 5.2 User reference snapshots (4)

| Class | URI | Description | Source | Output shape |
|---|---|---|---|---|
| `UserAccountsResource` | `firefly-iii://user/accounts` | "The authenticated user's active chart of accounts (asset, liability, expense, revenue)." | `AccountRepositoryInterface::getAccountsByType(...)`, scoped to `auth()->user()` | Lean array of `{ id, name, type, currency_code, current_balance, active }` |
| `UserTagsResource` | `firefly-iii://user/tags` | "The authenticated user's tag library." | `TagRepositoryInterface::get()` | Lean array of `{ id, tag, description }` |
| `UserCategoriesResource` | `firefly-iii://user/categories` | "The authenticated user's categories." | `CategoryRepositoryInterface::getCategories()` | Lean array of `{ id, name }` |
| `UserBudgetsResource` | `firefly-iii://user/budgets` | "The authenticated user's active budgets." | `BudgetRepositoryInterface::getActiveBudgets()` | Lean array of `{ id, name, active, auto_budget_amount?, auto_budget_period? }` |

### 5.3 Total resources = 8

Discovery payload size: 4 catalog entries + 4 user snapshots, each with a tight description.

---

## 6 — Reversible parking lot

Things to revisit before opening an upstream PR:

- Open the upstream issue / email @JC5 (`james@firefly-iii.org`) — required before any PR > 25 lines.
- Remove `WIP_MCP.md` from the branch destined for PR.
- Add AI-assistance disclosure form to PR body; check at least the "Code generation" box.
- Add `Assisted-by: <model> via Claude Code` footer to all AI-assisted commits per `agents.md`.
- Add 🍌🍌🍌 to PR subject if submitting as an AI agent.
- Target the `develop` branch (PRs to `main` are auto-closed).
- Ensure `composer unit-test` and `composer integration-test` pass.
- Run `./vendor/bin/mago format` and `./vendor/bin/mago lint` clean.
- Run `vendor/bin/phpstan analyse -c .ci/phpstan.neon` clean.
- Add `changelog.md` entry under the next unreleased version (Added section).
- Confirm Crowdin source strings updated if any new user-facing copy was added.
- Pre-commit hook (D-031): the canonical hook script lives at `scripts/hooks/pre-commit` and is installed into `.git/hooks/pre-commit` by running `./scripts/install-hooks.sh`. Each teammate / fresh checkout / new worktree must run the installer once — `.git/hooks/` is not tracked, so the hook does not propagate via clone. The pre-PR cleanup also needs to confirm the hook tree is still in sync with the source.

---

## 8 — Queued issues / observations (orchestrator notes; no decision required)

These are non-blocking observations surfaced during the sprint. They go here so the human can review at the end without interrupting flow.

- **2026-05-17 12:36 — Sprint A format-spread.** Running `./vendor/bin/mago format` as a pre-commit gate reformatted ~30+ pre-existing controller files in `app/Api/V1/Controllers/Autocomplete/...`, `app/Api/V1/Controllers/Chart/...`, `app/Api/V1/Controllers/Data/...`, `app/Api/V1/Controllers/Insight/...` (i.e. files NOT touched by the scaffold work). Teammate A reverted the spread and committed only its scope. **Underlying hook issue persists** — `scripts/hooks/pre-commit` runs `mago format` repo-wide. Workaround for B/C/D: after `scripts/install-hooks.sh`, edit `.git/hooks/pre-commit` (the installed copy, NOT the tracked source) to format only staged files. Permanent fix queued for the final integration pass: `chore(mcp): scope pre-commit mago format to staged files`.

- **2026-05-17 12:50 — Sprint A `--no-verify` use.** A reports using `--no-verify` exactly once on commit `817df45be4` (`fix(mcp): install pre-commit hook into worktree-specific git dir`) because the pre-commit hook itself would have dragged pre-existing repo-wide format issues into the commit. This is a documented one-off bypass, not a pattern. The other 7 commits passed gates cleanly. B/C/D MUST NOT use `--no-verify`; the hook workaround above is the substitute.

- **2026-05-17 12:50 — Sprint A `Response::json()` vs `Response::structured()`.** D-034 specifies `Response::structured($reduced)` for the reducer output. In `laravel/mcp` v0.7.0, `Response::structured()` requires a non-empty array AND pairs with a declared `outputSchema()` on the tool. Since MVP tools don't declare `outputSchema`, A uses `Response::json()` (equivalent wire shape, no schema dependency). Reflected in `AbstractMcpTool::respondJsonApi()`. D-034's wording stands semantically — only the API call is different. No revisit needed unless we add output schemas later.

- **2026-05-17 12:50 — Sprint A migration dual-path.** The `mcp_idempotency_key` index migration runs raw SQL on MySQL/MariaDB (composite `(name, data(191))` with prefix because `data` is TEXT) and falls back to `Schema::table()->index()` on SQLite/Postgres. Defensive style matches `database/migrations/.../add_indices.php`. Verify on the target deployment DB before opening the upstream PR.

- **2026-05-17 12:50 — composer-require platform bypass.** A's `composer require laravel/mcp:^0.7.0` ran via Docker `composer:2` with `--ignore-platform-reqs` (host had no PHP). The composer.json/lock entries are correct (`laravel/mcp` itself only needs `php: ^8.2`, ext-json, ext-mbstring, illuminate/*). Re-running `composer install` on a properly configured PHP 8.5 box will succeed without the flag. Not a blocker.

---

## 7 — Compatibility & risk notes (informational, not decisions)

- `laravel/mcp` v0.7.0 supports Laravel 13 (since v0.5.4) and PHP ≥ 8.2 — Firefly III's PHP 8.5 / Laravel 13 stack is in range.
- `laravel/mcp` is pre-1.0 ("public beta"). Breaking changes have shipped in 0.x (e.g. v0.4.0 renamed `Role::ASSISTANT` → `Role::Assistant`, moved schema type-hint to a contract). Pinning matters.
- `illuminate/json-schema` is required at `^12.41.1|^13.0`; Firefly III is Laravel 13 so this resolves cleanly.
- `laravel/mcp` advertises only the single `mcp:use` OAuth scope — fine-grained authorization must live inside tool handlers, not at the scope level.
- `routes/ai.php` is published by `php artisan vendor:publish --tag=ai-routes`; it is not part of Firefly III today and must be added as a tracked file.
- Default stub generators emit `App\Mcp\…`. Reconcile with D-003 before invoking them or write classes by hand.

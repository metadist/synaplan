# 05 — Anthropic-Compatible Messages API (Claude Code & agent CLIs)

> **Status:** Plan / research. No code changed yet. This document answers
> *"how do we point the Claude Code CLI at a Synaplan instance, and can the same
> endpoint serve other models (GPT, Gemini) too?"*
>
> **Scope reviewed:** `backend/src/Controller/OpenAICompatibleController.php`,
> `backend/src/Security/ApiKeyAuthenticator.php`,
> `backend/config/packages/security.yaml`, `backend/src/AI/Provider/*`,
> `backend/src/AI/Interface/ChatProviderInterface.php`,
> `backend/src/AI/Service/{AiFacade,ProviderRegistry}.php`,
> `backend/src/AI/Credential/*`, `backend/src/Service/RateLimitService.php`,
> `backend/src/Service/Mcp/*`, `backend/src/Mcp/McpServerFactory.php`,
> `backend/src/Service/RAG/VectorSearchService.php`,
> `backend/src/Service/UserMemoryService.php`,
> `backend/src/Service/Message/Handler/ChatHandler.php`,
> `backend/src/Seed/*`, `_docker/backend/Caddyfile`, `cloudflare/src/worker.ts`,
> `frontend/src/components/config/McpServersConfiguration.vue`, and
> `synaplan-docs/`.
>
> **Siblings:** [01-OPENAI-COMPATIBLE.md](./01-OPENAI-COMPATIBLE.md) (the other
> vendor-compatible surface),
> [../02-mcp-integration/02-MCP-CLIENT-ENRICHMENT.md](../02-mcp-integration/02-MCP-CLIENT-ENRICHMENT.md),
> [../../local-agent-client-research.md](../../local-agent-client-research.md)
> (shares the unenforced-API-key-scope blocker).
>
> **Primary sources verified against** (second-pass review, 2026-08):
> Anthropic's official
> [gateway protocol reference](https://code.claude.com/docs/en/llm-gateway-protocol)
> (the exact contract Claude Code expects from a gateway — §5.0 below),
> Anthropic prompt-caching semantics (prefix order, pricing multipliers), and
> the current Gemini function-calling schema surface. Corrections from that
> pass are folded in below and summarized in §14.

---

## TL;DR

Claude Code cannot talk to Synaplan today, for two independent reasons:

1. **Wrong protocol.** Synaplan speaks the **OpenAI** wire format
   (`POST /v1/chat/completions`). Claude Code speaks the **Anthropic Messages
   API** (`POST /v1/messages`) and is redirected with `ANTHROPIC_BASE_URL`.
   Different request shape, different SSE event names, different error envelope.
2. **No tool calling anywhere in the stack.** Claude Code is an agent; without
   tool use it is inert. `ChatProviderInterface` has no `tools` parameter and its
   return shape is text-only. **`AnthropicProvider`'s class docblock claims
   "Tool use (function calling)" and that is false** — its request body has no
   `tools` key and its SSE parser handles only `text_delta` / `thinking_delta`.

The good news is that the two hardest-looking prerequisites are already solved:

- **Auth needs zero changes.** Claude Code sends `x-api-key` (or
  `Authorization: Bearer` when `ANTHROPIC_AUTH_TOKEN` is used). Both are already
  accepted by `ApiKeyAuthenticator::supports()`, and `^/v1` is already a
  stateless firewall with `IS_AUTHENTICATED_FULLY` in `security.yaml`.
- **MCP already gives us a universal tool layer.** MCP tool schemas are JSON
  Schema, which is exactly what Anthropic `tools[].input_schema`, OpenAI
  `function.parameters` and Gemini `functionDeclarations[].parameters` all
  consume. `McpToolRegistry::catalogForUser()` already returns a user's whole
  tool catalog **with schemas**, and `McpClient::callTool()` already executes
  them. So tool support is written **once**, not once per provider.

**The single genuinely missing primitive is an agentic tool loop.** Confirmed
absent across `Service/Multitask/`, `MessageProcessor`, `ChatHandler` and every
provider: nothing calls an LLM, inspects the response for a tool request,
executes it, appends the result, and calls the LLM again. `TaskPlanner` plans
once and executes fixed DAG nodes; it never re-prompts with tool output.

---

## 1. Goal

```bash
export ANTHROPIC_BASE_URL="https://web.synaplan.com"
export ANTHROPIC_API_KEY="sk_my_synaplan_key"   # or ANTHROPIC_AUTH_TOKEN — set exactly one
claude
```

(`ANTHROPIC_API_KEY` arrives as `x-api-key`, `ANTHROPIC_AUTH_TOKEN` as
`Authorization: Bearer`; `ApiKeyAuthenticator` accepts both on `/v1/*`.
Setting both is a documented Claude Code foot-gun — the docs page must say
"exactly one".)

…and, with a model alias configured, the same CLI driving a GPT or Gemini model
through Synaplan, with usage metered against the user's Synaplan budget.

---

## 2. What already exists

### 2.1 Already in place — reuse, do not rebuild

| What | Where |
| ---- | ----- |
| `x-api-key` header auth | `ApiKeyAuthenticator::supports()` lines 40-46 |
| `Authorization: Bearer` on `/v1/*` (any token shape) | same, lines 55-59 |
| `^/v1` stateless firewall + `IS_AUTHENTICATED_FULLY` | `security.yaml` lines 18-25, 64 |
| Attribute route auto-registration under `/v1/` | `config/routes.yaml` |
| Caddy routes `/v1/*` to PHP | `Caddyfile` lines 182-193 |
| Anthropic HTTP client + key resolution | `AnthropicProvider`, `ProviderKeyStore` |
| Raw upstream SSE relay idiom | `McpController::toStreamedResponse()` lines 170-203 |
| Anthropic cache-token accounting | `AnthropicProvider` lines 236-247 |
| Cost budget with markup + top-ups | `RateLimitService::checkCostBudget()` lines 454-508 |
| Per-user MCP server registry (encrypted token) | `BMCPSERVERS`, `McpServerConfig` |
| MCP tool discovery **with `inputSchema`** | `McpToolRegistry::catalogForUser()` |
| MCP remote tool **execution** | `McpClient::callTool()` |
| MCP server CRUD + connection test | `/api/v1/mcp-servers` (6 routes) |
| MCP settings UI, 39 keys × 4 locales | `McpServersConfiguration.vue`, `/channels/mcp` |
| RAG + memory retrieval services | `VectorSearchService`, `UserMemoryService` |

### 2.2 Missing — the actual work

| What | Notes |
| ---- | ----- |
| `POST /v1/messages` | Arrives as `/v1/messages?beta=true` — match on path (§5.0) |
| `POST /v1/messages/count_tokens` | Officially optional; Claude Code estimates locally without it (§5.0) |
| Anthropic-shaped SSE synthesis | Only needed once the tool loop is active (§6.2) |
| Provider tool calling (any provider) | See §3 |
| Agentic tool loop | See §3 — the one genuinely new primitive |
| Per-user BYO provider keys (except Higgsfield) | `ProviderKeyStore` is operator-only (`ownerId=0`) |
| Usage/limits on outbound MCP calls | Zero `RateLimitService` integration today |
| Reusable RAG/memory prompt formatter | Inlined in `ChatHandler`, duplicated in `ChatRunner` |

---

## 3. The tool-calling gap, precisely

Grepped the whole backend for `tool_calls`, `tools`, `function_call`,
`functionDeclarations`, `tool_use`, `tool_choice`, `functionCall`,
`parallel_tool_calls`. Findings:

- **`HuggingFaceProvider`** is the only provider that will *forward*
  `tools` / `tool_choice` upstream (`FORWARDABLE_CHAT_OPTIONS`, lines 100-101) —
  but it **discards tool calls on the return path** in both `chat()` (line 203)
  and streaming (lines 812-816). Forward-only is useless.
- **`OpenAIProvider`** uses the **Responses API** for chat and passes no `tools`
  (its one `tools` usage is `image_generation`, line 1139).
- **`GoogleProvider`** has no `functionDeclarations`; it also drops the system
  role (maps `system` → `user`) and skips non-`data:` image URLs.
- **`AnthropicProvider`** hits the real `/v1/messages` endpoint but sends no
  `tools` and parses no `tool_use`. **Its docblock is wrong and should be fixed.**
- `tools:*` hits in `PromptCatalog` / `MessageSorter` are the internal prompt
  topic namespace, unrelated. `ModelCatalog`'s `'features' => ['tool_use']` is
  metadata no code reads.
- `StreamChunk` supports only `content`, `reasoning`, `finish`. **No tool chunk
  type**, and `visibleText()` actively filters everything except `content`.

**Conclusion:** we cannot route this through `AiFacade`. A standalone protocol
layer is required. But because of MCP (§5) the *tool* half is shared across
providers, so each translator only does protocol work.

---

## 4. Decisions already taken

Recorded so a reviewer can challenge them rather than re-derive them:

- **Key ownership: BYO with operator fallback.** Per-user encrypted key if
  present, else the operator key — but `ALLOW_OPERATOR_KEY` defaults to **off**.
  Claude Code burns tokens at a scale unlike chat; an always-on operator
  fallback means any API-key holder can drain the operator's Anthropic budget.
- **Context injection: session-stable, cache-safe** (see §7). Rejected
  per-request injection on cost grounds.
- **Scope: full.** Endpoint + `count_tokens` + feature flag + BYO keys +
  metering + frontend UI + Caddy/Cloudflare SSE config + `synaplan-docs` page,
  with rate-limit and budget reporting surfaced to the client including a 90%
  warning.
- **Multi-provider via a translator seam**, with MCP as the shared tool layer.

---

## 5. Design

### 5.0 The official gateway contract (verified)

Anthropic publishes the exact contract Claude Code expects from a gateway:
the [gateway protocol reference](https://code.claude.com/docs/en/llm-gateway-protocol).
It confirms this plan's shape and adds hard requirements the first draft
missed. Everything below is from that document, not inference:

**Endpoints and traffic**

- Inference posts to **`/v1/messages?beta=true`** — route-match on the *path*,
  not the full URL (Symfony routing ignores the query string, so no change
  needed, but tests must use the real URL).
- `POST /v1/messages/count_tokens` is **explicitly optional**: "when absent,
  Claude Code estimates context usage locally." This *resolves* the former
  open question — proxy it for Anthropic models, return 404 for everything
  else, and Claude Code degrades gracefully on its own.
- Startup traffic: a best-effort `HEAD /` connectivity probe (safe to let the
  frontend answer), and — only when the developer sets
  `CLAUDE_CODE_ENABLE_GATEWAY_MODEL_DISCOVERY=1` — a
  `GET /v1/models?limit=1000` with a 3-second timeout that treats **any
  redirect as failure**. It reads `id` and optional `display_name` from the
  response's `data` array, which the existing OpenAI-shaped `/v1/models`
  already provides — so **model discovery works against the existing endpoint
  with zero changes**. Caveat: the picker keeps only IDs containing `claude`
  or `anthropic` (case-insensitive), so GPT/Gemini aliases won't appear in
  `/model` unless named accordingly; users select them via `ANTHROPIC_MODEL`
  instead. Document this.

**Headers**

- Forward `anthropic-version` and `anthropic-beta` upstream **verbatim, as
  open lists** — "don't allowlist individual values, because the set changes
  with Claude Code releases." Same for unknown *body* fields
  (`context_management`, `output_config`, `tools[].strict`, …): beta headers
  pair with body fields, and stripping one half of a pair produces hard 400s.
  This is the strongest argument for the raw-body fast path in §5.2.
- Credential arrives in `x-api-key` (from `ANTHROPIC_API_KEY`) or
  `Authorization: Bearer` (from `ANTHROPIC_AUTH_TOKEN`) — both already
  accepted by `ApiKeyAuthenticator`. We replace it with the resolved upstream
  key and never forward the Synaplan key.
- **`x-claude-code-session-id`** uniquely identifies a Claude Code session
  ("use it to aggregate all requests from one session without parsing request
  bodies"), with `x-claude-code-agent-id` for subagents. This is a strictly
  better session key than the §7 content fingerprint — use the header when
  present, fall back to the fingerprint for plain SDK callers.

**Streaming**

- "Inference responses must stream … a gateway that buffers complete
  responses stalls the client" — confirms the Caddy `@not_streaming` fix is a
  correctness requirement, not an optimization.
- **Claude Code runs a byte-level watchdog: it aborts a stream silent for
  300 s.** Upstream SSE `ping` events are the only traffic during long
  thinking pauses, so the relay must forward pings and comment lines
  byte-for-byte (the raw relay does this for free). Consequence for Phase 2:
  while `McpToolLoop` executes a server-side tool between upstream calls,
  **we must emit our own `ping` events** or long tool executions kill the
  stream.
- Claude Code's automatic error recovery (e.g. after `thinking`-field
  rejections) **matches on the upstream's error wording** — so upstream error
  bodies must be forwarded unmodified, never wrapped in a Synaplan envelope.
  Only errors we originate (auth, budget, model resolution) use our own
  Anthropic-shaped payloads.

**System prompt attribution block**

Claude Code prepends an attribution block as the **first `system` array
entry**; `api.anthropic.com` strips it positionally, but *only* when the
`system` array arrives unchanged with the block first. Prepending, reordering
or string-merging `system` defeats the strip and leaks the block into the
prompt and cache key. This independently confirms §7's design rule: context
injection may only **append** trailing blocks, never prepend or restructure.

**Model aliases receive current-model fields**

Claude Code "treats model names it doesn't recognize, such as gateway
aliases, as current models" — aliased requests carry
`thinking: {"type":"adaptive"}` and current beta fields. The Anthropic
passthrough forwards these untouched; the GPT/Gemini translators (phases
5-6) must map or strip them explicitly or the upstream 400s.

**Official caveat for phases 5-6:** Anthropic "doesn't support routing Claude
Code to non-Claude models through any gateway." It works technically; it is
explicitly unsupported territory.

### 5.1 Component design

```mermaid
flowchart TD
    CC["Anthropic-protocol client"] -->|"POST /v1/messages, x-api-key"| Ctrl[MessagesApiController]
    Ctrl --> GW[MessagesGateway]
    GW --> Res[MessagesModelResolver]
    GW --> Cred[UserProviderKeyResolver]
    GW --> Budget[RateLimitService]
    GW --> Loop[McpToolLoop]
    Loop --> Cat["McpToolRegistry::catalogForUser"]
    Loop --> Exec["McpClient::callTool"]
    GW --> T{"resolved service"}
    T -->|anthropic| PT[AnthropicPassthroughTranslator]
    T -->|openai| OT[OpenAiMessagesTranslator]
    T -->|google| GT[GeminiMessagesTranslator]
    PT --> API["api.anthropic.com/v1/messages"]
    OT --> OAPI["OpenAI Chat Completions"]
    GT --> GAPI["Gemini generateContent"]
```

New namespace `App\AI\Messages`:

- `MessagesRequest` / `MessagesResponse` DTOs.
- `MessagesTranslatorInterface` — `supports(string $providerName): bool`,
  `complete(...): array`, `stream(..., callable $emit): MessagesUsage`. `$emit`
  always receives **already-Anthropic-shaped** events, so nothing above the
  translator branches per provider.
- `AnthropicPassthroughTranslator` — forwards the body **verbatim**, relaying raw
  SSE bytes while teeing them for usage. Verbatim forwarding is what buys tools,
  vision, prompt caching, extended thinking, and every future beta for free.
- `MessagesModelResolver` — see §5.3.
- `MessagesApiController` — thin per `AGENTS.md`, full OpenAPI annotations, and
  **Anthropic-shaped errors** `{"type":"error","error":{...}}`, deliberately
  different from `OpenAICompatibleController::openAiError()`.

Leave the OpenAI-shaped `GET /v1/models` alone — §5.0 shows Claude Code's
model discovery already consumes it (`data[].id`). Optionally add
`display_name` to entries; no shape change needed.

### 5.2 Passthrough body and header policy

Two request paths, chosen per request:

- **Raw fast path** (no alias substitution, no context injection, no tool
  injection): forward the request body **bytes** unchanged. This is the only
  path that is future-proof against new beta body fields (§5.0 "open lists")
  and guarantees the attribution-block strip and prompt-cache behaviour are
  byte-identical to a direct connection.
- **Decode-modify-encode path** (an alias rewrote `model`, or §6/§7 injection
  applies): parse to associative arrays, mutate only the specific keys, and
  re-encode. All unknown fields pass through untouched. JSON re-encoding is
  cache-safe — the cache key hashes the *rendered prompt*, not the raw JSON —
  but unknown-field preservation is mandatory (beta header/body pairing).

Header policy, per the §5.0 contract:

- Replace `x-api-key` / `Authorization` with the resolved upstream key.
- Forward `anthropic-version` and `anthropic-beta` verbatim; never allowlist.
- Consume (don't forward) `x-claude-code-session-id` / `-agent-id` — used for
  session-keyed budget notices (§8) and per-session context stability (§7).
- Strip hop-by-hop headers; set our own `content-length` after mutation.
- Response side: pass through `request-id` and Anthropic's
  `anthropic-ratelimit-*` headers when the upstream key is the user's own
  BYO key; suppress them when the operator key served the request (they leak
  the operator's account-level limits) and substitute Synaplan's own values.

### 5.3 Model resolution — must fail closed

Resolves against `BMODELS` (`providerId`, then `name`), mirroring
`OpenAICompatibleController::resolveModel()`, plus a **prefix/wildcard alias
map** (`MODEL_ALIASES` config) so Claude Code's hardcoded dated model IDs and
its small/fast background model can be redirected to any Synaplan model.

Two details the first draft glossed over:

- **Dated IDs.** Claude Code sends dated IDs (`claude-sonnet-4-6`,
  `claude-haiku-4-5-20251001`-style) that may not exist verbatim in the
  catalog. Resolution order: exact `providerId` → exact `name` → alias map →
  **normalized match** (strip a trailing `-YYYYMMDD` and retry) → fail.
- **Unknown models must 404, not pass through.** Cost attribution requires a
  `BMODELS` row: `CostCalculationService::calculateCost()` returns zero cost
  for an unknown `model_id`, which would silently disable budget enforcement
  exactly where Claude Code burns the most tokens. The 404 error message
  lists the resolvable Anthropic-service model IDs so the fix is self-evident
  (`/model` picker or `MODEL_ALIASES`).

### 5.4 Credentials

`UserProviderKeyResolver::resolve(string $provider, ?int $userId): ?array{key, source}`
— per-user encrypted `BCONFIG` rows via `ConfigRepository` + `EncryptionService`,
falling back to `ProviderKeyStore::getKey($provider)`. A direct generalization of
`HiggsfieldCredentialResolver`, so one class serves anthropic, openai and google.

### 5.5 Config (`BCONFIG` group `MESSAGES_GATEWAY`)

Resolution order per-user → global (`ownerId=0`) → code default, modelled on
`MultitaskRoutingConfig`:

- `ENABLED` (`0`), `ALLOW_OPERATOR_KEY` (`0`)
- `MCP_TOOLS_ENABLED` (`0`), `MCP_TOOLS_WITH_CLIENT_TOOLS` (`0`),
  `MCP_MAX_ITERATIONS` (`8`)
- `CONTEXT_INJECTION_ENABLED` (`0`), `BUDGET_NOTICE_ENABLED` (`1`)
- `MODEL_ALIASES` (JSON)

Plus the upstream endpoint, **stored in the database, set by the admin**
(product decision 2026-08, revising an earlier env-only draft):

- `UPSTREAM_URL` (default `https://api.anthropic.com`) — a **global-only**
  BCONFIG row (`ownerId=0`, never per-user), editable in the admin area.
  Resolution order: BCONFIG global → env `MESSAGES_GATEWAY_UPSTREAM_URL`
  (dev/test fallback, keeps the §10.1 fixture harness working with zero DB
  fiddling) → code default.
- **Why it needs guardrails:** this URL is where the gateway sends the
  resolved Anthropic keys. A UI-flippable upstream is a key-exfiltration
  vector for anyone with admin access. Mitigations, all mandatory:
  - Settable by **admin role only**, and only as the global row — the
    per-user config path must reject this key.
  - Validated on save: HTTPS required (plain `http://` allowed only for
    loopback/RFC-1918 dev hosts), checked through the existing `SsrfGuard`,
    no credentials in the URL.
  - Every change writes an audit log line (old → new, acting user), and the
    admin UI shows a prominent warning that all provider keys will be sent
    to the configured host.
  - The value is displayed on the gateway status page (§ Phase 4) so a
    tampered upstream is visible, not silent.

New `MessagesGatewayConfigSeeder` via `BConfigSeeder::insertIfMissing()`,
registered in the constructor **and** `$steps` of `SeedAllCommand` (lines 56-117).
Because BCONFIG defaults are bootstrap-only, existing installs need a
Galera-safe migration using raw `addSql()` + `INSERT IGNORE` — no
`Schema $schema` introspection.

---

## 6. MCP as the universal tool layer

`McpToolCatalogAdapter` maps `McpToolRegistry::catalogForUser($userId)` to
Anthropic `tools[]`; `inputSchema` passes through essentially unchanged.

Names are namespaced **`mcp__{serverId}__{tool}`** (the convention Claude Code
itself uses), because MCP tool names are unique only within a server. Anthropic
constrains tool names to `^[a-zA-Z0-9_-]{1,128}$`, so sanitise and clamp, keeping
a reverse map for dispatch.

`McpToolLoop` — the missing primitive:

1. Append our namespaced tools.
2. Call the provider via the resolved translator.
3. On `stop_reason === 'tool_use'`, partition `tool_use` blocks into **ours**
   (`mcp__` prefix) and **the client's**.
4. All ours → execute via `McpClient::callTool()`, append a `user` turn of
   `tool_result` blocks, go to 2.
5. Any of the client's → return the turn verbatim; the client owns its own loop.

Bounded by `MCP_MAX_ITERATIONS`, a wall-clock ceiling, and a per-turn tool-call
cap. Each iteration is a billable LLM round-trip and meters separately.

Two requirements the first draft missed:

- **Injected tools sit at position 0 of the cache prefix.** Anthropic renders
  the prompt `tools → system → messages` before hashing, so a tool-list
  change invalidates *everything* — worse than a system change. The injected
  catalog must therefore be **deterministic and session-stable**: sort by
  namespaced name, serialize stably, and pin the catalog snapshot per session
  (same session key as §7/§8) rather than re-reading `McpToolRegistry`'s
  300-second cache mid-session, where a registry refresh could reorder or
  alter the list between turns.
- **Keep-alive pings during tool execution.** Claude Code aborts any stream
  silent for 300 s (§5.0). Between upstream calls — while `callTool()` runs —
  the loop must emit SSE `ping` events itself.

### 6.1 The mixed-turn hazard (why the default is off)

Anthropic requires **every** `tool_use` block in a turn to be answered by a
`tool_result` in the next turn. A turn that mixes our tools with the client's
cannot be split without violating the protocol. Since Claude Code always sends
its own tools, `MCP_TOOLS_WITH_CLIENT_TOOLS` defaults to `0`: when the client
supplies `tools`, we do not inject.

*Alternative examined and rejected:* strip our `tool_use` blocks from the
returned turn, execute them server-side, and on the client's *next* request
splice our blocks and results back into the history before forwarding. It is
protocol-legal but makes the gateway stateful (client history and provider
history permanently diverge, keyed on session), breaks irrecoverably on any
splice mistake, and silently corrupts sessions that resume after cache
expiry. Not worth it for a default-off feature; recorded here so it isn't
re-proposed.

**So server-side injection does not help Claude Code.** For Claude Code, native
MCP is better and works today with no backend change at all:

```bash
claude mcp add --transport http synaplan https://web.synaplan.com/mcp \
  --header "Authorization: Bearer sk_your_synaplan_key"
```

Injection earns its keep with plain SDK callers, the GPT/Gemini translators, and
later Synaplan's own chat pipeline.

### 6.2 Streaming consequence

A mid-stream tool round-trip means one client-visible message spans N upstream
calls, so the pure relay **cannot** be used while the loop is active.
`MessagesEventEmitter` (monotonic `content_block` index space, suppressed
intermediate `message_stop`) is therefore needed in the MCP phase, not only in
the translator phases. Keep the pure relay as the fast path when the loop is off.

### 6.3 Safety and the metering gap

- Keep `McpFetchRunner`'s read-only posture as default (reject
  `readOnlyHint === false` / `destructiveHint === true`); add an explicit
  **per-server allow-mutating opt-in** rather than loosening globally.
- Cap `tool_result` content (mirror `McpFetchRunner`'s 12 000-char truncation) on
  top of `McpClient`'s existing 512 KiB body cap.
- **Close the metering gap:** outbound MCP calls check no limits and record no
  usage today. Add `checkLimit` + `recordUsage` with a new `source: 'MCP_TOOL'`.
- SSRF is already handled inside `McpClient` via `SsrfGuard`.

---

## 7. Context injection must be cache-safe

The caching mechanics were re-verified against Anthropic's documentation and
the design holds; the exact facts, since the whole section depends on them:

- The cache key is a **byte-exact prefix hash of the rendered prompt** in the
  fixed order `tools → system → messages`, evaluated at each of up to four
  `cache_control` breakpoints. Cache reads bill at **0.1x** input price,
  writes at 1.25x (5-min TTL, refreshed on every read) or 2x (1-hour TTL).
- **Content after a breakpoint does not invalidate that breakpoint.** This is
  what makes the trailing-block design sound: Claude Code's breakpoint on its
  own last system block keeps caching its tools+system across sessions even
  with our block appended after it, and its message-level breakpoints keep
  hitting as long as our appended block is byte-identical across the session.
- A per-turn-changing system block therefore invalidates every message-level
  breakpoint each turn: most input re-bills at 1x (plus 1.25x re-write)
  instead of 0.1x — the **5-10x** figure on long sessions, paid by whichever
  key §4 selected.

`MessagesContextInjector`:

- **Session key** = `x-claude-code-session-id` when present (§5.0 — exactly
  its documented purpose), else SHA-256 of the first user turn + user id for
  plain SDK callers. The injected block is computed once per session and
  replayed byte-identically on every subsequent turn.
- Cached in `CacheItemPoolInterface` (~2 h). On miss, embed once via
  `UserMemoryService::embedUserQuery()`, then
  `VectorSearchService::semanticSearchByVector()` and
  `UserMemoryService::searchMemoriesByVector()` — reusing `ChatHandler`'s
  shared-vector trick.
- **Appended as a trailing `system` content block**, after all client blocks.
  Never prepend, reorder, or merge: besides cache safety, Claude Code's
  attribution block must stay the *first* system entry or `api.anthropic.com`
  stops stripping it (§5.0).
- Deterministic serialization: stable ordering of retrieved chunks (sort by
  id, not score ties), no timestamps, no random IDs — any nondeterminism
  silently zeroes the cache hit rate.
- Opt-in per request via `X-Synaplan-Context: on|off`.
- **Hard character budget (~8000)**, matching `appendPluginContext`. RAG and
  memory context have *no* truncation anywhere today, which is unsafe at Claude
  Code's payload sizes.

Trade-off to document: context derives from the first turn only. That is the
price of cache safety, and it is why MCP stays the route for fresh mid-session
retrieval.

Refactor: extract the wrapper wording from `ChatHandler`'s private
`loadRagContext()` / `loadMemoriesContext()` into a shared
`KnowledgeContextFormatter` — it is inlined today and already duplicated in
`ChatRunner::ragContext()`, so this removes a third copy.

---

## 8. Reporting limits and budget back to the client

The Anthropic protocol has **no warning channel**, so three layers:

1. **Headers on every response:** `anthropic-ratelimit-requests-remaining`,
   `anthropic-ratelimit-tokens-remaining`, `retry-after` on 429, plus
   `x-synaplan-budget-percent` / `-remaining` / `-currency`.
   `checkCostBudget()` already returns `percent`, `remaining`, `budget`,
   `used_cost`. (When the upstream key is the user's own, relay Anthropic's
   real `anthropic-ratelimit-*` headers instead — §5.2.)
2. **At >= 90% budget, a one-time visible notice.** Headers are invisible in
   Claude Code's UI, so emit an extra text content block
   (`Synaplan: 92% of your monthly budget used (4.60 of 5.00).`). Mechanics,
   since "append a block" is nontrivial mid-relay:
   - Streaming: the relay already parses event boundaries to tee usage. On a
     qualifying response, splice a synthetic
     `content_block_start`/`content_block_delta`/`content_block_stop` triplet
     **immediately before the upstream's final `message_delta`** (the first
     event guaranteed to follow all content blocks), using index =
     highest-seen block index + 1. Non-streaming: append to the `content`
     array.
   - Only when `stop_reason` is `end_turn` — never inside a `tool_use` turn,
     where Claude Code is mid-loop and the text would pollute tool handling.
   - At most once per session, keyed on `x-claude-code-session-id` (fallback:
     §7 fingerprint) in the cache pool.
   - Skipped entirely for unlimited budgets (`checkCostBudget()` returns
     `budget <= 0` as allowed/percent 0).
3. **At 100%, a hard 429** with `type: rate_limit_error` and an actionable
   message; Claude Code surfaces API errors directly to the user and honours
   `retry-after`.

Metering: `recordUsage($user, 'API_CHAT', [...])` with `source: 'MESSAGES_API'`
and the resolved `model_id`. **Verified:** `CostCalculationService` is already
cache-aware and provider-aware — `calculateCost(promptTokens,
completionTokens, cachedTokens, cacheCreationTokens, modelId, timestamp,
cacheCreation1hTokens)` applies Anthropic's real 0.10x read discount and
1.25x/2.0x write multipliers (`CACHE_READ_DISCOUNT_ANTHROPIC` /
`CACHE_WRITE_MULTIPLIER_ANTHROPIC` / `CACHE_WRITE_MULTIPLIER_ANTHROPIC_1H`).
The gateway only maps fields: `cachedTokens = cache_read_input_tokens`,
`cacheCreationTokens = cache_creation_input_tokens`, `promptTokens =
input_tokens + both cache fields` — the same arithmetic `AnthropicProvider`
lines 236-247 already uses.
**Fixed 2026-09-02:** the 1-hour-TTL gap noted below was real — 1h-TTL cache
writes bill 2x but were costed at the flat 1.25x 5m rate. Fixed by reading the
usage payload's `cache_creation.ephemeral_1h_input_tokens` breakdown via the
new `MessagesUsage::extractCacheCreation1hTokens()` helper (shared by every
Anthropic usage-parsing call site: `AnthropicProvider`, both
`AnthropicPassthroughTranslator` stream paths, `GatewayToolLoop`) and billing
that slice at `CACHE_WRITE_MULTIPLIER_ANTHROPIC_1H` (2.0x) while the remainder
stays at 1.25x. See `docs/PRICING_MAINTENANCE.md` ("Cache-write pricing:
5-minute vs. 1-hour TTL").

**Known limitation:** usage is recorded after the stream ends, so concurrent
requests can overshoot the budget slightly. Either accept and document, or add a
cheap pre-flight reservation.

---

## 9. Infrastructure

- `_docker/backend/Caddyfile` lines 24-27: `/v1/messages` must join the
  `@not_streaming` matcher, or `encode zstd gzip` buffers the SSE stream. Today
  it excludes only `/api/v1/messages/stream` and `/api/v1/widget/*/message`.
  (Docker config change — "ask first" per `AGENTS.md`.)
- `cloudflare/src/worker.ts` lines 16-18: add `/v1/messages` to
  `SSE_PATH_PREFIXES` (its `Accept: text/event-stream` short-circuit may already
  cover it — verify).
- Timeouts: prod `max_execution_time` is 300 s, `default_socket_timeout` 60 s.
  Set an explicit long outbound `timeout` and **re-arm `set_time_limit(0)` per
  SSE loop iteration** — the codebase already documents that a one-time call is
  insufficient under FrankenPHP (comments in `HuggingFaceProvider`,
  `GoogleProvider`).
- **Ping fidelity end to end:** Claude Code's 300 s byte watchdog (§5.0) means
  Caddy and the Cloudflare worker must not strip or coalesce SSE `ping`
  events or comment lines. Raw relay + the `@not_streaming` exclusion covers
  Caddy; verify the worker path with a long-thinking request.
- **Worker-pool pressure (ops note):** every streaming request pins one
  FrankenPHP worker for the full stream duration, and Claude Code multiplies
  requests via parallel subagents. A handful of concurrent heavy sessions can
  occupy a large share of the pool. Document `FRANKENPHP_WORKER_NUM` sizing
  in the ops notes; no code change.
- Production Caddy lives in the private `synaplan-platform` repo pulling
  `ghcr.io/metadist/synaplan:latest`, which embeds this Caddyfile.

---

## 10. Phasing

1. **Gateway + Anthropic passthrough** — protocol layer, model resolver,
   credentials, config/seeder/migration, controller, metering + budget
   reporting, Caddy/Cloudflare. *Independently shippable; makes Claude Code work
   end-to-end.*
2. **MCP tool bridge** — catalog adapter, `McpToolLoop`, `MessagesEventEmitter`,
   safety + the MCP metering gap.
3. **Cache-safe context injection** + `KnowledgeContextFormatter` extraction.
4. **Frontend — under the Channels main menu** (product decision). The gateway
   is a way conversations reach Synaplan, which is exactly the Channels IA
   (`/channels/*` — "ways conversations reach Synaplan (widgets, email, API)",
   router comment §4.6). Concretely:
   - New route `/channels/agents` (name `channels-agents`) in
     `frontend/src/router/index.ts`, rendered through `ConfigView.vue` like
     `channels-mcp` / `channels-api` — new `currentPage` branch mounting a new
     `MessagesGatewayConfiguration.vue` in `components/config/`.
   - New child in `channelsChildren` in `useNavItems.ts` (between
     `mcp-servers` and `api-keys`), plus `nav.*` and `pageTitles.*` keys.
   - Canonical term per the UI-copy rules (one term, all four locales):
     propose **"AI Agents"** (de: *KI-Agenten*, es: *agentes de IA*, tr:
     *yapay zeka ajanları*) — the page connects agent CLIs like Claude Code.
     No implementation jargon ("Messages gateway", "passthrough") in primary
     copy.
   - Page content: gateway status, BYO key save/clear with masked display,
     model-alias editor, live budget display, copy-paste setup snippet
     (`ANTHROPIC_BASE_URL` + key), link to the docs page.
   - Plus the "expose to API gateway" toggle added to the existing
     `McpServersConfiguration.vue` at `/channels/mcp`. New i18n keys in
     **all four** locales; verify light, dark and V2. (Drive-by: sibling
     `TaskPromptsConfiguration.vue` line 1502 hardcodes the English string
     `'MCP Data Sources'`.)
5. **OpenAI translator** — Chat Completions, not the Responses API
   `OpenAIProvider` uses; tool schemas and streaming tool-argument deltas are
   more stable there. Reuses phase 2's emitter and tool layer. Must also
   strip/map Anthropic-only request fields that Claude Code sends to aliases
   (`thinking: {"type":"adaptive"}`, beta body fields — §5.0), or the
   upstream 400s.
6. **Gemini translator** — `functionDeclarations` / `functionCall` /
   `functionResponse`, plus `systemInstruction` and HTTP-image support that
   `GoogleProvider` lacks. *Verified 2026-08:* the Gemini API now accepts raw
   JSON Schema via **`parametersJsonSchema`** (mutually exclusive with the
   legacy OpenAPI-subset `parameters`), including `anyOf`, `additionalProperties`
   and `$defs`-style refs — so MCP `inputSchema` passes through mostly
   unchanged, and the previously planned deep schema-sanitising layer shrinks
   to guardrails (max nesting depth 32, name pattern `[a-zA-Z0-9_]{1,64}`
   for parameter keys). Same `thinking`-field stripping as phase 5.
7. **Documentation** (lands with phase 1, extended per phase):
   - **synaplan-docs**: new `docs/claude-code.md` — setup
     (`ANTHROPIC_BASE_URL` + exactly one credential var), model
     aliases/discovery, budget behaviour, the native-MCP alternative
     (`claude mcp add`), troubleshooting (buffered stream, 401 both-vars,
     model 404). Register in `index.php` `$order`/`$docsMap` and
     `sitemap.php`. Must distinguish Anthropic's `/v1/messages` from
     Synaplan's native `/api/v1/messages`.
   - **Main repo `README.md`** (explicit requirement): a bullet in
     `## Features` (~line 156, alongside WhatsApp/widgets) — e.g.
     "**Claude Code & Anthropic-compatible API** — point Claude Code or any
     Anthropic-protocol client at your instance (`/v1/messages`)" — and a row
     in the `## Documentation` table (~line 262) for the new in-repo doc.
   - In-repo `docs/ANTHROPIC_COMPATIBLE_API.md` (sibling of
     `OPENAI_COMPATIBLE_API.md`) + row in `docs/ADMIN.md` integrations table.
   - Fix `AnthropicProvider`'s false "Tool use (function calling)" docblock.

### 10.1 Incremental verification — smoke scripts per phase

Each phase lands with a runnable check, committed under
`_devextras/testing/messages-gateway/` (dev-extras, not shipped), so the
implementation is exercised end-to-end *while being built* instead of only at
the final PHPUnit gate. All scripts take the Synaplan API key from
`$SYNAPLAN_API_KEY` and target `http://localhost:8000`.

**Shared infrastructure (built first, with Phase 1):**

- `fixture-upstream.php` — a tiny PHP built-in-server script
  (`php -S 127.0.0.1:8099 fixture-upstream.php`) that replays recorded
  Anthropic responses: one non-streaming JSON body, one SSE transcript with
  `ping` events and realistic `message_start`/`content_block_*`/
  `message_delta` sequences (including a `tool_use` turn and a
  `stop_reason: max_tokens` case), plus canned 401/429/529 error bodies.
  Transcripts recorded once from the real API and checked in as fixtures —
  the same files feed the PHPUnit translator tests, so scripts and unit
  tests can never drift apart. Selected via a request header
  (`X-Fixture: tool-use`) so one server covers all cases.
- Backend pointed at it via `MESSAGES_GATEWAY_UPSTREAM_URL=http://host.docker.internal:8099`
  in `backend/.env` — the dev/test *fallback* tier of the §5.5 resolution
  order (BCONFIG global, admin-set, wins in production; env applies when no
  DB row exists, which is the normal dev state). No Anthropic key, no token
  spend.

**Phase 1 — `01-passthrough.sh`:**

- Non-streaming `POST /v1/messages?beta=true` (the real path Claude Code
  uses): assert 200, Anthropic response shape, and the
  `x-synaplan-budget-*` headers present.
- Streaming: `curl -N --no-buffer` piped through `ts` (timestamp each line)
  — asserts events arrive **incrementally**, which is the regression test
  for the Caddy gzip-buffering fix; a buffered stream shows all timestamps
  within the same second.
- Ping fidelity: fixture emits pings 2 s apart; assert they surface.
- `POST /v1/messages/count_tokens` for an Anthropic model (200) and an
  aliased non-Anthropic model (404).
- Unknown model → 404 whose body lists resolvable IDs; missing key → 401 in
  Anthropic error shape; upstream 429 fixture → body relayed **byte-identical**
  (the error-wording contract from §5.0).
- `GET /v1/models?limit=1000` — assert `data[].id` present (discovery shape).
- Optional real-key run: unset the override, set a real Anthropic key,
  re-run the same script; then the live test
  `ANTHROPIC_BASE_URL=http://localhost:8000 claude -p "say hi"`.

**Phase 2 — `02-tool-loop.sh`:** registers **Synaplan's own `/mcp` endpoint**
as the user's external MCP server via `POST /api/v1/mcp-servers` (it is a
real, always-available Streamable-HTTP server exposing `rag_search` /
`memory_search`), enables `MCP_TOOLS_ENABLED`, sends a prompt the fixture
answers with a `tool_use` turn for `mcp__…__rag_search`, and asserts: the
loop executed (BUSELOG rows with `source: MCP_TOOL`), the final stream is
one logical message with monotonic block indices, and pings appeared during
tool execution. A second call with client-supplied `tools` asserts injection
was refused (mixed-turn policy).

**Phase 3 — `03-context-injection.sh`:** two requests with the same
`x-claude-code-session-id`; with a `X-Synaplan-Debug: 1` dev-only response
header exposing the injected block's SHA-256, assert the hashes are
**identical** across turns (cache safety) and differ across session IDs.

**Phase 5/6 — `05-openai.sh` / `06-gemini.sh`:** same request corpus as
Phase 1 against aliased models, asserting the response is Anthropic-shaped,
`thinking`/beta fields were stripped (fixture upstream echoes the request
body it received into a debug field), and `input_json_delta` events appear
for streamed tool arguments.

Each script prints `PASS`/`FAIL` per assertion and exits non-zero on any
failure, so they can run in CI later if wanted — but their primary job is
fast feedback during implementation. They complement, not replace, the
PHPUnit/Vitest suite and the unfiltered pre-commit gate.

---

## 11. Gap analysis

| # | Gap | Severity | Notes |
| - | --- | -------- | ----- |
| 1 | **API key scopes stored but never enforced** — every key is full account access | **Blocker for operator-key mode** | Same blocker as [local-agent-client-research.md](../../local-agent-client-research.md) §3 and `n8n-integration-research.md` §7. Without scopes there is no "gateway-only" key, so a leaked key spends the operator's Anthropic budget *and* owns the account. Keeps `ALLOW_OPERATOR_KEY=0` the only safe default until fixed. |
| 2 | No agentic tool loop anywhere | High | §3, §6 — the core new engineering |
| 3 | No provider tool calling | High | §3 — solved via MCP rather than per provider |
| 4 | No per-user BYO provider keys | Medium | §5.1 — pattern proven by Higgsfield |
| 5 | Outbound MCP calls unmetered and ungated | Medium | §6.3 |
| 6 | `/v1/messages` would be gzip-buffered | Medium | §9 — silent, looks like a hang |
| 7 | RAG/memory context has no truncation | Medium | §7 |
| 8 | 300 s wall clock vs long agent turns | Medium | §9 |
| 9 | Budget enforced only post-stream | Low | §8 |
| 10 | `AnthropicProvider` docblock falsely advertises tool use | Low | Fix in passing |

---

## 12. Open questions

Resolved in the second-pass review (answers now in the body):

- ~~Should `count_tokens` be exact or estimated?~~ The endpoint is officially
  optional — Claude Code estimates locally when it's absent (§5.0). Proxy for
  Anthropic, 404 for other services.
- ~~One shared `/v1` surface or a separate prefix?~~ Shared. Claude Code's own
  model discovery consumes the existing OpenAI-shaped `/v1/models` (§5.0), so
  the shared namespace is now an asset, not a hazard.
- ~~Can the client tolerate our appended system block?~~ Yes, provided we only
  append: the attribution block must remain the first system entry (§5.0/§7).

Still open:

- **Is operator-key mode wanted at all before scopes are enforced (Gap 1)?** If
  the product intent is reselling Claude Code access through Synaplan billing,
  scope enforcement becomes a hard prerequisite rather than a nice-to-have.
- **Does routing Claude Code to GPT/Gemini actually produce acceptable quality?**
  Its system prompt and tool descriptions are tuned for Claude, and Anthropic
  explicitly does not support non-Claude routing through gateways (§5.0).
  Worth a spike before committing to phases 5-6.
- **Should `McpToolLoop` be reused for Synaplan's own chat?** It would give real
  agentic tool use and could replace the plan-once DAG for tool cases —
  strategically attractive, deliberately out of scope here.
- **Mutating MCP tools:** does the per-server allow-mutating opt-in need a
  confirmation UX like `03-MCP-SERVER-PUSH.md` §3.3 sketches for MCP actions?

---

## 13. Bottom line

The endpoint itself is **small**: auth, firewall, routing, the Anthropic HTTP
client, SSE relay idiom, cache-token accounting and budget machinery all already
exist. Phase 1 is a thin, additive translation layer plus config and metering.

The leverage is in **MCP**. Because MCP tool schemas are JSON Schema and
`callTool()` already works, tool support is written once and every provider
inherits it — which turns "add GPT and Gemini" from three tool implementations
into three protocol adapters.

The real risks are **not** in the proxy. They are: unenforced API key scopes
(Gap 1, shared with the local-agent work), the absence of any agentic loop to
build on, and Anthropic's prefix-based prompt caching, which quietly punishes the
obvious implementation of context injection by 5-10x.

---

## 14. Second-pass verification log (2026-08)

The three claims flagged for scrutiny, checked against primary sources:

1. **"Per-request context injection costs 5-10x via cache invalidation" —
   confirmed.** Cache matching is a byte-exact prefix hash in the order
   `tools → system → messages`; reads bill 0.1x, writes 1.25x/2x. A per-turn
   system change re-bills the whole conversation at ~1x instead of 0.1x each
   turn. The trailing-block design is additionally validated by a fact the
   first draft didn't have: content *after* a breakpoint does not invalidate
   it, so the client's own tools+system breakpoint keeps hitting across
   sessions. New corollary: injected MCP *tools* are even more
   cache-sensitive than system text (they render first) — §6.
2. **"Mixed tool_use turns cannot be split" — confirmed at the protocol
   level**, with the stateful splice-back alternative examined and rejected
   for cause (§6.1). Also softened: Claude Code responds to unknown tools
   with `is_error` tool_results rather than crashing, so mixed mode degrades
   rather than breaks — still default-off.
3. **"MCP inputSchema flows into all three providers' tool schemas" —
   confirmed, and simpler than planned for Gemini**, which now accepts raw
   JSON Schema via `parametersJsonSchema` (§10 phase 6).

New primary source found: the official
[gateway protocol reference](https://code.claude.com/docs/en/llm-gateway-protocol)
(§5.0), which resolved three open questions (count_tokens optional; shared
`/v1` namespace fine; `/v1/models` discovery already compatible) and added
five requirements the first draft lacked: forward `anthropic-version`/`-beta`
and unknown body fields as open lists; keep the attribution block first in
`system`; forward SSE pings and emit our own during server-side tool
execution (300 s byte watchdog); forward upstream error bodies unmodified
(retry logic matches on wording); and strip `thinking`/beta fields in the
non-Anthropic translators because aliases are treated as current models.

Internal claims re-checked in code: `CostCalculationService` already applies
Anthropic-correct cache pricing (§8 — the metering step is a field mapping,
not new pricing logic), and `MessagesModelResolver` gained the fail-closed
rule after confirming unknown `model_id`s cost out at zero (§5.3).

---

## 15. Backward-compatibility audit

Compatibility is by construction — everything is additive and default-off —
but "100%" was verified touchpoint by touchpoint rather than asserted:

**Verified safe in code:**

- **Routes:** the only existing `/v1/*` routes are `/v1/chat/completions` and
  `/v1/models` (`OpenAICompatibleController`). `/v1/messages` and
  `/v1/messages/count_tokens` are net-new; Synaplan's native SSE lives at
  `/api/v1/messages/stream` — different prefix, no collision. `security.yaml`
  is untouched (new routes inherit the existing `^/v1` firewall).
- **Caddyfile:** `@not_streaming` is a `not path` list
  (`/api/v1/messages/stream /api/v1/widget/*/message`); appending
  `/v1/messages*` only disables compression for the new paths. No existing
  path's behaviour changes.
- **Usage statistics UI:** `UsageStatistics.vue` handles unknown `source`
  values gracefully — `getSourceLabel()` falls back to the raw string and the
  icon helpers have `default` branches. New `MESSAGES_API` / `MCP_TOOL`
  sources render (unstyled) rather than break; adding
  `config.usage.sources.*` labels is polish, not a compat fix.
- **Feature flag:** `MESSAGES_GATEWAY.ENABLED` defaults `0` via
  `insertIfMissing` seeder + `INSERT IGNORE` migration — the endpoint answers
  403 on every existing install until an operator opts in. No existing
  BCONFIG key is modified.
- **Schema:** the only entity change is one new column on `BMCPSERVERS`
  (expose-to-gateway, default 0) via an idempotent `ADD COLUMN IF NOT EXISTS`
  migration; API responses gain fields only (generated Zod schemas are
  non-strict, so older consumers ignore them).

**Guardrails that must hold during implementation (the honest caveats):**

1. **`KnowledgeContextFormatter` extraction (Phase 3) is the single change
   that touches live chat-pipeline code.** It must be a byte-identical
   string extraction from `ChatHandler::loadRagContext()` /
   `loadMemoriesContext()` (and the `ChatRunner::ragContext()` duplicate) —
   any wording drift changes what existing chats send to models. Guard:
   assert the extracted output against the current literals in a unit test
   before deleting the inlined copies, and confirm the characterization
   snapshots don't drift.
2. **The upstream URL resolves DB → env → code default (§5.5)**, read in PHP
   (ProviderKeyStore-style), *not* as a bare `%env(...)%` container
   reference — a bare reference would make every existing install without
   the variable **fail to boot**. Same rule for any other new env var. The
   admin-set DB value additionally carries the §5.5 exfiltration guardrails
   (admin-only, HTTPS + SsrfGuard validation, audit log, visible on the
   status page).
3. **Mobile release policy (gap found in this audit, now in scope):** the
   private `synaplan-apps` pipeline classifies every changed path via
   `.github/mobile-impact-policy.json`, and **unlisted new paths fail closed
   to `store-required`**. The new frontend files
   (`MessagesGatewayConfiguration.vue`, router/nav edits) must be classified
   `ota-candidate` and the backend/gateway paths `backend-only` in the same
   PR, with `tests/mobile-impact.test.mjs` extended and
   `node scripts/mobile-impact.mjs --base <base> --head <head>` verified —
   otherwise the change is not backward compatible *with the release
   pipeline* even though the runtime is unaffected.
4. **`/v1/models`** stays byte-compatible: no shape change, optionally an
   *added* `display_name` field only.

Residual risk after the guardrails: the Caddyfile/worker edits ship in the
shared prod image — they are additive matchers, but they are the one place a
typo would affect existing traffic, so the Phase 1 smoke script's
incremental-stream check (§10.1) doubles as the regression test for them.

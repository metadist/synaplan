# MCP Integration Roadmap (2026, Authoritative)

> **Status:** Authoritative. This document **supersedes the transport and
> implementation decisions** in [`00-ORIGINAL-ROADMAP.md`](./00-ORIGINAL-ROADMAP.md).
> The original "SSE-only" transport decision is **obsolete** — the MCP spec
> replaced HTTP+SSE with **Streamable HTTP** in protocol revision `2025-03-26`,
> and the current spec (stable `2025-11-25`, release candidate `2026-07-28`) is
> built entirely around Streamable HTTP. The product goals, 3-step framing
> (Expose → Consume → Orchestrate), and security intent from the original
> roadmap still stand and are carried forward here.

## 0. Why MCP (beyond the existing API)

Synaplan already has a complete `/api/v1/*` REST surface plus an
OpenAI-compatible endpoint (`/v1/chat/completions`, `/v1/models`). MCP is **not**
a second API — it is a capability layer that REST cannot express, and the reason
to invest is **distribution**: once Synaplan is a registered remote MCP server,
it is installable in every MCP-capable host (Claude, ChatGPT, Cursor, Gemini,
VS Code, …) with no per-vendor integration work.

| Capability beyond REST | Synaplan mapping |
| :--- | :--- |
| **Tool discovery + self-describing schemas** | Hosts auto-discover what Synaplan can do; no hand-written client glue |
| **Resources** (addressable, subscribable read context) | RAG files, memories, shared chats as `synaplan://…` URIs |
| **Prompts** (reusable parameterized templates) | Task-prompts from `PromptController` become host-selectable prompts |
| **Sampling** (server asks the host's model) | Offload sub-tasks to the caller's LLM ("bring your own model") |
| **Elicitation** (mid-call user input) | Ask for missing args (workspace, file) through the host UI |
| **MCP Apps** (server-rendered UI, RC feature) | Widget config / RAG result browsers rendered in-host |
| **Tasks extension** (standard long-running jobs) | Wrap Messenger jobs (media/video/re-vectorize) instead of bespoke polling |
| **One OAuth, many hosts** | Reuse Keycloak once; works everywhere |

The crown jewel to expose is the **`MessageProcessor` pipeline**
(classify → web search → RAG → inference). A `chat` tool backed by that pipeline
is something no generic LLM client has — that is the real "beyond the API" value,
not raw CRUD.

---

## 1. Locked decisions (2026)

| Area | Decision | Rationale |
| :--- | :--- | :--- |
| **Transport** | **Streamable HTTP** (single `POST /mcp` endpoint, optional SSE upgrade for streaming). **No** standalone HTTP+SSE transport. | SSE transport is legacy/deprecated; Streamable HTTP is the modern, stateless-friendly standard. |
| **Sessions** | `mcp/sdk` v0.6.0 implements the **2025-11-25** spec, which is **session-based**: `initialize` mints an `Mcp-Session-Id`, and every later request replays it. Sessions are persisted in the **shared Redis cache** (PSR-16), so any node can serve any request. The fully **stateless** routing (`Mcp-Method` / `Mcp-Name`, SEP-2243) lands with the `2026-07-28` spec and is **not yet in the SDK** — adopt when it ships. | Works behind a round-robin LB via the shared store (no sticky sessions, but a shared store *is* required today). Fits Redis + multi-server Galera. |
| **Implementation** | **`mcp/sdk` core directly** (the official PHP SDK), **not** `symfony/mcp-bundle`. | We need to reuse our own multi-tenant authenticators and firewall config; the bundle's auth/discovery assumptions are too opinionated and the bundle is pre-1.0. We still pin `mcp/sdk` and budget for upgrades. |
| **Authorization** | OAuth 2.1 **Resource Server** backed by **Keycloak** (existing). Plus `sk_*` API-key header path for simple/CI clients. | We already run Keycloak OIDC (`OidcBearerAuthenticator`, `/api/v1/oidc/discovery`). This is the single biggest head start. |
| **Endpoint / firewall** | Dedicated `/mcp` firewall in `security.yaml`, sibling to the existing `/v1/` OpenAI-compatible firewall. | Mirrors the proven `OpenAICompatibleController` pattern. |
| **Exposure policy** | **Curated allowlist** (10–20 ops), not a 1:1 dump of ~200 endpoints. Read tools first; write tools gated behind allowlist + confirmation/elicitation. | Safety, clarity, and a small, well-named catalog beat an exhaustive one. |
| **Scoping** | Per-user via existing `userId` scoping + `getRoles()` RBAC. | No new authz model needed. |

---

## Implementation status

**Phase 1 spike — shipped** (lint + PHPStan + full PHPUnit suite green; verified
live against the dev stack). Implemented with `mcp/sdk` v0.6.0:

| Capability | Status | Where |
| :--- | :--- | :--- |
| Streamable HTTP `POST /mcp` (drives the SDK from a Symfony controller via PSR-7/Guzzle) | ✅ | `App\Controller\McpController` |
| `mcp` firewall — API key (`sk_*`, `X-API-Key`) **and** OIDC bearer | ✅ | `config/packages/security.yaml` (reuses `ApiKeyAuthenticator` + `OidcBearerAuthenticator`) |
| `401` + `WWW-Authenticate` challenge → PRM | ✅ | `App\Security\McpAuthenticationEntryPoint` |
| RFC 9728 Protected Resource Metadata (`/.well-known/oauth-protected-resource[/mcp]`) | ✅ | `McpController::protectedResourceMetadata` |
| DNS-rebinding (Origin/Host) + protocol-version middleware | ✅ | SDK middleware; allow-list via `MCP_ALLOWED_HOSTS` |
| Session persistence across requests (Redis / PSR-16) | ✅ | `App\Mcp\McpServerFactory` (`Psr16SessionStore`) |
| Tools `synaplan_chat` (full pipeline), `rag_search`, `memory_search` (user-scoped) | ✅ | `App\Mcp\McpServerFactory` |
| Per-call rate-limit check + usage recording (`source: MCP`) — `synaplan_chat` | ✅ | `App\Mcp\McpServerFactory` (`RateLimitService`) |
| Registry manifest `server.json` (created; not yet published) | ✅ | repo root `server.json` |
| Edge routing for `/mcp` + `/.well-known/…` (also fixed `/v1/*`) | ✅ | `_docker/backend/Caddyfile` |
| Functional tests | ✅ | `tests/Controller/McpControllerTest.php` |
| Public docs | ✅ | `synaplan-docs/docs/mcp.md` |

**Not yet done (next):** remaining tools (`rag_similar`, `memory_add`,
`file_ingest`, `list_chats`/`get_messages`, `list_prompts`), Resources, Prompts,
Tasks, a structured per-call **audit log** (rate-limit + usage recording is wired
for `synaplan_chat`; extend to the read tools), **publishing** the registry
`server.json`, and full OAuth 2.1 Resource-Server token validation
(RFC 8707/9207) — the API-key path works today; OAuth bearer relies on the
existing Keycloak validation.

---

## 2. Sequenced plan (both directions)

The three steps map to the three roles Synaplan plays. **Phase 1 (server) is
prioritized** because it directly delivers the "useful on the web for other
services" goal; the original docs over-invested in the client side first.

### Phase 1 — Synaplan as an MCP **Server** (Inbound / "Expose")

External hosts discover and call Synaplan tools/resources/prompts over one
authenticated Streamable HTTP connection.

**1.1 Transport & endpoint**

- Add `POST /mcp` (Streamable HTTP) via `mcp/sdk`.
- Validate `Origin` header (DNS-rebinding protection) → `403` on invalid.
- Require + validate `MCP-Protocol-Version` header; reject header/body mismatch
  with `400 HeaderMismatch`.
- Support SSE response upgrade for streaming tools (e.g. `chat`).

**1.2 Authorization (OAuth 2.1 Resource Server)**

- New `/mcp` firewall reusing `OidcBearerAuthenticator` + `ApiKeyAuthenticator`.
- Implement **RFC 9728 Protected Resource Metadata** at
  `/.well-known/oauth-protected-resource` pointing at Keycloak as the
  `authorization_servers` entry.
- On unauthorized: `401` + `WWW-Authenticate` with `resource_metadata` URL and a
  `scope` hint.
- Validate **RFC 8707** resource-indicator-bound tokens; validate `iss`
  (RFC 9207) to mitigate mix-up attacks.
- API-key path: accept `X-API-Key` / `Authorization: Bearer sk_*` for CI/simple
  clients (advertised in the registry `server.json` `headers`).

**1.3 Tools (curated v1 catalog, all user-scoped)**

| Tool | Backed by | R/W |
| :--- | :--- | :--- |
| `synaplan_chat` | `MessageProcessor` (full pipeline) | read* |
| `rag_search` | `RagController` / `VectorSearchService` | read |
| `rag_similar` | `VectorSearchService` | read |
| `memory_search` | `UserMemoryService` | read |
| `memory_add` | `UserMemoryService` | write |
| `file_ingest` | `FileUploadService` + `/files/{id}/process` | write |
| `list_chats` / `get_messages` | `ChatController` / `MessageController` | read |
| `list_prompts` | `PromptController` | read |

\* `synaplan_chat` is "read" from the catalog's perspective but produces a chat
record; treat creation side effects explicitly.

**1.4 Resources** (read-only first)

- `synaplan://file/{id}`, `synaplan://memory/{id}`, `synaplan://chat/{shareToken}`.
- Add update subscriptions in a later iteration.

**1.5 Prompts**

- Surface task-prompts (`PromptController`, sorter, planner configs) as MCP
  prompts with typed arguments.

**1.6 Tasks (long-running)**

- Wrap async Messenger jobs (media generation, video, re-vectorize) in the MCP
  **Tasks extension** instead of the current bespoke `/status` polling.

**1.7 Governance**

- Reuse `RateLimitService` per call.
- Per-call audit log (caller, tool, args summary, duration, status).
- Strict request-schema validation derived from the underlying op.

### Phase 2 — Synaplan as an MCP **Client** (Outbound / "Consume")

This is largely the existing detail in
[`02-MCP-CLIENT-ENRICHMENT.md`](./02-MCP-CLIENT-ENRICHMENT.md) and
[`03-MCP-SERVER-PUSH.md`](./03-MCP-SERVER-PUSH.md) — **kept, but sequenced after
Phase 1.** Update those docs' transport references from SSE to Streamable HTTP.

- **Enrichment (pull):** hook in `MessageProcessor` (step 5) to call configured
  external MCP servers and inject results pre-inference.
- **Action (push):** expose external MCP tools to the model as function/tool
  definitions; execute via an `McpClient`; confirm sensitive/write actions
  (elicitation / HITL).
- **Config:** per-user external servers in `BCONFIG` (`MCP_EXT` group); secrets
  stored as references, not plaintext (250-char `BVALUE` limit).
- **Egress safety:** block private IP ranges (SSRF), strict timeouts, circuit
  breaker, audit logging.

### Phase 3 — Orchestration ("Compose")

Unchanged in intent from the original roadmap: chain internal + external tools
into repeatable "Synaplan Processes" via a JSON DSL executed on Symfony
Messenger, with HITL gates and full traceability. Deferred until Phases 1–2 are
stable.

---

## 3. Distribution

- Publish `server.json` to the **MCP Registry** with a `remotes` entry
  (`type: "streamable-http"`, `url: https://web.synaplan.com/mcp`) and the
  optional `headers` block advertising the `X-API-Key` path.
- Validate against the current `server.schema.json`.

---

## 4. Open questions

- **Tool naming:** namespaced (`synaplan.core.rag_search`) vs flat
  (`rag_search`)? (Lean namespaced for collision-safety across plugins.)
- **Plugin tools:** how plugins opt in (manifest flag) and how their per-user
  routing (`/user/{userId}/plugins/...`) maps to MCP tool scoping.
- **MCP Apps:** which surfaces justify server-rendered UI first (widget config?
  RAG browser?).
- **`symfony/mcp-bundle` revisit:** re-evaluate once it reaches a stable 1.0 —
  it may become the lower-maintenance path if its auth hooks mature.

---

## 5. Immediate next actions

1. ✅ **Done** — `POST /mcp` on `mcp/sdk` behind a new `/mcp` firewall with the two
   read tools `rag_search` + `memory_search` (API key + OIDC bearer). See
   [Implementation status](#implementation-status).
2. ✅ **Done** — RFC 9728 PRM `.well-known` endpoint. (OAuth token-validation
   hardening per RFC 8707/9207 still pending.)
3. ✅ **Done** — `synaplan_chat` added (full `MessageProcessor` pipeline, mirrors
   `WebhookController::generic()`). Next: write tools (`memory_add`,
   `file_ingest`) and the remaining read tools, then Resources + Prompts.
4. Update Phase-2 docs (`02-…`, `03-…`) to Streamable HTTP terminology.

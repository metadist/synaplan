# Synaplan ↔ n8n Integration — Research & Recommendations

> **Status:** Research only. No code changed. This document maps Synaplan's
> existing integration surfaces against n8n's connector model and recommends
> where (and how) an integration makes sense, ordered from "works today, zero
> code" to "needs a small backend feature".
>
> **Scope of the codebase reviewed:** `backend/src/Controller/*`,
> `backend/src/Mcp/McpServerFactory.php`, `backend/src/Security/*`,
> `backend/config/packages/security.yaml`, `docs/OPENAI_COMPATIBLE_API.md`.

---

## TL;DR

Synaplan already exposes **four** machine-callable surfaces that n8n can talk to
with stock nodes — no Synaplan code required:

| Surface | Route(s) | n8n node | Auth | Direction |
|---------|----------|----------|------|-----------|
| OpenAI-compatible API | `POST /v1/chat/completions`, `GET /v1/models` | **OpenAI** node (custom Base URL) or **HTTP Request** | `Bearer sk_…` | n8n → Synaplan |
| MCP server | `POST /mcp` (Streamable HTTP) | **MCP Client Tool** node | `Bearer sk_…` (or OIDC) | n8n → Synaplan (as agent tools) |
| Generic chat webhook | `POST /api/v1/webhooks/generic` | **HTTP Request** node | `Bearer sk_…` / `X-API-Key` | n8n → Synaplan (sync reply) |
| Native REST API | `POST /api/v1/messages/send`, `/api/v1/rag/search`, `/api/v1/messages/upload-file`, … | **HTTP Request** node | `Bearer sk_…` / `X-API-Key` | n8n → Synaplan |
| Inbound channel webhooks | `POST /api/v1/webhooks/email`, `/whatsapp` | **HTTP Request** node | email = public, whatsapp = Meta verify | n8n → Synaplan |

**The one real gap:** Synaplan has **no outbound webhook / event emitter**. It
cannot natively "call n8n when something happens" (new message, classification,
feedback, document indexed, human-handoff requested). Triggering an n8n workflow
*from* Synaplan today requires either polling the REST API or adding a small
outbound-webhook feature (scoped in §6).

**Best first integration (recommended):** use Synaplan as an **AI/RAG tool node
inside n8n** via the **MCP Client Tool** node pointed at `POST /mcp` with a
Synaplan API key. It immediately exposes `synaplan_chat`, `rag_search`,
`memory_search/add`, `file_ingest`, `list_chats`, `get_messages`, `list_prompts`
to any n8n AI Agent with zero backend work. For non-agent / deterministic
workflows, the OpenAI node (custom Base URL → `/v1`) and the generic webhook are
the simplest.

---

## 1. How authentication works (the key enabler)

All programmatic access is unified behind **Synaplan API keys** (`sk_…`), managed
at **Settings → API Keys** in the UI or via `POST /api/v1/apikeys`
(`backend/src/Controller/ApiKeyController.php`). Keys are owned by a user and
carry a `scopes` array (default `['webhooks:*']`).

`App\Security\ApiKeyAuthenticator` accepts the key three ways
(`backend/src/Security/ApiKeyAuthenticator.php`):

- `X-API-Key: sk_…` — works on **every** firewall (`/api`, `/v1`, `/mcp`)
- `Authorization: Bearer sk_…` — works on every firewall (the `sk_` prefix is
  explicitly whitelisted in `supports()`)
- `?api_key=sk_…` query param — works on every firewall

Firewalls (`backend/config/packages/security.yaml`):

- `^/v1` (`openai_compat`) → `ApiKeyAuthenticator`, stateless
- `^/mcp` (`mcp`) → `OidcBearerAuthenticator` **or** `ApiKeyAuthenticator`, stateless
- `^/api` (`api`) → cookie/OIDC/query/API-key authenticators, stateless

> **Why this matters for n8n:** a single Synaplan API key, dropped into one n8n
> credential (HTTP Header Auth or the OpenAI credential's Base URL + key), unlocks
> *all* of these surfaces. There is no per-surface token juggling.

Public (no-auth) routes relevant to n8n:
- `POST /api/v1/webhooks/email` — public (anyone can POST an email payload)
- `POST /api/v1/webhooks/whatsapp` — public, but gated by Meta's `hub_verify_token`
- `POST /api/v1/webhooks/generic` — **requires** auth (good — this is the clean one)

---

## 2. Surface-by-surface analysis

### 2.1 OpenAI-compatible API — `/v1/*`  ✅ works today

`backend/src/Controller/OpenAICompatibleController.php` + `docs/OPENAI_COMPATIBLE_API.md`.

- `POST /v1/chat/completions` — full OpenAI request/response shape, supports
  `stream: true` (SSE). Synaplan resolves `model` against its registry and routes
  to the right provider (OpenAI/Anthropic/Groq/Gemini/Ollama) transparently.
- `GET /v1/models` — lists active models in OpenAI format.

**n8n fit:** n8n's **OpenAI** credential has a **Base URL** field (moved into the
credential in n8n PR #12175, late 2024). Point it at
`https://<instance>/v1`, set the API key to your `sk_…` key. The OpenAI node, the
**OpenAI Chat Model** sub-node (for AI Agent / Basic LLM Chain), LangChain nodes,
etc. then all run through Synaplan.

**Caveats** (from the doc): no function-calling/tools, no vision inputs, token
`usage` may be zero depending on provider. This path is a *raw model call shape*
— it does **not** carry the chat_id threading metadata the way the native API or
MCP does (though Synaplan's pipeline still applies behind the scenes).

### 2.2 MCP server — `POST /mcp`  ✅ works today, richest surface

`backend/src/Controller/McpController.php` + `backend/src/Mcp/McpServerFactory.php`.
Streamable HTTP transport, per-request user-scoped tool catalog. Tools exposed:

| Tool | Kind | What it does |
|------|------|--------------|
| `synaplan_chat` | write | Full pipeline answer (classification + web search + RAG + memories + inference); persists into a Chat, supports `chat_id` threading |
| `rag_search` | read | Semantic search over the user's vectorized documents |
| `rag_similar` | read | Chunks similar to a given `message_id` |
| `memory_search` | read | Search long-term user memories |
| `memory_add` | write | Store a durable memory |
| `file_ingest` | write | Add a text document to the knowledge base (chunk + embed) |
| `list_chats` / `get_messages` | read | Browse conversations |
| `list_prompts` | read | List the user's task prompts |
| Resources | read | `synaplan://file/{id}`, `synaplan://memory/{id}` |
| Prompts | read | The user's task prompts become MCP prompts |

Mutating tools enforce per-call rate limits and record usage, so MCP traffic
counts against the same budgets as the web app.

**n8n fit:** n8n's **MCP Client Tool** node connects to external MCP servers over
**HTTP Streamable** (the modern transport, which is exactly what `/mcp` speaks).
Configure the streamable URL = `https://<instance>/mcp` and add header
`Authorization: Bearer sk_…`. The community node `n8n-nodes-mcp` (and the
`-enhanced` fork) also support HTTP Streamable with custom headers and let an AI
Agent drive tool name + parameters directly.

> ⚠️ Transport note: as of 2026 the recommended MCP transport is **Streamable
> HTTP** (SSE is deprecated). Synaplan implements Streamable HTTP, so use a
> recent n8n / community-node version that defaults to (or can be forced to)
> `httpStreamable`. Some n8n builds historically defaulted to SSE — if you see
> "Session not found", force the transport to HTTP Streamable.

**Why this is the best starting point:** it requires **zero Synaplan changes**,
exposes Synaplan's *differentiated* value (RAG + memories + the full routing
pipeline, not just a raw LLM), and slots straight into n8n's AI-Agent tooling
model.

### 2.3 Generic chat webhook — `POST /api/v1/webhooks/generic`  ✅ works today

`backend/src/Controller/WebhookController.php::generic()`. Requires auth. Body:

```json
{ "message": "…", "channel": "n8n", "metadata": { "any": "string values" } }
```

Runs the message through `MessageProcessor` and returns the AI answer **plus any
generated files** synchronously:

```json
{
  "success": true,
  "message_id": 123,
  "response": { "text": "…", "files": [ … ], "metadata": { … } }
}
```

**n8n fit:** an **HTTP Request** node (POST, Header Auth credential `Bearer sk_…`).
This is the cleanest "send text, get answer + media" call for deterministic
(non-agent) workflows, and unlike `/v1` it returns Synaplan's `message_id` and
the structured file list.

### 2.4 Native REST API — `/api/v1/*`  ✅ works today

Useful endpoints for n8n HTTP Request nodes (all `Bearer sk_…`):

- `POST /api/v1/messages/send` — `{ message, trackId?, fileIds? }` → incoming +
  outgoing message objects. Supports attaching previously uploaded files.
- `POST /api/v1/messages/upload-file` — upload a file for RAG / attachment.
- `POST /api/v1/messages/enqueue` + `GET /api/v1/messages/{id}/status` — async
  processing + poll (good for long jobs / media generation).
- `GET /api/v1/messages/history` — conversation history.
- `POST /api/v1/rag/search`, `GET /api/v1/rag/similar/{chunkId}`,
  `GET /api/v1/rag/stats` — RAG operations.
- `POST /api/v1/apikeys` — programmatic key provisioning (e.g. an n8n onboarding
  workflow that mints a per-tenant key).

Full machine-readable contract: the OpenAPI/Swagger spec at `GET /api/doc`
(public). n8n's HTTP Request node can **import from a cURL / OpenAPI** description
to scaffold calls quickly.

### 2.5 Inbound channel webhooks — email / WhatsApp

- `POST /api/v1/webhooks/email` (public): n8n can forward a normalized email
  (`{from, to, subject, body, message_id, attachments[]}`) and Synaplan will
  find/create the user, run the pipeline, and email the reply itself. Has
  idempotency + rate limiting built in.
- `POST /api/v1/webhooks/whatsapp` (Meta payload shape): less useful as an n8n
  target unless you're emulating Meta's payload.

**n8n fit:** use these when n8n is acting as a *channel bridge* (e.g. Telegram /
Slack / a custom mailbox → Synaplan). For most cases the **generic** webhook is a
better target than the email webhook because it returns the answer to n8n instead
of sending an email out-of-band.

---

## 3. Where integration makes sense (use-case matrix)

### Pattern A — Synaplan *inside* n8n (n8n → Synaplan)  ⭐ recommended, available now
n8n orchestrates; Synaplan is the AI brain / knowledge base.

- **AI Agent with company knowledge:** n8n AI Agent + **MCP Client Tool → `/mcp`**.
  The agent can `rag_search` company docs, recall `memory_search`, and answer via
  `synaplan_chat`. (Best showcase of Synaplan's value.)
- **Drop-in LLM for existing n8n AI workflows:** OpenAI node Base URL → `/v1`.
- **Deterministic enrichment step:** HTTP Request → `/api/v1/webhooks/generic`
  ("summarize this ticket", "classify this email", "draft a reply from our KB").
- **Document ingestion pipeline:** n8n watches a Drive/SharePoint/S3 folder →
  pushes content to `file_ingest` (MCP) or `/api/v1/messages/upload-file` so the
  RAG corpus stays fresh automatically.

### Pattern B — n8n *behind* Synaplan (Synaplan → n8n)  ⚠️ needs the gap closed (§6)
Synaplan is the chat front-end; n8n runs business automations.

- "When a widget chat is classified as `topic=sales`, create a CRM lead / Slack
  ping / Jira ticket." Today there is **no event hook** to fire this. Options:
  1. Add an **outbound webhook** feature (§6) — clean, recommended.
  2. Poll `GET /api/v1/messages/history` from an n8n Schedule trigger — works
     today, hacky, latency-bound.

### Pattern C — n8n as a channel bridge (n8n ⇄ Synaplan)  ✅ available now
n8n receives an external channel event (Telegram, Slack, custom inbox), forwards
to `/api/v1/webhooks/generic` (or `/email`), and returns Synaplan's answer to the
channel. Lets Synaplan "support" channels it has no native connector for.

---

## 4. Recommended rollout (lowest effort → highest value)

1. **Document the pattern (zero code).** Add an `docs/N8N.md` guide: create an API
   key, then (a) MCP Client Tool → `/mcp`, (b) OpenAI node → `/v1`, (c) HTTP
   Request → `/api/v1/webhooks/generic`. Most users' needs are met here.
2. **Ship example n8n workflow JSON** (importable): "RAG-backed support agent"
   using the MCP node. Lives in repo as a sample.
3. **(Optional, small backend feature) Outbound webhooks** (§6) to unlock Pattern
   B without polling.
4. **(Optional, larger) A dedicated n8n community node** (`n8n-nodes-synaplan`)
   that wraps chat / rag_search / file_ingest with a Synaplan credential type, for
   a polished UX. Not necessary for functionality — the generic nodes already
   work — but nice for discoverability on n8n's node registry.

---

## 5. Concrete n8n configs (copy/paste)

See **`release4.1/n8n-integration-recipes.md`** for step-by-step node configs and
example payloads for each surface.

---

## 6. The gap: outbound webhooks (proposed enhancement)

To enable Pattern B cleanly, Synaplan would need an **event → HTTP POST** emitter.
Sketch (not implemented — this is research):

- **Config/entity:** per-user "Outbound Webhook" records (`url`, `secret`,
  `events[]`, `active`) — mirror the existing `ApiKey` / `InboundEmailHandler`
  CRUD style. Schema via a Doctrine migration; CRUD controller under
  `/api/v1/outbound-webhooks`.
- **Events to emit:** `message.created` (IN/OUT), `message.classified`
  (topic/intent), `chat.human_handoff_requested`, `feedback.created`,
  `document.indexed`. These are natural hook points in `MessageProcessor` and the
  realtime/handoff flow.
- **Delivery:** enqueue on the existing **Symfony Messenger** worker (Redis
  Streams) so emission is async and retried; sign payloads with an HMAC
  `X-Synaplan-Signature` (same idea as Stripe's webhook the repo already verifies).
- **n8n side:** a **Webhook** trigger node receives it; verify the HMAC in a
  Function node.

This reuses infrastructure already in the codebase (Messenger worker, the
webhook/CRUD patterns, HMAC verification like `StripeWebhookController`), so it is
a contained, non-invasive addition rather than new architecture.

> Per `AGENTS.md`, any schema change must go through a Doctrine migration and
> "Ask First Before … Changing database schema". This section is a proposal only.

---

## 7. Open questions / things to confirm before building

- **MCP transport compatibility:** verify the exact n8n version / node the team
  targets actually negotiates **Streamable HTTP** (not legacy SSE) against `/mcp`,
  including session persistence across `initialize → tools/list → tools/call`.
- **API-key scopes:** scopes exist (`webhooks:*`) but are not currently enforced
  per-route as far as the reviewed controllers show. If outbound webhooks or a
  Synaplan node ship, decide whether to enforce granular scopes (`chat`,
  `rag:read`, `rag:write`, `webhooks:emit`).
- **Rate limits:** n8n loops can fan out fast; `/v1`, `/mcp`, and `/webhooks/*`
  all run through `RateLimitService`. Confirm the API-key owner's level
  (NEW/PRO/TEAM/…) has headroom for automation workloads.
- **Multi-tenancy:** one API key == one Synaplan user. For per-customer n8n
  flows, mint a key per user (the `apikeys` endpoint supports this).

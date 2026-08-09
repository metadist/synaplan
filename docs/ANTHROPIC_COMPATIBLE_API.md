# Anthropic-Compatible Messages API

Synaplan exposes an Anthropic Messages API-compatible gateway so Claude Code and other Anthropic-protocol clients can use your instance as their backend.

> This is **not** Synaplan’s native chat SSE at `/api/v1/messages/stream`.  
> The gateway lives at **`POST /v1/messages`** (Claude Code calls `/v1/messages?beta=true`).

## Quick start (Claude Code)

1. Create an API key in Synaplan (**Channels → API Keys**).
2. Enable the gateway under **Channels → AI Agents** (admin: turn on “Enable Messages gateway”).
3. Save a BYO Anthropic key on that page (or allow the operator key carefully).
4. Configure Claude Code:

```bash
export ANTHROPIC_BASE_URL="https://your-synaplan-host"
export ANTHROPIC_API_KEY="sk_your_synaplan_api_key"
# or: export ANTHROPIC_AUTH_TOKEN="sk_your_synaplan_api_key"
# Set exactly one credential variable.
claude
```

`ANTHROPIC_API_KEY` is sent as `x-api-key`; `ANTHROPIC_AUTH_TOKEN` as `Authorization: Bearer`. Setting both is a common foot-gun — use exactly one.

## Endpoints

| Method | Path | Notes |
| ------ | ---- | ----- |
| `POST` | `/v1/messages` | Inference (streaming SSE when `stream: true`) |
| `POST` | `/v1/messages/count_tokens` | Optional; proxied for Anthropic models, 404 otherwise (Claude Code estimates locally) |
| `GET`  | `/v1/models` | Existing OpenAI-shaped list; Claude Code model discovery can use it |

## Auth

Same Synaplan API keys as the OpenAI-compatible API:

- `x-api-key: sk_…`
- `Authorization: Bearer sk_…`

## Feature flags (`BCONFIG` group `MESSAGES_GATEWAY`)

Defaults are **off** except budget notices, session summaries (both only take
effect once the gateway itself is enabled), and `WEB_SEARCH_MODE` /
`VISION_MODE` which default to `auto` so Claude Code never silently loses web
search or vision:

| Setting | Default | Meaning |
| ------- | ------- | ------- |
| `ENABLED` | `0` | Master switch |
| `ALLOW_OPERATOR_KEY` | `0` | Fall back to the install-wide Anthropic key |
| `UPSTREAM_URL` | `https://api.anthropic.com` | Admin-set global upstream (HTTPS; http only for loopback/private) |
| `MODEL_ALIASES` | `{}` | Map Claude Code model IDs → catalog IDs |
| `BUDGET_NOTICE_ENABLED` | `1` | One-time ≥90% budget notice in the response |
| `SESSION_SUMMARY_ENABLED` | `1` | Debounced AI summary chat per API session (uses the sorting model, shared with the OpenAI-compatible API) |
| `MCP_TOOLS_ENABLED` | `0` | Inject the user’s MCP catalog and run a server-side tool loop |
| `MCP_TOOLS_WITH_CLIENT_TOOLS` | `0` | Also inject when the client already sent `tools` (off — Claude Code brings its own) |
| `MCP_MAX_ITERATIONS` | `8` | Max LLM↔tool rounds per request (applies to every server-side tool) |
| `WEB_SEARCH_MODE` | `auto` | How to answer Anthropic’s `web_search_*` server tool — `auto`, `synaplan`, `passthrough` or `off` (see [Web search](#web-search)) |
| `VISION_MODE` | `auto` | How to handle image turns: `auto` (Synaplan PIC2TEXT when the resolved model lacks vision, otherwise passthrough), `synaplan`, `passthrough`, or `off` |
| `CONTEXT_INJECTION_ENABLED` | `0` | Append session-stable RAG/memory system block |

Env fallback for local smoke tests: `MESSAGES_GATEWAY_UPSTREAM_URL` (DB value wins in production).

Per-request overrides:

- `X-Synaplan-Context: on|off` — force context injection on/off for one request
- `X-Synaplan-Debug: 1` — response header `x-synaplan-context-hash` (SHA-256 of the injected block)

For Claude Code, prefer native MCP over server-side injection:

```bash
claude mcp add --transport http synaplan https://your-synaplan-host/mcp \
  --header "Authorization: Bearer sk_your_synaplan_api_key"
```

## Tools

Anthropic has two kinds of tool entry and the gateway treats them differently:

- **Client tools** (they carry an `input_schema`) are relayed verbatim — Claude Code’s `Bash`, `Read`, `Edit` and friends. The client keeps driving its own loop, and a turn containing even one client tool call is never executed server-side. For aliased models the translators map them to OpenAI `tool_calls` / Gemini `functionDeclarations` and back.
- **Server tools** (a versioned `type`, no `input_schema`) are capability requests aimed at the API side. Only `api.anthropic.com` can honour them, so they are never mapped to a function declaration for another provider.

Other Anthropic server tools (`code_execution_*` and friends) stay on the wire for the Anthropic passthrough and are never mapped to an OpenAI/Gemini function.

### Web search

`WEB_SEARCH_MODE` decides what happens to a `web_search_*` declaration. It needs no configuration to do something sensible:

| Mode | Behaviour |
| ---- | --------- |
| `auto` (default) | Synaplan answers the declaration with its own search when a search provider is configured, and otherwise forwards it untouched so `api.anthropic.com` can still honour it. |
| `synaplan` | Always offer Synaplan’s search, even to clients that never declared it. |
| `passthrough` | Never intervene — the declaration goes to the AI provider as sent, in exchange for Anthropic’s citations. |
| `off` | Drop the declaration instead of forwarding it. |

When Synaplan runs the search it uses `BraveSearchService` in the same server-side loop as MCP tools. The client sees one continuous message; intermediate tool rounds are suppressed from the SSE stream, with keep-alive pings while a tool runs.

Synaplan stays out of the way when the client ships its own runnable tool named `web_search`.

Every response to a request that declared web search carries `x-synaplan-web-search: synaplan|passthrough|off`, so a model answering without search can be told apart from a misconfigured gateway.

## Vision

Image blocks are forwarded on every route: unchanged on the Anthropic passthrough, as `image_url` (data URI or URL) for OpenAI, and as `inlineData` / `fileData` for Gemini.

Default `VISION_MODE=auto` also mixes in Synaplan’s own vision models:

- If the resolved chat model already has `vision`, images stay on the wire (Anthropic keeps its eyes).
- If it does not, Synaplan rewrites the turn onto the user’s PIC2TEXT / catalog vision model when that model’s provider is Anthropic, OpenAI, or Google — same fallback order as normal chat.
- If Synaplan has no usable vision model, images are still forwarded upstream (`x-synaplan-vision: passthrough`).
- When Synaplan vision is available, the gateway also offers an `analyze_image` tool (OCR/describe via PIC2TEXT) in the same server-side tool loop as `web_search`. Explicit `off` skips both the rewrite and the tool.

Applied handling is reported in the `x-synaplan-vision` response header (`synaplan` / `passthrough`).

## Errors

Provider rejections keep their status instead of collapsing into `500`: an unreadable image stays `400`, a bad provider key `401`, a provider rate limit `429`. This applies to the Messages gateway and the OpenAI-compatible endpoint alike, so clients can tell a retryable failure from a request that can never succeed.

## Metering

Usage is recorded as `API_CHAT` with `source: MESSAGES_API`, including Anthropic cache token fields. Outbound MCP tool calls record `source: MCP_TOOL`, Synaplan’s own search records `source: WEB_SEARCH`, and Synaplan vision tool calls record `source: VISION`. Rate limits use the `MESSAGES` action. Metering never fails a completed request.

Cost depends on whose key serves the request:

- **Operator key** — the install pays the provider, so the user's Synaplan cost budget is enforced before the request (429 once exhausted; concurrent streams may slightly overshoot — documented limitation).
- **BYO key** — the user pays the provider directly, so tokens are metered at **zero cost** (statistics only, no budget consumption). BYO keys require at least the **Pro plan**; saving a key and using it through the gateway are both refused below that level.

## Multi-provider aliases

`MODEL_ALIASES` can point Claude Code model IDs at OpenAI or Gemini catalog models. The gateway translates the Anthropic wire format:

- **OpenAI** — Chat Completions (`/v1/chat/completions`), not the Responses API
- **Google/Gemini** — `generateContent` / `streamGenerateContent` with `parametersJsonSchema` for tools

Anthropic-only fields such as `thinking: {"type":"adaptive"}` are stripped before the upstream call. This routing works technically; Anthropic does not officially support Claude Code against non-Claude models through a gateway.

## Related

- Tool/web search/vision integration plan: [CLAUDE_CODE_COMPATIBILITY_PLAN.md](./CLAUDE_CODE_COMPATIBILITY_PLAN.md)
- User docs: [docs.synaplan.com — Claude Code](https://docs.synaplan.com/) (page `claude-code`)
- OpenAI-compatible sibling: [OPENAI_COMPATIBLE_API.md](./OPENAI_COMPATIBLE_API.md)
- UI: **Channels → AI Agents**
- Smoke scripts: `_devextras/testing/messages-gateway/`

# Claude Code compatibility, round 2 — tools, web search and vision

Status of the plan: **phases 0–2 are implemented on this branch**, phase 3 is
scoped but not built. Each item below states what it fixes, how it is verified,
and what is deliberately left out.

Related: [ANTHROPIC_COMPATIBLE_API.md](./ANTHROPIC_COMPATIBLE_API.md) (reference
documentation for the gateway itself).

## 1. What a Claude Code user sees today

The first release made `/v1/messages` work for plain text. Everything that is
not plain text was reported back as broken, with three distinct symptoms:

| Reported symptom | Verdict |
| ---------------- | ------- |
| “I cannot search the web — no access to the internet”, with both `web_search_20260209` and `web_search_20250305` | Real. The gateway had no way to serve a web search request. |
| `HTTP 500: strlen(): Argument #1 ($string) must be of type string, array given` on an image request | Real, and a hard 500 **after** a successful completion. |
| `Anthropic API Error: Could not process image` for a 1×1 PNG | Not ours. Anthropic rejects the image; the request reached it intact. |

The reporter's conclusion — “Synaplan discards the tool on the way through” —
was right about web search and wrong about ordinary tool calling. It is worth
separating the two, because they fail for different reasons.

### Anthropic has two kinds of tools, and only one is forwardable

A **client tool** carries an `input_schema`. The model emits `tool_use`, the
client executes it and sends back `tool_result`. Claude Code's `Bash`, `Read`
and `Edit` are all client tools. These already worked: the gateway relays them
verbatim, and for aliased models both translators map them to OpenAI
`tool_calls` / Gemini `functionDeclarations` and back again.

A **server tool** has a versioned `type` and no `input_schema`:

```json
{ "type": "web_search_20250305", "name": "web_search", "max_uses": 5 }
```

It is not a tool the client can run. It is a request that *the API side* run
the search. Only `api.anthropic.com` can honour it, and only for an
organisation entitled to it. Through a gateway there is nothing behind the
declaration, so the model was offered a search tool it could never call and
answered out of training data instead — exactly the transcript in the report.

Worse, on an aliased route the declaration used to be mapped into an OpenAI
function with no parameters, which is a malformed function declaration.

## 2. Design decision

> A server tool is a **capability request**. Synaplan owns the server side of
> this API, so it should either **satisfy** the request with its own
> implementation or **forward** the declaration to an upstream that can — never
> leave the model holding a tool nobody will answer.

For web search that means: run Synaplan’s search when a provider is configured,
otherwise leave the Anthropic `web_search_*` declaration on the wire so
`api.anthropic.com` can honour it. Only an explicit `off` drops the declaration.

Three consequences follow, and they shape the whole implementation:

1. **Classify before translating.** Nothing may treat a server-tool declaration
   as a callable function. `AnthropicServerTools` is the single place that
   decides, and both translators consult it.
2. **One funnel, not two.** The MCP tool loop already did exactly what web
   search needs: inject tools, catch `stop_reason: tool_use`, execute, append
   `tool_result`, re-prompt. Adding a second, parallel mechanism for built-in
   tools would double the surface. The loop is generalised instead.
3. **Replace, do not add.** When Synaplan serves the search, the original
   `web_search_*` declaration must be *removed* from the upstream request.
   Leaving it in would make Anthropic run a second, duplicate search and would
   make every other provider reject an unknown tool type.

### The funnel

```
client request
  │  tools: [ {type: web_search_20250305}, {name: Bash, input_schema}, … ]
  ▼
GatewayToolCatalog          builds one session-pinned snapshot:
  ├─ MCP tools              (mcp__<server>__<tool>, if MCP_TOOLS_ENABLED)
  └─ native tools           (web_search, if WEB_SEARCH_MODE allows + provider present;
                            otherwise leave the Anthropic declaration for passthrough)
  ▼
GatewayToolLoop.injectTools  snapshot tools first (stable cache prefix),
  │                          client tools after; only replaced declarations are dropped
  ▼
translator (Anthropic passthrough | OpenAI | Gemini)
  ▼
stop_reason: tool_use → partition by name
  ├─ ours   → execute server-side, append tool_result, re-prompt
  └─ client → return verbatim; the client owns its own loop
```

Two properties are worth calling out because they are easy to lose:

- **Mixed turns stay with the client.** If a single turn contains even one
  client-owned `tool_use`, the whole turn is relayed untouched. Executing half a
  turn server-side and handing back the rest would desynchronise the client's
  loop.
- **Streaming hides only our rounds.** Intermediate tool rounds are suppressed
  from the wire and `message_delta` / `message_stop` are held back until the
  stop reason is known, so the client sees one continuous message. Keep-alive
  pings are emitted while a tool runs.

## 3. Phases

### Phase 0 — stop the bleeding (done)

| Change | Why |
| ------ | --- |
| `RateLimitService::textByteLength()` | Metering measured `input_text` with `strlen()`. A turn carrying an image is an array of content parts, so metering fataled on a request that had already succeeded. |
| Metering wrapped in try/catch in `OpenAICompatibleController` | Metering is bookkeeping after a successful completion. It must never turn a good answer into a 500. The Messages gateway already worked this way. |
| `contentToText()` for multimodal turns | Metering and the session summary now get readable text (`"What is on this page?\n[image]"`) instead of a JSON blob. |

### Phase 1 — vision on aliased routes (done)

Both translators only read `text` blocks, so an `image` block was silently
dropped on the way to an OpenAI or Gemini model: the model got the question
without the picture and answered plausibly and wrongly.

- OpenAI: base64 → `image_url` data URI, URL → `image_url`. Text-only turns keep
  their plain-string content, so the existing path is untouched.
- Gemini: base64 → `inlineData`, URL → `fileData`.

This does not apply to the Anthropic passthrough route, where image blocks were
already forwarded byte-for-byte.

### Phase 2 — web search through the funnel (done)

- `AnthropicServerTools` — classifies a declaration as server-side or
  client-executable. Consulted by both translators and by the catalog.
- `WebSearchTool` — Synaplan's search as a runnable tool backed by
  `BraveSearchService`. Deliberately named `web_search`, the same name the
  client declared, so prompt wording keeps working. Never throws: a failed
  search becomes an error `tool_result` the model can recover from in the same
  turn.
- `GatewayToolCatalog` — merges MCP and native tools into one snapshot and
  reports which client declarations are replaced.
- `GatewayToolLoop` (was `McpToolLoop`) — dispatches on `kind` (`mcp` /
  `native`), so both kinds run in the same loop on both the complete and the
  streaming path.
- `MESSAGES_GATEWAY.WEB_SEARCH_MODE` — defaults to `auto` (Synaplan search when
  a provider is configured, otherwise forward the Anthropic declaration), with
  explicit `synaplan` / `passthrough` / `off`. Admin-visible under
  **Channels → AI Agents**; the UI explains when no search provider is
  configured. Applied handling is echoed as `x-synaplan-web-search`.

Guard rails: Synaplan search is skipped when the client ships its own tool named
`web_search` (the client owns that name), usage is metered as `WEB_SEARCH`, the
tool result is clamped to 12 000 characters, and the existing loop bounds
(`MCP_MAX_ITERATIONS`, 240 s wall clock, 16 tools per turn) apply unchanged.
Other Anthropic server tools (e.g. `code_execution_*`) stay on the wire for the
Anthropic passthrough and are never mapped to OpenAI/Gemini functions.

### Phase 3 — not built yet

Ordered by value, with the reason each one is not in this branch.

1. **Anthropic-shaped search citations.** Anthropic's native web search returns
   `server_tool_use` / `web_search_tool_result` blocks and citation metadata. We
   return a plain `tool_result`, so the model cites URLs in prose rather than
   through the citation API. Clients that *render* citations structurally would
   need the richer block shape. Worth doing only once we know a client depends
   on it — the block shape is versioned and would tie us to one revision.
2. **More native tools in the same catalog.** The funnel is now generic; a
   `fetch_url` (read a page the search found) and a RAG/`knowledge_search` tool
   are the obvious next entries. Each is a `declaration()` + `execute()` pair
   plus a catalog entry, no loop changes. `fetch_url` needs SSRF protection
   before it can ship, which is why it is not bundled here.
3. **Per-user opt-in.** The flag is currently instance-wide. `MessagesGatewayConfig`
   already resolves per-user overrides, so this is a UI and seeding question
   rather than a backend one.
4. **Search result caching.** Repeated identical queries inside one session hit
   the provider every time. Cheap to add on `WebSearchTool` with the session key,
   but it changes the freshness guarantee, so it should be a conscious decision.
5. **`code_execution` and other server tools.** Same classification path, but
   each needs a sandbox. Explicitly out of scope.

## 4. Verification

Unit coverage: `WebSearchToolTest`, `GatewayToolCatalogTest`,
`GatewayToolLoopTest`, `OpenAiMessagesTranslatorTest`,
`GeminiMessagesTranslatorTest`, `OpenAICompatibleControllerMultimodalTest`.

End-to-end against the running stack:

```bash
php -S 127.0.0.1:8099 _devextras/testing/messages-gateway/fixture-upstream.php
php -S 127.0.0.1:8098 _devextras/testing/messages-gateway/fixture-brave-search.php
SYNAPLAN_API_KEY=sk_… ./_devextras/testing/messages-gateway/04-web-search.sh
```

The search fixture marks its results `FIXTURE_SEARCH_HIT` and the upstream
fixture answers `NO_WEB_SEARCH_TOOL_OFFERED` when nothing runnable was injected,
so the suite can tell a real search result apart from a model that answered out
of training data — which is precisely the failure being fixed.

## 5. Rollout

Web search works out of the box for Anthropic-backed Claude Code sessions:
default `auto` either runs Synaplan search or forwards the declaration upstream.
To use Synaplan’s own search results (and to serve search on aliased
OpenAI/Gemini routes), configure a search provider (`BRAVE_SEARCH_API_KEY` in
`backend/.env`). Admins can force `synaplan`, `passthrough`, or `off` under
**Channels → AI Agents**.

The metering and vision fixes in phases 0 and 1 are unconditional — both are
bug fixes with no configurable behaviour.

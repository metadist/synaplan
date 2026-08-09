# Claude Code compatibility, round 2 — tools, web search and vision

Status of the plan: **phases 0–2 are implemented on this branch**, phase 3 is
scoped but not built. Each item below states what it fixes, how it is verified,
and what is deliberately left out.

Related: [ANTHROPIC_COMPATIBLE_API.md](./ANTHROPIC_COMPATIBLE_API.md) (reference
documentation for the gateway itself).

## 1. What a Claude Code user sees today

The first release made `/v1/messages` work for plain text. Everything that is
not plain text was reported back as broken, with three distinct symptoms:

| Reported symptom | Verdict | Where it is answered |
| ---------------- | ------- | -------------------- |
| “I cannot search the web — no access to the internet”, with both `web_search_20260209` and `web_search_20250305` | Real. The gateway had no way to serve a web search request. | Phase 2 — Synaplan runs the search, or forwards the request when it cannot |
| `HTTP 500: strlen(): Argument #1 ($string) must be of type string, array given` on an image request | Real, and a hard 500 **after** a successful completion. | Phase 0 |
| `Anthropic API Error: Could not process image` for a 1×1 PNG | Half ours. Anthropic rejected the image, but Synaplan reported its `400` as a `500 internal_error`, which reads like a Synaplan bug and invites a pointless retry. | Phase 2b — the provider's status is relayed |
| “Tools are not integrated” | Not reproducible. Client tool calling round-trips through the gateway; there was no coverage proving it, which is why it was easy to believe. | Phase 2b — end-to-end coverage |

Every row now has a working path that needs no configuration. Where Synaplan
cannot do better than the AI provider, it deliberately gets out of the way and
passes the request straight through rather than failing.

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
> this API, so Synaplan should satisfy it — with its own web search — and where
> it cannot, forward the request untouched rather than swallowing it.

The second half matters as much as the first. An install with no search provider
still has one thing it can do: hand the declaration to the AI provider, which on
`api.anthropic.com` is exactly what the client wanted. Doing nothing is never
the right answer.

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
   make every other provider reject an unknown tool type. When Synaplan does
   *not* serve it, the declaration must survive byte-for-byte.

### The funnel

```
client request
  │  tools: [ {type: web_search_20250305}, {name: Bash, input_schema}, … ]
  ▼
GatewayToolCatalog          builds one session-pinned snapshot:
  ├─ MCP tools              (mcp__<server>__<tool>, if MCP_TOOLS_ENABLED)
  └─ native tools           (web_search, per WEB_SEARCH_MODE + provider present)
  ▼
GatewayToolLoop.injectTools  snapshot tools first (stable cache prefix),
  │                          client tools after, replaced declarations dropped
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
- `MESSAGES_GATEWAY.WEB_SEARCH_MODE` — admin-visible under **Channels → AI
  Agents**, four values, and the default needs no attention:

  | Mode | Behaviour |
  | ---- | --------- |
  | `auto` (default) | Synaplan searches when a provider is configured, otherwise forwards the declaration untouched. |
  | `synaplan` | Always offer Synaplan's search, even unrequested. |
  | `passthrough` | Never intervene — the plain passthrough, for orgs that want Anthropic's own search and its citations. |
  | `off` | Strip the declaration entirely. |

  An unrecognised value falls back to `auto` rather than to “no search”, so a
  typo cannot silently break a working install.

- `x-synaplan-web-search` response header — reports which of the above actually
  happened. A model answering “I cannot search the web” is otherwise
  indistinguishable from a misconfigured gateway, which is what made the
  original report hard to act on.

Guard rails: search is skipped when the client ships its own tool named
`web_search` (the client owns that name), usage is metered as `WEB_SEARCH`, the
tool result is clamped to 12 000 characters, and the existing loop bounds
(`MCP_MAX_ITERATIONS`, 240 s wall clock, 16 tools per turn) apply unchanged.

### Phase 2b — honest errors, and proof for the rest (done)

`ProviderException` now carries the upstream HTTP status, and the
OpenAI-compatible endpoint relays it with the error type OpenAI clients branch
on (`authentication_error`, `rate_limit_error`, `invalid_request_error`) instead
of answering `500 internal_error` for everything. A local failure with no
upstream response is still a `500`. The Messages gateway already relayed status
codes faithfully.

Client tool calling and vision were reported as broken but turned out to work;
what was missing was anything proving it. Both are now covered end-to-end
(`05-tools-and-vision.sh`), so a regression shows up as a failing test rather
than as another screenshot.

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
3. **Per-user opt-in.** The mode is currently set instance-wide.
   `MessagesGatewayConfig` already resolves per-user overrides, so this is a UI
   and seeding question rather than a backend one.
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
SYNAPLAN_API_KEY=sk_… ./_devextras/testing/messages-gateway/05-tools-and-vision.sh
```

`04-web-search.sh` walks the whole mode matrix: default install, `passthrough`
and `off`, asserting each time what actually left the gateway. The search
fixture marks its results `FIXTURE_SEARCH_HIT` and the upstream fixture answers
`NO_WEB_SEARCH_TOOL_OFFERED` when nothing runnable was injected, so the suite
can tell a real search result apart from a model that answered out of training
data — which is precisely the failure being fixed.

`05-tools-and-vision.sh` covers the other two reports: a client tool round-trip
(`tool_use` out, `tool_result` back in, streaming and not), image blocks
arriving upstream byte-for-byte on both endpoints, and provider errors keeping
their status.

## 5. Rollout

Everything is backend-only plus one admin setting, and nothing has to be
switched on: a fresh install answers a `web_search` declaration either with
Synaplan's own search (search provider configured) or by passing it to the AI
provider (no search provider). Configuring `BRAVE_SEARCH_API_KEY` in
`backend/.env` is what upgrades the second case to the first, and it is the only
step that makes web search work for non-Anthropic models.

The metering, vision and error-relay fixes are unconditional, because all three
are bug fixes with no configurable behaviour.

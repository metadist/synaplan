# Epic 1 — Synaplan as the provider of Collabora's built-in AI Assistant

Status: planned
Depends on: Epic 0 (for the WOPI-provisioned path and as test bed); the
`coolwsd.xml` path can be tested without Epic 0 against any document.
Shared work: tool calling in the chat providers is the same building block as
office-docs Phase B2 / `office-plan_v2.md` Sprint 3.

Collabora 26.04's AI sidebar talks to an OpenAI-compatible endpoint and lets
the model call the editor's own tools (draft/rephrase text, build formulas,
turn notes into slides, insert images, summarise). Pointing it at Synaplan
makes Synaplan the AI of every Writer, Calc and Impress session — with our
memories, knowledge (RAG), file context, rate limits and metering — without
installing anything in Collabora.

## Step 1.1 — Tool-calling-transparent `/v1/chat/completions`

**Built as office-docs Phase T** — the authoritative step list (T1–T7,
branches, file names, test fixtures) is
`../20260902-office-docs/03_phase_t_tool_calling_gateway.md`. This section
only records the Collabora-specific requirements that Phase T must satisfy;
Collabora's **editor** tools work after T3; Synaplan MCP + web search are
also visible to the model after T4 (executed server-side, never colliding
with editor tool names). Full provider coverage is T5/T6.

Today `OpenAICompatibleController::chatCompletions()` forwards only
`model`, `temperature`, `max_tokens`, `stream` and string `content`. The
sidebar sends `tools` (function declarations), `tool_choice`, assistant
messages with `tool_calls`, and `role: tool` results, and expects
`tool_calls` back (streamed as `delta.tool_calls[*].function.arguments`
chunks, `finish_reason: "tool_calls"`).

- Introduce `ToolCallingChatProviderInterface` (from `office-plan_v2.md`
  Sprint 3) with a normalized result (`content`, `toolCalls[]`,
  `stopReason`, `usage`) and implement it for the providers we run behind
  the gateway — order: Groq (Chat Completions wire format, simplest), OpenAI
  (Responses API mapping), Anthropic, Google. Reuse the mapping already
  written in `AI/Messages/Translator/OpenAiMessagesTranslator.php` and
  `GeminiMessagesTranslator.php`.
- Gateway: accept `tools`, `tool_choice`, multi-part `content`, `tool_calls`
  and `role: tool` in the request; emit OpenAI-shaped `tool_calls` in
  non-stream and stream responses. **Collabora's tools stay client-owned**
  — the gateway never executes them; the editor does. On dual-gated
  (`tool_use`) models Synaplan **also injects** the user's MCP catalog and
  `web_search` (Phase T4). Those are executed server-side; intermediate
  rounds are not streamed. Skip a Synaplan declaration if Collabora already
  used the same function name.
- Capability: dual gate — `ToolCallingChatProviderInterface` **and**
  catalog `tool_use` (flags are made consistent in Phase T1). Either side
  failing → 400 `tools_not_supported` (Collabora then shows chat-only
  mode), never a silent text answer to a tool request.
- `/v1/models`: Collabora queries it after the key is entered and shows the
  list. Make sure only chat-capable models appear and that the `id` is what
  the gateway accepts as `model`.
- Tests: request/response fixtures recorded from a real CODE 26.04 session
  (strip keys) in `tests/Fixtures/collabora/`; unit tests for the mapping per
  provider; a gateway test that streams a tool call end to end with the
  `TestProvider`.

## Step 1.2 — Provisioning the sidebar

Branch: `feat/collabora-ai-provisioning`

Three ways to hand Collabora the endpoint, key and model; support all three,
most specific wins (Collabora's own precedence: user settings > WOPI >
`coolwsd.xml`):

1. **Per document via WOPI (`UserPrivateInfo`)** — Epic 0's `CheckFileInfo`
   adds `{"AIProviderURL": "<SYNAPLAN_URL>/v1", "AIProviderAPIKey": "<key>",
   "AIProviderModel": "<model id>"}`. The key is a **Synaplan API key minted
   per user for this purpose** (`ApiKeyScope` limited to the gateway,
   revocable, labelled "Collabora editor"), created lazily on first
   `CheckFileInfo`; the model is the user's default chat model with the
   `tools` feature.
2. **Instance-wide via `coolwsd.xml`** for our own sidecar: `ai.enabled`,
   `ai.api_url`, `ai.api_key` (a service API key of a technical Synaplan
   user), `ai.model`, `ai.allow_user_settings`. Rendered from env in the
   compose `extra_params`; documented for operators. Users are then
   attributed to the technical account unless (1) overrides — prefer (1)
   wherever we are the WOPI host.
3. **Per user** — nothing to build; document how a user pastes their
   Synaplan API key into File > Options > AI Assistant (works for any
   Collabora, including partner platforms before Epic 4).

Admin UI: a "Collabora" card in the existing system-config groups showing
the endpoint to paste, whether tool calling is available for the default
model, and a test button that performs one gateway call with a dummy tool.

## Step 1.3 — Make Synaplan's strengths reach the sidebar

The sidebar's system prompt is Collabora's; what we control is the gateway.
Small, flag-gated improvements, each its own PR:

- **Knowledge**: when the API key carries a Synaplan "knowledge" scope,
  inject RAG context for the user's message the same way the Anthropic
  gateway does (`MessagesContextInjector`). Default off.
- **Memories**: same, for user memories.
- **Metering and limits**: already applied by the gateway; add a
  `source=collabora` tag (from the `User-Agent` or a header we set via
  `UserPrivateInfo` model alias) so admins can see editor usage separately.

## Acceptance

- Own sidecar with Epic 0: open a document → AI sidebar is enabled without
  user setup → "Rewrite this paragraph more formally" changes the paragraph;
  "Sanity check this data" in Calc answers with cell references; "Turn these
  notes into 5 slides" adds slides. Usage appears in the user's Synaplan
  metering.
- Any Collabora 26.04 with a pasted Synaplan key: chat works; document tools
  work with a tool-capable model; a non-tool model degrades to chat-only
  with Collabora's own notice.
- Upstream risk to watch: the permission/tool loop reported for some
  OpenAI-compatible proxies (CollaboraOnline/online #15997). Our fixtures
  must reproduce the exact multi-round shape; if Collabora needs a specific
  quirk (e.g. `tool_calls` ids echoed verbatim), it belongs in the gateway,
  not in the providers.

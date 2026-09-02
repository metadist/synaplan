# Sprint 3 — Grounded answers, citations, and chat discoverability

Status: planned
Date: 2026-09-02
Depends on: sprint 1 (topic + injection), sprint 2 (corpus + sync state)

Three steps. After this sprint "How do I connect WhatsApp?" answers from
the documentation with a clickable `[Doc:channels]` pill, and a signed-in
user who does not know what to type sees one quiet hint that asking is
allowed.

Invariants in play: C1 (flags off ⇒ identical), C5 (widget untouched),
C7 (no prices), C8 (five locales).

---

## SA7 — Bind the `synaplan` topic to the docs corpus

Branch: `feat/self-aware-docs-retrieval`

### Backend

- `backend/src/Service/SelfAware/Docs/PlatformDocsRetriever.php` —
  `final readonly`, `retrieve(string $query, int $userId, int $limit = 5, float $minScore = 0.35): PlatformDocsHits`:
  - returns empty when `!SelfAwareConfig::isDocsRagEnabled($userId)` or the
    sync state has no pages;
  - `VectorSearchService::semanticSearch($query, 0, 'SYSTEM:synaplan', $limit, $minScore)`
    — **owner 0**, so the query embeds with the same model the corpus used
    (Decision 4);
  - maps each hit's `file_id` through `PlatformDocsSyncState::pageByFileId()`
    to `{slug, title, url, section}`; hits whose file id is unknown (state
    rewritten mid-flight) are dropped, never rendered with a guessed URL;
  - de-duplicates by slug, keeps the best chunk per page, max 4 pages.
  `PlatformDocsHits` carries `list<PlatformDocsHit{slug,title,url,section,text,score}>`.

- `KnowledgeContextFormatter::formatPlatformDocsContext(PlatformDocsHits): string`:

  ```text
  ## Synaplan documentation (relevant to this question):
  [Doc:channels] Channels: WhatsApp & Email — <chunk text>
  [Doc:widget] Widget Integration — <chunk text>

  Use only what is written above for how-to and feature questions. REFERENCES: cite the page you used as [Doc:slug] (clickable). Rules:
  - ONE slug per bracket. Good: [Doc:channels] and [Doc:widget]. Bad: [Doc:channels, widget].
  - Only slugs from the list above. Never invent slugs or URLs.
  - If the documentation does not answer the question, say so and refer the user to the documentation site instead of guessing.
  ```

  The wording mirrors `formatMemoriesContext()` on purpose — the model
  already follows that pattern reliably.

- `SelfAwarePromptDecorator` (sprint 1) grows a second placeholder:
  `[PLATFORM_DOCS]` is replaced in the `synaplan` topic only (never in
  `general` — docs retrieval is one embedding call per turn and belongs to
  the explicit product topic). The decorator receives the hits from the
  handler so it stays a pure string function.

- `ChatHandler::handleStream()`: for topic `synaplan`, call the retriever
  before prompt assembly and emit the status **`docs_loaded`** with
  `metadata.docs = [{slug, title, url}]` through the same callback that
  emits `memories_loaded` (~L2971), so `StreamController` forwards it
  without change. `handle()` (non-stream) attaches the same list to the
  response metadata.
- Multitask: `ChatRunner::ragContext()` (`Service/Multitask/Execution/Runner/`)
  — when the `chat` node's `topic_id` is `synaplan`, use the retriever
  instead of the user-scoped `rag_query`. No new `Capability`.
- `docs_loaded` is added to the SSE status list in the OpenAPI description
  of the stream endpoint (`StreamController`) — documentation only, the
  payload is free-form like `memories_loaded`.

### Tests

- `PlatformDocsRetrieverTest.php` — flag off ⇒ empty without touching the
  search service; hits mapped via state; unknown file id dropped; per-slug
  de-dup; owner 0 is what reaches `semanticSearch` (assert the argument).
- `KnowledgeContextFormatterTest.php` — extend: `[Doc:slug]` block shape,
  deterministic order, no URL other than the state's.
- `ChatHandler` characterization/integration: a `synaplan` turn emits
  `docs_loaded` before `generating`; a `general` turn does not.

### Must NOT touch

`WidgetPublicController`, `tools:widget-default`, user RAG (`[Source N]`),
`MessageText.vue` (SA8).

### Acceptance

Dev stack after `app:selfaware:sync-docs`:

1. "How do I connect WhatsApp?" → `synaplan`; SSE shows `docs_loaded` with
   `channels`; the answer contains `[Doc:channels]` and describes the Meta
   Business API steps from the page.
2. "Wie binde ich das Chat-Widget ein?" → German answer, `[Doc:widget]`.
3. "Can you create PDFs?" → still answered from the inventory; if a docs hit
   is present it is cited, but the availability statement follows the
   inventory (Decision 1).
4. `SELF_AWARE.DOCS_RAG_ENABLED=false` → no `docs_loaded`, no `[Doc:…]`,
   inventory answers unchanged.

### Gate

```bash
make -C backend lint && make -C backend phpstan && make -C backend test
```

---

## SA8 — `[Doc:slug]` pills and `docs_loaded` in the frontend

Branch: `feat/self-aware-doc-pills`

### Frontend

- `frontend/src/views/ChatView.vue` — next to the `memories_loaded` handler
  (~L3365): on `docs_loaded`, store `data.metadata.docs` on the streaming
  message (`message.docs`) the same way memories are attached, so the pill
  renderer can resolve slugs. Persisted messages: the backend returns the
  list in message metadata for history reloads (SA7 non-stream path); if it
  is absent, pills degrade to plain text — never a guessed link.
- `frontend/src/components/MessageText.vue` — a `[Doc:slug]` transform
  beside the `[Memory:ID]` one (regex `\[Doc:([a-z0-9-]+)\]`, tolerate the
  trailing-dots quirk the memory regex handles). Render as a **link pill**:
  `<a>` with `href` = the `url` from `message.docs`, `target="_blank"`,
  `rel="noopener"`, text = `title`, a small book icon, tooltip
  `t('selfAware.docPill.tooltip')` ("Open in the documentation"). Unknown
  slug ⇒ plain `[Doc:slug]` text with no link. Streaming: the pill appears
  when the closing bracket arrives, like memory pills.
- Styling **only** through tokens/utilities from `style.css` (`.pill`,
  `var(--txt-secondary)`, `var(--bg-card)` …) — the memory pill's
  Tailwind-gray classes are legacy; do not copy them. Verify light, dark,
  and V2 (`.design-v2`) with the pill inside an assistant bubble.
- Extract the pill renderers if `MessageText.vue` grows past its current
  size: `components/chat/refs/DocRefPill.ts` (pure function returning the
  HTML string, unit-testable) — same shape for the memory pill is out of
  scope.

### i18n

`selfAware.docPill.tooltip`, `selfAware.docPill.ariaLabel` in `en`, `de`,
`es`, `fr`, `tr`. Canonical term: **documentation** (de: *Dokumentation*,
es: *documentación*, fr: *documentation*, tr: *dokümantasyon*).

### Tests

- `tests/unit/components/MessageText.docRef.spec.ts` — renders a link for a
  known slug with the given `url`; plain text for an unknown slug; no
  `href` ever built from a hardcoded host; `[Doc:a, b]` left untouched.
- `localeParity.spec.ts` stays green (no ledger additions).

### Acceptance

With SA7 on the dev stack, the WhatsApp question shows a "Channels:
WhatsApp & Email" pill that opens `https://docs.synaplan.com/channels` in a
new tab; readable in light, dark, and V2; keyboard-focusable.

### Gate

```bash
make -C frontend lint && docker compose exec -T frontend npm run check:types && make -C frontend test
```

---

## SA9 — Discoverability: hint line, `/help`, runtime-config feature flag

Branch: `feat/self-aware-discoverability`

### Backend

- `ConfigController::getRuntimeConfig()` — add
  `features.selfAware: bool` (`SelfAwareConfig::isEnabled($userId)`), with
  the OpenAPI property (`description`, `example`) next to `savedTasks`.
  Regenerate: `make -C frontend generate-schemas`, then `vue-tsc`.

### Frontend

- **Empty-chat hint (signed-in).** The streamlining decision stands:
  signed-in empty chats keep the greeting and get **no teaser cards**. Add
  exactly one quiet line under `welcomeGreeting` in `ChatView.vue`,
  `txt-secondary`, rendered only when
  `configStore.features.selfAware && !incognitoStore.active`:
  `t('selfAware.emptyHint')` — "Not sure what's possible here? Ask me what
  I can do." — where the second sentence is a button-styled link that sends
  `t('selfAware.emptyHintQuestion')` ("What can you do here?") as a normal
  message via `handleSendMessage`. No new component; ≤ 15 lines.
- **Guest landing.** `ExamplePrompts.vue` gets one additional card
  `selfAware.examplePrompt` ("What can you do?") when
  `configStore.features.selfAware`. Guests go through the same pipeline; the
  inventory is built for the guest user id, so guest-gated features show as
  not available — which is the truth for that visitor.
- **`/help` in the composer.** `ToolsDropdown.vue` command list: add
  `{ command: 'help', label: t('selfAware.helpCommand.label'), description: t('selfAware.helpCommand.description') }`
  in the same shape as `search` / `pic` / `vid`, shown only when
  `features.selfAware`. Insertion behaviour identical to the other commands.

### i18n (all five locales)

`selfAware.emptyHint`, `selfAware.emptyHintQuestion`,
`selfAware.examplePrompt`, `selfAware.helpCommand.label` ("Help"),
`selfAware.helpCommand.description` ("Ask what this AI assistant can do
here"). Use the canonical **AI assistant** term (de: *KI-Assistent*, es:
*asistente de IA*, fr: *assistant IA*, tr: *AI asistanı*).

### Tests

- `tests/unit/views/ChatView.selfAwareHint.spec.ts` (or extend the existing
  ChatView empty-state spec): hint visible with the flag, hidden without,
  hidden in incognito; clicking sends the question text.
- `ToolsDropdown` spec: `/help` present only with the flag.
- `localeParity.spec.ts`, `vue-tsc`, generated schema diff reviewed.

### Must NOT touch

Widget composer, `SetupChatModal.vue`, `useHelp` tours, `MarketingNews`.

### Acceptance

Signed-in, empty chat, flag on: one hint line under the greeting; clicking
it produces the "What can you do here?" turn routed to `synaplan`. Flag off
(`SELF_AWARE.ENABLED=false`): no hint, no `/help`, no example card —
pixel-identical to today. Incognito: no hint. Light/dark/V2 checked.

### Gate

```bash
make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types
```

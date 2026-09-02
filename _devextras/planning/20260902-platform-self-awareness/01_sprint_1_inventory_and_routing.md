# Sprint 1 — Capability inventory, `synaplan` topic, graceful inability

Status: planned
Date: 2026-09-02
Depends on: `00_master_plan.md` (Decisions 1–3, 8, 9)

Three steps, each one branch and one PR, each shippable alone. No network,
no RAG, no frontend. After this sprint the chat answers "Can you create
PDFs?" truthfully for the install it runs on and stops faking files for
things it cannot deliver.

Flag rule: everything in this sprint is a no-op when `SELF_AWARE.ENABLED` is
`false` (invariant C1). Check the flag through one service,
`SelfAwareConfig`, never by reading BCONFIG inline.

---

## SA1 — `PlatformCapabilityInventory` (no wiring yet)

Branch: `feat/self-aware-inventory`

### Backend

New namespace `backend/src/Service/SelfAware/`:

- `SelfAwareConfig.php` — `final readonly`, modelled on
  `Service/Multitask/MultitaskRoutingConfig.php`: `CONFIG_GROUP = 'SELF_AWARE'`,
  `KEY_ENABLED`, `KEY_INVENTORY_IN_GENERAL`, `KEY_DOCS_RAG_ENABLED`,
  `KEY_DOCS_MANIFEST_URL`, `KEY_DOCS_SYNC_STATE`; `isEnabled(?int $userId)`,
  `isInventoryInGeneral(?int $userId)`, `isDocsRagEnabled(?int $userId)`,
  `docsManifestUrl(): string`. Per-user override → owner 0 → constant
  default, exactly like `MultitaskRoutingConfig::resolveFlag()`.
- `CapabilityState.php` — `enum CapabilityState: string { Available = 'available'; NeedsSetup = 'needs_setup'; Absent = 'absent'; }`.
- `CapabilityFact.php` — `final readonly` DTO:
  `id` (stable snake_case key), `label` (English, prompt-facing),
  `state`, `detail` (one short clause, e.g. "DOCX, XLSX, PPTX, CSV"),
  `alternative` (`?string`, mandatory when state ≠ available),
  `adminHint` (`?string`, where to enable it), `docsSlug` (`?string`,
  page in `synaplan-docs`).
- `CapabilityReport.php` — `final readonly` with `list<CapabilityFact> $facts`,
  `string $version`, `bool $billingEnabled`, `bool $isAdmin`; helpers
  `byState(CapabilityState)`.
- `PlatformCapabilityInventory.php` — `final readonly`, `build(int $userId): CapabilityReport`.
  Every fact comes from a source that **already gates the behaviour**
  (invariant C6):

  | Fact id | `available` when | Source |
  | ------- | ---------------- | ------ |
  | `chat` | a chat provider with a key | `ChatReadinessService::isChatReady(userId:)` |
  | `file_analysis` | a `PIC2TEXT` model resolves + chat ready | `ModelConfigService::getDefaultModel('PIC2TEXT', $userId)` + provider availability as used by `ChatReadinessService` |
  | `knowledge_search` | a `VECTORIZE` model resolves | `ModelConfigService::getDefaultModel('VECTORIZE', $userId)`; `detail` = `VectorStorageFacade::getProviderName()` |
  | `memories` | Qdrant configured | same check as `ConfigController::getRuntimeConfig()` `features.memoryService` |
  | `image_generation` | a `TEXT2PIC` model resolves | `ModelConfigService` |
  | `video_generation` | a `TEXT2VID` model resolves | `ModelConfigService` |
  | `text_to_speech` | a `TEXT2SOUND` model resolves **or** `SYNAPLAN_TTS_URL` set | `ModelConfigService`, TTS client `isEnabled()` |
  | `speech_to_text` | a `SOUND2TEXT` model resolves (whisper.cpp ships in the base image) | `ModelConfigService` |
  | `web_search` | Brave key present | the same check `BraveSearchService` / `WebSearchTopicPolicy` use today |
  | `url_fetch`, `mcp_fetch`, `mcp_action`, `email_search` | flag on for this user | `SkillCatalog::descriptorFor()` + `MultitaskRoutingConfig::isFeatureEnabled()` — reuse, do not re-implement |
  | `document_generation` | always (PhpOffice in image) | `detail` from `DocumentGeneratorService` supported formats; `pdf_export` is a **separate fact**: `available` iff `OfficeConverterClient::isEnabled()` (office-docs A0); until A0 lands, a constant `needs_setup` with `adminHint` "office engine (`OFFICE_CONVERT_URL`)" |
  | `calendar_event` | always | `Capability::CalendarEvent` |
  | `email_me` | mailer configured | the check `InternalEmailService` uses |
  | `save_to_folder` | user has a WebDAV destination | `WebDavDestinationProvider` |
  | `saved_tasks` | flag | `SavedTaskConfig::isEnabled($userId)` |
  | `desktop_skills` | flag | `DesktopAgentConfig::isEnabled($userId)` |
  | `mcp_server` | flag | `McpConfig` (server side) |
  | `channel_whatsapp`, `channel_email` | configured for this user | the per-user channel settings the channel handlers read |
  | `plugins` | installed plugins with `chatCommands` | `PluginManager` — `detail` lists `name (/command)` |
  | `upload_formats` | always | `CapabilityService` (existing `/config/capabilities`) — `detail` = the format list |
  | `custom_topics` | user has own prompts | `PromptRepository::getTopicsWithDescriptions(0, $userId, excludeTools: true)` filtered to owner = user |

  Plus `KNOWN_ABSENT` (`public const`, reviewed each release):
  `music_generation` (alternative: original lyrics + text-to-speech when
  available), `code_execution` (alternative: Synaplan Desktop skills,
  `docsSlug: desktop-skills`), `phone_calls`, `authenticated_browsing`,
  `pdf_inplace_editing` (alternative: analyse the PDF, regenerate as DOCX),
  `live_human_operator_in_chat` (alternative: widget live support is an
  operator-side feature, `docsSlug: architecture`).

  `version` from `UpdateStatusService` (running version; published version
  when newer). `billingEnabled` from the same source the runtime config uses.
  `isAdmin` from the user's roles.

- `CapabilityReportRenderer.php` — `render(CapabilityReport): string`,
  deterministic order (fact table order, then `KNOWN_ABSENT`), three lines
  of grouped facts plus rules; **≤ 350 tokens** for a full install (assert
  in a test with a rough 4-chars-per-token bound, 1 400 characters). Shape:

  ```text
  ## This Synaplan installation (live, version 4.2.1)
  AVAILABLE NOW: chat · file analysis (PDF, Word, Excel, images, audio) · knowledge search over your files · image generation (/pic) · documents as DOCX, XLSX, PPTX, CSV · calendar invites (.ics) · web search · text-to-speech (MP3) · memories · saved tasks · plugins: Synaform (/form), FastBill (/fastbill)
  NEEDS SETUP: video generation (no video model configured) · PDF export (office engine not configured)
  NOT AVAILABLE: composing or producing music — alternative: original lyrics, read aloud as MP3 · running arbitrary code — alternative: Synaplan Desktop skills · …
  RULES: When asked whether you can do something, answer from the lists above and nothing else. Say plainly what is not available here and offer the closest alternative. Never promise, describe, or link a file you are not delivering in this turn. Never quote prices, plan limits or quotas.
  ```

  Admin variant appends the `adminHint` in parentheses to each
  `NEEDS SETUP` item; non-admin variant appends "— ask your administrator"
  once. When `billingEnabled`, the RULES line ends with "For plans and
  pricing, link the pricing page." Otherwise plans are not mentioned.

- Caching: `CachedPlatformCapabilityInventory` decorator (`cache.app`,
  key `selfaware.inventory.{userId}.{isAdmin}`, TTL 300 s). Invalidate on
  the admin "AI Models" save and on provider-key save (call `forget($userId)`
  from those services; a stale 5-minute window is acceptable everywhere else).

### Tests

- `tests/Unit/Service/SelfAware/PlatformCapabilityInventoryTest.php` —
  three fixture installs (no keys; one chat key; full stack with TTS +
  office engine) via mocked collaborators; asserts states per fact id,
  `alternative` present for every non-available fact, `KNOWN_ABSENT` all
  `absent`.
- `CapabilityReportRendererTest.php` — token budget, determinism (two
  renders identical), admin vs non-admin text, billing on/off wording,
  contains no digit followed by a currency symbol.
- PHPStan clean at the project level; `readonly` + `final` throughout.

### Must NOT touch

`ChatHandler`, `PromptCatalog`, `MessageClassifier`, frontend. This step is
pure library code.

### Acceptance

`docker compose exec -T backend php bin/console debug:container PlatformCapabilityInventory`
lists the service; a throwaway `bin/console app:selfaware:inventory --user 2`
(dev-only command added here, kept — it is the admin's debugging tool) prints
the rendered block for user 2 on the dev stack and shows `pdf_export` as
`needs_setup` and `music_generation` as `absent`.

### Gate

```bash
make -C backend lint && make -C backend phpstan && make -C backend test
```

---

## SA2 — `synaplan` topic, routing rules, `general` rewrite, flags

Branch: `feat/self-aware-topic`

### Backend

- `PromptCatalog::all()`: new routable entry `synaplan` (owner 0, seeded
  like `general`) with `description` for `[DYNAMICLIST]`:

  > Questions about Synaplan itself: what it can and cannot do, how to use a
  > feature (files, widgets, channels, plugins, desktop, API), what is new,
  > plans and pricing, "what are you / are you ChatGPT". NOT for doing the
  > task — a request to *create* something goes to the topic that creates it.

  System prompt `synaplanPrompt()` (English, one text):
  identity line (Decision 8.6); "answer from the PLATFORM CAPABILITIES block
  and, when present, the Documentation context; if neither covers the
  question, say so and point to the documentation link"; the `general` hard
  rules 1–2 and 6 verbatim; the pricing rule; the music/lyrics rule; the
  admin-hint rule; style: short, bullets when listing capabilities, always
  end a "no" with the alternative. Include the literal placeholder
  `[PLATFORM_CAPABILITIES]` and `[PLATFORM_DOCS]` (the latter empty until
  sprint 3) so the injection point is explicit and testable.

- `PromptCatalog::sortPrompt()` — add rule after the media rule:

  > **Questions about Synaplan itself** — whether it can do something
  > ("can you make PDFs?", "kannst du Videos erstellen?"), how a feature
  > works, what is new, what it costs, who/what it is → BTOPIC "synaplan".
  > A request to actually produce the thing ("create a PDF of this text") is
  > NOT a question about Synaplan — route it to the topic that produces it.

  Same rule in `planPrompt()` next to the existing "Plain question" example:
  one `chat` node, `topic_id: "synaplan"`, never the reply node for
  `email_me`.

- `PromptCatalog::generalPrompt()` — rewrite rule 3 and the style rule:

  > 3. If the user asks for a file (audio, image, video, document,
  > spreadsheet, slide deck, calendar invite): check the PLATFORM
  > CAPABILITIES block. If the format is AVAILABLE NOW, reply that you will
  > write the text and ask the user to rephrase as "create/generate …" so
  > the request reaches the generator. If it is NEEDS SETUP or NOT
  > AVAILABLE, say so in one sentence and offer the listed alternative.
  > Never pretend to attach anything.

  Style: "No meta-commentary about being an AI or your training —
  **except** when the user asks what you can do; then answer from the
  PLATFORM CAPABILITIES block." Append the `[PLATFORM_CAPABILITIES]`
  placeholder at the end of the prompt.

- `backend/src/Seed/SelfAwareConfigSeeder.php` — four rows via
  `BConfigSeeder::insertIfMissing` (Decision 9 table), owner 0; wired into
  `SeedAllCommand` as step 18 (renumber `demo-widget` to 19, update the
  docblock and `setHelp()` list).

- `PromptSeeder` already seeds whatever `PromptCatalog::all()` returns —
  confirm the new row appears with `app:prompt:seed` and is `excludeTools`-
  visible (no `tools:` prefix).

### Tests

- Re-record and review: `RoutingCharacterizationTest` (`routing_classification.json`),
  `PlannerPromptCharacterizationTest` (`planner_system_prompt.txt` gains the
  `synaplan` line in `[DYNAMICLIST]` and the new rule). Every changed line is
  explained in the PR description.
- `tests/Unit/Prompt/PromptCatalogTest.php` (extend or add): `synaplan`
  present, no `tools:` prefix, description non-empty, `generalPrompt()` and
  `synaplanPrompt()` contain `[PLATFORM_CAPABILITIES]` exactly once.
- Sorter routing on the eval set is verified in SA10 (needs a model); here,
  a unit test asserts the `tools:sort` text contains the new rule.

### Must NOT touch

`ChatHandler` (injection is SA3), `tools:widget-default`,
`tools:widget-setup-interview`, frontend.

### Acceptance

On the dev stack after `app:seed`: `SELECT BTOPIC FROM BPROMPTS WHERE BOWNERID=0`
lists `synaplan`; `SELECT * FROM BCONFIG WHERE BGROUP='SELF_AWARE'` shows four
rows; sending "Can you create PDFs?" in the web chat is classified
`topic=synaplan` (backend log `MessageSorter: … BTOPIC`) — the answer itself
is still unaware of the install until SA3.

### Gate

```bash
make -C backend lint && make -C backend phpstan && make -C backend test
docker compose exec -T -e UPDATE_ROUTING_SNAPSHOTS=1 backend ./vendor/bin/phpunit tests/Characterization
git diff backend/tests/Characterization/__snapshots__/   # review, then re-run make -C backend test
```

---

## SA3 — Inject the inventory, guard the fast path, add `/help`

Branch: `feat/self-aware-injection`

### Backend

- `ChatHandler::handle()` / `handleStream()` (system-prompt assembly,
  ~L400–530 / ~L1001–1148): after the topic prompt is loaded and **before**
  RAG/memories are appended, replace `[PLATFORM_CAPABILITIES]` with
  `CapabilityReportRenderer::render($inventory->build($userId))` when
  - `SelfAwareConfig::isEnabled($userId)`, and
  - topic is `synaplan`, or topic is `general` and
    `isInventoryInGeneral($userId)`, and
  - the message did not originate from a widget session (reuse the same
    distinction the handler already makes for widget prompts — verify the
    exact predicate while implementing; it must be one shared helper, not a
    duplicated condition in both code paths).

  Otherwise strip the placeholder (never ship the literal token to a model).
  Do this in one new `SelfAwarePromptDecorator::apply(string $prompt, string $topic, int $userId, bool $isWidget): string`
  so both code paths call one line, and `StreamController` needs no change.

- `MessageClassifier::TOOL_COMMANDS` — add `'/help' => 'synaplan'`.
  `detectToolCommand()` returns the topic unchanged; verify the downstream
  handler mapping treats a non-`tools:` topic from a command exactly like a
  sorter result (it should — `officemaker` reaches `ChatHandler` the same
  way). Strip the `/help` prefix like the other commands do.

- `MessageClassifier::canFastPathClassify()` — add the meta-question guard
  (Decision 3) as one `private const SELF_AWARE_GUARD_PATTERN` with a
  comment listing the five languages; returns `false` (defer to sorter) on
  match. Only active when `SelfAwareConfig::isEnabled()`.

- `SkillCatalog`: no change. The planner path gets the inventory through the
  `synaplan` topic's `chat` node (ChatHandler renders the prompt), so
  multitask needs no extra wiring here.

### Tests

- `tests/Unit/Service/SelfAware/SelfAwarePromptDecoratorTest.php` —
  placeholder replaced for `synaplan`; replaced for `general` only with the
  flag; stripped for other topics, for widgets, and when disabled; never
  leaves `[PLATFORM_CAPABILITIES]` in the output.
- `MessageClassifier` unit tests: `/help` → `synaplan`; guard defers
  "can you make PDFs?" / "was kannst du?" / "¿puedes buscar en internet?" /
  "peux-tu lire mes e-mails ?" / "video yapabilir misin?" when fast path is
  forced on; "write me a poem" still fast-paths.
- Re-record `routing_classification.json` (the `/help` case is added to the
  38 cases) and review.

### Must NOT touch

`WidgetPublicController`, `tools:widget-default`, `KnowledgeContextFormatter`,
frontend.

### Acceptance

Dev stack, no office engine, one chat key:

1. "Can you create PDFs?" → routed `synaplan`; answer states PDF is not set
   up here, names DOCX/XLSX/PPTX as available, no link, no fake file, ends
   with an offer.
2. "Create a PDF of the following text: …" → routed as today (not
   `synaplan`); answer offers DOCX instead of a fabricated download; the
   `__FILE_GENERATED__` path is not triggered.
3. "Would you create a song like the Beatles?" → answer: cannot compose or
   produce music; offers original lyrics in that style; mentions reading
   them aloud only if TTS is available on the stack.
4. `/help` → the same as "What can you do here?".
5. Same four in a widget conversation → unchanged behaviour from today.
6. Set `SELF_AWARE.ENABLED=false` (BCONFIG, owner 0) → 1–4 behave exactly as
   before this sprint.

### Gate

```bash
make lint && make -C backend phpstan && make test
```

(Frontend unchanged; the one-shot gate is still run because `make test`
covers both.)

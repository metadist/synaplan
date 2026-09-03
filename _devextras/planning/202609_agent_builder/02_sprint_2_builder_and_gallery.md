# Sprint S2 — Builder and gallery

**Track 2 (Agent Builder), sprint 2 of 6.** Steps `AB9`–`AB17`.

**Goal:** A non-technical user opens Manage → Assistants, builds an assistant in a form (Basics, Instructions, Models, Knowledge), tests it against the draft in a side panel, clones another one, and starts a chat from the gallery — without reading docs. `/ai/instructions` redirects to the new page; the Messages-gateway page loses the colliding "AI Agents" label.
**Depends on:** S1 (`AB1`–`AB8`): CRUD API, `agentId` on the stream endpoint, generated Zod schemas.
**Unlocks:** S3 (publish button and version list plug into this form), S4/S5 (further form sections), S6 (Export & import lives beside this UI).
**Repos:** `synaplan/` only. **Class:** `ota-candidate` (Vue, i18n, generated schemas) + `backend-only` for `AB9`, `AB10`, `AB14a`.
**Flag:** `AGENTS.ENABLED` off ⇒ nav shows `Instructions` as today, `/ai/assistants` is not registered, no redirect is installed. The label rename in `AB17` is **not** flag-gated (decision 2 is a plain copy fix).

---

## 0. Why this sprint exists

S1 made assistants real for the API. This sprint makes them real for people. Decision 10 (form first) and decision 5 (replace `/ai/instructions`) mean the current Instructions page is absorbed: the prompt is edited *inside* the assistant. The test panel exists so that editing never touches what other users see — that becomes load-bearing in S3 when published versions arrive.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `frontend/src/components/config/TaskPromptsConfiguration.vue` | Today's Instructions editor; the builder's Instructions section reuses its prompt textarea + model picker logic, then this component leaves the route |
| `frontend/src/views/ConfigView.vue` | Renders `TaskPromptsConfiguration` for `/ai/instructions`; the new route gets its own view |
| `frontend/src/router/index.ts` | `ai-instructions` route, existing redirect `/config/task-prompts → /ai/instructions`, `channels-agents` route |
| `frontend/src/composables/useNavItems.ts` | `grouped('assistants', …)` children `/ai/models`, `/ai/instructions`, `/ai/routing`; key `ai-agents` label `t('nav.aiAgents')` |
| `frontend/src/i18n/en.json` | `pageTitles.aiAgents`, `nav.aiAgents`, `messagesGateway.title` / `loadError` — every "AI Agents" string |
| `frontend/src/services/api/promptsApi.ts`, `widgetsApi.ts` | `httpClient` + Zod pattern for the new `agentsApi.ts` |
| `frontend/src/components/widgets/setup-wizard/WidgetSetupWizard.vue` + `backend/src/Service/WidgetSetupService.php` | The AI Setup Assistant (`tools:widget-setup-interview`, `sendSetupMessage()`, `generatePrompt()`) reused as the optional helper |
| `frontend/src/views/ChatView.vue` | How a chat is started with `promptTopic`; add `agentId` the same way |
| `frontend/src/stores/config.ts` | Runtime config store (`agentsEnabled`) |
| `frontend/tests/unit/i18n/localeParity.spec.ts` | Five-locale parity gate; new namespace `assistants` must be complete in all locales |

---

## 2. Developer steps

### 2.1 Gallery API (`AB9`, backend)

`GET /api/v1/agents/gallery` returns cards for **my** assistants only in S2 (shared-with-me and plugin packs are S3/S6; the response shape already has `origin: 'mine' | 'shared' | 'plugin'`). Card fields: `id`, `slug`, `name`, `description`, `icon`, `status`, `origin`, `ownerName`, `version` (null until S3), `updatedAt`, `starterPrompts` (first three). Never the draft JSON. Full OpenAPI; `GalleryCardSchema` regenerated.

### 2.2 Clone API (`AB10`, backend)

`POST /api/v1/agents/{id}/clone` → 201 new draft owned by the caller:

- Copies `BDRAFT` (the published definition once S3 exists), creates a new `BPROMPTS` row with the source prompt text via `PromptService`, sets `BPARENTID = source.BID`, `BSOURCE = manual`, `BSTATUS = draft`, slug `{source-slug}-copy` (deduplicated `-2`, `-3`).
- S2 permission: owner only (cloning a shared assistant needs `read`, wired in S3 through `AccessGate`). Foreign id ⇒ 404.
- `knowledge.folders` is copied as-is; `knowledge.ownFolder` starts empty — files are never copied, the clone has its own `TASKPROMPT:agent:{slug}`.

### 2.3 Frontend API client and store (`AB11`)

- `frontend/src/services/api/agentsApi.ts`: `list`, `get`, `create`, `update`, `remove`, `clone`, `gallery`, every call with the generated schema (`AgentSchema`, `GalleryCardSchema`, …). No hand-written interfaces.
- `frontend/src/stores/agents.ts` (setup style, `ref()` + `computed()`): `gallery`, `current`, `dirty`, `saveDraft()` with a 600 ms debounce plus an explicit Save button (autosave only touches the draft — nothing is live yet).

### 2.4 Gallery view (`AB12`)

Route `/ai/assistants` (name `ai-assistants`), view `frontend/src/views/AssistantsView.vue`, components under `frontend/src/components/assistants/`:

- `AssistantGallery.vue`: filter chips (`Mine`; `Shared with me` and `From plugins` appear in S3/S6), search, `AssistantCard.vue` with **Start chat**, **Clone**, **Edit** (owner), **Details** (owner, description, version). Empty state: one sentence + **Create assistant**.
- **Start chat** navigates to the chat view with `agentId`; the composer shows a pill "Talking to {name}" for the chat's lifetime; on reopen the pill is derived from message meta `AGENTID`.
- Tokens only (`surface-card`, `btn-primary`); dark, V2 and 320 px checked before merge.

### 2.5 Builder form — first four sections (`AB13`)

`AssistantBuilder.vue` at `/ai/assistants/:id` (`new` for a fresh draft), progressive disclosure, each section its own component ≤ 300 lines. Validation errors from `PATCH` (400 with `path`) render next to the field they belong to; the first section alone yields a working assistant.

| Section | Component | Writes |
| ------- | --------- | ------ |
| Basics | `BuilderBasics.vue` | `name`, `description`, `icon`, `behaviour.greeting`, `behaviour.starterPrompts[]` |
| Instructions | `BuilderInstructions.vue` | The `BPROMPTS` text via `promptsApi` (existing contract, C3) |
| Models | `BuilderModels.vue` | `models.chat` / `vision` / `vectorize` as catalog keys picked from the user's selectable models; "Use my default" = `null` |
| Knowledge | `BuilderKnowledge.vue` | `knowledge.ownFolder` (upload/list files in `TASKPROMPT:agent:{slug}` via the existing files API), `ragLimit`, `ragMinScore` |

### 2.6 Optional AI helper inside Instructions (`AB14`)

A **Help me write this** button opens a drawer that runs the existing `WidgetSetupService::sendSetupMessage()` interview and finally `generatePrompt()`; the result is *proposed* into the textarea with **Use this** / **Discard** — the helper never saves on its own. New route `POST /api/v1/agents/{id}/instructions/assist` adapts the service to an agent: its `Widget` parameter becomes a `SetupSubjectInterface` in a small `refactor(widgets)` PR first (`AB14a`), no behaviour change for widgets.

### 2.7 Test panel (`AB15`)

`AssistantTestPanel.vue`: a side chat that posts to `/api/v1/messages/stream` with `agentId` and `draft: true`; the backend (`AB7` path) passes `draft = true` to `AgentRuntimeResolver` only when the caller is the owner, otherwise 403. Test chats are incognito (transient, not listed in history) so testing leaves no trace. Resolver notes are shown inline ("Using your default chat model").

### 2.8 Route redirect and nav (`AB16`)

- Router: `/ai/instructions` → `redirect: '/ai/assistants'` when `agentsEnabled`; the legacy `/config/task-prompts` redirect follows the chain. Name `ai-instructions` is kept as an alias so deep links in docs keep working.
- `useNavItems.ts`: the `assistants` group child `Instructions` becomes `Assistants` (`nav.assistants`, path `/ai/assistants`). Net nav item count unchanged (master plan §5).
- Flag off: nav and route behave exactly as today (spec on the nav composable with `agentsEnabled = false`).

### 2.9 Five locales and the gateway label (`AB17`)

- New namespace `assistants` in `en`, `de`, `es`, `fr`, `tr` with the words fixed in master plan §5: Assistant / Assistent / Asistente / Assistant / Asistan; Clone / Duplizieren / Duplicar / Dupliquer / Kopyala; Version / Version / Versión / Version / Sürüm; Instructions / Anweisungen / Instrucciones / Instructions / Talimatlar (Publish arrives in S3 in the same namespace). "Prompt topic", "task prompt" and "system prompt" do not appear in primary copy.
- Rename in **all five** locale files: `pageTitles.aiAgents`, `nav.aiAgents`, `messagesGateway.title` → **Coding clients** / **Coding-Clients** / **Clientes de programación** / **Clients de codage** / **Kodlama istemcileri**; `messagesGateway.loadError` follows ("Could not load Coding clients settings"). Grep afterwards: zero hits for "AI Agents" in `frontend/src/`.
- No new ledger entries in `localeParityBaseline.json`.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C1 | `useNavItems.spec.ts`: with `agentsEnabled = false` the children are `Models` / `Instructions` / `Routing`; router spec: no redirect installed, `/ai/assistants` unknown |
| C2 | Test-panel and gallery chats carry `agentId`; ordinary chats do not — `RoutingCharacterizationTest` untouched |
| C3 | `BuilderInstructions.vue` uses `promptsApi` unchanged; backend prompt API tests green |
| C8 | New Vue/i18n paths fall under `frontend/**` = `ota-candidate`; `AB9`, `AB10`, `AB14a` backend paths listed `backend-only` |

- Frontend unit (Vitest, Pinia + i18n + stubbed `MessageText`): `AssistantGallery.spec.ts` (empty state, Start chat emits `agentId`), `AssistantBuilder.spec.ts` (field-level validation error, Save disabled while clean), `AssistantTestPanel.spec.ts` (posts `draft: true`), `agentsApi.spec.ts` (schema parse failure surfaces as error); `localeParity.spec.ts` green.
- Backend feature: `AgentGalleryTest`, `AgentCloneTest` (lineage, slug dedupe, no file rows copied), `AgentDraftTestModeTest` (non-owner `draft: true` ⇒ 403), `WidgetSetupServiceRefactorTest` (widget behaviour identical after `AB14a`).
- Gate: `make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types`.

---

## 4. Exit criteria / demo

1. Flag off: `/ai/instructions` renders as today; no "AI Agents" string remains anywhere in `frontend/src/`.
2. Flag on: the demo user creates "Contract review" in the form, writes the instructions (optionally with the helper), picks a model, uploads one file, tests in the panel, clicks **Start chat** from the gallery and gets a grounded answer. No docs consulted.
3. **Clone** produces an independent draft; editing it does not change the original (`BPARENTID` set).
4. `/ai/instructions` and `/config/task-prompts` land on `/ai/assistants`; five locales complete; dark, V2 and 320 px verified for gallery, builder and test panel.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| AB9 | `feat(agents): add gallery endpoint (mine)` | backend-only | AB8 |
| AB10 | `feat(agents): add clone endpoint with BPARENTID lineage` | backend-only | AB8 |
| AB11 | `feat(assistants): add agentsApi client and Pinia store` | ota-candidate | AB8, AB9 |
| AB12 | `feat(assistants): add gallery view and start-chat pill` | ota-candidate | AB11 |
| AB13 | `feat(assistants): add builder form (Basics, Instructions, Models, Knowledge)` | ota-candidate | AB11 |
| AB14a | `refactor(widgets): let WidgetSetupService accept a setup subject interface` | backend-only | — |
| AB14b | `feat(assistants): add optional AI helper for instructions` | ota-candidate + backend-only | AB13, AB14a |
| AB15 | `feat(assistants): add draft test panel (owner-only, incognito)` | ota-candidate + backend-only | AB12, AB7 |
| AB16 | `feat(assistants): route /ai/instructions to /ai/assistants and update nav` | ota-candidate | AB12, AB13 |
| AB17 | `fix(i18n): rename Messages-gateway page to Coding clients and add assistants namespace` | ota-candidate | — |

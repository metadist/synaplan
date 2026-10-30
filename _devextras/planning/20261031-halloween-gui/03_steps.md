# 03 — Steps

One section per pull request, in the order of
[`00_master_plan.md`](./00_master_plan.md) §2. Each section is the
change: stay inside the files it names; a second bug found on the way is
recorded in STATUS and gets its own step. Tests are written first, and a
bug's test fails on today's code for the reason in the finding.

Every pull request: branch off current `main`, Conventional Commit, no
attribution, `node scripts/mobile-impact.mjs --base origin/main --head HEAD`
before the push, Copilot review requested, every thread answered, **All
Checks Passed** green before the next step ([`../AGENTS.md`](../AGENTS.md)).

---

## HG0 — Measure the `/v1` route; teach the model stub

**Commit:** `test(e2e): teach the model stub Ollama's listing and the OpenAI routes`
**Branch:** `test/20261031-model-stub` · **Class:** no-app-impact · **Needs:** §0 ticked

### Measure first, record in STATUS

Against the bundled Ollama 0.40.12, an Ollama 0.32.x, and a vLLM (or LM
Studio / llama.cpp when no GPU is at hand — say which), each registered
as a temporary OpenAI-compatible endpoint on the dev stack and removed
afterwards. No key, account or private address in STATUS.

| # | Check |
| - | ----- |
| 1 | `/v1/models` lists the same ids as `/api/tags` |
| 2 | A streamed chat through `OpenAICompatibleProvider` reaches `complete` |
| 3 | Thinking arrives as `delta.reasoning`; the Thinking switch is honoured or ignored (say which) |
| 4 | A tool call with a model that lists `tools` |
| 5 | An image question with a model that lists `vision` |
| 6 | `bge-m3` through `/v1/embeddings`: 1024 dimensions; a file uploaded on a fresh install is found by search |
| 7 | A chat model **without** `tools` in its listing: what a chat turn that needs a tool (an agent tool, an MCP tool) does — answers without it, or fails |
| 8 | `app:sort-eval` with a 3B chat model bound to SORT, next to the gpt-oss-120b score |
| 9 | Which fields each version sends: `capabilities`, `context_length`, `embedding_length`, `remote_host` in `/api/tags`; `/api/show` where they are missing |
| 10 | Time to the first token of a cold 3B model on CPU (sets the test-message deadline in HG5) |
| 11 | Which signal reliably says "this process runs in a container" (`/.dockerenv`, cgroup, `SYNAPLAN_ROLE`) for D4 |

A failed row that native Ollama passes reopens D1 in writing before HG3.

### Stub

`frontend/tests/e2e/stub-servers/ollama/ollama-stub-server.ts` and
`frontend/tests/e2e/helpers/ollama-stub.ts`. Existing routes and their
responses stay as they are, so `ollama-integration.spec.ts` does not
change.

- `GET /api/version`; `capabilities`, `details.context_length` and
  `details.embedding_length` on every `/api/tags` entry; `POST /api/show`.
- `GET /v1/models`, `POST /v1/chat/completions` (streamed and not,
  `delta.reasoning` while thinking is on), `POST /v1/embeddings`.
- A bearer check when a key is configured: 401 without one, 403 with a
  wrong one.
- `/__configure {"preset": …}`: `two-models` (`llama3.2:3b`, `bge-m3`),
  `four-models` (adds `glm-ocr` with `vision` and `glm-5.2:cloud` with
  `remote_host`), `empty`, `unauthorized`, `slow` (first token after a
  configurable delay), `vllm` (`owned_by: "vllm"`, `max_model_len`, no
  `/api/*`).

**Gate:** `make -C frontend lint`; `make test-e2e` with
`ollama-integration.spec.ts` green.
**Exit:** the matrix in STATUS; D1 confirmed or reopened.

---

## HG1 — A disposable fresh install for developers

**Commit:** `chore(dev): start a disposable fresh install next to the dev stack`
**Branch:** `chore/20261031-fresh-install` · **Class:** no-app-impact · **Needs:** HG0, D11

### Change

- `docker-compose.test.fresh.yml`, an overlay on `docker-compose.test.yml`:
  - `app_test`: `SEED_DEMO_DATA: "false"`, `OLLAMA_BASE_URL: ""`, and
    the four fake provider keys the test stack sets (`OPENAI_API_KEY`,
    `ANTHROPIC_API_KEY`, `GROQ_API_KEY`, `GOOGLE_GEMINI_API_KEY`:
    `"test-key"`, its own FIXME) blanked, so the wizard does not show
    cloud providers as connected. If the app needs them to boot, that is
    HG1's first finding and gets a STATUS line.
  - `ollama-stub`: the network alias `ollama`, the preset `two-models` at
    start, and its control port published on `${FRESH_STUB_PORT:-11437}`
    instead of 11434, so a developer's own Ollama keeps its port
    (docs/E2E_TESTING.md already notes that collision).
- Root `Makefile`: `fresh` (builds the test image unless `FRESH_BUILD=0`,
  stops a running test stack and says so, starts the overlay with
  `--wait`, prints the address and the checklist), `fresh-down`
  (`down -v`), `fresh-reset` (both).
- `_devextras/fresh-install/README.md`: what it starts and what it never
  touches, J-HG-1 as a five-minute checklist (light, dark, 320 px, one
  other locale), the J-HG-4 presets
  (`curl -X POST localhost:11437/__configure -d '{"preset":"empty"}'`),
  and how to use a real Ollama instead (`host.docker.internal:11434`).

**Gate:** `make fresh` → `/setup` on `http://localhost:8001` →
`make fresh-down` → `make fresh` → `/setup` again; the dev stack on
`:5173` keeps its data; `make test-e2e-full` unchanged.
**Exit:** J-HG-6 steps 1–3 in STATUS.

---

## HG2 — A model's identity: its server, and `x` = `x:latest`

**Commit:** `fix(models): tell imported models apart by server and treat x:latest as x`
**Branch:** `fix/20261031-model-identity` · **Class:** backend-only · **Closes:** F7, F14

File the two bugs first (title format of the repository's issues; F7's
measured preview, F14's code path), then `Fixes #…` for both in the pull
request. One topic: what makes two catalog rows the same model.

### Change

- **Per server.** An OpenAI-compatible row is identified by `service`,
  `BJSON.endpoint`, `tag` and `providerId`:
  `ModelDiscoveryService::existingProviderIds()` looks at the listed
  endpoint's rows only, and the existence lookup in
  `ModelImportApplier::apply()` includes the endpoint.
- **Routing per row.** The call options carry the selected row's
  `BJSON.endpoint`, so `OpenAICompatibleProvider::endpointForCall()`
  never falls back to the first row with that id; the Messages gateway
  (`OpenAiMessagesTranslator`) resolves the same way.
  `resolveForModel()` by id alone stays only for a row without an
  endpoint (a single-endpoint install).
- **`x` = `x:latest`.** One small canonical-name helper (case-insensitive)
  for Ollama ids, used by the two lookups above and by the withdrawn
  comparison in `ImportedModelListingCheck`. A match that differs only
  by `:latest` is reported as existing. A catalog row without import
  provenance is left untouched — no `meta.import` is written onto it, so
  the listing check never starts treating a seeded row as imported.

### Tests (red first)

- `ModelDiscoveryServiceTest`: catalog `bge-m3`, listing `bge-m3:latest`
  → `exists: true`; endpoint `a` has `llama3.2:3b`, endpoint `b` lists
  it → `exists: false` for `b`.
- `ModelImportApplierTest`: applying `bge-m3:latest` / `vectorize` next to
  `bge-m3` / `vectorize` creates no row and writes no meta on the
  catalog row; importing `llama3.2:3b` from `b` next to `a`'s row
  creates a second row with `endpoint: b`.
- Routing: a chat with `b`'s row goes to `b`'s base URL, a chat with
  `a`'s row to `a`'s (`MockHttpClient`, both directions).
- `ImportedModelListingCheckTest`: an imported `foo:latest` is not
  withdrawn when the server lists `foo`, and the other way round; `a`
  dropping a model does not mark `b`'s row.

**Gate:** `make ci-local`. No Playwright: no screen and no OpenAPI change.

---

## HG3 — The server's own capability list

**Commit:** `feat(models): read capabilities and cloud models from the server listing`
**Branch:** `feat/20261031-server-capabilities` · **Class:** ota-candidate (generated schemas, one dialog line) · **Needs:** HG0 · **Closes:** F5, F6

### Change

- `OllamaProvider::listPulledModels()` keeps `capabilities`,
  `details.context_length`, `details.embedding_length` and `remote_host`
  when the server sends them, and works unchanged when it does not.
- Discovery of an OpenAI-compatible source asks `{root}/api/tags` once
  (through the registry's pinned, SSRF-guarded request) and uses it when
  it answers with models — an Ollama behind `/v1`. HG4 stores the kind so
  later listings skip the question.
- A small mapper from server capabilities to tags: `completion` → chat,
  `embedding` → vectorize, `vision` → pic2text only on a route that reads
  images (OpenAI-compatible yes, native Ollama no). `tools` and
  `thinking` go into the row's `BJSON` under the catalog's existing
  feature keys where one exists, else `meta.import.capabilities`.
- Order: server list → `/api/show` (at most 20 models, 3 s each) → the
  name guess. `DiscoveredModel` gains `capabilities`, `contextLength`,
  `embeddingDimensions`, `remoteHost`, `tagSource` (`server` | `probe` |
  `name`) and `preselected` (false when the row exists or runs remotely).
- A remote row: `priceKnown: false`, never preselected; the applier
  writes `meta.import.remoteHost`.
- OpenAPI on the preview rows, complete with examples; then
  `make -C frontend generate-schemas`.
- `ModelImportDialog.vue`: preselect by `preselected` instead of
  `!exists`; under a remote row, `aiInfra.modelImport.runsRemote` in five
  locales. Nothing else changes in the dialog until HG6.

### Tests

- `ModelDiscoveryServiceTest`: listed capabilities win over the name
  (`glm-ocr` → chat + pic2text on an OpenAI-compatible route, chat only
  on native Ollama); no capabilities → `tagSource: name`; a `remote_host`
  row is not preselected and has no known price.
- `OllamaProvider` listing test: the new fields are kept; a payload
  without them (an older Ollama) still parses.
- `ModelImportDialog.spec.ts`: the remote row is unchecked and shows the
  sentence.

**Gate:** `make ci-local`, generated schemas + `vue-tsc`, `make test-e2e`.

---

## HG4 — Connect by address

**Commit:** `feat(models): connect a model server by its address`
**Branch:** `feat/20261031-connect-model-server` · **Class:** ota-candidate (generated schemas) · **Needs:** HG3, D1, D4, D5

### Change

- **Address** (a value object): parses what an administrator types
  (scheme optional, `/v1`, `/api`, `/api/tags`, `/v1/models` and trailing
  slashes stripped to a root, IPv6 in brackets), knows loopback.
- **Probe** (a service): for one address, `{root}/api/version` +
  `{root}/api/tags` → Ollama; else `{base}/v1/models` or `{base}/models`
  → OpenAI-compatible, flavour from `owned_by` (`vllm`, `llamacpp`, …)
  or `/version`; Synaplan itself is recognised by its own health answer,
  not only by the configured URL, and refused (`isSynaplan`). Every
  request goes through the registry's `resolveUpstream()` pin and
  `SsrfGuard` policy, no redirects, 3 s per call. Transport errors map to the failure codes of
  [`02`](./02_journeys_and_copy.md) §4. Answers `{kind, flavour, version,
  baseUrl, modelCount, addressChanged, tried[], failure}` and stores
  nothing.
- **Loopback in a container** (HG0 row 11 names the signal): try
  `host.docker.internal`, then `ollama` (port 11434 only). The key is
  sent only to the typed host and, for a typed loopback, to those two
  names — never anywhere else.
- **Suggestions** (D5): the fixed list, concurrent requests, 1.5 s each,
  at most 2 s in total, skipped when
  `OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY` is set, never Synaplan's own
  address.
- **Registry:** the encrypted payload gains `kind`, `addedVia`
  (`wizard` | `admin` | `cli`), `addedBy` and `addedAt`; `listEndpoints()`
  returns them, never the key. A name is generated when none is given
  (`ollama-local`, `vllm-gpu01`, a numeric suffix on collision, never
  `synaplan-demo`).
- **API** — the existing `AdminOpenAiEndpointsController`, not a second
  controller for the same resource:
  - `POST /api/v1/admin/openai-endpoints/detect` — address and optional
    key → probe result.
  - `POST /api/v1/admin/openai-endpoints/suggestions` — D5.
  - `POST /api/v1/admin/openai-endpoints` — `name` optional.
  - `GET /api/v1/admin/openai-endpoints` — adds `kind`, provenance,
    `location` (`sameMachine` | `bundled` | `network` | `internet`), the
    catalog count and "new on the server" (a live
    listing, cached 60 s), and the environment-pinned native Ollama as a
    read-only row (`source: environment`).
  - Complete OpenAPI annotations; `make -C frontend generate-schemas`.

### Tests

- Address: a table of inputs → roots, loopback, scheme, `/v1`, IPv6.
- Probe with `MockHttpClient`: Ollama; vLLM; 401 → `needsKey`; 403 →
  `wrongKey`; an HTML 200 → `notModelServer`; Synaplan's own API →
  `isSynaplan`; refused, timeout, unknown name, certificate → their
  codes; the loopback order; the key never sent to a third host;
  public-only refuses private addresses.
- Suggestions: skipped when public-only; within the time budget on a
  fake clock; never Synaplan's address.
- Controller: detect stores nothing; save without a name generates one;
  the list never contains a key; a non-admin gets 403.

**Gate:** `make ci-local`, generated schemas + `vue-tsc`, `make test-e2e`.

---

## HG5 — Import with a cost, choose for chat, console

**Commit:** `feat(models): import a server's models with a cost and choose one for chat`
**Branch:** `feat/20261031-import-with-cost` · **Class:** ota-candidate (generated schemas) · **Needs:** HG4, D6, D7, D14 · **Closes:** F4, F8, F12 · **Journey:** J-HG-5

### Change

- **Apply** takes, per row, `priceIn` and `priceOut` (USD per 1M tokens,
  finite, 0–1000, optional; absent keeps today's behaviour) and, per
  request, `useFor: {chat?, search?, vision?}` naming ids from the same
  request. A sent price is stored with `meta.import.priceKnown: true` and
  `priceSource: admin`; `BSHOWWHENFREE=1` stays; an existing row is never
  repriced.
- **Defaults**, per D7, through the write path
  `ProviderDefaultsService::applyGlobalDefaults()` already uses —
  `DEFAULTMODEL/<PURPOSE>` rows at `ownerId = 0`, then its cache clear —
  extended to take explicit model ids. Not
  `ModelConfigService::setDefaultModel()`: it writes the legacy
  `ai/default_<capability>_model` row, which `getDefaultModel()` does not
  read.
  - chat → CHAT, TOOLS, ANALYZE, SORT, PLAN, SUMMARIZE, MEM, the seven
    purposes `ProviderDefaultsService` writes — the MEM row (tag `mem`,
    not selectable) is created once; TOOLS is the helper-task model
    (`getToolsModelConfig()`), not tool calling, so it simply follows;
    when the server lists no `tools` for the chat model, the response
    says `toolCallsUnsupported` (wording after HG0 row 7);
  - search → VECTORIZE only while no vectors exist, else
    `searchNeedsReindex` with the current model and nothing changed;
  - vision → PIC2TEXT only for a model that reads images on its route;
  - `ai.default_chat_provider` set to the route's provider, so
    `app:provider:apply-defaults --auto` never moves it;
  - the Synaplan Demo is left before any binding;
  - readiness caches dropped, then `isChatReady()` read fresh.
- **Response:** `created`, `skipped`, `rows`, `defaults` (purpose →
  `{id, name}`), `defaultsSkipped` (purpose → reason), `searchNeedsReindex`,
  `toolCallsUnsupported`, `demoLeft`, `chatReady`, `personalOverride`
  (the acting administrator's own CHAT, when it differs). Complete
  OpenAPI.
- **Test message:** `POST /api/v1/admin/openai-endpoints/{name}/try` —
  one fixed prompt, at most 40 tokens, through the normal AI pipeline
  (usage recorded for the administrator at the row's cost), a deadline
  from HG0 row 10. Answers `{ok, text, ms}`, `slow`, or a failure code.
  Always a terminal answer.
- **Disconnect:** `DELETE /api/v1/admin/openai-endpoints/{name}` switches
  the server's imported rows off with `meta.import.disabledBy:
  disconnect`, moves defaults with the replacement rules of
  [#2898](https://github.com/metadist/synaplan/pull/2898), and says what
  moved and whether chat is still ready. Connecting the same address
  again switches on only the rows marked `disconnect`.
- **Console** (D14), thin commands over the same services:
  `app:model-server:add <name> <address> [--key-env=VAR | --key-stdin] [--label=…]`,
  `app:model-server:list [--format=json]`,
  `app:model-server:remove <name> [--yes]`,
  `app:model:import <server> [--all] [--model=ID]… [--cost-in=N] [--cost-out=N]
  [--use-for-chat=ID] [--use-for-search=ID] [--use-for-vision=ID] [--include-remote]
  [--dry-run] [--format=json]`.
  A key on the command line itself is refused (shell history). Output
  and errors are the same sentences as the UI.

### Tests

- `ModelImportApplierTest`: a sent price is stored with `priceSource:
  admin`; a 0/0 import is listed by `GET /api/v1/config/models` (the
  [#2110](https://github.com/metadist/synaplan/issues/2110) regression);
  an existing row keeps its price.
- Defaults service: the seven purposes at `ownerId = 0`; the MEM row
  created once; VECTORIZE untouched while vectors exist; PIC2TEXT only
  where images are read; `ai.default_chat_provider` set; the Demo left
  before the first write (order asserted); a personal override reported.
- Integration on a fresh install whose stored CHAT is Anthropic without
  a key: before the apply, `getConfiguredDefaultModel('CHAT')` names the
  Anthropic row; after one apply with `useFor.chat`,
  `getConfiguredDefaultModel()` — the stored binding, without the
  quality fallback — names the chosen row for each of the seven purposes,
  and `chatReady` is true. `chatReady` alone is not the assertion: it can
  turn true through the fallback (F4). A following
  `app:provider:apply-defaults --auto` changes nothing.
- Disconnect and reconnect: marked rows only; an administrator's own
  switch-off survives.
- Each command with `CommandTester`: JSON shape, a second run changes
  nothing and says so, `--dry-run` writes nothing, `--key` refused.
- Controller: price and `useFor` validation, ids outside the request
  refused, a non-admin gets 403.

**Gate:** `make ci-local` (deptrac and the size ratchet included: the
new code goes into new classes), generated schemas + `vue-tsc`,
`make test-e2e`. J-HG-5 walked on `make fresh`, entered in STATUS.

---

## HG6 — The card in AI infrastructure

**Commit:** `feat(admin): connect a model server and import its models in one card`
**Branch:** `feat/20261031-model-server-card` · **Class:** ota-candidate · **Needs:** HG5, D3, D8, D9 · **Closes:** F2, F9 · **Journeys:** J-HG-2, J-HG-3, J-HG-4

### User-flow

Pointer: AI infrastructure → Providers & keys → *Your own model servers*;
Model catalog → **Connect a server**. Journeys J-HG-2, J-HG-3, J-HG-4
([`02`](./02_journeys_and_copy.md) §3). Wireframes W2–W6. The copy in
02 §4 is reviewed before the first component.

### Change

- New components, each under 300 lines: `ModelServerConnectCard.vue`
  (W2), `ModelServerList.vue` and `ModelServerRow.vue` (W5),
  `ModelImportList.vue` (W3, taken out of `ModelImportDialog.vue`, which
  becomes a thin dialog around it), `ModelImportResult.vue` (W4, with the
  test message and W6). One composable for the flow, whose states all
  end: idle → checking → found | failed → importing → done | failed. No
  `setTimeout` to order requests.
- `ModelsAndKeysTab.vue`: *Your own model servers* first; the Local AI
  accordion keeps the download card and the help link; *Add your own
  service* goes. The file gets shorter.
- `OpenAiCompatibleEndpointsPanel.vue`: **Connect a server** above the
  table opens the card; the stacked add form becomes **Edit** on a row
  (custom headers, auth header); its buttons get house classes and its
  result colours tokens.
- Model catalog: **Open in model catalog** lands filtered to the server
  (an existing filter where one fits, otherwise one `?server=` query).
- API clients extended in `services/api/`; Zod schemas from
  `generated/api-schemas.ts` only.
- Icons from the map in `docs/FRONTEND_CONVENTIONS.md`; "model server"
  gets one glyph (proposed `ServerStackIcon`) added to the map in the
  same pull request if it has none.
- Every string of 02 §4 used here, in five locales.

### Tests

- Vitest per component: every failure code renders its sentence and no
  HTTP code; `needsKey` focuses the key field; preselection; the remote
  row unchecked with its warning; cost prefilled 0, the listed price, or
  unknown; **Cost for all selected** touches selected rows only; the
  use-for selects offer eligible rows only; the vision select hidden
  without a vision model; the result card's sentences and numbers come
  from the response; the BMODELS line; the personal-override line; the
  test message's three endings; the server row's five answers; no
  **Disconnect** on the environment row.
- Playwright `@ci` `model-server-connect.spec.ts` on the test stack's
  stub: connect → import with costs → result → catalog shows the rows →
  disconnect → reconnect restores; presets `empty` and `unauthorized`.
- `make -C frontend test-e2e-layout` for the card and the list at 320 px.

**Gate:** `make ci-local && make test-e2e`, the layout run, J-HG-2,
J-HG-3 and J-HG-4 walked on `make fresh` and entered in STATUS.

Exit (UX contract §6):

1. J-HG-2, J-HG-3, J-HG-4 walked in the browser.
2. The rows found in ten seconds from the result card and from the rail;
   the server from Providers & keys.
3. Kind-specific copy in five locales: *Disconnect* a server is not
   *Remove* a model; the result says what did not change.
4. Empty server (one sentence, **Check again**), every failure, a
   public-only installation without suggestions. No flag: the card is
   core setup.
5. Light, dark, glass, 320 px; chips, warning and inputs at WCAG AA.

---

## HG7 — The wizard offers your own server

**Commit:** `feat(setup): connect your own model server in the first-run wizard`
**Branch:** `feat/20261031-wizard-own-server` · **Class:** ota-candidate · **Needs:** HG6 · **Closes:** F1 · **Journey:** J-HG-1

### User-flow

Pointer: `/setup`, step 2. Journey J-HG-1, wireframe W1, copy 02 §4
"Wizard".

### Change

- `SetupProviderStep.vue`: the new title and description; the suggestion
  card (suggestions asked once on mount); the choice *Own model server* /
  *Cloud provider*; *Own* shows the card, the inline list and the result
  from HG6; *Cloud* shows today's tiles and key field unchanged.
- The ready line and the Demo card follow the import result: after an
  import whose `chatReady` is true, the ready line shows and the Demo is
  not offered.
- `setup.provider.skipHint` names today's page (it still says "Admin →
  AI Provider Setup").
- The MOBILE-APP SEAM in `SetupWizardView.vue` (server switch) stays as
  it is. Addresses are probed by the Synaplan server, so the copy never
  says "this device".

### Tests

- `SetupProviderStep.spec.ts`: suggestion shown and hidden; the choice;
  after a successful import the ready line and no Demo card; a failure
  renders its sentence; **Continue** still works without connecting.
- `SetupWizardView.spec.ts`: steps and progress unchanged.
- The whole journey on a fresh install is HG10's `@fresh` spec; until
  then it is walked on `make fresh`.

**Gate:** `make ci-local && make test-e2e`, the layout run, J-HG-1
walked on `make fresh` and on the quickstart image with a real Ollama
(Docker Desktop now, Linux after HG9), timed, entered in STATUS.

Exit (UX contract §6): the five bullets of J-HG-1 in
[`02`](./02_journeys_and_copy.md) §3.

---

## HG8 — One honest Ollama address

**Commit:** `fix(admin): stop offering an Ollama address the server cannot use`
**Branch:** `fix/20261031-ollama-address-honesty` · **Class:** ota-candidate · **Needs:** HG6, D2 · **Closes:** F3 (code), F13 (status line)

File the bug first with F3's three measured values (without the
address).

### Change

- `OLLAMA_BASE_URL` in the system settings schema: read-only with
  `envOverride` and `effectiveValue` (the pattern `SystemConfigValues`
  uses for `REGISTRATION_ENABLED`) when the process has a value; not
  offered when it has none. `SystemConfigWriter` refuses a write to the
  key with one sentence pointing at the connect card, like a `managedBy`
  field.
- `ModelsAndKeysTab.vue`: the pinned address as the read-only row of W5;
  no URL field.
- System status: the Ollama line names the connected model servers
  instead of "OLLAMA_BASE_URL is unset" when one exists, and the pinned
  one as "Set by the server environment".

### Tests

- `SystemConfigWriter` test: the write is refused with the sentence; the
  schema marks the field read-only when set.
- `FeatureStatusReporterTest` and the `features_status.*.json`
  snapshots, re-recorded on purpose and reviewed line by line.
- `ModelsAndKeysTab.spec.ts`, `ProviderSetupView.spec.ts`,
  `AdminConfigView.spec.ts` and `provider-keys.spec.ts`, which assert an
  editable `#OLLAMA_BASE_URL` today.

**Gate:** `make ci-local && make test-e2e`; the "pinned" rows of J-HG-4
walked.

---

## HG9 — A host name for model servers on the same machine

**Commit:** `fix(deploy): let the app reach a model server on the same host`
**Branch:** `fix/20261031-compose-host-gateway` · **Class:** no-app-impact · **Needs:** D13 · **Closes:** F10 (Linux)

### Change

- `extra_hosts: ["host.docker.internal:host-gateway"]` on the app roles
  of `deploy/compose.yaml`, `deploy/quickstart/compose.yaml` and
  `deploy/quickstart/compose.lite.yaml`; on CasaOS and Umbrel only where
  their validators accept it (otherwise a comment and a docs line).
- One comment line each: Docker Engine 20.10 or newer.

### Tests

- `deploy/scripts/tests/` green (the inlined secrets script is outside
  the change).
- A quickstart boot on Linux reaches an Ollama started with
  `OLLAMA_HOST=0.0.0.0` at `host.docker.internal:11434`, and Docker
  Desktop still does.

**Gate:** as above; STATUS line with both boots.

---

## HG10 — CI walks the first run

**Commit:** `test(e2e): walk the first run on a fresh install against the model stub`
**Branch:** `test/20261031-fresh-install-e2e` · **Class:** no-app-impact · **Needs:** HG1, HG7, D12 · **Journey:** J-HG-6

### Change

- `frontend/tests/e2e/tests/first-run-own-server.spec.ts`, tagged
  `@fresh`: J-HG-1 end to end — administrator, suggestion, two models at
  cost 0, use for chat, the test message answered by the stub, access
  step, done, a chat answered by the stub; then on `/admin/setup` the
  presets `empty`, `unauthorized` and `four-models` (the cloud row).
- `frontend/tests/e2e/playwright.fresh.config.ts`, a config of its own
  (`@fresh`, one worker). It has **no** `globalSetup` / `globalTeardown`:
  the shared ones in `playwright.config.ts` sign in as the seeded
  administrator and rewrite the instance defaults (`ownerId = 0`) to the
  TestProvider on whatever `BASE_URL` points at, and `config/config.ts`
  derives the API address from the same variable, the dev stack by
  default. `npm run test:e2e:fresh` sets `BASE_URL=http://localhost:8001`
  explicitly and refuses to start when `BASE_URL` points at `:5173` or
  `:8000`. The `@ci` projects do not pick up `@fresh`.
- `.github/workflows/ci.yml`: job *E2E Tests (fresh install)* on
  `docker-compose.test.yml` + `docker-compose.test.ci.yml` +
  `docker-compose.test.fresh.yml`, the same trace and backend-log
  artifacts as the other E2E jobs, required by **All Checks Passed**.
- `docs/E2E_TESTING.md`: the project and how to run it locally.

**Gate:** the fresh config green twice in a row on `make fresh`; the dev
stack's instance defaults unchanged afterwards (read before and after
with the call `helpers/model-defaults.ts` uses for its snapshot);
`make test-e2e-full` unchanged.

---

## HG11 — Docs

**Commit:** `docs: connect your own model server`
**Branch:** `docs/20261031-own-model-server` · **Class:** no-app-impact · **Needs:** HG7, HG8

### This repository

- `docs/INSTALLATION.md` *Connect an AI Provider*: your own model server
  first, the cloud key second.
- `docs/CONFIGURATION.md`: model servers in the table of where settings
  live (database, at once); `OLLAMA_BASE_URL` pins the bundled Ollama.
- `README.md` provider table: the Ollama row says "your own model server
  (Ollama, vLLM, …) — connect it in the setup wizard; `OLLAMA_BASE_URL`
  pins the bundled one".
- `docs/ADMIN.md`: AI infrastructure → Providers & keys, the server
  rows, disconnect.

### docs.synaplan.com (`synaplan-docs`, its own pull request, linked)

- `docs/modules/local-ai.md` *An Ollama you already run*: the connect
  card; Linux needs `OLLAMA_HOST=0.0.0.0` and a firewall rule; Docker
  Desktop has the host name built in.
- `docs/ai-infrastructure.md`: import with a cost, use for chat, the
  result card, disconnect and reconnect.
- `docs/faq.md`, `docs/quickstart.md`: remove the `OLLAMA_BASE_URL`
  advice that cannot work.
- A new page *Run Synaplan on your own models*: same computer, a Linux
  server, a GPU box with vLLM (`--api-key`, `--served-model-name`),
  Kubernetes service names; the console commands.

**Gate:** the docs repository's own checks.

---

## HG12 — Release walk, then archive

**Commit:** `docs(planning): record the halloween-gui walks and archive the track`
**Branch:** `docs/20261031-halloween-walks` · **Class:** no-app-impact · **Needs:** every step above

- J-HG-1…J-HG-6 walked on the released image: Docker Desktop and Linux,
  a real Ollama with two models, a vLLM (or LM Studio / llama.cpp). The
  five-minute timing for J-HG-1.
- ROADMAP row 33 removed, a decision-log line added, the folder moved to
  `2026-archive/` with a dated line at the top of STATUS
  ([`../AGENTS.md`](../AGENTS.md) "Finished plans leave the live
  directory").

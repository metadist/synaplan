# Code cleanup V2 — break up the god classes

**Status:** Queued 2026-10-14. Starts when
[`../20261014-code-cleanup-V1/STATUS.md`](../20261014-code-cleanup-V1/STATUS.md)
shows root files = 4 and deptrac green on `main`. Tick §0 before G1.
**Priority:** Row 2 of [`../ROADMAP.md`](../ROADMAP.md); its exit
**closes the cleanup window** and reopens feature work (first in line:
the prompting flow, row 8, which builds on the chat pipeline G5–G9
extract).
**Goal:** No class in the chat hot path over 800 lines, no constructor over
10 dependencies, no method over 100 lines — and a gate that keeps it so.
The three worst offenders are split along seams measured in §1/§3; the
rest is frozen in a ratchet and shrinks when touched.
**Class:** `backend/**` is `backend-only`. The only frontend touch is
`make -C frontend generate-schemas`, which must produce a **zero diff**
(the OpenAPI contract does not change in this track).
**Prerequisite:** V1 (layers enforced, `Service/` sorted). V2 extracts
classes *into* those folders; doing it before V1 would re-scatter the root.

Files in this track:

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Baseline, decisions, safety net, step table, gates |
| `01_seams.md` | Per-class anatomy and the target shape, derived from the measured method clusters |
| `STATUS.md` | Step log with the three numbers |

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | Size gate as **ratchet tests** in `tests/Architecture/` (tokenizer-based, no new dependency): class ≤ 800 lines, constructor ≤ 10 params, method ≤ 100 lines. Every current offender is frozen at its current value in a dated allow-list; a listed entry may shrink or disappear, never grow; a new offender fails. Alternative: `phpmd` (new dependency, XML rules, its own baseline). | ratchet tests | |
| 2 | **Safety net before the first split.** No god class is touched until its characterization test is green on `main` (§2). The snapshot is the contract; a refactor PR that changes a snapshot is wrong until proven otherwise in review. | Locked | |
| 3 | `ChatHandler::handle()` and `handleStream()` become **one pipeline with a sink** (buffered vs. streaming). 42 of their ~45 helpers are shared today; two pipelines would double the extraction work. | Locked | |
| 4 | `ConfigController::runtime()` becomes an **assembler over tagged contributors** (`RuntimeConfigContributorInterface`, one per config area / feature module). The OpenAPI annotation stays on the controller, so the generated Zod schema is unchanged. | Locked | |
| 5 | `StreamController::streamMessage()` is split **transport first, orchestration second**: an `SseWriter` (event vocabulary in §3) and a `StreamRequestResolver` come out before any business step moves. | Locked | |
| 6 | Order of attack: ConfigController (lowest risk, highest arity) → ChatHandler → StreamController → the next tier (§1) only as touched. | Approved | |
| 7 | Extraction rule: a new class has **one reason to change**, ≤ 10 constructor params, lives in the V1 domain folder of its concern, and is `final readonly`. No "Helper", "Util", "Manager" names. | Locked | |
| 8 | `docs/ARCHITECTURE.md` (one page: layer map, deptrac graph, domain folder legend, the three numbers) and `make metrics` ship in this track, because they are how the result is shown. | Approved | |

---

## 1. Measured baseline (2026-10-14, `main`)

| Class | Lines | Ctor params | Worst method | Tests touching it |
| ----- | ----- | ----------- | ------------ | ----------------- |
| `Controller/StreamController` | 4,430 | 36 | `streamMessage()` **2,008 lines**; `handleNonStreamingRequest()` ~450 | 17 files, 2 E2E specs |
| `Service/Message/Handler/ChatHandler` | 4,607 | 39 | `handleStream()` 802; `handle()` 673 — 42 shared private helpers | 21 files |
| `Controller/ConfigController` | 2,572 | **50** | `runtime()` 654 (62 response keys); `models()` 339 | 6 files, 5 E2E specs |
| `Service/Admin/SystemConfigService` | 2,961 | 13 | — | |
| `Service/WhatsAppService` | 2,560 | 25 | — | |
| `Service/Message/Handler/MediaGenerationHandler` | 2,351 | 23 | — | |
| `Controller/WidgetPublicController` | 2,234 | 27 | — | |
| `Service/Message/MessageClassifier` | 2,082 | 21 | — | characterization snapshots exist |
| `Service/Message/MessageProcessor` | 2,056 | 21 | `processStream()` ~590 | 23 files |
| `Service/Message/Handler/FileAnalysisHandler` | 2,046 | 11 | — | |

Repo-wide at the proposed thresholds: **64** files over 800 lines, **98**
constructors over 10 params (37 over 15, 20 over 20). Those numbers are the
starting allow-lists; the track reports them in `STATUS.md` after every PR.

Not god classes, excluded from the gate by path: `Model/ModelCatalog.php`
(5,919 lines of catalog data) and `Prompt/PromptCatalog.php` (2,274).

---

## 2. Safety net (G1–G2, before any split)

| Target | Characterization test | Fixture |
| ------ | --------------------- | ------- |
| `StreamController` | **Golden SSE transcript.** For a fixed set of requests (plain chat, chat with file, chat that triggers RAG + memories + web search, guest, cancelled, error from provider) record the ordered event sequence `event: <name>` + the JSON shape (keys, not values) into `tests/Characterization/__snapshots__/sse/`. The vocabulary has **two halves**. *Fixed names* written by `StreamController` itself (18): `data`, `status`, `complete`, `error`, `generating_file`, `file`, `thinking`, `reasoning`, `reasoning_complete`, `run_started`, `perf`, `message`, `links`, `audio`, `tts_generating`, `voice_reply_failed`, `guest_remaining`, `guest_limit_reached`. *Pipeline status names* forwarded verbatim as event names through the status callback (`$this->sendSSE($statusUpdate['status'], …)`, ~27 today): `started`, `preprocessing`, `classifying`, `classified`, `analyzing`, `analyzing_prompt`, `searching`, `search_complete`, `search_failed`, `searching_files`, `fetching_urls`, `urls_fetched`, `pages_read`, `checking_memories`, `memories_loaded`, `docs_loaded`, `digests_loaded`, `feedback_loaded`, `generating`, `generated`, `editing`, `pause`, `resume`, `end`, `processing`, `complete`, `error`. The frontend keys on several of them (`docs_loaded`, `digests_loaded`, `pages_read`, `fetching_urls`, …). The fixtures must drive the RAG, memory, web-search and URL-fetch paths so these appear in the transcript; `SseWriter` keeps the fixed names as constants **and** passes any status name through unchanged. | Fake AI provider with scripted tokens (the `AiFacade` test double already used by the 17 existing tests); fake search / URL fetcher |
| `ChatHandler` | **Prompt assembly snapshot.** For the same request set, snapshot the final message array handed to `AiFacade` (system prompt sections in order, user content blocks, tool definitions). This is the real contract of the class: what reaches the model. | Same fake provider; frozen clock via `TimeContextBuilder` |
| `ConfigController::runtime()` | **Response snapshot** of `/api/v1/config/runtime` key set and types under three env profiles (minimal, full, OIDC-only). Plus: `make -C frontend generate-schemas` must be a **zero diff** — the Zod schema is the frontend's contract. | `.env.minimal`, dev `.env`, an OIDC profile |

Rules: snapshots are re-recorded only with `UPDATE_*_SNAPSHOTS=1`, reviewed
line by line, same as `RoutingCharacterizationTest` today. A split PR that
needs a snapshot change is split wrong — unless the diff is a bug fix that
is called out in the PR description.

---

## 3. Target shapes

Derived from the method clusters in [`01_seams.md`](./01_seams.md). Names
are proposals; the *boundaries* are the decision.

### ConfigController (50 → 7)

Measured per route (`$this->…` members used inside each route body):
`runtime()` touches **38** members, `GET /models` 8 services, the
`/models/defaults` GET+POST+reset trio 10, the two `/routing/*` pairs 4,
the three check routes 7, `/capabilities` and `/features` one each.

```
ConfigController           routes + OpenAPI annotations + isGranted() only; 7 deps
  ├─ RuntimeConfigAssembler        iterates tagged RuntimeConfigContributorInterface (35 deps split 7 ways)
  │     ├─ AuthRuntimeConfig        registration, guest chat, mailer, demo login, setup state, recaptcha
  │     ├─ BillingRuntimeConfig     billing, taximeter, mobile version
  │     ├─ ChatRuntimeConfig        chat action policy, readiness, welcome, progress narration, self-aware, search interpreter, document tools, tools, workflows
  │     ├─ ModulesRuntimeConfig     ModuleRegistry → `modules`, gates, plugins, compute, bundle, provider registry
  │     ├─ FeaturesRuntimeConfig    IAM, saved tasks, desktop agent, agents
  │     ├─ BrandingRuntimeConfig    branding, marketing news, platform links, AI provider disclosure, client context
  │     └─ SpeechRuntimeConfig      web speech, whisper
  ├─ ModelListingService           GET `/models` (group limits, cost, locked defaults)
  ├─ ModelDefaultsService          GET/POST/reset `/models/defaults`
  │     └─ VectorizeModelChangePolicy   embedding change guard + metadata + capability inventory (the POST-only path)
  ├─ RoutingModelsService          planner / summary model GET+POST
  ├─ ModelCheckService             `/models/{id}/check`, `/memory-service/check`, `/local-ai/status`
  ├─ CapabilityService             `/capabilities` (already a service; stays)
  └─ FeatureStatusReporter         `/features` (already a service; stays)
```

Each contributor owns its keys and its dependencies (2–9 each, see
`01_seams.md` §3 for the exact member → class table). The `modules` block
already comes from `ModuleRegistry`; the contributor pattern generalises
what AGENTS.md already demands ("let the registry drive feature status,
runtime config, and the gate 404").

**As delivered (G3 + G4).** Nine dependencies, not seven: the memory-store
probe (`Memory/MemoryServiceStatus`) and `LocalAiDownloadStatusService` stay
separate instead of being folded into the model check, which they do not
belong to. `ModelCheckService` became `Model/ModelAvailabilityCheck`, and
the tag → picker rules moved out of `GET /models` into
`Model/PickerCapabilities`. Lock and allow-list refusals are a domain
exception (`DefaultModelRejectedException`) that the controller maps to
409 / 422.

Every route stays in this one controller. Nelmio orders the OpenAPI
`paths` by route registration, and the generated frontend schema file
pins a SHA-256 of that document: moving a route to another controller
would change the hash and turn a backend-only refactor into an OTA
frontend change. The six large 200-response schemas live in
`App\Attribute\OpenApi\Config\*` instead — subclasses of `OA\Response`,
which swagger-php reads by `instanceof`, so the document stays byte-identical
(verified against `/api/doc.json`). The controller ends at ~740 lines and
leaves the size ledger.

### ChatHandler (4,607 lines, 39 deps → pipeline)

```
ChatHandler (≤ 300 lines)   orchestrates stages, owns the sink
  ├─ ChatGenerationOptionsResolver   applyRuntimeProfile / ProfileGenerationOptions / ReasoningLevel / LocalThinkingBudget
  ├─ ChatSystemPromptBuilder         location, time, document-image, media-edit, self-aware, plugin context, office hints, summary / quoted-reference formatting
  ├─ ChatKnowledgeContextLoader      ragSettings, assistantShouldSearchKnowledge, agentRagScopes, loadRagContext, shared vector resolver, docs-not-found reply
  ├─ ChatMemoryContextLoader         loadMemoriesContext, loadDigestContext, loadFeedbackContext, searchFeedback, filterByScore, memory vector resolver
  ├─ ChatMessageAssembler            buildMessages / buildStreamingMessages, buildCurrentMessageContent, fitAttachmentText, system-message fallback for models without system role
  ├─ VisionAttachmentEncoder         image mime / base64 / downscale / vision fallback model / generated-image provenance
  ├─ ChatDocumentOutputHandler       extractFileGenerationData, tryDocumentToolsEdit, applyDocumentEditResult, storeGeneratedDocument / File
  ├─ RoutingHandoffResolver          routingHandoffTools / routingHandoffFor / slashHintReply
  └─ PendingMemoryExtraction         buildPendingMemoryExtraction / dispatchPendingMemoryExtraction
```

`handle()` and `handleStream()` collapse into one `run(ChatRequest, ChatSink)`;
`BufferedChatSink` and `StreamingChatSink` are the only difference.

### StreamController (4,430 lines, 36 deps → transport + orchestration)

```
StreamController (≤ 200 lines)   four routes, auth, delegates
  ├─ SseWriter                       sendSSE, sanitizeUtf8; the 18 fixed names as constants, pipeline status names passed through; the only place that writes to the response
  ├─ StreamRequestResolver           resolveStreamParams, parseIncognitoHistory, resolveIntendedChatModel / EffectiveChatModelId, pinned agent, draft / owner checks
  ├─ ChatStreamOrchestrator          the 2,008-line body as named steps: run start → process → persist → usage → voice reply → complete
  ├─ StreamPersistence               persistRagSources / ClassificationSortingMeta / TaskPlanDefinition / OriginalMediaMeta / TaskPlanFiles / ChatErrorMeta, generated file storage (shared with ChatDocumentOutputHandler)
  ├─ StreamUsagePayloadBuilder       buildAiModelsPayload / ChatUsagePayload / ExtraUsageEntry / appendContextUsage
  └─ VoiceReplyStep                  processVoiceReply / emitVoiceReplyFailed
```

`handleNonStreamingRequest()` (~450 lines) reuses the orchestrator with a
buffered sink — the same move as in ChatHandler.

### Next tier

`MessageProcessor`, `MediaGenerationHandler`, `WhatsAppService`,
`WidgetPublicController`, `SystemConfigService`, `MessageClassifier`,
`FileAnalysisHandler`: frozen in the ratchet, split **only when a feature
PR touches them**, following the same recipe (characterize → extract →
snapshot unchanged). No dedicated PRs in V2 unless the three above finish
early.

---

## 4. Step table

| Step | Commit title | Class | Depends |
| ---- | ------------ | ----- | ------- |
| G0 | `docs(planning): code cleanup V2 master plan` | no-app-impact | — |
| G1 | `test(backend): size ratchet for classes, constructors and methods` | backend-only | V1 done |
| G2 | `test(backend): characterization snapshots for stream, chat handler and runtime config` | backend-only | G1 |
| G3 | `refactor(config): runtime config assembled from tagged contributors` | backend-only | G2 |
| G4 | `refactor(config): move model defaults, routing models and checks out of ConfigController` | backend-only | G3 |
| G5 | `refactor(chat): extract prompt, knowledge and memory context builders from ChatHandler` | backend-only | G2 |
| G6 | `refactor(chat): extract message assembly, vision encoding and document output from ChatHandler` | backend-only | G5 |
| G7 | `refactor(chat): one chat pipeline with buffered and streaming sinks` | backend-only | G6 |
| G8 | `refactor(stream): extract SSE writer and stream request resolver` | backend-only | G2 |
| G9 | `refactor(stream): chat stream orchestrator with named steps` | backend-only | G7, G8 |
| G10 | `docs(backend): ARCHITECTURE.md with layer graph and make metrics` | no-app-impact | G9 |

G3–G4 (config) and G5–G7 (chat) are independent tracks; G8 can start with
them; G9 needs both chat and stream extractions. Each step is one PR and
leaves `main` releasable.

**Delivery (decided 2026-10-14).** One branch and one pull request per god
class, each into `main`, in this order: `configcontroller` (G3 + G4),
`chathandler` (G5–G7), `streamcontroller` (G8–G9, plus G10). Steps stay
separate commits inside each pull request so a reviewer can follow them.
CI only runs for pull requests into `main`, so the branches are not
stacked; the later ones take the earlier ones' ledger and `STATUS.md`
changes in a merge from `main`.

Sizing: G1–G2 one day together; G3–G4 one day; G5–G7 three to four days;
G8–G9 three days; G10 half a day. About two weeks of focused work, spread
over sprints as capacity allows — the ratchet makes "not finished yet"
safe.

---

## 5. Gates

Per PR, in addition to `make ci-local` (which from V1 includes deptrac):

```bash
make -C backend test                                   # includes the ratchet + characterization suites
docker compose exec -T backend php bin/console lint:container
make -C frontend generate-schemas && git diff --exit-code frontend/src/generated/api-schemas.ts
make test-e2e                                          # every G3–G9 PR: stream, chat, config are user-visible paths
```

- A snapshot diff in `tests/Characterization/__snapshots__/` in a
  `refactor:` PR blocks the merge until explained in the description.
- `STATUS.md` row per PR with: files > 800, ctors > 10, methods > 100, and
  the lines/params of the class being worked on.
- `node scripts/mobile-impact.mjs --base main --head HEAD` → `backend-only`.
- The SSE vocabulary in §2 is an **external contract** (web, widget,
  desktop client, mobile apps consume it). Adding an event is a `feat:`
  with frontend work; this track adds none.

---

## 6. Non-goals

- No behaviour change, no new SSE event, no OpenAPI change, no new endpoint.
- No change to `MessageClassifier` / `MessageSorter` routing (its own
  snapshots stay untouched).
- No test-tree reorganisation beyond the new `tests/Characterization/` files.
- No work on the next tier (§3) unless a feature PR opens the file anyway.

---

## 7. After V2 (V3 candidates, carried from V1 §8)

Domain-first layout (`src/Widget/{Controller,Service,Repository}`), the
`AI` ↔ `Service` layer split, domain-to-domain deptrac rules, the
`Plugin\Sdk` namespace with `class_alias` shims, PHPStan 5 → 8, mirroring
`tests/` to `src/`. None of these needs to be decided before V2 ends.

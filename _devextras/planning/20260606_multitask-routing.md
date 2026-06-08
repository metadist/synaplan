# Multi-Task Routing — From Single-Topic Classification to a Parallel Task Plan

**Date:** 2026-06-06
**Status:** Planning (no implementation yet)
**Routing model:** `groq:openai/gpt-oss-120b:chat` (BID 76) — already the seeded `DEFAULTMODEL.SORT`
**Scope owner:** backend `Service/Message/*`, additive frontend, additive DB migration + seed

---

## 1. Goal

Today every inbound prompt is reduced to **one** topic → **one** intent → **one** handler → **one** model. That is the source of the confusion the request calls out: we maintain a list of models *with general purposes* **and** a separate assignment of models *to tasks*, and the two never meet in a single request.

We want the router to treat an incoming prompt as a **collection of one or more tasks**, emit a **JSON task plan (a DAG of commands)**, and execute those tasks — **in parallel where they are independent**, sequentially where one feeds the next. Each task binds to the model already configured for that capability (`DEFAULTMODEL.*`), so the "model catalog" and the "task→model" assignment finally describe the same thing.

### Canonical example (the acceptance scenario for the whole project)

> User sends a Word document and asks: *"What's in there? Summarize it into a short MP3."*

The planner must produce a plan equivalent to:

```
extract_text(doc)  ──►  summarize(text)  ──►  tts(summary) ──►  reply(text + mp3)
```

…and the **same** message could arrive via **WhatsApp, web chat, the API, or email**, and the answer must go back out on **that same channel** with the text + the MP3 attached.

### Hard constraints (non-negotiable)

1. **The embeddable ChatWidget keeps working unchanged.** The widget uses `skipSorting` + `fixed_task_prompt`; that path must continue to bypass the planner and behave exactly as today (see §3.4).
2. **No existing data is broken.** All schema changes are additive Doctrine migrations. No column is dropped or repurposed. Existing `BMESSAGES` / `BMESSAGEMETA` / `BPROMPTS` / `BMODELS` rows keep working untouched.
3. **All current tools stay in place:** RAG, web search, mail handling, office-doc generation, image/video/audio generation, vision/file analysis, memories, feedback, plugins.
4. **Current UX is preserved.** A plain chat message still streams a single text answer; a `/pic` command still makes one image. Multi-task behaviour is additive, not a regression of the simple path.
5. **Every phase ships behind a feature flag and has a success test (acceptance gate) that must pass before release.**

---

## 2. Current State (verified against the code)

> Investigated in detail; references below are concrete. Parent investigations:
> [routing pipeline](d3b9fdcf-a77d-459c-9f6a-794eab21eb75), [models & widget](b294c107-e794-43b5-94b3-42a57e0e126e), [channels & media](322255a6-c08a-4266-8c61-035f446d62a5).

### 2.1 Pipeline today

`MessageProcessor` orchestrates: **preprocess → classify → (web search) → route → single handler**.

- **Preprocess** — `Service/Message/MessagePreProcessor.php`: downloads attachments and extracts text. Documents via Apache Tika (`Service/File/FileProcessor.php`), audio via Whisper, images stored for later vision. Text lands in `Message.fileText` / `File.fileText`. **Text extraction already happens here** — that is important: it means the first node of our canonical example is largely an existing capability.
- **Classify** — `Service/Message/MessageClassifier.php` runs a decision tree: fast-path heuristics → slash commands (`/pic`, `/vid`, `/tts`, `/search`, …) → file/audio shortcut (`analyzefile`) → `MessageSorter` (LLM AI sorter). It returns a single `{topic, intent, language, media_type, …}`.
- **AI sorter** — `Service/Message/MessageSorter.php` loads the `tools:sort` prompt (`Prompt/PromptCatalog.php::sortPrompt()`), injects `[DYNAMICLIST]` (enabled `BPROMPTS` topics), and calls `DEFAULTMODEL.SORT` (= **gpt-oss-120b on Groq** in prod) at `temperature 0.1`, parsing one JSON object with `BTOPIC/BLANG/BWEBSEARCH/BMEDIA/BDURATION/BRESOLUTION/BINPUTMODE`.
- **Route** — `Service/Message/InferenceRouter.php` maps `intent` → one of **three** registered handlers tagged `app.message.handler`:
  - `ChatHandler` (chat, RAG, summarize, officemaker, vision-in-chat)
  - `MediaGenerationHandler` (image / video / audio-TTS generation)
  - `FileAnalysisHandler` (vision / document Q&A)
  - `code_generation`, `email`, `calendar`, `summarize`, `translate` intents are *mapped* but fall back to `chat`.
- **Model selection** — each handler resolves: `Again model_id` → widget `override_model_id` → prompt `PromptMeta.aiModel` → `DEFAULTMODEL.{CAPABILITY}` via `Service/ModelConfigService.php`. Capability→tag in `Model/ModelCatalog.php::CAPABILITY_TAGS`.

### 2.2 Models & tasks

- `Model` (`BMODELS`): keyed by `BTAG` capability (`chat`, `text2pic`, `text2vid`, `text2sound`, `pic2text`, `sound2text`, `vectorize`, `mem`); features in `BJSON`.
- `Prompt` (`BPROMPTS`): a *task/topic* with system text (`BPROMPT`), `BSELECTION_RULES`, `BKEYWORDS`, `BENABLED`. `PromptMeta` (`BPROMPTMETA`) carries `aiModel`, `tool_internet`, `tool_files`, …
- Bindings live in `BCONFIG` group `DEFAULTMODEL` (`CHAT`, `SORT`, `SUMMARIZE`, `MEM`, `TEXT2PIC`, `TEXT2VID`, `TEXT2SOUND`, `PIC2TEXT`, `SOUND2TEXT`, `VECTORIZE`, …). Seeded in `Seed/DefaultModelConfigSeeder.php`. **There is no `Model`↔`Prompt` ORM relation** — they meet only at runtime.

### 2.3 Channels

| Channel | Inbound | Processor call | Outbound |
|---|---|---|---|
| Web chat (SSE) | `StreamController` `GET /api/v1/messages/stream` | `processStream` | SSE events + persisted OUT message |
| Web async | `MessageEnqueueService` → `ProcessMessageCommand` | `process` (worker) | poll `/status` |
| WhatsApp | `WebhookController::whatsapp` → `WhatsAppService` | `processStream` (sync in webhook) | `WhatsAppService::sendMessage/sendMedia` |
| Email | `WebhookController::email` | `process` | `InternalEmailService::sendAiResponseEmail` (supports MP3 attach) |
| Generic API | `WebhookController::generic` | `process` | JSON only |
| Widget | `WidgetPublicController` `POST /api/v1/widget/{id}/message` | `processStream` with `skipSorting`+`fixed_task_prompt` | SSE |

Channel identity is recorded on `Chat.source`, `Message.messageType`, `Message.providerIndex`, and `MessageMeta.channel`. Multi-file output is **already** representable (`Message`↔`File` ManyToMany via `BMESSAGE_FILE_ATTACHMENTS`); voice-conversations already delivers text+MP3 on web/WhatsApp/email.

### 2.4 What this means for us (the good news)

- The **building blocks already exist** as services/handlers. This project is mostly an **orchestration layer** above them — not a rewrite of RAG, TTS, media gen, or Tika.
- The **output container already supports multiple files**, so "text + MP3" needs no new storage shape.
- The router model is **already gpt-oss-120b on Groq**.
- The widget already has a clean bypass we can preserve verbatim.

### 2.5 Gaps we must close

- The classifier emits **one** topic; there is no representation of "several tasks".
- `InferenceRouter` picks **one** handler; there is no executor that chains/parallelises handlers.
- Outbound delivery is fragmented per channel and assumes a single text(+optional one file) result.
- The async (`enqueue`) path doesn't set `chatId` or deliver outbound — fine for now, but a multi-task executor must not regress it.

---

## 3. Target Architecture

### 3.1 Concepts

Introduce a **Task Plan** as an explicit, persisted artefact produced by the router and consumed by an executor.

```
Inbound Message
      │
      ▼
 Preprocess (unchanged: Tika / Whisper / store images)
      │
      ▼
 TaskPlanner  ── gpt-oss-120b (DEFAULTMODEL.PLAN, falls back to SORT) ──►  TaskPlan JSON
      │                                                                      (validated against schema)
      ▼
 TaskPlanExecutor (DAG)
   ├── topological order
   ├── independent nodes run in parallel
   └── each node → a TaskRunner that wraps an EXISTING service/handler
      │
      ▼
 ResultAssembler  → single OUT Message (+ N File attachments)
      │
      ▼
 Channel delivery (unchanged per-channel adapters)
```

### 3.2 Task Plan schema (the "JSON of commands")

A plan is a small DAG. Each node names a **capability** (which maps to the existing `DEFAULTMODEL.*` binding and an existing runner), optional `depends_on`, typed `inputs` (literal, message ref, file ref, or `from: <nodeId>`), and `params`.

```jsonc
{
  "version": 1,
  "language": "en",
  "reply_node": "n4",            // which node's output is the user-facing reply text
  "tasks": [
    { "id": "n1", "capability": "extract_text",
      "inputs": { "files": "$message.files" } },

    { "id": "n2", "capability": "summarize",
      "depends_on": ["n1"],
      "inputs": { "text": "$n1.text" },
      "params": { "style": "short", "max_words": 120 } },

    { "id": "n3", "capability": "text2sound",
      "depends_on": ["n2"],
      "inputs": { "text": "$n2.text" },
      "params": { "format": "mp3" } },

    { "id": "n4", "capability": "compose_reply",
      "depends_on": ["n2", "n3"],
      "inputs": { "text": "$n2.text", "attachments": ["$n3.file"] } }
  ]
}
```

**Capability vocabulary (v1)** — every entry maps to an existing service/handler so nothing new has to be invented to run it:

| Capability | Runs via (existing) | Model binding |
|---|---|---|
| `extract_text` | `MessagePreProcessor` / `FileProcessor` (Tika/Whisper) | n/a (Whisper uses `SOUND2TEXT`) |
| `chat` / `summarize` / `translate` | `ChatHandler` | `DEFAULTMODEL.CHAT` (or prompt `aiModel`) |
| `rag_query` | `ChatHandler` + `VectorSearchService` | `CHAT` + `VECTORIZE` |
| `web_search` | `BraveSearchService` + `SearchQueryGenerator` | `SORT` for query opt |
| `file_analysis` (vision/OCR) | `FileAnalysisHandler` | `PIC2TEXT`/`ANALYZE` |
| `image_generation` | `MediaGenerationHandler` | `TEXT2PIC`/`PIC2PIC` |
| `video_generation` | `MediaGenerationHandler` | `TEXT2VID` |
| `text2sound` (TTS) | `AiFacade::synthesize` | `TEXT2SOUND` |
| `document_generation` | `ChatHandler` (officemaker) + `DocumentGeneratorService` | `CHAT` |
| `compose_reply` | new `ResultAssembler` (no model) | n/a |

> **Design rule:** v1 adds **no new model integrations and no new generation services.** A capability is a thin adapter over code that already runs in production. This keeps the blast radius small and protects every existing tool.

### 3.3 Backward compatibility = "a single task is just a degenerate plan"

The existing classifier output `{topic, intent}` is **losslessly expressible** as a one-node plan. We build a bidirectional adapter:

- `ClassificationToPlan`: today's `{intent: chat, topic: general}` → `[{id:n1, capability:chat, reply_node:n1}]`.
- `PlanToClassification` (per node): each runner receives the **same** `$classification` array shape it expects today, so `ChatHandler`/`MediaGenerationHandler`/`FileAnalysisHandler` need **zero signature changes**.

This is the key to not breaking anything: handlers stay as-is; the executor feeds them one node at a time using the array they already accept.

### 3.4 Widget & fixed-prompt invariant

`MessageProcessor` already short-circuits when `options['fixed_task_prompt']` is set (lines ~81–124 and ~555–621) and when `is_widget_mode`/`skipSorting`. **The planner is inserted only on the non-fixed, non-widget classification branch.** When `fixed_task_prompt`/`skipSorting` is present we build a **single-node `chat` plan** from the fixed topic and run it through the executor's degenerate path — identical observable behaviour to today. This is an explicit, tested invariant (Phase 2 gate).

### 3.5 Parallelism strategy (progressive)

PHP request handling is synchronous, so we stage parallelism:

- **Phase 3 (sequential DAG):** topological execution, dependencies passed by reference. The canonical doc→summary→mp3 example is a *chain* — it needs correct sequencing, not parallelism — so we get the headline scenario working first.
- **Phase 4 (parallel leaves):** genuinely independent nodes (e.g. "summarize this doc **and** make an image of a cat") run concurrently. Two viable mechanisms, decided in Phase 4 design spike:
  - **(A) Concurrent provider HTTP** via Guzzle async/promises inside one PHP process (simplest; good for I/O-bound provider calls).
  - **(B) Messenger fan-out**: dispatch independent nodes as child commands on `async_ai_high`, with a coordinator that joins results. More scalable, more moving parts; revisit for heavy media.
  - Default recommendation: **(A) for v1**, keep streaming UX intact; treat (B) as a later optimisation. The executor interface is identical either way, so the schedule strategy is swappable.

### 3.6 Routing model

- Add a new capability **`PLAN`** seeded to `groq:openai/gpt-oss-120b:chat`, falling back to `SORT` if unset (additive `DefaultModelConfigSeeder` entry + `ModelCatalog::CAPABILITY_TAGS['PLAN'] => 'chat'`). This lets us tune the planner independently of the legacy sorter without touching `SORT`.

---

## 4. Data & Config Changes (all additive)

1. **Persist the plan** (new table `BMESSAGE_TASKS`, Doctrine migration):
   - `BID`, `BMESSAGEID` (FK to inbound `BMESSAGES.BID`), `BNODEID`, `BCAPABILITY`, `BDEPENDSON` (json), `BSTATUS` (`pending|running|done|failed|skipped`), `BMODELID` (nullable), `BRESULTREF` (json: text ref / file id), `BERROR` (nullable), `BSTARTED`, `BFINISHED`.
   - Rationale: observability, retries (`/again`), and UI progress. **Additive only** — existing flows ignore it.
   - Cheaper alternative (acceptable for v1): store the whole plan + per-node status as a JSON blob in `BMESSAGEMETA` key `task_plan`. Decide in Phase 1; the table is preferred for querying/metrics.
2. **`DEFAULTMODEL.PLAN`** seed row (idempotent) = gpt-oss-120b on Groq.
3. **Planner prompt** as a new system `BPROMPTS` topic `tools:plan` (seeded in `PromptCatalog`), with placeholders for the capability catalog and the enabled task-topic `[DYNAMICLIST]`. The legacy `tools:sort` prompt stays for fallback.
4. **No changes** to `BMESSAGES`, `BMESSAGEMETA`, `BMODELS`, `BPROMPTS` columns. Multi-file output reuses the existing `BMESSAGE_FILE_ATTACHMENTS` junction.

---

## 5. Feature Flags

- `MULTITASK_ROUTING_ENABLED` (`BCONFIG`, global + per-user) — master switch. Default **off** through Phase 4.
- `MULTITASK_SHADOW_MODE` — plan is generated, validated, logged, and persisted but **not executed** (old path still answers). Used in Phase 1 to gather real-world plans with zero user impact.
- `MULTITASK_PARALLEL_ENABLED` — gates Phase 4 concurrency; sequential DAG runs when off.

Resolution mirrors `DEFAULTMODEL` (user `BCONFIG` → global `BCONFIG` → default off) via `ModelConfigService`-style lookup.

---

## 6. Implementation Phases

> Every phase obeys the repo **pre-commit gate** (AGENTS.md): `make -C backend lint && make -C backend phpstan && make -C backend test && make -C frontend lint && docker compose exec -T frontend npm run check:types && make -C frontend test`. The "Success test (release gate)" below is **in addition** to the standard gate and must be green before the phase is considered shippable.

### Phase 0 — Foundations & behavioural baseline

**Goal:** lock down current behaviour so we can prove "no regression" later.

- Add the three feature flags (default off) + config plumbing.
- Build a **golden corpus**: ~40 representative inbound messages covering general chat, `/pic`, `/vid`, `/tts`, doc upload, image upload (analyse vs edit), web-search question, officemaker, RAG, WhatsApp/email/widget origins. Record current classification + response shape as fixtures.
- Add a characterization test harness that replays the corpus through `MessageProcessor` (test provider) and snapshots `{topic, intent, model, response shape, attachments}`.

**Success test (release gate):**
- Full existing suite green; new characterization snapshots committed and stable across 3 consecutive runs (deterministic with `TestProvider`).
- Flags proven inert: toggling them changes nothing yet (covered by a test).

### Phase 1 — Task Plan schema + planner prompt (SHADOW mode)

**Goal:** generate and validate plans for real traffic without executing them.

- Define the plan schema (PHP value objects `TaskPlan`/`TaskNode` + JSON Schema for validation).
- Seed `tools:plan` prompt and `DEFAULTMODEL.PLAN` (gpt-oss-120b/Groq). Prompt instructs gpt-oss-120b to emit the §3.2 JSON given the message, files, history, the capability catalog, and the enabled topic `[DYNAMICLIST]`.
- `TaskPlanner` service: builds the planner input, calls the model, parses + **schema-validates** the JSON, repairs/falls back to a single `chat` node on invalid output.
- Wire in `MessageProcessor` under `MULTITASK_SHADOW_MODE`: when on, plan is produced, validated, persisted (`BMESSAGE_TASKS` or `task_plan` meta), and logged — **old path still answers the user**.

**Success test (release gate):**
- Schema-validation unit tests (valid + adversarial malformed inputs → safe fallback).
- Replay the golden corpus through the planner (recorded model responses / `TestProvider`): **100%** produce schema-valid plans; the canonical doc→summary→mp3 prompt yields the 4-node chain (`extract_text → summarize → text2sound → compose_reply`).
- Shadow mode proven non-user-facing: with shadow on, the golden-corpus responses are **byte-identical** to Phase 0 snapshots.
- Planner P50/P95 latency measured and within an agreed budget (target: planner adds < 1s P50 on Groq).

### Phase 2 — Executor for single-task plans (degenerate path) + adapters

**Goal:** turn the flag on for *simple* messages and prove identical behaviour, including the widget.

- `TaskPlanExecutor` (sequential), `TaskRunner` interface, and adapters wrapping `ChatHandler`, `MediaGenerationHandler`, `FileAnalysisHandler`, `BraveSearchService`, `AiFacade::synthesize` — each adapter feeds the existing handler the `$classification` array it already expects (`PlanToClassification`).
- `ClassificationToPlan` for the fixed-prompt/widget/again branches → single `chat` node.
- `ResultAssembler` that, for a one-node plan, returns the **exact** result structure handlers return today (so SSE/WhatsApp/email/widget delivery is untouched).
- Behind `MULTITASK_ROUTING_ENABLED`: when on, every plan still has exactly one executable node (we do **not** enable multi-node generation yet — planner is constrained to single-node output in this phase via a prompt switch / post-filter).

**Success test (release gate):**
- With the flag **on**, the entire golden corpus produces responses **equivalent** to Phase 0 snapshots (text equal; same model chosen; same attachment shape). Diff harness must report zero behavioural diffs.
- **Widget invariant test:** `skipSorting`+`fixed_task_prompt` request produces identical SSE event stream and OUT message with flag on vs off.
- `/pic`, `/tts`, doc-analysis, RAG, officemaker each verified through the executor path.
- Existing suite green; PHPStan clean; frontend checks green (no frontend change yet, but run them).

### Phase 3 — Multi-task DAG (sequential) + the canonical scenario

**Goal:** the headline feature, end-to-end, on web chat.

- Remove the single-node constraint; allow the planner to emit multi-node plans.
- Implement dependency resolution: node outputs (`$nX.text`, `$nX.file`) injected into dependent node inputs; `extract_text` reads preprocessed file text; `compose_reply` assembles text + N file attachments into one OUT message.
- Status callbacks: emit per-node progress through the existing `progressCallback`/SSE status channel so the UX shows "Extracting… Summarising… Generating audio…".
- Failure handling: a failed node marks the plan partially failed; `compose_reply` returns best-effort text + a clear error note rather than crashing the turn (falls back to legacy single-handler path if the whole plan fails).

**Success test (release gate):**
- **Acceptance scenario:** upload a `.docx` + "summarise into a short mp3" on web SSE → one OUT message containing the summary text **and** a playable MP3 attachment; intermediate status events observed; `BMESSAGE_TASKS` rows show `extract_text/summarize/text2sound/compose_reply` all `done`.
- DAG executor unit/integration tests: chains, branches, missing-dependency rejection, cyclic-plan rejection, per-node failure isolation.
- Regression: Phase 2 single-task equivalence still holds (golden corpus unchanged).
- Frontend: multi-step status renders; i18n strings added to **both** `en.json` and `de.json`; `vue-tsc` + Vitest green.

### Phase 4 — Parallel execution of independent nodes

**Goal:** run independent tasks concurrently without breaking streaming or ordering.

- Design spike: choose mechanism (A) concurrent provider HTTP vs (B) Messenger fan-out (§3.5); document decision in this file.
- Implement scheduler that runs all dependency-satisfied nodes concurrently, gated by `MULTITASK_PARALLEL_ENABLED`; deterministic assembly order regardless of completion order.
- Bound concurrency (config cap) to protect provider rate limits (esp. Groq) and memory.

**Success test (release gate):**
- Two independent nodes (e.g. `summarize(doc)` ∥ `image_generation("a cat")`) both complete; wall-clock < sum of individual latencies (prove real concurrency); assembled reply deterministic across 5 runs.
- Failure isolation: one branch fails → the other still returns; partial result + error surfaced.
- Rate-limit guard test: concurrency cap respected; no provider 429 storm in a burst test.
- Sequential mode (flag off) still passes Phase 3 gate.

### Phase 5 — Cross-channel parity

**Goal:** multi-task results deliver correctly on WhatsApp, email, API, and widget — not just web SSE.

- Ensure `ResultAssembler` output (text + N files) maps onto each channel's delivery: WhatsApp `sendMedia` per file, email `sendAiResponseEmail` with attachment(s), API JSON includes file URLs, widget SSE emits files.
- Confirm async/enqueue path either runs the executor and persists (no outbound regression) or is explicitly excluded for v1.
- Widget remains single-task (fixed prompt) by invariant — verify untouched.

**Success test (release gate):**
- Acceptance scenario re-run via **WhatsApp** (inbound voice/doc → text+MP3 back) and **email** (doc attachment → reply email with MP3) in integration tests with mocked channel transports.
- API webhook returns structured multi-file result.
- Widget end-to-end unchanged (re-run Phase 2 widget invariant).
- Channel delivery unit tests for multi-file assembly per channel.

### Phase 6 — UX, observability & admin clarity

**Goal:** make the new model legible and resolve the original "confusing" complaint.

- Frontend: show the task plan as discrete progress steps in the chat turn (reuse existing status events); collapse to the normal single-bubble UX for single-task turns (no UX change for simple chats).
- Admin: a read-only "task plan" view for a message (debugging); clarify the models config UI copy so "model purpose" (capability) and "task assignment" are presented as the same concept.
- Metrics: per-capability task counts, plan size distribution, planner latency, failure rates (logged + optional dashboard).

**Success test (release gate):**
- Frontend tests + `vue-tsc` green; i18n parity en/de; simple-chat UX visually unchanged (snapshot/E2E).
- Admin plan view renders persisted `BMESSAGE_TASKS`.
- Metrics emitted and asserted in a smoke test.

### Phase 7 — Rollout & GA

**Goal:** safe production enablement with a rollback path.

- Enable for internal/admin users first (per-user flag), then a small canary cohort, then global default-on.
- Keep `MessageClassifier`/`MessageSorter` as the **fallback** path indefinitely (planner failure → legacy classification). Do **not** delete the legacy path in this project.
- Document rollback: flip `MULTITASK_ROUTING_ENABLED` off → instant revert to current behaviour (legacy path always present).

**Success test (release gate):**
- Canary monitoring window with no increase in error rate / latency SLO breach / provider cost anomaly beyond agreed thresholds.
- Documented one-switch rollback verified in staging (flip flag, behaviour reverts, golden corpus matches Phase 0).
- `docs/` updated (routing architecture, planner prompt, capability catalog, `MIGRATIONS.md` for the new table).

---

## 7. Testing Strategy (cross-cutting)

- **Determinism:** all automated tests use `TestProvider` / recorded model responses — never live Groq in CI. Planner JSON outputs for the corpus are fixtures.
- **Golden-corpus diff harness** (Phase 0) is the backbone regression guard for every later phase.
- **Schema validation** is a first-class test surface (valid, malformed, partial, hostile inputs → safe single-`chat` fallback).
- **Channel integration tests** mock WhatsApp/email/widget transports and assert delivered payloads (text + files).
- **DAG property tests:** acyclicity enforced, dependency completeness, deterministic assembly, per-node failure isolation.
- **Performance tests:** planner latency budget; parallel speed-up proof; concurrency cap / rate-limit safety.
- **Widget invariant test** repeated in Phases 2, 5, 7 — the canary for "did we break the embed?".

---

## 8. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Planner emits invalid/hallucinated plans | Strict JSON-schema validation + repair + single-`chat` fallback; shadow mode (Phase 1) to measure real validity before executing anything. |
| Multi-task latency worse than single answer | Phase 4 parallelism; planner latency budget gate; sequential fallback; keep simple-chat on a fast path (no plan needed for trivial chat — reuse existing fast-path heuristic to skip the planner). |
| Provider rate limits (Groq) under fan-out | Bounded concurrency cap; Messenger fan-out option for heavy media; per-capability throttling. |
| Widget / fixed-prompt regression | Hard invariant + dedicated test in 3 phases; planner never runs on that branch. |
| Data breakage | Additive migrations only; legacy columns untouched; legacy path retained as fallback; one-switch rollback. |
| Scope creep into new model integrations | v1 capability vocabulary maps **only** to existing services; new providers are out of scope. |
| Cost increase (extra planner call per turn) | Skip planner for trivial chat via existing fast-path; gpt-oss-120b on Groq is the cheap/fast tier already used for SORT. |

---

## 9. Out of Scope (v1)

- New AI providers or generation services.
- Reworking the legacy `MessageController::send` direct-`AiFacade` path (left as-is).
- Outbound delivery for the async `enqueue` path (remains poll-only unless trivially included in Phase 5).
- User-authored visual task-graph editor (future; planner is automatic in v1).
- Cross-channel operator reply forwarding for email/widget (existing gap; not introduced here).

---

## 10. Definition of Done (project)

1. The canonical doc→summary→mp3 scenario works end-to-end on web, WhatsApp, and email, behind a flag that is GA-enabled.
2. The golden corpus from Phase 0 shows **zero** regressions for all simple/single-task flows with the flag on.
3. The ChatWidget behaves identically to today (invariant test green).
4. No existing table altered destructively; one additive migration; legacy routing retained as fallback with one-switch rollback.
5. All tools (RAG, web search, mail handling, office gen, media gen, vision, memories, feedback, plugins) still function, now invokable as task-plan capabilities.
6. Docs updated; CI pre-commit gate green on every phase.

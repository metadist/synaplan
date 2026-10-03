# Decision-model routing — master plan

**Status:** Draft 2026-10-03. Tick §0 before the first product PR.
**Goal:** Two tracks on one execution core.
**D-track (routing):** route most chat turns with a local *decision model*
(Ollama System One: Nimble / Clef) in well under the time of today's LLM
sorter call, and hand only the uncertain rest to the sorter — without a
single extra misroute.
**P-track (product):** offer decision models to people — an
Ollama-compatible `/v1/systemone`, a Synaplan-native `/api/v1/decisions`
with thresholds and escalation, and a **decision mode** in chat that builds,
runs, visualizes and explains decision requests.
**Binding UX:** [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md) (U1–U12) — surfaces in D7, P4, P5.
**Class:** D1–D6, P1–P3 `backend-only`; D7, P4, P5 `ota-candidate`; P6 tests + docs.

Files in this track:

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Why, decisions, architecture, question set, step table |
| `01_sprints.md` | Per-step brief (vibe prompt, files, exit criteria, class) |
| `02_api_and_decision_mode.md` | API design (both doors), decision mode UX, wireframes, test example, journeys |
| `STATUS.md` | Step log + measured numbers |

---

## Start here (60 seconds)

**What we build:** a new layer in the routing cascade. It asks a small local
model a handful of *typed* questions about the incoming message ("which
topic?", "needs the web?", "needs several steps?") and gets back a choice
plus a probability for each. Confident ⇒ route right away. Not confident ⇒
the existing LLM sorter decides, exactly like today.

**Where routing lives:**

| You want to change… | Go to… |
| ------------------- | ------ |
| The cascade order | `backend/src/Service/Message/MessageClassifier.php::classify()` |
| The layer names / `source` strings | `backend/src/Service/Message/Routing/RoutingLayer.php` |
| The LLM sorter (fallback) | `backend/src/Service/Message/MessageSorter.php` (`tools:sort`) |
| The closest existing sibling layer | `Routing/EmbeddingRouterService.php` + `EmbeddingRouterConfig.php` |
| Which sources may plan | `backend/src/Service/Multitask/TaskPlanExecutor.php::planForExecution()` |
| The live-model eval | `backend/src/Command/SortEvalCommand.php` + `backend/tests/Eval/sort_eval_corpus.json` |
| The pattern for a non-chat model port | `backend/src/Plug/Rerank/` (port, registry, adapter, eval corpus, metrics) |
| Compatible API doors (precedent) | `Controller/OpenAICompatibleController.php`, `Controller/MessagesApiController.php` |
| API key scopes | `backend/src/Security/ApiKeyScope.php` (+ `ApiKeyScopeSubscriber`) |
| The chat composer + tool badges | `frontend/src/components/ChatInput.vue` (`activeTool`), `ToolsDropdown.vue` |
| Message part renderers | `frontend/src/components/MessagePart.vue`, `stores/history.ts` (`PartType`) |
| E2E Ollama stub | `frontend/tests/e2e/stub-servers/ollama/ollama-stub-server.ts` |

**The loop that keeps you safe:** default-off flag → eval numbers → shadow
numbers → per-account opt-in → global. Never skip a rung. A routing layer
that misroutes is worse than 500 ms of latency; that is why the fast path,
the embedding router and the tool-call deferral are all still off.

**Rules for the AI assistant driving this:**

- One step = one PR. Do not start D5 inside the D2 branch.
- Every number in `STATUS.md` comes from a command you ran, with the command
  next to it. No estimated accuracy, no "should be faster".
- Never hardcode a model name. The decision model is a catalog entry with
  tag `decide`, resolved through `DEFAULTMODEL.DECIDE` (`ModelRepository` /
  `ModelConfigService`), like `RERANK`.
- The decision layer must never break a turn: timeout, HTTP error, 4xx,
  unknown key in the answer ⇒ log once, return "no decision", sorter runs.
- Default-off everywhere. With the flag off, `RoutingCharacterizationTest`
  snapshots must not change at all.
- No customer message text in the eval corpus, in a shadow table, or in a
  PR description. Internal / demo accounts or rewritten utterances only.
- No node IPs in this public repo. Ops steps point to
  `synaplan-platform/docs/WEB-CLUSTER-OPS.md`.

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | The decision model is a **plug port** `App\Plug\Decision` (interface + registry + Ollama adapter), mirroring `Plug/Rerank`. Ollama System One is the first adapter; a hosted backend can be added later without touching routing. | Proposed | |
| 2 | New model tag `decide` in `ModelCatalog` (Nimble, Clef Flash, Clef as Ollama entries) and slot `DEFAULTMODEL.DECIDE`. No slot row ⇒ layer off. | Proposed | |
| 3 | The layer commits only when **every required field** clears its threshold (all-or-nothing per turn). Partial commits are a later decision. | Proposed | |
| 4 | Thresholds are per field, global only (`DECISION_ROUTING.*` in BCONFIG), calibrated from eval + shadow data. Never guessed in code; the seeded values are deliberately strict (0.95). | Proposed | |
| 5 | Commit on the **top-option probability** (`probabilities[choice]`) and, for yes/no, on `noul ≥ τ_hi` or `≤ τ_lo`. The API's `confidence` (entropy) is logged, not used to gate — Ollama states it is not calibrated correctness. | Proposed | |
| 6 | More than 25 routable topics for an account (system + own prompts + routable assistants + `synaplan`) ⇒ v1 skips the decision layer for that turn. A two-stage shortlist is D-later. | Proposed | |
| 7 | Shadow mode stores answers and probabilities in a new table `BROUTINGSHADOW` (no message text, 30-day retention). **Schema change — ask first.** Alternative: structured log channel only. | Ask | |
| 8 | The Ollama image pin in `docker-compose.yml` moves to a version ≥ 0.35.1 (Clef needs 0.35.1). **Docker config — ask first.** | Ask | |
| 9 | No new composer/npm dependency. The adapter uses Symfony HttpClient; the existing Ollama PHP client has no System One call. | Proposed | |
| 10 | Optional feature ⇒ `FeatureModule` (`DecisionRoutingModule` in `backend/src/Module/`), decisive config listed in `.env.minimal` / `docker-compose.minimal.yml`. No bare `isEnabled()` + status block. | Locked (AGENTS.md) | |
| 11 | **One core, three callers:** `DecisionService` (validation, limits, usage, thresholds, escalation) is used by routing, the API and the chat. No caller talks to the port directly. | Proposed | |
| 12 | **Two API doors:** `POST /v1/systemone` byte-compatible with Ollama (no extensions), and `POST /api/v1/decisions` as the Synaplan superset (`accept`, `escalation`, `outcome`, `decided_by`). | Proposed | |
| 13 | New API key scope `decisions:run`; empty-scope and `*` keys keep access (grandfather rule). New rate-limit bucket `DECISIONS`. | Proposed | |
| 14 | Escalation is opt-in per request (`escalation: chat_model`). An escalated answer carries `probabilities: null` — we never fabricate a probability from an LLM. | Proposed | |
| 15 | Decision mode is a composer state (tool `decide`, `/decide`, palette), not a separate page. Every run is a normal chat turn with `DECISION_REQUEST` / `DECISION_RESULT` message meta; the message text is a one-line summary. App only, not the widget. | Proposed | |
| 16 | Live preview calls the native endpoint without saving; off by default, debounced with `AbortController`, counted against `DECISIONS`. | Proposed | |
| 17 | Examples ("Ticket triage", "Reply needed today?", "Tone check", "Photo shows damage?") are frontend presets in five locales in v1. Saved decision templates are a later decision. | Proposed | |
| 18 | Charts are plain SVG/divs with style tokens — no chart dependency. Icon for "decide": Heroicons `ScaleIcon`, added to the icon map. | Proposed | |

---

## 1. Why — the facts this plan rests on

1. **Every ordinary turn pays one LLM sorter call today.** The three layers
   that could skip it ship off: fast path (`CLASSIFIER.FAST_PATH_ENABLED`,
   off after misrouting polite media requests), embedding router
   (`EMBEDDING_ROUTER.ENABLED=0`, threshold 0.88 "not a measured value"),
   tool-call deferral (`NATIVE_TOOL_ROUTING.ENABLED=0`). Default sort model:
   `groq:openai/gpt-oss-120b:chat`. Our own code comments put the sorter at
   200–800 ms TTFT, plus the occasional structured-output healing retry.
2. **Extra full sorter calls for one bit.** A chat pinned to an assistant
   calls the whole sorter only to learn `BWEBSEARCH` / `BREADPAGES`
   (`MessageClassifier::pinnedWebSearchVote()`).
3. **No real confidence.** A successful sorter call is always
   `confidence: 1.0` (`MessageSorter::buildRoutingDecision()`); only parse
   failures and invalid topics lower it.
4. **Skipping the sorter kills the planner.** Fast path and embedding router
   carry no `multi_step` vote, so `TaskPlanExecutor` only plans for
   `ai_sorting`, `attachment_document_or_audio`, `saved_task`. A decision
   model *does* return a `multi_step` probability.
5. **Regex lexicons in five languages.** Self-awareness guard, spoken-output,
   produce-a-file, merge/export, code-execution — each is a hand-kept word
   list that has misfired before (#952, #1042, #1237, #2047, #2049).
6. **Eval corpus is too small to calibrate.** `sort_eval_corpus.json` has
   52 cases (38 general, 8 mediamaker, 4 officemaker, 2 docsummary).

## 2. The decision-model API (what we can and cannot ask)

Source: [Ollama decision guide](https://docs.ollama.com/capabilities/decision),
[API reference](https://docs.ollama.com/api/systemone),
[Sanity glossary](https://www.sanity.io/glossary/decision-model).

- `POST {OLLAMA_BASE_URL}/v1/systemone` with `model`, `state` (string or
  JSON), `questions` (1–64 named questions), optional `images` (Clef only),
  `keep_alive`. One JSON response, no streaming.
- Question types: `choice` (2–26 keyed options with descriptions →
  `choice`, `probabilities`, `confidence`), `noul` (yes/no → probability of
  true), `score` (2–26 ordered levels → weighted mean).
- Questions are independent: "Answers are not passed to later questions."
- Limits: body ≤ 64 KiB without images; input must fit the loaded context,
  never truncated. Errors: 400 (bad request / too long / cloud model), 404
  (model not pulled), 413, 500.
- Local GGUF models only (cloud models rejected). Ollama ≥ 0.35.0; Clef /
  Clef Flash ≥ 0.35.1.
- `confidence` = `1 - H(p)/ln(N)`, **not calibrated correctness**.
- Known category weaknesses: counting, arithmetic, dates, large state full of
  irrelevant detail, no text output.
- Speed claims (70–500 ms, 40–200× vs. generation) are vendor numbers for a
  different model family; an independent test saw 2–3×. **We measure ours.**

## 3. Architecture

```
MessageClassifier::classify()
  slash command → agent pin → [fast path, off] → "Again" overrides
  → attachment rules
  → NEW  DecisionRouter (flag DECISION_ROUTING.ENABLED, slot DEFAULTMODEL.DECIDE)
  │        RoutingStateBuilder   compact JSON state (§5)
  │        RoutingQuestionSet    typed questions (§4), topics from registry + BPROMPTS
  │        DecisionService::run() → DecisionRegistry::active() → OllamaSystemOneAdapter → /v1/systemone
  │        DecisionCommitPolicy  per-field thresholds → commit | escalate(reason)
  │      commit   ⇒ source=decision_model, confidence=p(topic), alternatives, votes
  │      escalate ⇒ fall through (log reason)
  → [embedding router, off] → [tool-call deferral, off]
  → MessageSorter (tools:sort)          ← unchanged safe fallback
```

Shadow mode (D3) runs the same `DecisionRouter` **after** the sorter, off
the request path (Messenger, `async` transport), and stores both answers.

The product side (P-track, [`02_api_and_decision_mode.md`](02_api_and_decision_mode.md))
calls the same core:

```
DecisionService  ← validation (limits), usage booking, thresholds, escalation
  ├─ DecisionRouter              (routing, D-track)        usage source DECISION_ROUTER
  ├─ POST /v1/systemone          (Ollama-compatible)       usage source DECISION_API
  ├─ POST /api/v1/decisions      (Synaplan-native)         usage source DECISION_API
  └─ DecisionHandler             (chat decision mode)      usage source DECISION_CHAT
```

## 4. Question set

| Key | Type | Options / meaning | Replaces / feeds |
| --- | ---- | ----------------- | ---------------- |
| `topic` | choice | system capabilities + account topics + routable assistants + `synaplan`, each with its BPROMPTS description | `BTOPIC` |
| `language` | choice | `MessageSorter::SUPPORTED_LANGUAGES` (10) | `BLANG` |
| `web_search` | noul | needs live / current web data | `BWEBSEARCH`, `pinnedWebSearchVote()` |
| `read_pages` | choice | `0` snippets / `2` / `3` full pages | `BREADPAGES` |
| `multi_step` | noul | needs more than one tool or output | `BMULTI` → planner |
| `media_type` | choice | image / video / audio | `BMEDIA` (only read when topic = mediamaker) |
| `input_mode` | choice | text_only / reference_images | `BINPUTMODE` |
| `self_aware` | noul | asks about this product itself | `SELF_AWARE_GUARD_PATTERN` |
| `spoken_output` | noul | wants to *hear* the result | `SPOKEN_OUTPUT_PATTERN` |
| `file_production` | noul | wants a new file made from an attachment | `messageRequestsFileProduction()` |

Not asked (numbers / free text): `BDURATION`, `BRESOLUTION` stay with the
sorter or regex; the media prompt text stays with `MediaPromptExtractor`
(it needs generation — a decision model cannot write it). In v1 the regex
heuristics stay authoritative; the noul questions are logged next to them
so D3 can show whether they are better before anything is replaced.

Required fields for a commit (Decision #3): `topic`, `language`,
`web_search`, `multi_step`, plus `media_type` when `topic = mediamaker`.

## 5. State builder rules

The state is JSON, small, and only what the questions concern:

```json
{
  "message": "hätte ich gerne das bild einer katze",
  "attachments": [{"kind": "document", "ext": "xlsx"}],
  "previous_user": "first 200 chars",
  "previous_assistant": "first 200 chars",
  "last_assistant_produced": "image | file | none",
  "thread_produced": ["image"],
  "ui_language": "de"
}
```

- Reuse what the classifier already computes (`lastAssistantGeneratedFile`,
  `lastAssistantGeneratedMedia`, `threadHasGenerated*`, `AttachmentDigest`
  routing view) — do not re-derive it.
- No full history, no RAG text, no system prompt. Irrelevant context is the
  documented accuracy killer.
- Hard cap well under 64 KiB; over the cap ⇒ escalate, never truncate
  silently.

## 6. Calibration method

1. Run `app:sort-eval --decision` (D2) on the corpus; per field record top
   probability and correctness.
2. Bucket by top probability (0.5–0.6 … 0.9–0.95, 0.95–1.0). Accuracy per
   bucket = reliability curve.
3. Per field pick the lowest τ whose cumulative accuracy above τ is
   **≥ the sorter's accuracy on the same cases** (and ≥ 0.97 for `topic`).
4. Coverage = share of turns where *all* required fields clear τ. That is
   the share of sorter calls we save.
5. Repeat with shadow data (D3) on real traffic before turning anything on.

Go / no-go after D2: coverage ≥ 40 % at the accuracy bar above, and decision
call p95 ≤ 40 % of sorter p95 on the same machine class. Miss ⇒ stop, write
the numbers into `STATUS.md`, keep the port for secondary uses only.

## 7. What we do not rebuild

| Already here | Use it |
| ------------ | ------ |
| Cascade + `RoutingDecision` (confidence, alternatives, fallback reason) | `Routing/RoutingDecision.php`, `RoutingLayer` |
| Flag + threshold config shape | `EmbeddingRouterConfig` (per-user flag, global threshold) |
| Non-chat model port, registry, eval corpus, metrics | `Plug/Rerank/*` |
| Topic list + descriptions for the sorter | `PromptRepository::getTopicsWithDescriptions()`, `appendRoutableAssistants()`, `SystemCapabilityRegistry` |
| Live-model eval + per-layer latency | `SortEvalCommand` (`--cascade`, `--json`, `--repeat`) |
| Usage booking | `RateLimitService::recordUsage()` (as `EmbeddingRouterService` does) |
| Ollama base URL | `OLLAMA_BASE_URL` (same as `OllamaProvider`) |
| Feature status / gate / docs | `FeatureModuleInterface`, `ModuleRegistry` |

## 8. Step table

| Step | Commit title | Class | Depends |
| ---- | ------------ | ----- | ------- |
| D0 | `docs(routing): decision-model routing plan` | no-app-impact | — |
| D1 | `feat(routing): decision-model plug port with Ollama System One adapter` | backend-only | D0, §0 #1 #2 #9 |
| D2 | `feat(routing): decision-model question set and sort-eval mode` | backend-only | D1 |
| D3 | `feat(routing): shadow-run the decision router next to the sorter` | backend-only | D2, §0 #7 |
| D4 | `feat(routing): decision model answers the pinned-assistant web vote` | backend-only | D3 |
| D5 | `feat(routing): decision-model layer in the routing cascade` | backend-only | D3 |
| D6 | `feat(routing): let decision-model turns reach the planner` | backend-only | D5 |
| D7 | `feat(routing): decision model slot, status and routing note` | ota-candidate | D5 |
| D8 | ops rollout in `synaplan-platform` (private) | ops | D5, §0 #8 |
| P1 | `feat(api): decision service with limits, thresholds and escalation` | backend-only | D1 |
| P2 | `feat(api): Ollama-compatible /v1/systemone and native /api/v1/decisions` | backend-only | P1 |
| P3 | `feat(chat): decision turns in the message pipeline` | backend-only | P1 |
| P4 | `feat(chat): decision mode in the composer` | ota-candidate | P2, P3 |
| P5 | `feat(chat): decision result card and API call preview` | ota-candidate | P4 |
| P6 | `test(e2e): decision mode journey on the Ollama stub` + docs | tests + docs | P5 |

The two tracks share only D1 (the port). After D1 they can run in
parallel: the P-track does not wait for the routing go / no-go, because a
decision API is useful even if routing stays on the sorter.

**Tomorrow (day 1):** D1 + D2 and the go / no-go number. If time is left,
P1 on top of D1.

## 9. Gates

- `make ci-local` before every commit; `make test-e2e` before pushing D7.
- `make -C backend phpstan` unscoped (it covers `tests/`).
- Flag off ⇒ `RoutingCharacterizationTest` snapshots byte-identical. Flag-on
  cases are *new* characterization cases with an injected decision result.
- Every routing step PR carries before/after `app:sort-eval --json` output.
- `node scripts/mobile-impact.mjs --base main --head HEAD` (new paths under
  `backend/**` must classify as `backend-only`; new `frontend/src/components/decision/**`
  as `ota-candidate`).
- P2/P3: `make -C frontend generate-schemas` after the OpenAPI annotations,
  then `vue-tsc`. P4/P5: `make test-e2e` and `make -C frontend test-e2e-layout`.

## 10. Risks

| Risk | Mitigation |
| ---- | ---------- |
| Misroutes at "confident" probabilities | Calibrate per field; all-or-nothing commit; shadow before opt-in |
| Weak in de / tr / es | Corpus balanced across five locales; thresholds checked per language |
| GPU contention on the shared GPU node (chat, embeddings, TTS) | Small model, `keep_alive` negative, measure VRAM in D8 |
| Cold model load (seconds) | `keep_alive`; adapter timeout 800 ms ⇒ escalate |
| Self-hosters without Ollama | Module unconfigured ⇒ layer absent, sorter as today |
| Topic list > 25 | v1 skips the layer (§0 #6); count affected accounts in D3 |
| Follow-up edits ("mach es blau") | `last_assistant_produced` in state; corpus cases for it |

## 11. Non-goals

- Replacing the LLM sorter. It stays the fallback and the source of
  `BDURATION`, `BRESOLUTION` and anything uncertain.
- Generating text with the decision model (media prompts, plans).
- Image routing with Clef in v1 (noted for later: #1237-style cases).
- Re-enabling the regex fast path.
- Decision mode in the embeddable widget.
- Saved decision templates, batch decisions (CSV in, labels out), and
  decisions as a Saved Task / workflow step — good follow-ups once P6 ships.

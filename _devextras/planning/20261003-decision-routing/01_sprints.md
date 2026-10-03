# Decision-model routing — sprints

Roadmap: [`00_master_plan.md`](00_master_plan.md) §8.

Each step has a **vibe brief**: paste it into the agent as the task, after
pointing it at `00_master_plan.md`. The exit criteria are the definition of
done; the agent reports each one with the command it ran.

The `ota-candidate` steps (D7, P4, P5) carry the five exit bullets from
[`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md) §6.

---

## Day 0 — local setup (30 min, before D1)

Docker Ollama on macOS runs on the CPU and will give you wrong latency
numbers. For the spike, use a native Ollama (Metal) on the Mac, or a tunnel
to the GPU node (see `synaplan-platform/docs/WEB-CLUSTER-OPS.md`).

```bash
brew upgrade ollama            # must print ≥ 0.35.1
ollama --version
ollama pull nimble
ollama pull clef-flash
ollama show nimble             # note parameters + context length in STATUS.md

curl -s http://localhost:11434/v1/systemone -H 'Content-Type: application/json' -d '{
  "model": "nimble",
  "state": {"message": "hätte ich gerne das bild einer katze"},
  "questions": {
    "topic": {"type": "choice", "instructions": "Which capability should handle this message?",
      "criteria": {"general": "Answer or chat in text", "mediamaker": "Create an image, video or audio",
                   "officemaker": "Create a Word, Excel, PowerPoint or PDF file", "docsummary": "Summarize a document"}},
    "web_search": {"type": "noul", "instructions": "Does answering need current information from the web?"}
  }
}' | jq
```

Point the dev backend at it for D1/D2 only (do not commit):
`OLLAMA_BASE_URL=http://host.docker.internal:11434` in `backend/.env.local`,
then `docker compose restart backend worker`.

---

## D1 — Plug port + Ollama System One adapter (backend-only)

### Goal

- Do: `App\Plug\Decision\` with `DecisionProviderInterface`
  (`key()`, `descriptor()`, `decide(DecisionRequest): DecisionResult`,
  `health()`), value objects `DecisionQuestion` (choice / noul / score),
  `DecisionRequest` (model, state, questions, keepAlive), `DecisionAnswer`
  (choice, probabilities, confidence | noul), `DecisionResult` (answers,
  usage, latency ms), `DecisionRegistry::active()`, and
  `Adapter/OllamaSystemOneAdapter` (Symfony HttpClient, `OLLAMA_BASE_URL`,
  800 ms timeout, maps 400/404/413/500 to a typed `DecisionUnavailable`
  reason).
- Do: `health()` reads `GET /api/version` and reports
  "Ollama too old for decision models (needs 0.35.0)" honestly.
- Do: catalog entries with tag `decide` (Nimble, Clef Flash, Clef) in
  `ModelCatalog`, slot `DEFAULTMODEL.DECIDE` in `DefaultModelConfigSeeder`
  — no default value row (no slot ⇒ off).
- Do: a thin `App\Service\Decision\DecisionService::run(DecisionRequest,
  ?User, string $usageSource)` — limits (1–64 questions, 2–26 options,
  64 KiB), model resolution via `DEFAULTMODEL.DECIDE`, usage booking. P1
  extends it; D2 and later call only this, never the port directly.
- Do: `app:decide:probe "<text>"` prints the four-topic + web question
  answers, probabilities and latency.
- Do not: touch `MessageClassifier`, add a composer package, change
  `docker-compose.yml`.

### Files

`backend/src/Plug/Decision/**` (new), `backend/src/Model/ModelCatalog.php`,
`backend/src/Seed/DefaultModelConfigSeeder.php`,
`backend/src/Command/DecideProbeCommand.php` (new),
`backend/tests/Unit/Plug/Decision/**` (new, with a mocked HttpClient).

### Vibe brief

> Read `_devextras/planning/20261003-decision-routing/00_master_plan.md`
> §0, §2, §7. Implement step D1 from `01_sprints.md`. Mirror the shape of
> `backend/src/Plug/Rerank/` (interface, registry, adapter, health). The
> adapter posts to `/v1/systemone` exactly as in the API reference in §2,
> with Symfony HttpClient and an 800 ms timeout; it must never throw out of
> `decide()` for HTTP or decode errors — return a result that says
> "unavailable" with a reason. Add catalog entries with tag `decide` and
> the `DEFAULTMODEL.DECIDE` slot following the `RERANK` precedent. Unit
> tests cover: choice + noul mapping, 404 model missing, 413, timeout,
> malformed JSON, unknown answer key. Then run `make ci-local`.

### Exit criteria

1. `app:decide:probe "hätte ich gerne das bild einer katze"` returns
   `mediamaker` with probabilities and a latency, against native Ollama.
2. Ollama down / model not pulled ⇒ the probe prints one plain sentence,
   exit code non-zero, no stack trace.
3. Unit tests green; `make ci-local` green.
4. `STATUS.md`: Ollama version, model sizes, probe latency p50 over 20 runs.

---

## D2 — Question set, state builder, `sort-eval --decision` (backend-only)

### Goal

- Do: `Service/Message/Routing/RoutingStateBuilder` (§5 of the master
  plan) and `RoutingQuestionSet` (§4) — topics from the same source the
  sorter uses (`PromptRepository` + `appendRoutableAssistants` logic +
  `SelfAwareConfig`), so both layers see the same option list. Move the
  shared topic-list building into one place instead of copying it.
- Do: `DecisionRouter::decide(Message, history, userId): DecisionVerdict`
  returning per-field answers plus `commit|escalate(reason)` from a
  `DecisionCommitPolicy` with thresholds from a new
  `DecisionRoutingConfig` (BCONFIG `DECISION_ROUTING`, seeded strict at
  0.95, ENABLED=0).
- Do: `app:sort-eval --decision` runs the corpus through the decision
  router and the sorter, reports per field: accuracy, reliability buckets
  (§6), coverage at current thresholds, latency p50/p95 for both layers,
  per language. `--json` for diffing.
- Do: grow `backend/tests/Eval/sort_eval_corpus.json` to ≥ 300 cases:
  ≥ 40 per locale (de, en, es, fr, tr), every field represented, including
  follow-up turns (history in the case), polite media requests, spoken
  output, produce-a-file, self-aware, live-data questions. Rewritten, never
  copied customer text. Keep `SortEvalCorpusTest` green (extend its shape
  check for the new fields).
- Do not: wire anything into `MessageClassifier`.

### Vibe brief

> Implement D2 from `01_sprints.md`. Read `MessageSorter::classify()` lines
> for topic list building and reuse it (extract a shared
> `RoutingTopicCatalog` if needed — no duplicated topic logic). Build the
> state exactly as master plan §5; reuse the classifier's existing
> generated-file / generated-media probes by moving them into a small
> helper both can call. Add `--decision` to `SortEvalCommand` following the
> existing `--cascade` code path and its `layerStats`. Then write corpus
> cases in batches of 50, run `app:sort-eval --decision --json` after each
> batch, and stop to show me the reliability table per field.

### Exit criteria

1. `make -C backend sort-eval` still works unchanged without `--decision`.
2. `app:sort-eval --decision --json` output saved under `STATUS.md` D2 with
   the exact command, model, Ollama version and machine.
3. Go / no-go from master plan §6 written down with the numbers.
4. `make ci-local` green.

---

## D3 — Shadow mode (backend-only)

**Ask first:** §0 #7 (new table) — or agree on log-only.

### Goal

- Do: after a sorter classification on a non-widget, non-incognito turn,
  dispatch `DecisionShadowMessage` (message id, user id, sorter result,
  sorter latency) on the `async` transport. The handler rebuilds state,
  runs `DecisionRouter`, stores both answers + probabilities + latency +
  would-commit flag in `BROUTINGSHADOW` (raw idempotent migration,
  `CREATE TABLE IF NOT EXISTS`, no message text). Cleanup command deletes
  rows > 30 days.
- Do: flag `DECISION_ROUTING.SHADOW_ENABLED` (per user, then global,
  default 0) and a sample rate `SHADOW_SAMPLE_PERCENT` (global, default 0).
- Do: `app:routing:shadow-report` prints agreement per field, reliability
  buckets on sorter-as-label, would-be coverage, decision latency p50/p95,
  share of turns skipped for > 25 topics.
- Do not: change the routing result of any turn.

### Exit criteria

1. Flag on for the demo user in dev: 20 chats produce 20 rows, the chat
   answers are unchanged, response time unchanged (shadow is async).
2. Ollama stopped ⇒ rows record `unavailable`, chat unaffected.
3. Report command output in `STATUS.md`.
4. Migration reviewed against AGENTS.md Galera rules (no `Schema` API).

---

## D4 — Pinned-assistant web vote (backend-only)

### Goal

- Do: in `MessageClassifier::pinnedWebSearchVote()`, ask the decision
  router for `web_search` + `read_pages` first when the module is active
  and `DECISION_ROUTING.SECONDARY_ENABLED` is on; commit when both clear
  their thresholds, otherwise call the sorter as today.
- Do: record usage (`RateLimitService::recordUsage`, source
  `DECISION_ROUTER`) like `EmbeddingRouterService`.

### Exit criteria

1. Unit tests: committed, escalated, unavailable.
2. Shadow data shows web-vote agreement ≥ the threshold used; numbers in
   `STATUS.md`.
3. Characterization snapshots unchanged with the flag off.

---

## D5 — Decision layer in the cascade (backend-only)

### Goal

- Do: `RoutingLayer::DecisionModel = 'decision_model'`; call
  `DecisionRouter` in `MessageClassifier::classify()` after the attachment
  rules and before the embedding router, under the same guards the
  embedding router uses (`!produceFileWork`, `!spokenOutput`, no model
  override, non-empty text) plus §0 #6. On commit return a classification
  with `topic`, `language`, `web_search`, `read_pages`, `multi_step`,
  `media_type`, `input_mode`, a `RoutingDecision` with real confidence and
  discarded alternatives, and `sorting_usage` for the taximeter.
- Do: `language` commits only if it clears its threshold *or*
  `resolveConfidentLanguage()` agrees — the "German question, English
  answer" incident must not come back.
- Do: `DecisionRoutingModule` (FeatureModule): configured by
  `OLLAMA_BASE_URL`, `DEFAULTMODEL.DECIDE`, `DECISION_ROUTING.ENABLED`;
  env listed in `.env.minimal` / `docker-compose.minimal.yml`.
- Do not: put `decision_model` on the planner allow-list yet (D6).

### Vibe brief

> Implement D5. Read the embedding-router block in
> `MessageClassifier::classify()` and model the new block on it, including
> the language guard and `withForceWebSearch()`. The new block must be a
> no-op when the module is inactive. Add characterization cases with an
> injected `DecisionVerdict` (committed media request, escalated ambiguous
> request, unavailable). Run the full gate, then `app:sort-eval --decision
> --json` before and after and paste both into the PR.

### Exit criteria

1. Flag off ⇒ snapshot diff empty.
2. Flag on for the demo user: "hätte ich gerne das bild einer katze",
   "wie wird das Wetter morgen in Berlin?", "mach daraus eine Excel" (after
   a generated table), "was kannst du?" each route correctly; logs show
   `decision_model` or a named escalation reason.
3. Measured end-to-end time to first token before/after on 20 turns in
   `STATUS.md`.
4. `make ci-local` + `make test-e2e` green.

---

## D6 — Planner reachability (backend-only)

### Goal

- Do: add `decision_model` to `TaskPlanExecutor::planForExecution()`'s
  source allow-list. `multi_step` is set to `true` / `false` only when its
  probability clears τ_hi / τ_lo; otherwise `null` (pre-vote behaviour:
  planner decides).

### Exit criteria

1. `UtterancePlanCharacterizationTest` + `PlannerPromptCharacterizationTest`
   unchanged with the flag off; new cases with it on.
2. "Write a love poem and read it to me as an MP3" via the decision layer
   produces a plan with a speech node.

---

## D7 — Admin slot, status and routing note (ota-candidate)

### User-flow

**J-DR-1.** An admin opens AI infrastructure, finds "Quick routing model",
picks Nimble, sees "Ready" (or "Ollama is too old — update to 0.35"), and
switches it back off from the same row.
**J-DR-2.** A user asks for a picture; the chat's info popover for that
turn says it was routed by the quick routing model with the probability,
next to where the sorting model is listed today.

### Goal

- Do: one card on AI infrastructure (pattern: Smart Search S5b) for the
  `DEFAULTMODEL.DECIDE` slot + `DECISION_ROUTING.ENABLED`, with module
  status from `ModuleStatusPresenter`; findable from Ctrl/Cmd+K.
- Do: routing note in the per-turn usage / info display
  (`stores/usageTaximeter.ts` consumer).

### Exit criteria

1. J-DR-1 and J-DR-2 walked in the browser (U10).
2. The card is reachable in ten seconds from the palette ("routing model") (U2).
3. Copy in all five locales; "quick routing model" is one canonical term (U3).
4. Ollama missing / model not pulled / too old: one sentence each with the
   fix; flag off ⇒ no routing note, no dead control (U5, U8, U11).
5. Light, dark, design v2, 320 px (U9).

---

# P-track — decision models as a product

Design: [`02_api_and_decision_mode.md`](02_api_and_decision_mode.md). Read
it fully before P1; the vibe briefs below refer to its sections.

---

## P1 — Decision service: thresholds and escalation (backend-only)

### Goal

- Do: extend `DecisionService` with `accept` rules (§3.3), the `outcome`
  (`accepted` / `needs_review` / `escalated`), and `escalation:
  chat_model` — re-ask only the failed questions to the account's tools
  model via `AiFacade::chat()` with a structured-output schema built from
  the questions (enum per choice, boolean per yes/no, integer level per
  scale). Escalated answers: `decided_by: chat_model`,
  `probabilities: null`.
- Do: rate-limit bucket `DECISIONS` in `RateLimitConfigSeeder`; usage
  sources `DECISION_API` / `DECISION_CHAT` / `DECISION_ROUTER`.
- Do not: add controllers (P2) or touch the chat pipeline (P3).

### Vibe brief

> Read `02_api_and_decision_mode.md` §3.1 and §3.3. Extend
> `DecisionService` from D1 with accept rules, outcome and escalation.
> Build the escalation schema with the existing structured-output helpers
> (look at `SortClassificationSchema` for the enum pattern). Unit-test
> every outcome, the `min_probability` rule for yes/no on both sides, a
> failed escalation (chat model error ⇒ outcome `needs_review`, never an
> exception), and that escalated answers never carry probabilities. Run
> `make ci-local`.

### Exit criteria

1. Unit tests for all three outcomes and the escalation failure path.
2. `make ci-local` green; no controller, no frontend change.

---

## P2 — The two API doors (backend-only)

### Goal

- Do: `DecisionApiController` with `POST /v1/systemone` (Ollama schema
  only, Ollama error shape) and `POST /api/v1/decisions` (superset, §3.3).
  Both thin: parse → `DecisionService` → respond.
- Do: complete OpenAPI annotations for both (every property, example,
  error); `GET /v1/models` lists `decide` models with
  `synaplan:decision` (+ `synaplan:vision` for Clef).
- Do: scope `decisions:run` in `ApiKeyScope` (constant, path map, tests);
  module gate 404 when `DecisionRoutingModule` is inactive.
- Do: contract fixtures under `backend/tests/Fixtures/systemone/` copied
  from the Ollama docs examples (choice, multiple questions, 404, 413).
- Do: `make -C frontend generate-schemas` and `vue-tsc` locally. Nothing to
  commit there: `frontend/src/generated/` is gitignored and CI regenerates it
  from the OpenAPI annotations, so the step stays `backend-only`.

### Vibe brief

> Read `02_api_and_decision_mode.md` §3. Model the controller on
> `OpenAICompatibleController` (auth, rate limit, error helper) but keep
> each method under 50 lines — everything else lives in `DecisionService`
> or a small request parser. The `/v1/systemone` door must accept exactly
> the Ollama request and return exactly the Ollama response shape; test
> it against the fixtures byte-for-byte on keys. Add the scope with the
> grandfather rule untouched. Verify both endpoints in Swagger UI at
> `http://localhost:8000/api/doc` against native Ollama. Then regenerate
> frontend schemas and run `make ci-local`.

### Exit criteria

1. The curl from §3.2 works against the dev stack with a dev API key, and
   the same request against native Ollama returns the same keys.
2. Functional tests: restricted key without scope ⇒ 403; module off ⇒
   404; over-limit body ⇒ 413; > 26 options ⇒ 400 with the field named.
3. `make ci-local` green; schemas regenerated; `vue-tsc` green.

---

## P3 — Decision turns in the chat pipeline (backend-only)

### Goal

- Do: the stream/send endpoint accepts an optional `decision` object
  (§4.5); `MessageClassifier` routes it deterministically
  (`RoutingLayer::DecisionRequest = 'decision_request'`, before every AI
  layer); `DecisionHandler` in `InferenceRouter` runs `DecisionService`,
  emits an SSE `decision` event and `complete`, stores `DECISION_REQUEST`
  on the IN message and `DECISION_RESULT` + the one-line summary on the OUT
  message. "Use this chat" builds the state from the last turns with the
  size check.
- Do: the history API returns the decision payload on the message
  (OpenAPI annotated), runtime config exposes `features.decisionMode`
  (module active **and** a decide model available).
- Do: incognito works (nothing persisted).

### Vibe brief

> Read `02_api_and_decision_mode.md` §4.5. Follow how slash tool commands
> become a deterministic `RoutingDecision` in `MessageClassifier` and how
> an existing handler is registered in `InferenceRouter`. Add
> characterization cases for the new layer; existing snapshots must not
> change. Keep the handler thin and put summary-sentence building in a
> small tested class (`DecisionSummary`). Regenerate schemas, run
> `make ci-local`.

### Exit criteria

1. A decision turn sent via the API appears in history with request,
   result and summary; reload returns the same payload.
2. Provider down ⇒ the turn ends in a terminal state with the §3.4
   sentence, never "running" forever (U8).
3. Snapshots unchanged; `make ci-local` green.

---

## P4 — Decision mode in the composer (ota-candidate)

### User-flow

J-DM-1, J-DM-4, J-DM-5 (§6 of the design file).

### Goal

- Do: tool `decide` in `ToolsDropdown` (`ScaleIcon`), `/decide` in
  `stores/commands.ts`, palette command "Start a decision"; all absent
  when `features.decisionMode` is false.
- Do: `DecisionBuilder` and its children, `useDecisionDraft`, examples in
  five locales, model picker limited to `decide` models, vision-only photo
  example, "Use this chat", 64 KiB counter, inline limit errors, mobile
  three-step sheet. `ChatInput.vue` grows by ≤ 40 lines.
- Do: send through P3; the user's turn renders as a compact "Decision
  request" card.
- Do not: live preview, API call panel, result visualisations (P5).

### Vibe brief

> Read `02_api_and_decision_mode.md` §2, §4.1–4.3 and §4.6. Build the
> components listed in §4.6 for the builder only. Follow AGENTS.md form,
> button and token rules exactly (full input class chain, `btn-*` with
> padding and radius, no Tailwind colours). New i18n namespace `decision`
> in all five locales, using the §2 vocabulary. Start from the "Ticket
> triage" example and get it sending end to end before polishing. Walk
> J-DM-1 in the browser in light, dark and 320 px, then run
> `make ci-local` and `make test-e2e`.

### Exit criteria

1. J-DM-1 (up to sending), J-DM-4 and J-DM-5 walked in the browser (U10).
2. Decide reachable from Tools, `/decide` and Ctrl/Cmd+K; chat list
   filter "Decisions" (U2).
3. All copy in five locales with the §2 vocabulary (U3).
4. Empty state: one sentence + "Start from an example"; module off ⇒ no
   tool, no command, no palette entry (U5, U11).
5. Light, dark, design v2, 320 px (U9).

---

## P5 — Result card, live preview, API call panel (ota-candidate)

### User-flow

J-DM-1 (results + rerun), J-DM-2, J-DM-3.

### Goal

- Do: `MessageDecision` part (`PartType` `decision`) →
  `DecisionResultCard` with choice bars, yes/no gauge, scale chart,
  threshold line, outcome chip, the read-only sentence, and actions (Edit
  and rerun, Run on another model, API call, Copy JSON).
- Do: `DecisionApiPreview` — curl / Python / JavaScript / JSON for both
  doors, generated from the same draft → request function the builder
  sends with (one source of truth), key as placeholder, "Create an API
  key" link.
- Do: `useDecisionLivePreview` — opt-in, 500 ms debounce, `AbortController`,
  native endpoint, not persisted; bars animate in place.
- Do: add `ScaleIcon` → decide to the icon map in
  `docs/FRONTEND_CONVENTIONS.md`.

### Vibe brief

> Read `02_api_and_decision_mode.md` §4.2 and §4.4. Charts are plain
> divs/SVG with style tokens and must meet WCAG AA in both themes — measure
> the bar and label contrast in DevTools. The API preview and the actual
> request must come from one function in `useDecisionDraft`; add a Vitest
> that proves the copied JSON equals what was sent. Walk J-DM-2 for real:
> copy the curl, create a dev API key, run it in a terminal.

### Exit criteria

1. J-DM-1, J-DM-2 and J-DM-3 walked in the browser, including the
   terminal run of the copied curl (U10).
2. The result is findable after reload in the chat and under "Decisions"
   (U2).
3. Outcome chips and every §3.4 error in five locales (U3, U8).
4. Live preview off ⇒ no request while typing; on ⇒ only the latest
   request survives (verified in the network panel) (U6).
5. Light, dark, design v2, 320 px; chart contrast measured (U9).

---

## P6 — E2E on the Ollama stub + docs (tests + docs)

### Goal

- Do: the Ollama stub serves `POST /v1/systemone` with a deterministic
  Ticket-triage fixture and honours `shouldSimulateError('/v1/systemone')`.
- Do: `decision-mode.spec.ts` tagged `@ci` with the journey from the
  design file §5, plus the 404 error run; layout run at 320 px dark.
- Do: a "Decision API" page in `synaplan-docs` (both doors, the
  vocabulary, the Ticket-triage example, honest limits) — separate repo,
  separate PR.

### Exit criteria

1. `make test-e2e` and `make -C frontend test-e2e-layout` green locally.
2. The spec fails if the decision tool is shown with the module off.
3. Docs PR linked in `STATUS.md`.

---

## D8 — Production rollout (ops, `synaplan-platform`)

Private repo; no IPs here.

1. Upgrade Ollama on the GPU node to ≥ 0.35.1, `ollama pull nimble`,
   set keep-alive so the model stays loaded; record VRAM before/after.
2. Bump the pinned Ollama digest (§0 #8) in this repo for dev parity.
3. Shadow at 10 % for 3 days → report → thresholds into BCONFIG via a
   migration that UPDATEs the rows (seeder values are bootstrap-only).
4. `ENABLED=1` per user for internal accounts → 2 days → global.
5. Rollback: `DECISION_ROUTING.ENABLED=0` (global row), no deploy needed.

# Adaptive research — the chat chooses the effort

**Status:** Plan, 2026-10-28, written from the owner's observation of the
same day: *"In Cursor the agentic loop simply runs; there is no option. Why
does Synaplan make research a mode? If I ask what I need to marry a Chinese
citizen in China — which offices, which documents, in which order — the loop
should make the answer better and more complete on its own."* The owner
decided: **no option; the chat adapts itself** (web chat, Synaplan Desktop
and the gateway route together; Tools → Research and the Desktop chip go;
the only escapes are in the moment itself).
**Priority:** Roadmap row 31. Supersedes row 21's §0 #5 (people choose the
depth) and #15 (the Research chip); everything else of row 21 stays and is
the engine this plan drives.
**Base:** `main` after research quality RS10 and RW1–RW3 merged; research
turned on by default on 2026-10-28 (row 21 STATUS).
**Goal:** A person types a question. Simple questions are answered in
seconds, as today. A question that needs one current fact gets one search
and a few pages, as today. A question with several parts, a comparison, a
procedure or a regulation becomes a research run on its own: the chat says
in one sentence what it is doing and why, shows progress and a Stop, and a
cited report lands in the chat a few minutes later. Nobody picks a mode.
Whoever wants less says so ("short"), whoever wants more clicks **Look
deeper** under any answer.

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Start here: why, target shape, decisions, journeys, step table, gates, risks |
| [`01_router_and_engine.md`](./01_router_and_engine.md) | The effort rating in the sorter, depth `auto`, the limits, the gateway header, the router corpus and its gate |
| [`02_journeys_and_ui.md`](./02_journeys_and_ui.md) | Journeys J-AR-1…7, wireframes, copy in five locales, the five questions, empty / error / flag-off states |
| [`03_sprints.md`](./03_sprints.md) | One brief per step AR1–AR7 and AX1: reads, touches, tests first, exit |
| [`STATUS.md`](./STATUS.md) | Step log and measured numbers |

---

## 1. Why — what is there, what is measured, what others do

### 1.1 Today: two paths that never meet

| Path | What happens | Where |
| ---- | ------------ | ----- |
| Web chat `POST /api/v1/messages/stream` | `MessageClassifier` → `MessageSorter` (`tools:sort`, model `DEFAULTMODEL.SORT`) votes `BWEBSEARCH` and `BREADPAGES` → at most **one** web search → 2–3 pages read (`ReadPagesPolicy`, cap `WEB_SEARCH.READ_PAGES_MAX` = 3) → one answer. No loop, no second search, no plan. | `Service/Message/Classification/*`, `TurnWebSearch`, `WebResearchService::deepen` |
| Research run `POST /api/v1/research` | Brief → plan → gather → read → reflect (loop) → write → check → deliver. Lead in tool mode or round mode. Only started from Tools → Research (web) or the Research chip (Desktop), with a depth the person picks. | `Service/Research/Run/*`, `ResearchController`, `ResearchComposer.vue`, `ResearchCard.vue` |
| Gateway `POST /v1/messages` (Desktop, Claude Code, Synamail) | `GatewayToolLoop`: `web_search` up to `WEB_SEARCH_MAX_ROUNDS` (seeded **2**), then a forced wrap-up; `web_fetch` ≤ 3 per turn; `knowledge_search` when a folder is pinned. | `AI/Messages/Tools/GatewayToolLoop.php` |

Nothing rates a question as "needs more". The sorter's three votes
(`BWEBSEARCH`, `BREADPAGES`, `BMULTI`) choose between no search, one search
and the multitask planner; none of them starts `ResearchRunService`. The only
"scale the effort" text in the product is one line in the lead prompt
(`tools:research_lead`), which runs after a person has already chosen a depth.
`RESEARCH.TOOL_MODE_MODELS` is seeded empty, so even a started run uses round
mode unless an administrator edits the row.

### 1.2 What the measurements say (row 21 STATUS)

| Comparison | Fact recall | Citation precision | Cost | Wall time |
| ---------- | ----------- | ------------------ | ---- | --------- |
| Gateway loop with web search (B1+ web), hard corpus | 0.96 | 64.0 % | 0.059 USD / answer | 10 s p50 |
| Research engine, tool mode (S-T), hard corpus | 0.96 | 87.3 % (91.9 % without meta sentences) | 0.070 USD / answer | 35 s p50 |
| Research engine with the fact-check (RS8), smoke set | 0.78 across 9 | 96.0 % | 0.117 USD median | 98 s p50 |
| Gateway without web search, hard corpus | 0.03 | — | 0.021 USD | 7.5 s |

Reading: on facts the model already knows, nothing beats a direct answer. On
new facts the loop and the engine find the same amount; the engine's claims
are backed by sources far more often, at about the same cost and three times
the wait. The gain of a research run is **completeness on multi-part
questions and sentences a person can check**, not cleverness on single facts.
That is exactly the shape of the owner's marriage question: several
authorities, several documents, an order, rules that change, and every
sentence should point to a page one can open.

### 1.3 How others decide the effort

| Product | Mechanism | What we take |
| ------- | --------- | ------------ |
| ChatGPT (GPT-5 "Auto") | A real-time router picks fast or thinking per message from conversation type, complexity, tool need and explicit intent ("think hard"). Criticised as opaque; OpenAI later added manual Fast / Thinking. | Explicit intent wins in both directions. Automation needs a visible reason and an escape in the moment, or the modes come back. |
| Perplexity "Auto" | A classifier rates sub-questions, comparison need, multi-step reasoning and synthesis difficulty, routes to Fast or Pro Search, and "errs on the side of Pro when there is any ambiguity". Deep Research stays an explicit mode (2–4 minutes). | The four complexity signals. Lean up when unsure, but never for conversation, code, creative text or translation. The explicit minutes-long mode is replaced by **Look deeper** (owner, 2026-10-28). |
| Anthropic research system | Effort-scaling rules in the prompt: simple fact-finding 1 agent, 3–10 tool calls; a comparison 2–4 subagents, 10–15 calls each; complex research more. "Agents struggle to judge appropriate effort." | Budgets are ceilings; the lead stops when every point is backed. Depth `auto` sizes the ceiling to the plan. |
| Claude API `effort` | A soft signal that scales thinking, text and the number of tool calls; "lower effort combines operations into fewer tool calls". | The chat passes a lower effort to the lookup path and the catalog default to the run; not a new slot. |
| Gemini (app and API) | Normal chat decides on its own whether a Google search improves the answer; Deep Research is a separate agent with a plan the person can edit. | The "search or not" decision already exists as `BWEBSEARCH`; this plan adds the third stage on top of it. |
| Adaptive-RAG (research) | A small classifier routes no-retrieval / single-step / multi-step by question complexity; three classes match always-expensive baselines at a fraction of the cost. | Three stages, one cheap rating, no second model call. |

---

## 2. Target shape

```text
 person types ─► tools:sort (SORT model, ~300 ms) rates BEFFORT
                    │
   ┌────────────────┼──────────────────┬──────────────────────────┐
   ▼                ▼                  ▼                          ▼
 answer           lookup             research                   followup
 stream now       one search,        ResearchRunService::start   POST /research/{id}/followups
 (seconds)        2–3 pages,         depth auto, no questions,   on the report already in
                  sources list       plan visible, card, Stop    this chat (RS10)
 (5–20 s)                            → report into the chat
                                     (minutes)
   │                │                  │
   └── Look deeper ─┘                  └── Answer now (stop_gathering)
       (starts a research run                → report from what was read,
        with the answer's sources               or a plain answer when
        as seed)                                nothing was read yet)
```

Three stages, one rating per message, decided on the server, the same for
every client. Inside a run the effort keeps adapting: the engine stops the
moment every plan point is answered (stop rule 3 of `03` §2.5), the lead
prompt already scales (2–4 searches for one fact), and depth `auto` sets the
ceiling from the plan: one or two points → the quick budget with lead rounds;
three or more → the standard budget. **Thorough never starts on its own**
(20 minutes, up to 5 USD): only **Look deeper** / **Go deeper**, with the
minutes and the cost named first.

The result of a research stage is what row 21 already delivers: the assistant
message with the report, the Markdown file, the sources with quotes, the
fact-check sentence, Not confirmed, follow-ups.

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | **One rating per message, from the sorter.** `tools:sort` gets `BEFFORT: answer \| lookup \| research` and `BEFFORT_WHY` (closed code, `01` §1.2). No second model call. Lean up when unsure, **never** to `research` for smalltalk, code, creative text, translation, rephrasing, or a follow-up the thread already answers. Explicit intent wins both ways: "short", "quickly", "in one sentence" → `answer`; "thorough", "in detail", "research", "with sources", "step by step with the authorities" → `research`. | Proposed | |
| 2 | **New classes; frozen files do not grow.** `MessageSorter` (921 lines, `classify` 356, `parseResponse` 109) and `GatewayToolLoop` (1,431) are in the size ratchet; `ChatStreamOrchestrator` has 10 constructor parameters, the limit. The rating is read in `App\Service\Message\Effort\EffortVerdict`; the start of a run is `App\Service\Message\Effort\AutoResearchGate`, a stage after `TurnClassificationResolver` beside `TurnWebSearch`, not a line in `MessageSorter::parseResponse`. | Proposed | |
| 3 | **Depth `auto` in the engine** (`ResearchDepth::Auto`): no plan wait, 0 clarifying questions, the ceiling from the plan's point count (`01` §2), lead mode as `standard` (tool mode when the lead model is on `TOOL_MODE_MODELS`), the check as `standard` (removal). Thorough stays manual. | Proposed | |
| 4 | **Tool mode becomes the default for models that passed conformance.** `RESEARCH.TOOL_MODE_MODELS` seeded with the catalog keys that scored 20/20 in `app:research:conformance` (`anthropic:claude-sonnet-5-5:chat`, `anthropic:claude-sonnet-5:chat`); a migration sets the row on existing installs **only when it is still `[]`**. `LEAD_MODE` stays `auto`. gpt-oss-120b (12/20) stays in round mode. | Proposed | |
| 5 | **Escapes instead of modes.** During an automatic run: **Answer now** (= `stop_gathering`: the report from what was read; when nothing was read yet, the run is cancelled and the message is answered as `lookup`). Under every `lookup` answer and every `answer` that the sorter rated `research` but a limit held back (#7): **Look deeper** (starts a run with the answer's sources as `seed_sources` and the last turns as `context`). After an `auto` report: **Go deeper** as today (J-RQ-9). | Proposed | |
| 6 | **The reason in one sentence** (U3, U8). At the start: "I am looking through several sources for this — about {minutes} minutes." followed by the reason from `BEFFORT_WHY`: "it has several parts", "it is a comparison", "it needs current sources", "you asked for a thorough answer". No forbidden word (`05` §6 of row 21: lead, reader, token, prompt, …). | Proposed | |
| 7 | **Honest at a limit, never a silent downgrade.** `MAX_ACTIVE_RUNS` full (5 per install today), the account's usage budget spent (`RateLimitService::checkCostBudget`), web off and no folder to read, the research store unreachable, module off — and the daily plan limit of row 21 §0 #25 once it ships → the message is answered as `lookup` (or `answer` when no search is possible) and the answer **begins** with one sentence that names the limit and the way out (`02` §4). The sentence is kind-specific. | Proposed | |
| 8 | **Tools → Research and the Desktop chip go.** `ResearchComposer.vue` is deleted; `ResearchCard.vue`, `handlers/research.ts` and `researchSession.ts` stay and are fed by the gate's `research_started` event. Sources of an automatic run: the web when `ResearchSearch::available()`, plus the folders the chat already searches (web: the pinned Library folders of the chat; Desktop: the `x-synaplan-rag-group-key` folder). The admin tab (RW2) stays and gets one switch **Automatic research** (`RESEARCH.AUTO_ENABLED`, seeded `1`). It is an operator cost switch, not a person's option. Off ⇒ the chat behaves as today, no card, no Look deeper (U11). | Proposed | |
| 9 | **The gateway is opt-in.** Claude Code and Synamail expect the answer in the same request; a minutes-long run does not fit. Without a header the gateway rates only `answer` / `lookup` and gets a higher ceiling: `WEB_SEARCH_MAX_ROUNDS` seeded **4** (was 2) with a migration that raises rows still at `2`, and the effort rules of `01` §3 in the gateway system prompt so the model stops early on simple questions. With `x-synaplan-effort: auto` (sent by Desktop) a `research` rating starts the run with the pinned folder as a source, answers with the one sentence of #6 and the header `x-synaplan-research-run: rr_…`. Additive, optional, default behaviour unchanged (mobile policy). | Proposed | |
| 10 | **Desktop** (`synaplan-desktop`, its own pull requests): the chip goes, the header is sent, the card attaches to the run named in the response header (`GET /v1/research?active=1` for reattach), **Answer now** and **Look deeper** as on the web, the report in `out/` and on the Out board as today. `protocol: 1` and the vendored fixtures untouched. | Proposed | |
| 11 | **Eval before the switch flips.** Router corpus `backend/tests/Eval/research/router.json`: ≥ 60 messages in five languages labelled by the owner (`answer` / `lookup` / `research` / `followup`), containing the 20 starter and 12 hard items of row 21, smalltalk, code, translation, follow-ups and the marriage question. `app:research:router-eval` reports agreement, over-escalation (`research` on an `answer` label) and under-escalation (`answer` or `lookup` on a `research` label). **Gate:** agreement ≥ 90 %, over-escalation ≤ 5 %, under-escalation ≤ 10 %, zero extra model calls per message. Plus the cost curve: mean cost per 100 corpus messages before and after, from `BUSELOG`. The router runs on the SORT model, so the eval stays far under the 15 € cap. | Proposed | |
| 12 | **Row 21 is superseded in two rows and kept otherwise.** §0 #5 (people choose Standard / Thorough) and #15 (the Research chip) are replaced by this plan; #24 (no widget, no channels) and #25 (plan limits) stay. Dated STATUS lines in both tracks. | Proposed | |
| 13 | **The widget and the channels stay out** (row 21 §0 #24). The widget never searches; WhatsApp, Telegram and mail answer as today. A rating of `research` on those paths is treated as `lookup`. | Locked (row 21) | |
| 14 | **No schema change, no new dependency, no compose change** beyond the seeds and migrations named in #4, #8 and #9 (BCONFIG rows only). Run state stays in Redis. | Proposed | |
| 15 | **Saved Tasks keep their own switch.** `MULTITASK.RESEARCH_ENABLED` and the `research` capability (RW3) are unchanged; the planner is not the chat. | Proposed | |

---

## 3. Journeys

Details, wireframes and copy in [`02_journeys_and_ui.md`](./02_journeys_and_ui.md).

| Journey | Who | Walked in |
| ------- | --- | --------- |
| **J-AR-1** — Simple stays fast: "What time is it in Tokyo?" is answered in seconds, no card, no extra button | Ada | AR3 |
| **J-AR-2** — Hard becomes research: the marriage question gets the sentence with its reason, the card with Stop and Answer now, and a cited report in the chat a few minutes later | Ada | AR3, AR7 (live) |
| **J-AR-3** — Look deeper: a lookup answer with three sources → Look deeper → a run seeded with those sources → report | Ben | AR3 |
| **J-AR-4** — Follow-up: "And in Austria?" under the report → a short cited answer from the same sources, no new full run | Chris | AR3 |
| **J-AR-5** — At a limit: daily runs used or web off → a short answer that begins with the reason; an administrator sees the link | Ada, Admin | AR3 |
| **J-AR-6** — Desktop: J-AR-2 in Synaplan Desktop, the same card in the thread, the report in `out/` and on the Out board | Dana | AR6 |
| **J-AR-7** — Admin: Automatic research off → J-AR-2 answers as today, no card, no Look deeper; on again → J-AR-2 as above | Admin | AR4 |

---

## 4. What we do not rebuild

| Already here | Use it |
| ------------ | ------ |
| The research engine, both lead modes, budgets, stop rules, outcomes, the fact-check, delivery into the chat | `App\Service\Research\Run\*` (row 21 RS6–RS10) |
| Follow-ups that reuse the sources (`question`, `revise`, `deeper`, `refresh`) | `POST /research/{id}/followups` (RS10) |
| `seed_sources` and `context.turns` on the start request | `10_contracts.md` §2.3 of row 21 (accepted today, applied by AR2) |
| The research card, its event handler and the session store in the web app | `ResearchCard.vue`, `handlers/research.ts`, `researchSession.ts` (RW1) |
| The admin tab with the switch, models, budgets and plan limits | `ResearchAdminTab.vue`, `ResearchAdminService` (RW2) |
| The sorter and its snapshot contract | `MessageSorter`, `tools:sort`, `RoutingCharacterizationTest` |
| The one-search path with page reading and sources | `TurnWebSearch`, `WebResearchService::deepen`, `KnowledgeSources.vue` |
| Conformance per lead model | `app:research:conformance`, `LeadConformance` |
| The eval harness and the corpora | `app:research:eval`, `corpus.json`, `corpus-hard.json` |
| Desktop research card, Out board entry, reattach | `synaplan-desktop` RD3–RD9 |

---

## 5. Step table

| Step | Commit title | Class | Depends |
| ---- | ------------ | ----- | ------- |
| AR0 | `docs(planning): adaptive research — the chat chooses the effort` | no-app-impact | — |
| AR1 | `feat(messages): the sorter rates how much a question needs` | backend-only | AR0, §0 #1–#2, #11 |
| AR2 | `feat(research): depth auto sizes the run to the plan` | backend-only | AR0, §0 #3–#4, #8 (seed) |
| AR3 | `feat(chat): the chat researches on its own when a question needs it` | ota-candidate | AR1, AR2, §0 #5–#8 |
| AR4 | `feat(admin): an operator can turn automatic research off` | ota-candidate | AR2 |
| AR5 | `feat(messages): the gateway raises its search ceiling and starts research on request` | backend-only | AR1, AR2, §0 #9 |
| AR6 | `feat(research): the chat researches on its own` (in `synaplan-desktop`) | desktop | AR5, §0 #10 |
| AR7 | `test(research): adaptive research measured on the corpus` | no-app-impact | AR3, AR5 |
| AX1 | `docs: the chat chooses how deep to look` (in `synaplan-docs`) | docs | AR3, AR6 |

Order: AR0 → AR1 and AR2 (independent) → AR3 (AR4 in the same window) →
AR5 → AR6 → AR7 → AX1. One step per pull request; the owner merges.

---

## 6. Gates

Per commit:

```bash
make ci-local            # lint, phpstan (src + tests), deptrac, PHPUnit, Vitest, vue-tsc
```

Per pull request, in addition:

```bash
make test-e2e                                              # AR3, AR4
make -C frontend test-e2e-layout                           # AR3, AR4 (card, notice and Look deeper at 320 px)
make -C frontend generate-schemas && docker compose exec -T frontend npm run check:types   # AR2 (capabilities), AR5 (headers in OpenAPI)
docker compose exec -T -e UPDATE_ROUTING_SNAPSHOTS=1 backend ./vendor/bin/phpunit tests/Characterization/RoutingCharacterizationTest.php   # AR1 — then read every changed line
docker compose exec -T backend php bin/console app:research:router-eval --corpus=router     # AR1, AR7 (gate of §0 #11)
docker compose exec -T backend php bin/console app:research:eval --corpus=smoke --arm=b4 --depth=auto # AR2, AR7 (AR2 adds --depth to the command)
node scripts/mobile-impact.mjs --base main --head HEAD     # class as in §5
```

Synaplan Desktop, per pull request: `make ci-local` and
`cargo run -p synaplan-core --example usecases -- --quick`.

- `RESEARCH.AUTO_ENABLED = 0` ⇒ the routing snapshots are unchanged except
  for the new `BEFFORT` fields, no `research_started` event is ever sent,
  no notice, no Look deeper, no card (U11).
- New classes respect deptrac (`App\Service\Message\Effort\*` is Application
  layer and may depend on `App\Service\Research\Run\*`) and the size ratchet.
- Locale parity green in both apps; no ledger additions.
- PHPUnit and Playwright use the test doubles of row 21 (§0 #27 there); the
  router eval and the live marriage walk run on the dev stack with real
  providers and are recorded in STATUS with their command.

### Track exit

- J-AR-1…5 and J-AR-7 walked in the browser and entered in STATUS; J-AR-6
  walked on Windows and one other OS.
- Router gate of §0 #11 met and recorded; cost per 100 messages before and
  after recorded; the marriage question answered live with a report whose
  fact-check sentence is in STATUS.
- Tools → Research, `ResearchComposer.vue` and the Desktop chip are gone;
  `RESEARCH.AUTO_ENABLED` seeded on; `TOOL_MODE_MODELS` seeded.
- AX1 merged in `synaplan-docs`; the row 21 user docs no longer describe a
  mode.
- Plan archived with a dated note when AX1 ships.

---

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| Over-escalation: simple questions wait minutes and cost dollars | Gate §0 #11 (≤ 5 % over-escalation); `research` never for the excluded kinds (#1); ceiling from the plan (#3); **Answer now** on the card; the admin switch (#8); `MAX_ACTIVE_RUNS` and the usage budget apply to automatic runs too, and row 21 #25 (runs per day by plan) becomes a real need once runs start on their own — it is asked again in STATUS |
| Under-escalation: a hard question gets a thin answer | **Look deeper** under every searched answer (#5); under-escalation ≤ 10 % in the gate; "lean up when unsure" |
| The automation feels opaque (the ChatGPT lesson) | The reason sentence (#6); the card's Details log; the Stop |
| The sorter's JSON grows and the fast cheap model gets it wrong | Closed enums with a strict parser that falls back to `answer`; the characterization snapshots; the router corpus in five languages |
| The chat's turn ends with a sentence and the person thinks that was the answer | The notice is a distinct block with the card directly under it, not a plain assistant bubble; the report arrives as a new message with a badge on the chat (U6) |
| A run starts and the worker queue is full | `MAX_ACTIVE_RUNS` and the `queued` sentence stay; a full queue is a limit of #7 and answers as `lookup` with the reason |
| The gateway path (Claude Code, Synamail) changes behaviour | Research only with the header (#9); the raised round ceiling is measured on the desktop use cases UC-03 / UC-04 before and after (AR5 exit); documented in `docs/ANTHROPIC_COMPATIBLE_API.md` |
| Snapshots drift silently | Re-record once in AR1, read every line, name the diff in the pull request |
| Two automatic runs for one message (retry, reconnect) | The gate uses the inbound message id as `Idempotency-Key` |
| The notice and the report land in the wrong chat | The gate passes the turn's `chat_id`; incognito turns never start a run (`researchSession` already refuses incognito) |
| Frontend files grow past the ratchet | New components only (`AutoResearchNotice.vue`, `LookDeeperAction.vue`); `ChatView.vue` and `ChatInput.vue` unchanged in size or smaller |

---

## 8. Non-goals

- A manual depth picker, a research mode, a chip, a slash command for
  research. ("Look deeper" and "Go deeper" are actions on a result, not
  modes.)
- Automatic Thorough runs.
- Research in the widget or over WhatsApp, Telegram or mail (row 21 §0 #24).
- A second router model or an embedding router for the effort rating.
- Changing the engine's budgets, prompts or outcomes beyond depth `auto`.
- Point researchers, parallel sub-loops, external deep-research agents (row
  21 §9).

---

## 9. Later

| # | What | Why it waits |
| - | ---- | ------------ |
| 1 | Learn the rating from use: a thumbs-down on an `answer` that should have been research, or a **Look deeper** click right after an answer, becomes a labelled row for the corpus | Needs the corpus and the eval command first (AR1, AR7) |
| 2 | Stream the report's summary into the chat as it is written instead of delivering it as one message | Needs the writer to emit sections as events (row 21 `03` §2.6 has `section`); UX decision whether a growing report or a finished one reads better |
| 3 | Pass a lower `effort` to the lookup path on models that support it | Measure first whether it shortens the one-search path without losing recall |
| 4 | The gateway starts research without a header when the client declares it can wait (a tool the client exposes) | Needs a client that does; Desktop uses the header |

# Adaptive research — steps

Roadmap: [`00_master_plan.md`](./00_master_plan.md) §5. Router and engine:
[`01_router_and_engine.md`](./01_router_and_engine.md). Journeys and copy:
[`02_journeys_and_ui.md`](./02_journeys_and_ui.md). Row 21's engine docs
(`03_research_engine.md`, `04_prompting.md`, `10_contracts.md`) stay the
reference for everything this plan does not change.

Every step has the same shape: **Goal** (do / do not), **Reads**, **Touches**,
**Tests first** (written and red before the code), **Brief** (paste into the
coding agent after pointing it at `00_master_plan.md`), **Docs**, **Exit**. A
step starts only when the §0 rows it names are ticked. AR6 is a pull request
in `synaplan-desktop`, AX1 in `synaplan-docs`, everything else in `synaplan`.
UI steps (AR3, AR4, AR6) carry a **User-flow** line and the five exit bullets
of `02` §7.

Rules for every step: one step = one PR with the §5 commit title; no new
dependency; no model name in code (catalog keys in seeders only); the frozen
files of the size ratchet do not grow (`MessageSorter.php`,
`GatewayToolLoop.php`, `ChatView.vue`, `ChatInput.vue`); five locales in the
same PR; numbers in STATUS only from commands; `make ci-local` before every
commit, `make test-e2e` before every push that touches the frontend; mobile
impact classified with `node scripts/mobile-impact.mjs`.

---

## AR1 — The sorter rates how much a question needs

**Class:** backend-only. **Needs:** §0 #1, #2, #11 ticked; the router corpus
labelled by the owner.

**Goal.** Do: add `BEFFORT` / `BEFFORT_WHY` to `tools:sort` and read them in a
new class; ship the corpus and `app:research:router-eval`; meet the gate. Do
not: start any run, touch `MessageSorter::parseResponse`, change any other
sorter field.

**Reads.** `01` §1 and §4; `backend/src/Prompt/PromptCatalog.php`
(`tools:sort`, rules 6–6b and "Answer format"); `MessageSorter::classify`
and `parseResponse` (to see what the decoded JSON looks like, not to edit);
`Service/Message/Classification/SorterVerdict.php`;
`tests/Unit/Service/Message/Classification/SorterVerdictTest.php`;
`tests/Characterization/RoutingCharacterizationTest.php`;
`Command/ResearchEvalCommand.php` (shape of an eval command).

**Touches.** `PromptCatalog.php` (the 6c rule and two answer-format lines);
new `Service/Message/Effort/EffortVerdict.php` (enum `Effort`, enum
`EffortReason`, `fromSorterJson()`, `toClassificationFields()`);
`SorterVerdict.php` (one call, flattening `effort`, `effort_why`,
`effort_voted`); new `Command/ResearchRouterEvalCommand.php` +
`Service/Research/Eval/RouterEval*.php`; new
`tests/Eval/research/router.json`; snapshots re-recorded.

**Tests first.**

- `EffortVerdictTest`: the three values, the eight reasons, missing field →
  `answer` / `voted=false`, garbage → defaults, `research` with
  `BWEBSEARCH=0` kept.
- `SorterVerdictTest`: the three new keys appear in the classification;
  `parse_failed` → `answer`, `voted=false`.
- `RouterEvalTest`: metrics on a fixture corpus with a scripted sorter
  (agreement, over-, under-escalation, no-vote, confusion matrix).
- `RoutingCharacterizationTest` re-recorded; the diff contains only the new
  fields.

**Brief.**

> Add rule 6c and the two answer-format lines of `01` §1.3 to `tools:sort`.
> Create `App\Service\Message\Effort\EffortVerdict` that reads `BEFFORT` and
> `BEFFORT_WHY` from the decoded sorter JSON with the defaults of `01` §1.2,
> and call it once from `SorterVerdict::classify` so `effort`, `effort_why`
> and `effort_voted` join the classification array. Do not edit
> `MessageSorter`. Add `app:research:router-eval --corpus=router
> [--repeat=N]` that runs the production sorter (through `SorterInput`) on
> `tests/Eval/research/router.json` and prints the `01` §4.2 table and the
> confusion matrix. Re-record the routing snapshots once and list every
> changed line in the pull request. Run the eval three times on the dev
> stack and put the table in STATUS.

**Docs.** `docs/RESEARCH.md` gets a section "How the chat decides" (the three
stages, the corpus, the command). `docs/ARCHITECTURE.md` chat path: the
effort fields.

**Exit.** Gate of `01` §4.2 met (agreement ≥ 90 %, over ≤ 5 %, under ≤ 10 %,
no-vote ≤ 2 %, 0 extra calls, p95 within 20 %) on the SORT model of the dev
stack, three repeats, recorded in STATUS with the command; snapshots
reviewed; `make ci-local` green.

---

## AR2 — Depth `auto` sizes the run to the plan

**Class:** backend-only. **Needs:** §0 #3, #4, #8 (the seed only), #14.

**Goal.** Do: `ResearchDepth::Auto`, the ceiling rule, `hint`, `answer_now`,
`automatic` in capabilities, `RESEARCH.AUTO_ENABLED` and the
`TOOL_MODE_MODELS` seed with their migrations. Do not: change the stop rules,
the checker, budgets of the other depths, or any prompt except the brief's
one sentence for `hint: deeper`.

**Reads.** `01` §2; `Service/Research/Run/ResearchDepth.php`,
`ResearchRequest.php`, `ResearchBudget.php`, `ResearchBudgetConfig.php`,
`ResearchRunService.php` (`open`, `guardLimits`, the `stop_gathering`
branch), `Lead/LeadModeResolver.php`, `ResearchCapabilities.php`,
`Seed/ResearchConfigSeeder.php`, `migrations/Version20261028110000.php`
(the shape of a BCONFIG migration on this cluster);
`tests/Unit/Service/Research/Run/ResearchStagesTest.php`,
`ResearchFollowUpTest.php`.

**Touches.** `ResearchDepth.php` (+ `Auto`); `ResearchRequest.php`
(`hint`); new `Run/ResearchAutoCeiling.php` (the point-count rule, returns
the budget to shrink to); `ResearchBudget.php` (`shrinkTo()`); the plan
stage (one call after the plan is known, one `plan` event extra);
`LeadModeResolver.php` (`Auto` as `Standard`); `ResearchRunService.php`
(`answer_now` branch — small; if the file is near a ratchet limit, a new
`Run/AnswerNow.php`); `ResearchCapabilities.php` (`auto` row, `automatic`);
`ResearchConfig.php` (`AUTO_ENABLED`, `autoEnabled()`);
`ResearchConfigSeeder.php` (`AUTO_ENABLED = '1'`, `TOOL_MODE_MODELS` list);
`migrations/Version20261028120000.php` (`TOOL_MODE_MODELS` where `'[]'`);
`ResearchController.php` OpenAPI (`auto`, `answer_now`, `hint`,
`automatic`); `ResearchEvalCommand.php` (`--depth`); generated schemas.

**Tests first.**

- `ResearchAutoCeilingTest`: 1–2 points → quick, ≥ 3 → standard, `brief` →
  quick with the point cap, `deeper` → never quick.
- `ResearchStagesTest`: an `auto` run with a 2-point plan uses rounds and the
  quick ceiling; a 5-point plan keeps standard and tool mode when the lead is
  on the list; the `plan` event carries the ceiling.
- `ResearchRunServiceTest` (new or existing): `answer_now` before a source →
  `stopped_by_user` and a `direct_answer` marker; after a source → the
  `stop_gathering` path; during writing → `202` no-op.
- `LeadModeResolverTest`: `Auto` decides like `Standard`.
- `ResearchConfigSeederTest` / migration test: seed values; the migration
  leaves a non-empty list alone.
- Capabilities test: `automatic` follows `AUTO_ENABLED` and the module.

**Brief.**

> Add `ResearchDepth::Auto`. An `auto` run starts with the standard budget
> without questions and without a plan wait; after the plan stage
> `ResearchAutoCeiling` picks quick (≤ 2 points, or `hint: brief`) or
> standard, the budget shrinks once, the lead mode is fixed before the first
> lead call, and the `plan` event names the ceiling. Add `hint` to the
> request (`brief` | `deeper`, only read by `auto`). Add input type
> `answer_now` with the three behaviours of `01` §2.5. Add
> `RESEARCH.AUTO_ENABLED` (seeded on) and `automatic` to the capabilities.
> Seed `TOOL_MODE_MODELS` with the two conformance-passing keys and write
> the migration that sets it only where the row is still `[]`. Raw
> idempotent SQL in migrations, no `Schema` calls. Regenerate the frontend
> schemas.

**Docs.** `docs/RESEARCH.md` (depth `auto`, `answer_now`, `hint`,
`AUTO_ENABLED`, the tool-mode seed), `docs/CONFIGURATION.md` (the two rows),
`docs/MIGRATIONS.md` only if a new pattern appears (it should not).

**Exit.** `app:research:eval --corpus=smoke --arm=b4 --depth=auto` on the dev
stack: recall not below the standard row of row 21 STATUS on the same items,
cost per run and p50 recorded; a 2-point item visibly ran quick (ledger);
`make ci-local` green; schemas regenerated and `vue-tsc` green.

---

## AR3 — The chat researches on its own when a question needs it

**Class:** ota-candidate. **Needs:** AR1, AR2 merged; §0 #5–#8.
**User-flow:** J-AR-1…5 (`02` §2).

**Goal.** Do: the gate, the notice, the card attached to the message,
Answer now, Look deeper, the limit sentences; remove Tools → Research and
`ResearchComposer.vue`. Do not: grow `ChatView.vue`, `ChatInput.vue`,
`ChatStreamOrchestrator`; add a mode, a setting or a chip; touch the widget
or any channel path.

**Reads.** `01` §1.1, §1.5, §2.4–2.6; `02` entirely;
`Service/Message/MessageProcessor.php` (the stream and buffered paths),
`Processing/TurnClassificationResolver.php` (`withWidgetMode`,
`withRagScope`), `Processing/TurnWebSearch.php`, `TurnDispatcher`,
`Research/Run/ResearchReportDelivery.php`, `ResearchOutcomeText.php`;
frontend `components/research/ResearchHost.vue`, `ResearchCard.vue`,
`services/chat/stream/handlers/research.ts`, `researchSession.ts`,
`stores/research.ts`, `components/ToolsDropdown.vue`, the message actions
row component used by `ChatMessage.vue`, `views/SharedChatView.vue`;
`tests/e2e/tests/research.spec.ts`;
`tests/unit/services/chat/stream/researchHandler.spec.ts`;
`docs/FRONTEND_CONVENTIONS.md` § Iconography.

**Touches.** Backend: new `Service/Message/Effort/AutoResearchGate.php`,
`GateDecision.php`, `EffortNotice.php` (the sentence per reason from the
backend locale files); `MessageProcessor.php` (one constructor parameter,
one call per path); `Processing/TurnStatus` or the status relay (the
`research_started` event); the handler's "say first" slot for
`effort_notice`; `StreamReplyPersister` or its input (`providerIndex =
RESEARCH_NOTICE`); backend locale files (six limit sentences). Frontend:
new `components/research/AutoResearchNotice.vue`,
`components/chat/LookDeeperAction.vue`; `handlers/research.ts`
(`research_started` → attach the card to the message id);
`researchSession.ts` (`lookDeeper(messageId, seedSources)`,
`answerNow(runId)`); `ResearchCard.vue` (Answer now beside Stop, phase
consequence); `ResearchHost.vue` (card anchored to a message, not to the
composer); the message actions row (+ `LookDeeperAction`); `ChatMessage.vue`
renders `RESEARCH_NOTICE` through `AutoResearchNotice`; `ToolsDropdown.vue`
(entry removed); delete `ResearchComposer.vue`; `stores/research.ts`
(`openComposer` removed, `available` → `automatic`); five locale files;
`docs/FRONTEND_CONVENTIONS.md` icon map (+ Look deeper).

**Tests first.**

- `AutoResearchGateTest`: the six checks in order, every fall-through reason,
  widget / channel / Saved Task / Again / fixed-prompt / incognito turns return
  silent fall-through before reading the rating; the start body of `01` §2.4;
  `Idempotency-Key = msg:{id}`; `AUTO_ENABLED = 0` → silent.
- `MessageProcessorTest`: a `research` rating ends the turn with the notice
  reply and the `research_started` status; a refused rating continues to
  `TurnWebSearch` with `effort_notice` set; nothing changes for `answer`.
- `EffortNoticeTest`: six reasons × five locales resolve to a non-empty
  sentence; placeholders match.
- `MessageTurnCharacterizationTest` (`sse_transcript.json`): one new
  transcript for the `research_started` turn.
- Frontend: `researchHandler.spec.ts` (`research_started` attaches to the
  message); `AutoResearchNotice.spec.ts` (notice text per reason, minutes);
  `LookDeeperAction.spec.ts` (shown for `lookup` and `effort_notice`, hidden
  for `answer`, disabled reasons, click → session call with seed sources);
  `ResearchCard.spec.ts` (Answer now per phase; disabled in writing).
- Playwright `research.spec.ts`: replace "the menu entry is absent" with
  "nothing of automatic research exists when it is off"; add J-AR-1 (no
  element), J-AR-2 (notice + card + report via the stubbed run), J-AR-3
  (Look deeper), J-AR-5 (one limit reason), the 320 px dark case for the
  notice + card; `test-e2e-layout` case for the actions row at 320 px.

**Brief.**

> Create `AutoResearchGate` with the checks of `01` §2.4 and call it in
> `MessageProcessor` after `finish()` and before `TurnWebSearch::run()`, on
> both paths. On a start, send `research_started {run_id, minutes, why}`
> through the status callback and return the notice sentence as the turn's
> reply with `providerIndex = RESEARCH_NOTICE`. On a refused `research`
> rating, set `classification['effort_notice']` and let the turn continue;
> the handler puts the matching sentence first. In the web app render the
> notice as `AutoResearchNotice.vue` with the card under it, anchored to the
> message; add **Answer now** to the card with the three consequence
> sentences; add `LookDeeperAction.vue` to the actions row for `lookup`
> answers and held-back answers; remove the Tools → Research entry and
> delete `ResearchComposer.vue`. Five locales. Walk J-AR-1…5 in the browser
> and enter them in STATUS.

**Docs.** `docs/RESEARCH.md` ("How the chat decides" gets the journeys;
the "Start a research" section is rewritten — there is no start button),
`docs/UX_CONTRACT.md` only if a new shared pattern is named (the notice
block is a candidate for §4; the owner decides). `docs/FRONTEND_CONVENTIONS.md`
icon map.

**Exit.** The five bullets of `02` §7 for J-AR-1…5; `make ci-local`,
`make test-e2e`, `make -C frontend test-e2e-layout` green; locale parity
green with no ledger additions; `ResearchComposer.vue` and `research.menu`
gone; `ChatView.vue` / `ChatInput.vue` not larger; mobile-impact
`ota-candidate`.

---

## AR4 — An operator can turn automatic research off

**Class:** ota-candidate. **Needs:** AR2 merged (AR3 in the same window;
this step can merge before or after it). **User-flow:** J-AR-7.

**Goal.** Do: the second switch on the admin tab with its consequence
sentence, the two info lines, the audit entry. Do not: add a per-person
setting; add a depth picker to the admin tab.

**Reads.** `02` §2 J-AR-7, §3.4, §4 admin keys;
`Service/Research/Run/ResearchAdminService.php`;
`components/admin/research/ResearchAdminTab.vue`;
`tests/Unit/Service/Research/Run/ResearchAdminServiceTest.php`;
`tests/unit/components/admin/research/ResearchAdminTab.spec.ts`.

**Touches.** `ResearchAdminService.php` (`autoEnabled` in the view and the
update; the no-vote rate of the last 24 h from the classification log or
`BUSELOG` meta — one query in a repository); `ResearchAdminTab.vue` (one row,
two info lines); admin OpenAPI + generated schemas; five `admin.json`
files.

**Tests first.** `ResearchAdminServiceTest` (round-trip, audit with old and
new value, second switch refused while Research is off); `ResearchAdminTab.spec.ts`
(row present only when the module is on, disabled while Research is off,
consequence text, info lines conditional); Playwright: J-AR-7 with the stub
(off → J-AR-2 shows nothing; on → the notice appears).

**Brief.**

> Add **Automatic research** under the Research switch on
> `ResearchAdminTab.vue`, bound to `RESEARCH.AUTO_ENABLED` through
> `ResearchAdminService`, with the help text and the off-consequence of `02`
> §4, disabled with "Turn on Research first." while Research is off. Show
> the "web search is off" line when `ResearchSearch::available()` is false
> for the install and the no-vote line when the 24-hour rate is above 2 %.
> Audit `research.settings.update`. Five locales. Walk J-AR-7.

**Docs.** `docs/RESEARCH.md` admin section; `docs/FEATURE_FLAGS.md` (one
line: the module switch and the automatic switch are different things).

**Exit.** Five bullets of `02` §7 for J-AR-7; `make ci-local`, `make
test-e2e` green; schemas regenerated.

---

## AR5 — The gateway raises its search ceiling and starts research on request

**Class:** backend-only. **Needs:** AR1, AR2 merged; §0 #9.

**Goal.** Do: `WEB_SEARCH_MAX_ROUNDS` 2 → 4 with the migration, the effort
paragraph in the gateway system prompt, the `x-synaplan-effort` request
header and the two response headers, the gate on this path. Do not: change
behaviour without the header beyond the ceiling and the paragraph; grow
`GatewayToolLoop.php`; touch the Anthropic server-tool path.

**Reads.** `01` §3; `Controller/MessagesApiController.php` (headers,
`x-synaplan-web-search`), `AI/Messages/Tools/GatewayToolLoop.php`
(`WEB_SEARCH_MAX_ROUNDS` use, `WRAP_UP_AFTER_SEARCH`),
`AI/Messages/DesktopTurnOptions.php`, `MessagesContextInjector.php`,
`Seed/MessagesGatewayConfigSeeder.php`, `docs/ANTHROPIC_COMPATIBLE_API.md`;
`tests/Unit/AI/Messages/GatewayToolLoopTest.php`.

**Touches.** `MessagesGatewayConfigSeeder.php` (`'4'`);
`migrations/Version20261028130000.php` (rows at `'2'` → `'4'`); new
`AI/Messages/GatewayEffortRules.php` (the paragraph) wired where the system
prompt is assembled; new `AI/Messages/GatewayEffortHeader.php` (parse the
request header); `MessagesApiController.php` (one parameter annotation, the
response headers — if the controller is at its size limit, a small
`GatewayResearchStart` service); a call into `SorterVerdict` + `AutoResearchGate`
for header `auto`; `docs/ANTHROPIC_COMPATIBLE_API.md`.

**Tests first.** `GatewayToolLoopTest` (the loop allows four rounds when the
row says 4; the wrap-up still fires); `GatewayEffortRulesTest` (paragraph
present once); `MessagesApiControllerTest` or a functional test (no header →
no sorter call, no research header; `auto` + a `research` rating with the
scripted sorter → `202`-style message with the notice and
`x-synaplan-research-run`; `auto` + `answer` → normal answer with
`x-synaplan-effort: answer`); migration test (rows at `'3'` untouched).

**Brief.**

> Seed `MESSAGES_GATEWAY.WEB_SEARCH_MAX_ROUNDS` as 4 and migrate rows still
> at 2. Add `GatewayEffortRules::paragraph()` to the gateway system prompt.
> Parse `x-synaplan-effort`; when it is `auto`, classify the last user
> message with `SorterVerdict` and run `AutoResearchGate` with the
> `x-synaplan-rag-group-key` folder as the knowledge source; on a start,
> answer with the notice sentence and set `x-synaplan-research-run` and
> `x-synaplan-effort`. Without the header nothing but the ceiling and the
> paragraph changes. Measure UC-03 / UC-04 and the hard corpus with
> `--arm=b1plus --web-search` before and after and put both rows in STATUS.

**Docs.** `docs/ANTHROPIC_COMPATIBLE_API.md` (header, response headers, the
ceiling), `docs/RESEARCH.md` (gateway section), `docs/CONFIGURATION.md`
(the round default).

**Exit.** Both measurement rows in STATUS (cost, recall, p50; the raised
ceiling must not lower recall on UC-03 / UC-04 and may raise cost by at most
1.5× on the hard corpus — otherwise the seed stays 2 and STATUS says why);
`make ci-local` green; Playwright not required (no UI) unless the OpenAPI
change reaches the web app's generated schemas, then `vue-tsc`.

---

## AR6 — The chat researches on its own (synaplan-desktop)

**Class:** desktop (store-required there is not a question; the Desktop
app ships its own releases). **Needs:** AR5 deployed on the stack Desktop
talks to; §0 #10. **User-flow:** J-AR-6.

**Goal.** Do: remove the chip and the depth sheet; send
`x-synaplan-effort: auto`; attach the card to the run named in the response
header; Answer now and Look deeper; keep everything RD3–RD9 built. Do not:
change `protocol: 1`, the vendored fixtures, the Out board, or any native
seam.

**Reads.** `_extras/planning/20261017-research-quality/` Desktop steps
RD3–RD9 and their STATUS; the composer, the research card, the thread
reducer, the gateway client; `02` §3.5.

**Touches.** Composer (chip and sheet removed); gateway client (request
header; read the two response headers); thread model (a message can carry a
run id; the notice renders as the assistant turn); research card (Answer
now); message actions (Look deeper, same copy); locale files (five); the
use-case set (`usecases/main.rs`: UC for J-AR-6 and J-AR-1 on Desktop).

**Tests first.** Unit tests on the header parsing and the thread reducer;
the use-case set extended with "hard question → run attached", "simple
question → no run"; snapshot of the composer without the chip.

**Brief.**

> Remove the Research chip and its depth sheet. Send `x-synaplan-effort:
> auto` on every chat turn; when the response carries
> `x-synaplan-research-run`, render the response text as the notice turn and
> attach the research card to that run id (reuse the RD3 card and its
> reattach through `GET /v1/research?active=1`). Add **Answer now** to the
> card and **Look deeper** to the actions of answers that cite sources, with
> the copy of the web app in five locales. Keep the report delivery into
> `out/` and the Out board. Walk J-AR-6 on Windows and one other OS.

**Docs.** Desktop README research section; `docs/DESKTOP.md` here (one
paragraph: the chat decides, the header).

**Exit.** Five bullets of `02` §7 for J-AR-6; `cargo run -p synaplan-core
--example usecases -- --quick` green; the Desktop STATUS and this STATUS
carry the walk.

---

## AR7 — Adaptive research measured on the corpus

**Class:** no-app-impact. **Needs:** AR3 and AR5 merged and on the dev
stack.

**Goal.** Do: re-run the router gate; run the cost curve; answer the
marriage question live and record the report's fact-check sentence; walk
J-AR-2 live. Do not: change product code (a finding becomes an issue or a
STATUS line, not a silent fix in this step).

**Reads.** `01` §4; row 21 `06_evaluation.md` and the STATUS tables (the
columns to keep).

**Touches.** This `STATUS.md`; `tests/Eval/research/router.json` only to
add items the live runs showed missing (labelled by the owner).

**Tests first.** None (measurement step).

**Brief.**

> On the dev stack with real providers: run `app:research:router-eval
> --corpus=router --repeat=3` and record the table; run the 100-message cost
> curve (the router corpus plus 40 smalltalk / code items through the chat
> API) before — `main` at the AR0 commit — and after, from `BUSELOG`, with
> the number of automatic runs started; ask the marriage question in the
> web chat, let the run finish, and record outcome, points, sources,
> fact-check sentence, cost and wall time; walk J-AR-2 end to end and enter
> it. Add nothing to the corpus without the owner's label.

**Docs.** None beyond STATUS.

**Exit.** The router gate met after AR3/AR5; the cost curve recorded with
a sentence on whether the mean cost per 100 messages stayed within 2× of
before (if not, the owner decides between a stricter prompt and a lower
ceiling, as a new STATUS line); the live marriage report recorded; the plan
ready for AX1.

---

## AX1 — The chat chooses how deep to look (synaplan-docs)

**Class:** docs. **Needs:** AR3 and AR6 shipped.

**Goal.** Do: rewrite the user-facing research pages so no page describes a
mode, a chip or a depth picker; one page "How the chat decides" with the
three stages in plain words, Answer now, Look deeper, the limit sentences,
the administrator's switch; Desktop page updated. Do not: name internal
words (the row 21 `05` §6 list).

**Reads.** `02` §2 and §4; `docs/RESEARCH.md` after AR3–AR5; the existing
research pages in `synaplan-docs` from row 21 RX steps.

**Touches.** `synaplan-docs` research pages (web, Desktop, administrators,
API — the header and `auto`).

**Tests first.** The docs repository's link and word checks.

**Brief.**

> Replace every sentence that tells a person to pick Research, Standard or
> Thorough. Write "How the chat decides" from `02` §2 (J-AR-1, J-AR-2,
> J-AR-3, J-AR-5) in the voice of the existing pages, with screenshots from
> the walks in STATUS. Update the Desktop page and the API page (`depth:
> auto`, `x-synaplan-effort`, `x-synaplan-research-run`, `answer_now`,
> `hint`). Five locales where the docs have them.

**Exit.** Docs merged; the row 21 pages no longer describe a mode; this plan
archived with a dated note at the top of its STATUS.

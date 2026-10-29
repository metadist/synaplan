# Adaptive research — status

Every number here names the command, the model and the stack it came from.
Router numbers come only from `app:research:router-eval`; quality, cost and
latency numbers from `app:research:eval` or the Desktop use-case runner. A
walk is a dated entry with what was clicked, typed, found and undone, not a
chat message.

| Step | State | Note |
| ---- | ----- | ---- |
| AR0 | done | Plan written 2026-10-28; registered as roadmap row 31. §0 unticked. No code. |
| AR1 | done | 2026-10-28. `BEFFORT` / `BEFFORT_WHY` in `tools:sort`; router corpus and `app:research:router-eval`; gate passed (entry below) |
| AR2 | done | 2026-10-28. Depth `auto` with the ceiling from the plan, `hint`, `answer_now`, `RESEARCH.AUTO_ENABLED`, `TOOL_MODE_MODELS` seeded (`Version20261028120000`) |
| AR3 | done | 2026-10-28. Gate in `MessageProcessor`, notice, `research_started`, card on the message, Answer now, Look deeper, limit sentences; Tools → Research and `ResearchComposer.vue` removed; J-AR-1 walked (entry below) |
| AR4 | done | Automatic research switch on the admin tab; J-AR-7 walked. The "no vote in 24 h" info line was not built (see entry) |
| AR5 | done | Gateway: `WEB_SEARCH_MAX_ROUNDS` 2 → 4 with migration, effort paragraph, `x-synaplan-effort` / `x-synaplan-research-run` / `x-synaplan-research-limit`; hard corpus measured before and after (gate met). UC-03 / UC-04 live in synaplan-desktop and were not run from here |
| AR6 | planned | Synaplan Desktop: chip removed, header sent, card attached, Answer now, Look deeper |
| AR7 | planned | Router gate after AR3/AR5, cost curve, the marriage question live, J-AR-2 walked |
| AX1 | planned | User docs: the chat chooses how deep to look |

---

## 2026-10-28 — owner decision and plan

**Owner, 2026-10-28:** *"In Cursor the agentic loop simply runs; there is no
option. Synaplan should not need one either. It should adapt itself: when a
question is harder, it searches longer."* Two clarifications in the same
interview:

1. **No mode anywhere.** Tools → Research in the web app and the Research
   chip in Desktop are removed. The only controls a person sees are in the
   moment: **Answer now** while a run is going, **Look deeper** under an
   answer that did not run, **Go deeper** on a report as before.
2. **All surfaces together.** Web chat, Synaplan Desktop and the gateway
   route `POST /v1/messages` are planned as one track, not web first.

What was checked before writing (dev stack `main`, 2026-10-28):

- The web chat has no effort decision today: `tools:sort` votes
  `BWEBSEARCH`, `BREADPAGES`, `BMULTI`; `TurnWebSearch` runs at most one
  search and reads at most three pages; nothing calls
  `ResearchRunService::start` except the composer and the API.
- The gateway loop allows two search rounds (`MESSAGES_GATEWAY.WEB_SEARCH_MAX_ROUNDS = 2`),
  then forces a wrap-up.
- `RESEARCH.TOOL_MODE_MODELS` is seeded `[]`, so a run uses round mode
  unless an administrator lists a model; conformance 2026-10-26 gave Sonnet
  5 and 5.5 20/20, gpt-oss-120b 12/20.
- Row 21's measured gap (STATUS 2026-10-23): on the hard corpus the engine
  and the gateway-with-search both reach recall 0.96; citation precision
  87.3 % against 64.0 %; 0.070 against 0.059 USD per answer; 35 s against
  10 s p50. The engine's gain is backed, complete answers on multi-part
  questions — the owner's example question is one.
- Competitors (web, 2026-10-28): ChatGPT's GPT-5 Auto router (and the
  manual Fast / Thinking it had to add back), Perplexity's Auto classifier
  that "errs toward Pro", Anthropic's effort-scaling rules and `effort`
  parameter, Gemini's own search decision with Deep Research as a separate
  agent, Adaptive-RAG's three-way complexity router. Summarised in
  `00` §1.3.

Decisions proposed in `00` §0 (#1–#15), to be ticked by the owner before
AR1. Supersedes row 21 §0 #5 and #15 (dated line in that STATUS). Size
ratchet and deptrac respected by putting every new rule in
`App\Service\Message\Effort\*` and new Vue components. Cost of this entry:
$0 (no model called).

Open for the owner at §0: #4 (seed tool mode for the two Sonnet keys), #9
(the gateway header name and the 2 → 4 ceiling), #11 (the gate numbers), and
row 21 #25 (runs per day by plan — becomes a real need once runs start on
their own).

---

## 2026-10-28 — AR1: router gate passed

`php bin/console app:research:router-eval --user=1` on the dev stack, user
1's sort model (Anthropic `claude-sonnet-5-5`), corpus
`backend/tests/Eval/research/router.json` (67 questions):

| Measure | Result | Gate (`01` §4.2) |
| ------- | ------ | ---------------- |
| Agreement with the labelled effort | 98.5 % | ≥ 90 % |
| Over-escalation | 0 % | ≤ 5 % |
| Under-escalation | 0 % | ≤ 10 % |
| Reason agreement | 93.9 % | reported |
| No vote | 0 % | ≤ 2 % |
| Sorter p50 / p95 | 1 744 / 2 609 ms | reported |

What the first run taught: a dev database seeded before this step still
held the old `tools:sort` prompt, so every vote was empty. `app:seed`
(run on every container start) rewrites the prompt; the number above is
the second run. Existing installs get the same rewrite on their next
start — there is nothing to do by hand. Cost of the two runs: 134 sort
calls on Sonnet 5.5, well under one dollar.

## 2026-10-28 — AR2 and AR3 built; J-AR-1 walked

The chat decides on its own. Built and gated locally (`make ci-local`
equivalents, full PHPUnit with the dev stack's 48 pre-existing
database-bound failures unchanged, full Vitest, Playwright
`research.spec.ts` 6/6):

- A question the sorter rates `research` gets one notice sentence in the
  chat language, a `research_started` event and the run's card on that
  message; the reply persists `effort` / `effortNotice` so a reload shows
  the same.
- **Answer now** is the primary action on the running card and says what
  happens right now (still reading / already writing / nothing read yet);
  Stop stays beside it. **Look deeper** sits under a `lookup` answer and
  under a reply whose research was not started, and sends the question
  with the last six turns.
- The limits that keep a research from starting are each one sentence
  (quiet hours, budget, daily cap, research off); the answer still comes.
- Tools → Research and `ResearchComposer.vue` are gone; `useResearchStore`
  lost `beginResearch`/`composerOpen` and gained `adopt`, `lookDeeper`,
  `answerNow`.

Walked in Chromium (Playwright, headless, dev stack `:5173`), not a
screenshot: Tools menu opened — no Research entry, on and off; a
multi-source question typed — notice read, card found on the message with
the ceiling line "a short look", fact-check present, reload showed the
same card; Answer now clicked — consequence sentence read, run ended;
Look deeper clicked under a lookup answer — `POST /api/v1/research`
carried `depth: auto`, `hint: deeper`; automatic research off — no Look
deeper; 320 px dark — card fits.

Size ratchet respected by moving code out, never in: `chatTurns.ts`
(`userTextBefore`, `lookDeeperInput`, `groupMessagesByDay`),
`messageEffort.ts`, `messageFileDisplay.ts` on the frontend;
`SorterEffortStub`, `AutoResearchTurn::noticeResult`,
`PlanStage::announcePlan` on the backend. `TestProvider.php` kept its
frozen length (one-line call into the stub).

## 2026-10-28 — AR4: the administrator's switch; J-AR-7 walked

`RESEARCH.AUTO_ENABLED` has a second row under Research on the admin
research tab (`ResearchAutoSwitch.vue`): Turn off / Turn on from the row,
the consequence in one sentence ("the chat answers every question
directly; Look deeper disappears; runs already going finish"), "Turn on
Research first." while research is off (the backend answers 409
`research_off` to a `PUT` that tries anyway), a note when web search is
off (folders only). Every change is audited as `research.settings.update`
with `autoEnabledBefore` / `autoEnabled`. Capabilities are forgotten on
save so the chat's Look deeper follows the switch without a reload.

Walked in Chromium (Playwright, headless, admin account): tab opened, the
row found under Research in under ten seconds; Turn off clicked —
consequence sentence read, `PUT /api/v1/admin/research` carried
`{"autoEnabled": false}`, the row said Off; Turn on clicked — On again;
Research turned off — the row disabled with "Turn on Research first.".

Not built: the "questions without a vote in the last 24 h" line of `02`
§3.4. There is no honest source for it yet — the sorter result is not
stored per message, and counting replies without `effort` would count
every unsorted path (widget, saved task, channel) as a missing vote and
inflate the rate. It needs a counter written where the vote is read; a
follow-up, not a guess on the tab.

## 2026-10-28 — AR5: the gateway's ceiling and `x-synaplan-effort`

Built: `WEB_SEARCH_MAX_ROUNDS` seeds `4`; `Version20261028130000` raises
rows still at `2` and leaves a chosen value alone; `GatewayEffortRules`
appends the effort paragraph once per turn whenever Synaplan runs the
search itself; `x-synaplan-effort: auto` rates the last user text with the
production sorter and runs `AutoResearchGate::decideFor` (the chat's gate,
refactored around `GateTurn` so both doors share the checks); a started
run answers the turn with the notice sentence as one assistant message —
JSON or the Anthropic SSE sequence — and sets `x-synaplan-research-run`;
a held-back `research` rating runs the turn as usual and names the limit
in `x-synaplan-research-limit`. Without the header the sorter is never
called. `GatewayToolLoop.php` shrank by three lines.

Measured on the dev stack with `app:research:eval --arm=b1plus
--web-search` on the hard corpus (12 items, user 1, `claude-sonnet-5-5`
on the gateway, deterministic fact recall, cost from `BUSELOG` rows in the
command window; `--gateway-url=http://127.0.0.1:80`):

| Date | Arm | Ceiling | Recall | Searches | Cost (messages) | Prompt / completion tokens | Answer ms p50 / max | Report |
| ---- | --- | ------- | ------ | -------- | --------------- | -------------------------- | ------------------- | ------ |
| 2026-10-28 | B1+ | 2, no paragraph (code before AR5) | 1.00 across 12 | 17 | 1.54 USD | 715 131 / 11 293 | 10 960 / 29 718 | `var/research-eval/ar5-before.md` |
| 2026-10-28 | B1+ | 4 + paragraph | 1.00 across 12 | 19 | 2.00 USD | 949 318 / 10 027 | 9 121 / 18 022 | `var/research-eval/ar5-after.md` |

Gate (`03` AR5 exit): recall not lowered (1.00 → 1.00); cost 1.30× on the
hard corpus, under the 1.5× ceiling. The seed stays 4. Both runs abstained
on hard-11 and hard-12 (the trap items) with full recall. Two searches
more across twelve questions: the paragraph keeps a one-fact question at
one search; the extra rounds went to the multi-part items.

Not measured from here: UC-03 / UC-04 are Desktop use cases in
`synaplan-desktop` (`cargo run -p synaplan-core`); they are the AR6 exit
on the stack that carries this change.

Walked on the gateway with an API key (curl, dev stack): "Say hello in
one word." with `x-synaplan-effort: auto` → `X-Synaplan-Effort: answer`,
the model's "Hello!"; the four-country visa comparison → `X-Synaplan-Effort:
research`, `X-Synaplan-Research-Run: rr_…`, body one assistant message
"I am looking through several sources for this — about 6 minutes. It is a
comparison."; the same question with `stream: true` → the six Anthropic
events and the **same** run id (idempotency key `gw:<user>:<question
hash>`); `GET /v1/research/{id}` showed the run `gathering`, depth `auto`,
ceiling `standard`; `POST /v1/research/{id}/cancel` → 200.

The eval needed the gateway on (`MESSAGES_GATEWAY.ENABLED`,
`ALLOW_OPERATOR_KEY`) on the dev database; both rows were set back to `0`
afterwards.

## 2026-10-29 — The research lives in the reply bubble

Owner feedback after walking J-AR-1 on the dev stack: the research card
under the thread was a second surface, and the notice sentence read as
boilerplate. The card is gone. The reply the chat gives when it starts a
run is the surface, from the first second to the finished report:

- Backend: the reply is stamped `research_run_id` (meta, exposed as
  `researchRunId` on the history row and on the stream's `complete`).
  `ResearchReportDelivery` now finds that reply
  (`MessageMetaRepository::findMessageInChatByMeta`) and writes the report
  into its text — status `complete`, file attached — instead of posting a
  second message; a run without such a reply (Look deeper, the API) still
  gets a new one, stamped the same way. The notice sentence stays as the
  text a channel without the UI reads and the text the report replaces.
- Frontend: `ResearchTurn.vue` inside the bubble. Running: spinner,
  "Researching…", the last step as a live line ("Read · Embassy notice"),
  a fold "3 steps so far · 1 sources read" that opens the full step list
  (`ResearchSteps.vue`: plan, searches with their queries, sources
  found/read/blocked, points, rounds, sections written, the fact-check),
  **Answer now** / **Stop** with their consequence in one line; the
  fallback sentence is hidden while the run is live. Done: the report is
  the bubble's text and one muted line stays below it ("Researched: 7
  steps · 1 sources read") that opens the same list — after a reload the
  list is rebuilt once from `GET /research/{id}/events?after=0`, and a run
  still going is followed again from there. The event handler keeps an
  ordered `steps[]` and ignores a replayed page by sequence.
  Look deeper appends an empty reply bound to the new run, so it works in
  its own bubble, and the reload after the run brings the persisted one.
- Removed: `ResearchHost.vue`, `ResearchCard.vue`, the teleport under the
  thread; the chat binding moved to `useResearchBinding()` in
  `MainLayout`. Locale keys for the card went, `research.turn.*` and
  `research.step.*` came, in all five locales.

Walked (Playwright, stubbed stream and run, chromium): the working reply
with the live line and Answer now; the report replacing it with the
steps line below; the fold showing the query, the source and "3/4"; the
reload showing the report and the line; Answer now ending the run in the
same bubble; Look deeper opening its own reply that ends with the report;
320 px dark. Screenshots were taken for the two states and reviewed
(light working state, dark 320 px working state).

## 2026-10-29 — The answer is what was asked; the audit lives in the fold

A real run (visa rules for remote workers in four countries: costs,
minimum income, duration, tax) produced a sound research — 5 points, 12
sources — but a bloated reply: an "Empfehlung nach Profil" section the
question never asked for (emptied by the check, heading left standing), a
"Nicht bestätigt" list of every removed sentence including table-row
fragments, the closing count and the same-provider sentence inside the
report, and `[e47]`-style evidence ids as raw text.

- Prompts: the planner's outline holds one section per thing the goal asks
  for and no recommendation, advice, outlook or "next steps" section unless
  the brief asks; a comparison of three or more items is one table section.
  The writer answers what the goal asks and nothing more, a 2–4 sentence
  summary, one table (row per item, column per asked aspect), and names a
  gap in the place the reader looks for it ("no source confirms the fee")
  as well as under Not confirmed.
- `ReaderReport`: the checked report stays as it is in the run's state (Look
  deeper still seeds its plan from Not confirmed, revise still works on it).
  The chat message gets the reader's version: no Not confirmed section, no
  closing count, no same-provider sentence, no heading the check emptied,
  and every evidence id renumbered to its source in order of first
  appearance (`[e12][e31]` → `[1][2]`).
- Sources: `ResearchReportDelivery` stores the cited sources under the
  question (`SearchResultRepository::saveForReply`) and stamps
  `web_search_results_count` on the reply, so the chat history renders the
  numbers as the existing citation badges with the Sources carousel beneath
  — no second citation renderer. A file source links to `/files?file=<id>`.
  The attached markdown file lists the sources at the end. The "Web Search"
  badge stays off on a research reply (`webSearchBadge` in `ChatMessage`).
- Honesty moved, not dropped: the `check` event carries `same_provider`,
  and the fold's fact-check row says "14 left out of the answer because no
  source confirmed them. Checked by a model of the same provider." (wraps,
  five locales). `complete_partial` now says the answer leaves the open
  points out and names Look deeper as the recovery.
- `ResearchReplyText` composes text, file and sources; the delivery's
  constructor stays at ten parameters.

Walked (Playwright, stubbed): the report with the `1` badge, the source
card with its host, the fact-check row with its detail, no "Web Search"
badge, the reload; 320 px dark. Screenshot reviewed.

## 2026-10-29 — Fine-tuning for agents & research under `/ai/models`

Instead of adding five research role dropdowns or a second card beneath
the 11-capability grid, `PlannerModelCard.vue` is now a collapsible
"Fine-Tuning for Agents & Research (Optional)" card (collapsed by
default, summary badge showing "All automatic" or "{count} custom") with
a 3-column row when research is enabled (`PLAN` task planning, `READ`
reading sources, `VERIFY` fact-checking) and the note that leading the
research and writing the answer always use the model selected in the
chat. When research is off, the two research slots and the note are
absent (U11).

- Backend: `RoutingModelsService::plannerSelection()` and
  `saveAgentModels()` expose and store per-user `DEFAULTMODEL.READ` and
  `DEFAULTMODEL.VERIFY` alongside `DEFAULTMODEL.PLAN`, plus
  `researchEnabled`. `ConfigResearchModelLookup::slot()` checks
  `ModelConfigService::getConfiguredDefaultModel()` first so a per-user
  `READ` or `VERIFY` override wins over the seeded `-1` instance row.
- OpenAPI & Zod schemas regenerated; all five locales updated.

## 2026-10-29 — The model asks for the web; the research reply shows its spend

**Web search on request.** The sorter decides before the answer whether a
turn searches (`BWEBSEARCH`); nothing caught the case where it said no and
the model then found it had no current facts. Now, on a direct-route turn
where web search is available but was not chosen, the system prompt ends
with one instruction (`FreshInfoRequest::instruction()`): when the model
lacks current information it replies with exactly one line,
`[[SEARCH_WEB: <phrase>]]`. `FreshInfoGuard` holds the head of the stream
until it is known not to be the marker, so the person never sees it.
`ChatHandler` returns the request instead of an answer; `FreshInfoRetry`
(in `MessageProcessor`, stream and buffered) searches with the model's own
phrase (`TurnWebSearch::searchRequested`, short page reads), then
dispatches the same turn again with the results and without the
instruction — one request per turn, no loop. If the search finds nothing
the model answers from what it has. The probe call's tokens are kept under
the reply's usage (`web_search_request_usage` → `ai_usage_extra`, kind
`LLM`), so the info panel is honest. Widget turns, multitask routing and
topics the sorter never searches for stay as they were.

UX: the processing timeline's web step says "The answer needs current
information — the model asked to check the web" (five locales) via the
`searching` status metadata `reason: model_requested`.

**Research reply usage.** A research reply showed only the sorting's
tokens and cost because the run's model calls were never written to the
message. `ResearchSpend` (in the run state, stored with it) sums every
call per role and model with the charged cost (`facade_charged_cost`,
falling back to the catalog price); `StageCall`, `LeadStage` and
`ResearchDirectAnswer` feed it; `ResearchReportDelivery` writes the rows to
the reply's `ai_usage_extra` next to the existing sorting row and
replaces its own rows on a second delivery. The info panel now shows
`LEAD`, `READ`, `WRITE`, `VERIFY` lines and a total that is the run's.

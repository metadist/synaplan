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

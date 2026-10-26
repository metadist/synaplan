# Research quality — status

Every result here names the command, the versions and the stack it came from.
Quality, cost and latency numbers come only from `app:research:eval` or the
desktop use-case runner (`06_evaluation.md` §6). A walk is a dated entry, not a
chat message.

| Step | State | Note |
| ---- | ----- | ---- |
| RQ0 | done | Plan, baseline research, model roles, engine, prompts, journeys, evaluation, step briefs; registered as roadmap row 21 |
| RQ1 | done | Full desktop use-case run `V42D2` on 2026-10-23 against local `main`. 5 passed, 6 failed. Recorded below. |
| RQ2 | in progress | Eval corpus, scorer, test doubles and `app:research:eval` are in the working tree. B0 starter set is recorded below. B4 was stopped on cost and is not scored; it does not decide this step |
| RS1 | in progress | Gateway wrap-up answers fully; round limit from config. Merged in #2637. Run `V42D2`: UC-04 passed. UC-03 failed (46/51). Sonnet 5.5 and gpt-oss-120b answered with a source in 3 rounds. Not done. |
| RS2 | in progress | `web_fetch` run by Synaplan on non-Anthropic `/v1/messages` routes. `OpenAiGatewayToolLoop` (`POST /v1/chat/completions`) is a follow-up, decided 2026-10-22: that endpoint never sees Anthropic’s server-tool declaration, and its constructor is frozen at 13 parameters. Run `V42D2`: UC-04 passed on Sonnet 5.5, gpt-oss-120b and mistral-large-4. UC-04b is not in the runner. Not done. |
| RS3 | in progress | `knowledge_search` and `read_document` on `POST /v1/messages` when a folder is pinned. `OpenAiGatewayToolLoop` (`POST /v1/chat/completions`) is a follow-up, same reason as RS2. Manual walk 2026-10-22 below: the simple question answered from the first-turn injection, and one explicit follow-up ran both tools. Run `V42D2`: UC-05 passed (ready in 132 ms, UC-05d hit after Ready). UC-06 facts 0/3. UC-05e is not in the runner. Not done. |
| RS4 | in progress | Document reader behind `WEB_SEARCH.READ_DOCUMENTS` (web search reads locally, no vision). Smoke reg-01, reg-02, sci-01 scored 1.00 with the flag off and on (2026-10-23), but no run opened a document: the only PDF was outside the 3-page cap. Done needs one smoke item whose top 3 results include a PDF, measured flag off and on. The flag stays off. |
| RD1 | in progress | File tools without a skill, Desktop agent prompt, language-neutral checks, output budget, B1. `make ci-local` passed. Quick set `9RDYA` is recorded below; UC-03-de failed once and passed on the rerun. J-RQ-1 is not walked yet. |
| RQ2b | done | Starter set and hard set scored 2026-10-23. Hard set (12 new-fact items): spike tool mode and B1+ with web search both reach recall 0.96; uncalibrated citation precision 87.3 % against 64.0 %, at 1.2× cost. Gate missed narrowly (precision under 92 %); owner accepted go by explicit override on 2026-10-23. B4 not run. Judges not yet calibrated by people |
| RS5 | done | `READ` and `VERIFY` slots, role resolver, `meta.family` and search hosting, [#2688](https://github.com/metadist/synaplan/pull/2688). No production binding (RQ3) |
| RS6 | in progress | Engine code merged (#2691, #2697, #2708, #2712). Engine v1 smoke and fairness recorded 2026-10-24. P-MIN walked 2026-10-24 except the files-only run, which waits for the knowledge source (RS9) |
| RS7 | done | Starter set 2026-10-26, like the spike: round mode 0.81 across 16 (spike S-R 0.63), tool mode 0.88 across 16 (spike S-T 0.81), same on both repeats. Sonnet conformance 20/20, gpt-oss-120b 12/20. Queue wait in STATUS. The RS6 clause of the exit cannot be measured (no RS6 corpus row); the owner waived it on 2026-10-26. Per-host politeness is not built. Flag stays off |
| RS8 | in progress | Free check, checker model, and one thorough revision. A partial statement keeps the supported part; a revision that adds a fact is rejected and the removal stands. A contradicted statement never stays cited. Seeded draft still 6/6 and 0/6. Citation precision was not measured. Flag stays off |
| RS9 | planned | Keyword + meaning search, document cards — §0 #14 |
| RS10 | planned | Follow-ups and revisions |
| RQ3 | planned | Bake-off, seeded presets, flag decision |
| RD2 | planned | `ChatView.vue` split (synaplan-desktop) |
| RD3 | planned | Research mode, progress, result card; J-RQ-2, J-RQ-6 |
| RD4 | planned | Questions and plan review; J-RQ-4 |
| RD5 | planned | Report, sources, versions, follow-ups; J-RQ-2, J-RQ-9 |
| RD6 | planned | Turn into Word, slides, table, email; J-RQ-5 |
| RD7 | planned | Add a folder; J-RQ-3 |
| RD8 | planned | Research team in Models; J-RQ-7 |
| RD9 | planned | Window closed, notification; J-RQ-4 |
| RW1 | planned | Research in the web chat; J-RQ-8 |
| RW2 | planned | Admin research settings; J-RQ-10 |
| RW3 | planned | Research as a Saved Task step |
| RX1 | planned | User docs: quick wins (synaplan-docs) |
| RX2 | planned | User docs: Desktop research, team, air-gapped (synaplan-docs) |
| RX3 | planned | User docs: web research, administration (synaplan-docs) |

---

## 2026-10-26 — RS8 thorough revision

No live run. When the checker returns a `partial` verdict, a thorough run
makes one writer call (`tools:research_revise`). Without a partial there is
nothing a revision could keep, so no call is made. A `partial` statement
keeps the part the source backs, counts as softened, and the closing count
names it. Every cited sentence of the revision must be a supported
statement verbatim or a shortened partial that keeps its id; a reworded
`unsupported` or `contradicted` statement that stays cited is rejected, and
the Not confirmed section may not gain a citation (`[e1, e2]` markers
included). A revision that adds a fact the draft did not have is rejected,
and the standard removal stands, so the run still delivers. Standard and
quick do not revise. A thorough run with no model call left removes
instead. `softened` stays 0 on that path.

Command:

```bash
php bin/phpunit tests/Unit/Service/Research/Run/CheckStageTest.php
```

Citation precision was not measured. The RS7 reports were not saved with
the passages they cite, so there is no before row to judge, and this
change does not turn research on. This is not the RS8 exit.

---

## 2026-10-26 — RS8 checker model, standard removal

No live run. After the report, `CheckStage` runs the free check and then
asks the checker model, in batches that fit its input budget, for a
verdict on every cited statement that passed. `supported` stays.
`partial`, `unsupported`, `contradicted` and a missing verdict are
removed and listed under the writer's "Not confirmed" section, which is
itself not checked again. A free-check failure is removed the same way and
is not sent to the model. Only verdicts for the batch's own statements
count; an unusable row counts as unsupported instead of failing the run.
A batch holds at most 30 statements so the verdicts fit the output cap;
statements left unchecked when the budget runs out count as not confirmed. The report ends with how many
statements a source backs, and names the provider when the checker and
the writer share a family. The run summary uses those counts. Every
depth, including thorough, uses this removal; a revision that keeps the
supported part is not built. `softened` in the check event is 0.

Command:

```bash
php bin/phpunit tests/Unit/Service/Research/Run/CheckStageTest.php
```

Citation precision was not measured. This is not the RS8 exit.

---

## 2026-10-26 — RS8 free check, seeded draft

No model and no live run. `DraftCheck` compares a draft with the text of
the evidence it cites: an unknown id, a number, a date or a proper name
the evidence does not contain, and a factual sentence with no citation.
Dates compare equal as ISO (`12.03.2024`, `12 March 2024`, `12. März 2024`,
`12 mars 2024`, `12 marzo 2024`, `12 Mart 2024`). A dotted date is
day.month.year. `2024/1689` is not a date. A proper name is two
capitalised words, or a word with an internal capital; a single
capitalised word is not one, because German writes every noun that way,
and a word such as "Im" or "Today" that opens the run is not part of the
name. List markers, table headers and the dot in `12. März` do not split
or create a statement. A revision that adds a new problem (an uncited
fact, or a cited one its evidence does not back) is rejected.

The seeded draft is the report in `DraftCheckTest`, six planted errors
and six supported statements. Command:

```bash
php bin/phpunit tests/Unit/Service/Research/Run/DraftCheckTest.php
```

Detection **6/6**. False alarms **0/6**. This is not the RS8 exit: the
checker model is not built, a run does not apply the check, and citation
precision was not measured.

---

## 2026-10-26 — RS7 starter set in both modes

The same 20 questions the spike was scored on, so the comparison with S-R
and S-T is like for like. Local m4 stack, `main` at `972ee840e` (with the
read-first rule of #2746). Research was pinned on with `RESEARCH_ENABLED=1`
for the window and removed afterwards; the database row stays `0`,
`LEAD_MODE` is back to `auto` and `TOOL_MODE_MODELS` is `[]`. User 1,
`CHAT` and `SORT` `BMODELS` 379 (Claude Sonnet 5.5); the `SORT` pin was
removed afterwards. Web search Brave, no knowledge source (RS9). Corpus
sha256 `56c371a25e01520f6d601da376b3df4a11ff6d3c245f8a8eb532a99624c844fa`,
all 20 items, standard depth, repeat 2, **two runs at a time** (three
pushed queue time into the 360 s wall on 2026-10-25). Round mode is
`LEAD_MODE=rounds`, tool mode `LEAD_MODE=tools`. Each run is
`POST /api/v1/research`; the reports are scored by:

```bash
app:research:eval --corpus=full --arm=b1 --import=<reports>
```

"Across 16" leaves out the three document-only items and trap-01 (no match
string), as the spike rows do. The scorer's own mean is across 19 and
counts doc-01, doc-02 and doc-03 as 0.00.

| Mode | Repeat 1 (16) | Repeat 2 (16) | Scorer mean (19) | Spike row | Traps |
| ---- | ------------- | ------------- | ---------------- | --------- | ----- |
| Rounds | 0.81 | 0.81 | 0.68, 0.68 | S-R 0.63 | both abstained twice; trap-02 recall 1.00 |
| Tools | 0.88 | 0.88 | 0.74, 0.74 | S-T 0.81 (judged 0.78) | both abstained twice; trap-02 recall 1.00 |

Round mode misses fact-01, mix-01 and mix-02 on both repeats; tool mode
misses mix-01 and mix-02. The mix items need the knowledge source as well.
Tool mode ended `failed_nothing_found` only on the six document-only runs
and on trap-01 once. Round mode ended that way on doc-02, doc-03, fact-01
and trap-01 once each.

| Mode | Cost, 40 runs | Median per run | Wall p50 / p95 | Queue wait ≥ 20 s: steps, p50 / p95 |
| ---- | ------------- | -------------- | -------------- | ----------------------------------- |
| Rounds | 7.030820 USD | 0.159838 USD | 163.1 / 245.2 s | 34, 54.5 / 114.9 s |
| Tools | 4.970934 USD | 0.109488 USD | 100.7 / 153.6 s | 18, 39.5 / 61.4 s |

Percentiles are linear (rank `(n-1)*p`). Wall time is the driver's clock
and includes the queue. The queue sample counts only steps whose
`queued_since` reached 20 s while two runs shared one local worker; it is
not a production queue. No run ended `stopped_limit`.

Against the exit of 07 §RS7: both modes are above both spike rows, tool
mode by 7 points and round mode by 18, the same on both repeats. Citation
precision was not judged. Conformance and queue wait are recorded. The
clause "above the RS6 row" cannot be measured: RS6 has no corpus row and
the engine without reflect no longer exists. The owner waived that clause
on 2026-10-26, and RS7 is done. Per-host politeness, named in the RS7
goal, was not built.

---

## 2026-10-25 — RS7 tool mode reads before it finishes

Cause of the tool-mode row below: in all 14 runs that ended
`failed_nothing_found`, the lead searched in its first turn and called
`finish` in its second, with no `read_sources` ("the snippets already
settle it"). All 6 complete runs had searched, read and then finished. The
report cites only evidence cards, so a finish before any read leaves it
nothing. Read from the run state in Redis (`conversation.turns`).

The fix: a `finish` while nothing was read, a found source is unread and
another turn plus a read still fit the budget, is refused once with
`read_first`. The other calls of that turn run. After any read, even one
that found nothing, `finish` is accepted. The lead prompt now says that
search results are not evidence.

Same stack, user, model (`BMODELS` 379), Brave, corpus sha256, width 3 and
driver as the entry below, `LEAD_MODE=tools`, scored with
`app:research:eval --corpus=smoke --arm=b1 --import=<reports>`:

| Run | fact recall (9) | web only (7) | trap-01 |
| --- | --------------- | ------------ | ------- |
| Repeat 1 | 0.78 | 1.00 | abstained |
| Repeat 2 | 0.78 | 1.00 | abstained |

No run ended `failed_nothing_found` except trap-01 on repeat 2. doc-01 and
mix-01 stay 0.00 (no knowledge source, RS9). The 20 runs spent
**2.762702 USD** (median 0.116368, against 0.040058 before). Wall time p50
**174.6 s**, p95 **292.7 s**. Queue sample: 29 steps at or above 20 s,
p50 **38.0 s**, p95 **130.2 s** (linear rank).

Tool mode now reaches 0.78 against round mode's 0.67 on this set, and is
3 points under the spike's S-T 0.81, which is a different corpus (starter
20, 16 scored). The RS6 corpus row was still not run. RS7 stays in progress
until the owner decides on the exit. Research was pinned on for the window
and removed afterwards; `LEAD_MODE` is `auto` and `TOOL_MODE_MODELS` is `[]`.

---

## 2026-10-25 — RS7 smoke, conformance and queue wait

Local m4 stack, branch `feat/research-rs7-wait`
([#2741](https://github.com/metadist/synaplan/pull/2741)), measured after
`12421b548` with the year fix below already in the worker. Research was
pinned on with `RESEARCH_ENABLED=1` for the window and removed afterwards.
The database row stays `0`. After the window, `LEAD_MODE` is `auto`,
`TOOL_MODE_MODELS` is `[]`, and a start returns `404 feature_not_configured`.
User 1, `CHAT` and `SORT` both `BMODELS` 379 (Claude Sonnet 5.5,
`anthropic:claude-sonnet-5-5`). The `SORT` pin was removed afterwards.
Web search was the keyed Brave provider. `knowledge_available` is false
(RS9), `read_documents` is false. Corpus
`backend/tests/Eval/research/corpus.json`, sha256
`56c371a25e01520f6d601da376b3df4a11ff6d3c245f8a8eb532a99624c844fa`,
`--corpus=smoke` (10 items, all standard depth). No question was opened
and no plan waited.

There is no engine arm in `app:research:eval`. Each run is
`POST /api/v1/research` (depth `standard`, three at a time, repeat 2).
The text is scored by:

```bash
app:research:eval --corpus=smoke --arm=b1 --import=<reports>
```

The `ms` column of that command is 0. Times below are the driver's wall
clock. A percentile is linear: rank `(n-1)*p`.

Round mode is `LEAD_MODE=auto` with an empty tool list. Repeat 1 scored
**0.67 across 9**. Repeat 2 under the three-wide queue scored **0.33**:
reg-01, reg-02 and tech-01 ended `stopped_limit` (the 360 s wall includes
queue time). Those three, rerun one at a time, all finished `complete`
with recall 1.00 (extra 1.004018 USD). Repeat 2 with those three texts
replaced scored **0.67 across 9**. fact-01 is 0.00 on both repeats.
doc-01 and mix-01 are 0.00 on both (no knowledge source). trap-01
abstained on both. Dropping doc-01 and mix-01, the web-only mean is
**0.86 across 7**. The 20 loaded runs spent **4.149274 USD** (median
0.200263). Wall time p50 **251.0 s**, p95 **380.0 s** (the first six times
are `updated_at - created_at`, because that driver hung before it wrote
them). Queue sample is only steps whose `queued_since` reached 20 s: 24
steps, p50 **65.0 s**, p95 **234.5 s**. The first six runs were not polled.

Tool mode is `LEAD_MODE=tools`, worker restarted before the pass, same
user, model, corpus and width. Repeat 1 scored **0.22 across 9**, repeat 2
**0.33 across 9**. The per-item mean is **0.28**. Hits are reg-02 and
tech-01 on both repeats, and sci-01 on repeat 2. trap-01 abstained on
both. The 20 runs spent **1.416622 USD** (median 0.040058). Wall time p50
**90.3 s**, p95 **162.2 s**. Queue sample: 13 steps, p50 **39.0 s**, p95
**70.8 s**.

Both loaded passes together: 37 steps at or above 20 s, p50 **54.0 s**,
p95 **176.0 s**. One local worker, three runs at a time. A step picked up
before 20 s is not in the sample. This is not a production queue.

Conformance, same day, `app:research:conformance <key> --user=1`:

| Key | Result |
| --- | ------ |
| `anthropic:claude-sonnet-5-5:chat` | 20/20 |
| `anthropic:claude-sonnet-5:chat` | 20/20 |
| `groq:openai/gpt-oss-120b:chat` | 12/20 |

None of them was added to `TOOL_MODE_MODELS`.

RS6 has no corpus recall (the 2026-10-24 row is one Eiffel Tower
question), so "above the RS6 row" was not run. The spike on 2026-10-23 is
the starter set of 20 (16 scored), not this smoke set: S-R 0.63, S-T 0.81.
Round mode at 0.67 sits above S-R and 14 points below S-T. Tool mode at
0.28 sits below both, past the 3-point smoke noise band. The exit bar is
not met. RS7 stays in progress. Citation precision was not judged.

The first fact-01 pilot (`rr_5e918f332bb61e77`) stuck in gathering:
`str_contains()` rejected the year `2024`, which PHP stores as an int
array key. That run was cancelled and is not in the scores. `PassageFocus`
now casts terms to strings, and the scored runs include that fix.

---

## 2026-10-24 — RS6 engine v1 smoke and fairness

Local dev stack. Research was pinned on with
`RESEARCH_ENABLED=1` in `backend/.env` for the measurement and removed
afterwards. The database row stays `0`. `CHAT` and `SORT` were set to
`BMODELS` 379 (Claude Sonnet 5.5, `anthropic:claude-sonnet-5-5`) and put
back to `-1` afterwards. Web search was the keyed Brave provider.
`GET /api/v1/research/capabilities` then reported `enabled: true`,
`web_search_available: true`, `knowledge_available: false`,
`read_documents: false`.

A standard run before the sorter fix (`rr_5ddfe637190fb426`) searched and
finished `failed_nothing_found`. The `tools:research_triage` prompt asked
for "one entry per result id" without naming the `results` list or the
`id` field, so the sorter returned one verdict per hit id, the code only
read a `results` list, and nothing was opened. The prompt now spells out
the schema shape, and gather also reads a verdict map keyed by hit id.
With that shape accepted, standard run `rr_124552ec9cecc1a8` in chat 389
finished `complete` in about 72 seconds: 26 statements, 26 backed by a
source, spent 0.135306 USD (estimate 1 USD). The report is the assistant
message in that chat. Question: "In which year was the Eiffel Tower
completed, and who designed it?"

Fairness, same stack. A `.md` upload is vectorized inside the request
(`Ready` is status `vectorized`). Idle file 53: 67 ms. During the standard
run, file 54: 23 ms. During thorough run `rr_f0307ef95d034790` (then
cancelled, `stopped_by_user`), file 56: 69 ms. All three came back
vectorized.

P-MIN (`09` §4) was walked later the same day, see the next entry.

## 2026-10-24 — triage prompt re-smoke and P-MIN walk

Same local stack, flag pin and `CHAT`/`SORT` = 379 as above, all put back
afterwards (`RESEARCH_ENABLED` removed, `CHAT`/`SORT` = `-1`, backend and
worker recreated). The corrected `tools:research_triage` prompt was seeded
with `app:seed`.

Triage with the corrected prompt: a temporary log in gather (removed
afterwards) showed the sorter reply as `{"results": [...]}`, a list with
16 rows for 16 hits, so the map fallback was not needed. Standard run
`rr_f299b0890ee318cb` (chat 391, same Eiffel Tower question) finished
`complete` in 64 seconds: 8 sources, 19 evidence cards, spent 0.125782 USD.

Quick run `rr_28f8aaeec5ac496b` ("What is the boiling point of water at
the summit of Mount Everest?") ended `failed_nothing_found` after reading
two pages. Both pages start with navigation (menus, product lists); the
reader gets the first 12 000 characters, the answer on the Everest page
starts after character 8 000, and the reader rightly answered
`useful: false`. Fixed below.

Read stage fix, same day. A source longer than the passage budget now
sends the passages that share words with the goal, the points and their
queries (short menu items need two shared words), each with the passage
before and after it, in page order; ids stay those of the full text, so
the passage check is unchanged. Gather also keeps twice as many picks as
the read budget, because blocked and empty pages do not use it. Before,
quick run `rr_38b67d48ae217908` ended `failed_nothing_found` with all four
picks blocked (bot block, 404, no text).

| Run | Depth | Outcome | Cards | Sources read / blocked | Spent USD |
| --- | ----- | ------- | ----- | ---------------------- | --------- |
| `rr_a972d5bf40b3f852` | quick | `complete` | 10 | 2 / 2 | 0.066772 |
| `rr_fbea2902da45484d` | quick | `complete_partial` (1 of 4 points) | 5 | 2 / 1 | 0.046024 |
| `rr_6e814c0797a336e9` | quick | `complete_partial` (1 of 4 points) | 7 | 2 / 2 | 0.050036 |
| `rr_b3b2767cd2dfa527` | standard, Eiffel Tower | `complete` | 39 | 6 / 4 | 0.170230 |

The Everest page (766 passages) now sends 38 passages and gives 3–7
cards per run, quoting "at the top of Everest the boiling temperature of
the water is 70°C" from §736. Before the fix the quick question failed in
three of four runs; after it, none of three failed.

P-MIN. `docker-compose.minimal.yml` overlays `app_test` on the test stack
with the `TestProvider` only, so it cannot run a real model. The walk used
a temporary overlay outside the repository that puts the same blank
variables on the dev `backend` and `worker`, plus an empty Brave key
(P-MIN has no web search). The Anthropic key stayed.

| Step | Result |
| ---- | ------ |
| Minimal as shipped (`RESEARCH_ENABLED` empty, gate on) | capabilities and start answer 404 `feature_not_configured`; no research surface |
| Research pinned on, before the fix | capabilities said `web_search_available: true` (`brave` is the default provider name, no key). Run `rr_103b1a0f7d0a482f` paid for brief and plan (0.010098 USD), sent 4 searches to no provider and ended "The sources that were searched held nothing…" — wrong, nothing could be searched |
| Research pinned on, after the fix | capabilities `web_search_available: false`, `web_search_provider: null`, `knowledge_available: false`. Run `rr_070d4819880ca7c6` ended in 5 seconds with `failed_no_sources`, "There was nothing to research: turn on the web or add files to this project.", 0 USD, posted into chat 396 |
| Web PDF without Tika | `ResearchSourceReader` on `w3.org/…/dummy.pdf`: blocked, reason `unreadable`. Same URL with Tika on the full stack: read, "Dummy PDF file" |
| Files-only run with a cloud embedding | Not possible in engine v1: gather skips `files` queries and capabilities reports `knowledge_available: false` by design. Comes with the knowledge source (RS9) |

The fix: `ResearchSearch::available()` answers from the web search
gateway's health. Capabilities use it, and the pipeline ends a run with
`failed_no_sources` before the brief when nothing can be searched.

## 2026-10-23 — run storage and run kernel shared with agent handling (row 25)

The owner approved, in the agent-handling decision session
([`../20261023-agent-handling/00_master_plan.md`](../20261023-agent-handling/00_master_plan.md)
§0 #5 and #9), one table **`BRUNS`** for assistant runs and research runs
(column `BKIND` = `assistant` · `research`). It replaces the `BRESEARCHRUNS`
alternative of §0 #7 here; §0 #7's default (Redis only in v1) is not changed
by this entry — whether RS6 writes `BRUNS` rows is decided when RS6 starts.
The run machinery (units, claims, checkpoints, event log, reaper, delivery)
is shared as `App\Service\Run\*`: whichever of RS6 and AH8 is built first
creates it, and the other adopts it. Nothing in RS1–RS4, RD1 or RQ2b changes.

---

## 2026-10-23 — RQ2b spike against B1+

Live runs on the evaluation account (admin user id 1). Chat model is that
account's default, Claude Sonnet 5.5 (`BMODELS` 379, catalog price 2 / 10 USD
per 1M prompt / completion tokens). Web search is Brave. Corpus sha256
`56c371a25e01520f6d601da376b3df4a11ff6d3c245f8a8eb532a99624c844fa`.
Working tree `feat/research-spike` at `8f4e069beb74`, spike code uncommitted.
Stack: local `docker compose` on the m4 context.

The spike does not open a knowledge folder. `doc-01`, `doc-02` and `doc-03`
are errors and stay out of the mean. `mix-01` and `mix-02` run on the web
only. B1+ was given folder `rq2-eval`. Citation precision and coverage were
not judged (`--judges` was not set). Passage check dropped 0 findings on every
arm below; a kept finding had a real passage id. Reader calls in one batch
run one after another. B4 was not run: there is no `--b4-endpoint`.

`sci-01` and `mix-02` failed in the first tool-mode pass because an empty tool
argument was sent back as a JSON array. They were run again after that fix.
The 20/30 sweep is entirely after the fix. Reports:
`backend/var/research-eval/rq2b-starter.log`,
`rq2b-sci-01.md`, `rq2b-mix-02.md`, `rq2b-b1plus.log`, `rq2b-sweep.log`.

```bash
docker compose exec -T backend php bin/console app:research:spike \
  --corpus=full --mode=both --user=1 --repeat=2 \
  --report=var/research-eval/rq2b-starter.md

docker compose exec -T backend php bin/console app:research:spike \
  --corpus=full --mode=tools --user=1 --repeat=2 --only=sci-01 \
  --report=var/research-eval/rq2b-sci-01.md

docker compose exec -T backend php bin/console app:research:spike \
  --corpus=full --mode=tools --user=1 --repeat=2 --only=mix-02 \
  --report=var/research-eval/rq2b-mix-02.md

docker compose exec -T backend php bin/console app:research:eval \
  --corpus=full --arm=b1plus --user=1 --repeat=2 \
  --gateway-url=http://127.0.0.1:80 --folder=rq2-eval \
  --report=var/research-eval/rq2b-b1plus.md

docker compose exec -T backend php bin/console app:research:spike \
  --corpus=full --mode=both --user=1 --repeat=1 --searches=20 --sources=30 \
  --report=var/research-eval/rq2b-sweep.md
```

| Date | Step | Arm | Corpus | Depth | Recall | Citation precision | Coverage | Abstention | Cost | Time p50 / p95 | Command |
| ---- | ---- | --- | ------ | ----- | ------ | ------------------ | -------- | ---------- | ---- | -------------- | ------- |
| 2026-10-23 | RQ2b | S-R | starter 20 | 10 searches, 15 sources, repeat 2 | 0.63 across 16 | not judged | not judged | both traps abstained; trap-02 recall 0.00 | 0.44 USD sum of per-item mean token costs. No usage row | 17168 / 28316 ms | `app:research:spike --corpus=full --mode=rounds --repeat=2` |
| 2026-10-23 | RQ2b | S-T | starter 20 | 10 searches, 15 sources, repeat 2 | 0.81 across 16 | not judged | not judged | both traps abstained; trap-02 recall 1.00 | p50 0.045 USD, sum 0.75 USD, from mean tokens. No usage row | 26613 / 37785 ms | `app:research:spike --corpus=full --mode=tools --repeat=2`, then `--only=sci-01` and `--only=mix-02` |
| 2026-10-23 | RQ2b | S-R | starter 20 | 20 searches, 30 sources, repeat 1 | 0.56 across 16 | not judged | not judged | both traps abstained; trap-02 recall 1.00 | p50 0.014 USD, sum 0.39 USD, from tokens. No usage row | 16936 / 42395 ms | `app:research:spike --corpus=full --mode=rounds --repeat=1 --searches=20 --sources=30` |
| 2026-10-23 | RQ2b | S-T | starter 20 | 20 searches, 30 sources, repeat 1 | 0.81 across 16 | not judged | not judged | both traps abstained; trap-02 recall 1.00 | p50 0.059 USD, sum 1.10 USD, from tokens. No usage row | 28263 / 58355 ms | `app:research:spike --corpus=full --mode=tools --repeat=1 --searches=20 --sources=30` |
| 2026-10-23 | RQ2b | B1+ | starter 20 | gateway, repeat 2 | 0.92 across 19 | not judged | not judged | both traps abstained; trap-02 recall 1.00 | 0.38 USD across 120 usage rows in the command window (115840 prompt, 14940 completion). Not a per-item p50 | 3195 / 5426 ms | `app:research:eval --corpus=full --arm=b1plus --repeat=2 --folder=rq2-eval` |
| 2026-10-23 | RQ2b | B4 | starter 20 | — | not run | not judged | not judged | not run | not run | not run | no `--b4-endpoint` |

Spike token cost uses the catalog price of model 379 on the mean prompt and
completion tokens of each item. It does not include Brave. The spike wrote no
`BUSELOG` row. B1+ cost is the sum of `BUSELOG.BCOST` from 12:48:50 to
12:51:20 UTC on 2026-10-23.

On the 14 web items that are not mixed, tool mode and B1+ are both 0.93.
Raising the budget from 10/15 to 20/30 did not raise tool-mode recall. Tool
mode at 10/15 used on average 1.5 searches and 2.5 sources. At 20/30 it used
1.8 searches and 4.6 sources.

**Go / no-go (§0 #40), for the owner to accept.** No-go. The better spike arm
is tool mode at 0.81. B1+ is 0.92. That is not 10 points higher, and 0.92 plus
10 points is above 1, so no rerun on this corpus can clear the fact-recall
clause. Citation precision was not judged. The recorded spike cost is above
twice the B1+ window. Phase C is re-planned around the gateway loop before any
RS6 code. RQ2b stays in progress until this sentence is accepted.

The S-T rows above ran with tool declarations that had no argument schema.
The spike now declares `query`, `ids`, `source`, `question` and `reason`, and
the root cause of the empty-argument rejection is fixed in
`AnthropicProvider` (an empty tool input is sent as `{}`). Tool-mode recall
was not re-measured after that change. It cannot change the fact-recall
clause, because B1+ plus 10 points is above 1.

## 2026-10-23 — RQ2b citation precision

Branch `feat/research-citation-judge` from `a9f75713b`, code uncommitted. Same
account, model, corpus hash and stack as above. Both arms ran again with
`--answers`, so each answer is saved with the passages it cites. The spike
cites evidence ids; each id keeps the passage text the reader cited. A URL
citation would be read again and matched to the statement's words and
numbers. B1+ gave none.

```bash
docker compose exec -T backend php bin/console app:research:spike \
  --corpus=full --mode=tools --user=1 --repeat=2 \
  --report=var/research-eval/rq2b-cite-tools.md \
  --answers=var/research-eval/rq2b-cite-tools.json

docker compose exec -T backend php bin/console app:research:eval \
  --corpus=full --arm=b1plus --user=1 --repeat=2 \
  --gateway-url=http://127.0.0.1:80 --folder=rq2-eval \
  --report=var/research-eval/rq2b-cite-b1plus.md \
  --answers=var/research-eval/rq2b-cite-b1plus.json

docker compose exec -T backend php bin/console app:research:citations \
  --answers=var/research-eval/rq2b-cite-tools.json,var/research-eval/rq2b-cite-b1plus.json \
  --judges=openai:gpt-6.1-sol,xai:grok-4.7,google:gemini-3.1-pro-preview \
  --user=1 --out=var/research-eval/rq2b-citations-v2
```

Judges are GPT-6.1 Sol and Grok 4.7; Gemini 3.1 Pro decides a split. None is
the Claude writer. Pairs from both arms are shuffled together (seed 1), and
the prompt is `judges/citation.md` with no arm name. **No human calibration
exists (`06` §4), so these numbers are a signal and gate nothing.** The 40
blind pairs for people to label are in
`backend/var/research-eval/rq2b-citations-v2/sample.json`.

| Date | Step | Arm | Corpus | Depth | Recall | Citation precision | Coverage | Abstention | Cost | Time p50 / p95 | Command |
| ---- | ---- | --- | ------ | ----- | ------ | ------------------ | -------- | ---------- | ---- | -------------- | ------- |
| 2026-10-23 | RQ2b | S-T | starter 20 | 10 searches, 15 sources, repeat 2 | 0.78 across 16 | 93.8 % (182 of 194; 2 uncertain), judges agree on 93.4 % | not judged | both traps abstained; trap-02 recall 1.00 | p50 0.046 USD, sum 0.90 USD, from mean tokens. No usage row | 25243 / 46384 ms | `app:research:spike --corpus=full --mode=tools --repeat=2 --answers=…` |
| 2026-10-23 | RQ2b | B1+ | starter 20 | gateway, repeat 2 | 0.89 across 19 | none: 0 cited statements in 40 answers | not judged | both traps abstained; trap-02 recall 1.00 | 0.39 USD across 60 usage rows (40 messages, 20 sorting), about 0.01 USD per answer | 3335 / 5169 ms | `app:research:eval --corpus=full --arm=b1plus --repeat=2 --folder=rq2-eval --answers=…` |
| 2026-10-23 | RQ2b | B1+ web | starter 20 | gateway with `web_search` declared, repeat 2 | 0.95 across 19 | not measurable: 5 cited statements, all on `trap-01`, every cited page unreadable | not judged | both traps abstained; trap-02 recall 1.00 | 0.36 USD across 50 usage rows (40 messages, 10 sorting), about 0.009 USD per answer | 2823 / 4737 ms | `app:research:eval --corpus=full --arm=b1plus --repeat=2 --folder=rq2-eval --web-search --answers=…` |

The spike wrote 196 cited statements in 26 answers. I read the 12 the
judges rejected, which is one reader and not a calibration:
- 8 are real or partial misses: the sentence cites the wrong evidence id
  (p3, p177), or the passage lacks a detail the sentence adds (p48, p54,
  p83, p104, p168, p179).
- 2 are meta sentences (p4, p129).
- 1 is a judge error: p119's passage says "Default: 3306".
- 1 is strict: p17's passage lacks only the date.
A first pass on the same answers had a sentence-split bug at German
abbreviations ("Art. 17"). It scored 95.3 %. That shows how much the judge
result moves between passes.

B1+ did not search the web. None of its 40 answers has a URL. 18 say the
answer comes from the model's own knowledge. Its `[1][2][3]` markers point to
knowledge-base passages that are not about the question, and there is no
sources list to resolve them. The usage log for the run window has
`knowledge_search` rows and no web search. B1+'s recall and its low cost are
those of an answer from memory, not of research.

B1+ web is B1+ with web search available. With `--web-search` the adapter
declares Anthropic's `web_search` tool, as a desktop client does. The gateway
(`WEB_SEARCH_MODE` `auto`) answers it with Synaplan's own search. The model
decides whether to search. In 40 answers it searched twice, both on
`trap-01` (two `tool:web_search` usage rows). Two answers contain a URL.
18 still say the answer comes from the model's own knowledge. The 5 cited
statements point to EUR-Lex and syna.de PDF pages. The reader could not
open any of them (EUR-Lex blocks the fetch; PDFs are not read while
`WEB_SEARCH.READ_DOCUMENTS` is off), so there is nothing to judge. Run from
16:33:56 to 16:36:11 UTC; report `rq2b-b1plus-web.md`.

Judge cost: the judges wrote no usage row. The total tokens were 41708 for
GPT-6.1 Sol, 361444 for Grok 4.7 and 2324 for Gemini. Priced at the
completion rate, that is at most about 2.6 USD for this pass.

**Go / no-go (§0 #40), updated, for the owner to accept.** Still no-go. The
fact-recall clause fails as above (0.78 against 0.89). Tool mode reaches
93.8 % citation precision, and B1+ cites no source at all, so the precision
part would pass. But the judges are not calibrated, and that clause also
needs the cost to be at most 2× B1+. The spike's p50 is 0.046 USD per answer
against about 0.01 USD for B1+, which is 4.7×. With web search available
(B1+ web) nothing changes. The model almost never searches, recall rises to
0.95, cost stays about 0.009 USD per answer, and it cites no readable
source. On this corpus the gateway answers from memory. It does not research.
The corpus asks facts the model already knows, so it cannot show the value
of research. That is a corpus question for the owner, not a spike result.

## 2026-10-23 — RQ2b on a harder corpus

Same branch, account, writer model and stack. New corpus
`backend/tests/Eval/research/corpus-hard.json`, 12 items, sha256
`fd3325500cb07d9e6166d4371c0916776dae6a533da75685e50f3f5131f88d62` at run
time. Every item needs a fact from after the writer's training data: the
2026 Nobel prizes, the Deutscher Buchpreis and Premio Planeta 2026, the
newest PHP 8.5 and 8.6 releases, the ECB rates since September 2026, one
multi-hop item (Kagan against Carson), and two traps (an outdated ECB rate,
a false Murakami premise). Five languages. **The gold was written and
checked by the agent against nobelprize.org, kva.se, deutscher-buchpreis.de,
premioplaneta.es, php.net and ecb.europa.eu, not by a person.** One gold
row was wrong: hard-10 said "20 years older"; the dates give 19.5 years.
It now matches "19", and every arm below was rescored from its saved
answers with the fixed gold.

```bash
docker compose exec -T backend php bin/console app:research:spike \
  --corpus=tests/Eval/research/corpus-hard.json --mode=tools --user=1 --repeat=2 \
  --report=var/research-eval/hard-tools.md --answers=var/research-eval/hard-tools.json

docker compose exec -T backend php bin/console app:research:eval \
  --corpus=tests/Eval/research/corpus-hard.json --arm=b1plus --user=1 --repeat=2 \
  --gateway-url=http://127.0.0.1:80 --folder=rq2-eval [--web-search] \
  --report=var/research-eval/hard-b1plus[-web].md \
  --answers=var/research-eval/hard-b1plus[-web].json

docker compose exec -T backend php bin/console app:research:citations \
  --answers=var/research-eval/hard-tools.json,var/research-eval/hard-b1plus.json,var/research-eval/hard-b1plus-web.json \
  --judges=openai:gpt-6.1-sol,xai:grok-4.7,google:gemini-3.1-pro-preview \
  --user=1 --out=var/research-eval/citations-hard
```

| Date | Step | Arm | Corpus | Depth | Recall | Citation precision | Coverage | Abstention | Cost | Time p50 / max | Command |
| ---- | ---- | --- | ------ | ----- | ------ | ------------------ | -------- | ---------- | ---- | -------------- | ------- |
| 2026-10-23 | RQ2b | S-T | hard 12 | 10 searches, 15 sources, repeat 2 | 0.96 across 12 | 87.3 % (226 of 259; 8 uncertain), judges agree on 85.4 % | not judged | both traps abstained | 1.67 USD for 24 answers, 0.070 USD mean, from tokens. No usage row | 34539 / 53047 ms | as above |
| 2026-10-23 | RQ2b | B1+ | hard 12 | gateway, repeat 2 | 0.03 across 12 | not measurable: 3 cited statements | not judged | both traps abstained | 0.50 USD across 59 usage rows, about 0.021 USD per answer | 7470 / 9068 ms | as above |
| 2026-10-23 | RQ2b | B1+ web | hard 12 | gateway with `web_search` declared, repeat 2 | 0.96 across 12 | 64.0 % (32 of 50; 4 unreadable) | not judged | both traps abstained | 1.42 USD across 72 usage rows (35 `tool:web_search`), about 0.059 USD per answer | 10138 / 21955 ms | as above |

Time is over the 12 per-item means; with 12 items the maximum stands in for
p95. B1+ ran from 16:43:56 to 16:47:02 UTC, B1+ web from 16:47:02 to
16:51:12; the spike writes no usage row, so the windows hold only B1+.
`citations-hard/report.md` shows B1+ and B1+ web as one `b1plus` row (63
statements, 62.7 %), because both arms carry the same arm name. The split
above matches each statement to the answer file it came from; 6 statements
appear in both files and are left out.

What the numbers say:
- Without web search B1+ knows none of it (0.03). It says so and
  abstains, so it does not invent facts.
- With web search B1+ now searches (35 searches in 24 answers) and finds
  the facts as well as the spike (0.96 each). Its only miss is a German
  answer that writes "2,50 %", which the scorer does not match against
  "2.5".
- The spike's sentences are better backed by their sources: 87.3 % against
  64.0 %. B1+ web mostly lists sources at the end or cites a whole page,
  so fewer of its sentences can be checked (50 against 259), and those
  that can be checked are less often supported.
- 13 of the spike's 33 rejected statements are "not confirmed" or premise
  sentences, which say what the evidence does not contain. Without them it
  is 226 of 246, 91.9 %. That is a reading by one agent, not a rule change.
- The spike costs 1.2× B1+ web and takes about 3.4× as long.

Judge cost: no usage row. Tokens 86479 GPT-6.1 Sol, 678880 Grok 4.7,
11379 Gemini 3.1 Pro; at most about 5 USD at the completion rate. Gemini
timed out once; the split was then decided without it.

**Go / no-go (§0 #40), on the hard corpus, for the owner to accept.**
No-go by the letter of the gate, but close. The fact-recall clause fails:
0.96 against 0.96, no 10-point lead. The precision clause needs ≥ 92 % for
the spike and < 92 % for B1+ at ≤ 2× its cost. The cost holds (1.2×) and
B1+ web misses 92 % (64.0 %), but the spike reaches 87.3 %, or 91.9 %
without its meta sentences. Both readings stay below 92 %. The judges are
uncalibrated, so this result can move by a few points either way.
The plain reading: on new facts the spike is as complete as the gateway
with search, and its claims are clearly better sourced (+23 points), at
about the same cost and three times the wait. Whether that is enough for
RS6 is the owner's decision, best after people label the 40 blind pairs in
`citations-hard/sample.json`.

**Owner decision, 2026-10-23: go.** The owner accepts the hard-corpus
result as go although the precision clause is missed narrowly (87.3 %,
91.9 % without meta sentences, against 92 %). Reason: same recall as B1+
with web search, clearly better sourced claims, cost within 2×. This is an
explicit override of the gate, not a pass. RS6 may start when asked;
human calibration of the judges is still open.

## 2026-10-23 — RQ1 full desktop use-case run

Measurement only. No product code changed. The local stack was checked out
on synaplan `main` before the run. A fresh harness was paired for
`admin@synaplan.com`. Target `http://localhost:8000`. Run id `V42D2`.
Report: `synaplan-desktop/_extras/planning/20260917-desktop-use-case-test-plan/runs/20261023081938.json`.

```bash
cd synaplan-desktop/src-tauri
cargo run -p synaplan-core --example usecases -- --full
```

| Repo | SHA |
| ---- | --- |
| synaplan | `bb70055b055e773b4ed6b205c63b77e7b479aa72` |
| synaplan-desktop | `12e2510f035fc6776c34642e74007023d4119bd6` |

5 passed, 6 failed, 0 skipped, 1533 s. 51 of 62 chat models were available.

| Case | Result |
| ---- | ------ |
| UC-00 | FAIL. VECTORIZE `ollama:bge-m3:vectorize` is `provider_unavailable`. No SOUND2TEXT or TEXT2SOUND default. |
| UC-01 | FAIL. 46/51 answered `PONG`. Empty text: `mistral:mistral-large-4:chat`. `model_unavailable`: four Hugging Face Kimi rows. |
| UC-02 | FAIL. 46/51 wrote `hello.md`. `google:gemini-2.5-flash-lite:chat` left the file missing. Same four Hugging Face rows unavailable. |
| UC-03 | FAIL. 46/51 answered with a source. Sonnet 5.5 and gpt-oss-120b did, in 3 rounds. `mistral-large-4` ended on the wrap-up sentence with no URL. `gemini-3.7-flash` used 5 rounds (ceiling 4). |
| UC-04 | PASS. 3/3 wrote `sources.md` (Sonnet 5.5, gpt-oss-120b, mistral-large-4). |
| UC-05 | PASS. 3/3 named the fact. Ready in 132 ms. UC-05d hit after Ready in the same thread. |
| UC-06 | FAIL. 7/7 ready in 7.9 s, 37.72 MB/min, 0/3 facts. |
| UC-07 | FAIL. No dictation model. |
| UC-08 | PASS. |
| UC-09 | PASS. 616 words, `truncated=true`. |
| UC-10 | PASS. 4 failures, plain copy. |

`CHANGES.md` moved C1, C2, C3a and C5 to Done because those gates were green. C3b, C4, C6, C7, C8, C9 and C10 stay open. RS1, RS2 and RS3 stay in progress.

---

## 2026-10-23 — RD1 B1 smoke, before and after

Both exports used the workspace default chat model `anthropic:claude-sonnet-5-5:chat`, web search on, no skill. The catalog route does not send `maxOutput`, so plain chat uses 8,192. Agent turns are not streamed. Ten items finished in about a minute either way, so the missing stream did not slow this set.

The before binary was compiled before the prompt change. The after binary includes it. Both HEADs are the commits below; the desktop working tree holds the uncommitted RD1 change.

```bash
cd synaplan-desktop/src-tauri
cargo run -p synaplan-core --example usecases -- --research-corpus /path/to/backend/tests/Eval/research/corpus.json
```

| | Before | After |
| --- | --- | --- |
| Run | `7A1D7` | `VSZSV` |
| File | `runs/b1-7A1D7.json` | `runs/b1-VSZSV.json` |
| Time | 54 s | 62 s |
| synaplan | `bb70055b055e773b4ed6b205c63b77e7b479aa72` | same |
| synaplan-desktop | `12e2510f035fc6776c34642e74007023d4119bd6` | same |

Scored with `app:research:eval --corpus=smoke --arm=b1 --import=<file>` on the local stack. Fact recall is **0.78** on both (9 items with match strings). `doc-01` and `mix-01` are 0.00: the harness has no contract to read. `trap-01` abstained both times. The other seven items are 1.00. The `ms` column is 0 because an import is not a live answer.

Quick set `9RDYA` (2026-10-23T09:40:11Z, `--quick`, 458 s, report `runs/20261023094011.json`): 9 passed, 4 failed. UC-01, UC-02, UC-03, UC-04, UC-04-de, UC-05, UC-08, UC-09 and UC-10 passed. UC-00, UC-06 and UC-07 failed for the same local reasons as `V42D2`. UC-03-de failed 6/7: `groq:openai/gpt-oss-120b:chat` returned the gateway wrap-up sentence and no URL. The same case passed 7/7 on a second run (`--only UC-03-de`, report `/tmp/synaplan-uc03-de-rerun/runs/20261023094334.json`, copied to `runs/uc03-de-rerun-20261023094334.json`). That second run is not the ledger.

UC-01 first token was not slower than `V42D2`. Sonnet 5.5 ttft 1179 ms / total 1191 ms (was 3145 / 3146). gpt-oss-120b ttft 530 ms / total 530 ms (was 2072 / 2072). Plain chat now uses 8,192 tokens. UC-09 finished: 2795 words, `end_turn`.

`make ci-local` in synaplan-desktop passed on this working tree (lint, vue-tsc, Vitest 204, clippy, 227 Rust tests, frontend build, workspace clippy, cargo build). J-RQ-1 has not been walked in the desktop window. RD1 stays in progress.

---

## 2026-10-23 — RS4 flag on vs off

Live runs, not a fixture replay. Account is admin user id 1. The chat model is
that account's default (Claude Sonnet 5.5). Web search is Brave. The three
smoke items do not read the knowledge folder.

Stack: local `docker compose` on the m4 context. synaplan
`feat/web-search-read-documents` at `2b4dd17b6`. The flag row was missing
before the on-run, which reads as off. It was set to `1` for the second run
and set back to `0` afterwards. Reports:
`backend/var/research-eval/2026-10-23-rs4-off.md` and
`backend/var/research-eval/2026-10-23-rs4-on.md`.

```bash
docker compose exec -T backend php bin/console app:research:eval \
  --corpus=/tmp/rs4-corpus.json --arm=b0 --user=1 \
  --frozen=var/research-eval/frozen/2026-10-23-rs4-off \
  --report=var/research-eval/2026-10-23-rs4-off.md

docker compose exec -T backend php bin/console app:research:eval \
  --corpus=/tmp/rs4-corpus.json --arm=b0 --user=1 \
  --frozen=var/research-eval/frozen/2026-10-23-rs4-on \
  --report=var/research-eval/2026-10-23-rs4-on.md
```

`/tmp/rs4-corpus.json` is reg-01, reg-02 and sci-01 copied from the starter corpus.

| Flag | reg-01 | reg-02 | sci-01 | Mean recall |
| ---- | ------ | ------ | ------ | ----------- |
| off | 1.00 in 18651 ms | 1.00 in 7638 ms | 1.00 in 3882 ms | 1.00 |
| on | 1.00 in 17738 ms | 1.00 in 7660 ms | 1.00 in 3683 ms | 1.00 |

Both runs read the same HTML pages. The only document URL was
`https://www.datenschutz-bayern.de/datenschutzreform2018/OH_Loeschung.pdf`
on reg-02, at result 8. The page cap is 3, so it was not opened either time.
No chat cost row was written. This run shows the flag does not hurt HTML
reads; it does not measure document reading. The flag stays off.

---

## 2026-10-22 — RQ2 B0 on the starter set

Live run, not a fixture replay. Account is admin user id 1. The chat model is
that account's default (Claude Sonnet 5.5). Web search is Brave. Knowledge
folder `rq2-eval` holds the three generated files `preisliste.txt`,
`protokoll.txt` and `rahmenvertrag.txt`. The older `Wissensordner` files were
not used.

Stack: local `docker compose` on the m4 context. synaplan `test/research-eval-corpus`
at `0de94b240`, with the eval command still uncommitted. Report:
`backend/var/research-eval/2026-10-22-b0.md`.

```bash
docker compose exec -T backend php bin/console app:research:eval \
  --corpus=full --arm=b0 --user=1 --folder=rq2-eval \
  --frozen=var/research-eval/frozen/2026-10-22-b0 \
  --report=var/research-eval/2026-10-22-b0.md
```

| Date | Step | Arm | Corpus | Depth | Recall | Citation precision | Coverage | Abstention | Cost p50 | Time p50 / p95 | Command |
| ---- | ---- | --- | ------ | ----- | ------ | ------------------ | -------- | ---------- | -------- | -------------- | ------- |
| 2026-10-22 | RQ2 | B0 | starter 20 | standard | 0.95 | not judged | not judged | both traps avoided the forbidden sentence | not recorded | 4865 ms / 17819 ms | `app:research:eval --corpus=full --arm=b0` |

Recall is the mean over the 19 items that have a match string. `sci-02` ("What
is the chemical symbol of water?") is 0.00 because the answer did not contain
`H2O`. Every other scored item is 1.00. `trap-01` has no match string, so it
is not in the mean; it did not use the forbidden sentence. The chat calls did
not write a cost row, so no cost is entered. Times are the median and the 95th
percentile of the 20 row durations in the report.

---

## 2026-10-22 — RS3 manual walk (not the desktop runner)

A walk, not a use-case run. `06_evaluation.md` still wants UC-05, UC-05e and
UC-06 from the desktop runner; this entry only records what one paired key
did against the local dev stack.

Stack: local `docker compose` on the m4 context. synaplan
`feat/knowledge-folder-as-a-tool` at `c9534c70e`. `KNOWLEDGE_TOOLS=desktop`
(owner 0). Model `openai/gpt-oss-20b` on Groq, operator key. Command:
`POST /v1/messages` with `Authorization: Bearer` (a paired desktop key) and
`x-synaplan-rag-group-key: Wissensordner`.

Folder `Wissensordner`, three files, each vectorized as one chunk
(`BFILES` 19–21): `projektcode.txt` (“ZEBRA-41”), `budget.txt` (“73.900 €”),
`kickoff.txt` (“14. März”).

| Turn | Question | Result | Evidence |
| ---- | -------- | ------ | -------- |
| 1 | “Nenne Projektcode, Budget und Kickoff-Datum.” | Answer contains all three planted facts, cited as the injected passages. No tool call. | Response `chatcmpl-46ac7822-1276-4486-8aaf-54dae13a11ab`, 648 in / 182 out. `BUSELOG` 814 is `MESSAGES` only |
| 2 | “Die Textstellen oben reichen nicht. Suche im angehefteten Ordner nach dem Budget und lies die gefundene Datei vollständig.” | Answer quotes the whole budget file, including “Andere Beträge gelten nicht.” | Response `chatcmpl-5a974f2b-2e72-435a-adb6-b9a13bc703cd`, 2403 in / 351 out. `BUSELOG` 818 `tool:knowledge_search` success, 819 `tool:read_document` success |

Not measured: time to first token (UC-05 before / after), a question that
makes the model search twice on its own (UC-05e asks for `knowledge_search`
≥ 2), and UC-06. The desktop use-case runner was not used.

---

## 2026-10-22 — architecture review (RQ0, third pass)

Full review in [`08_review.md`](./08_review.md) §2b: the plan checked against
current deep-research systems (Anthropic's research system, LangChain Open
Deep Research, the Gemini Deep Research agent) and re-checked against `main`.
Not committed yet; local working-tree changes for the owner's review.

| Finding | Evidence | Change |
| ------- | -------- | ------ |
| The engine-driven loop contradicted the plan's own thesis (the model should drive the tools) | `01` §1 vs `00` §0 #2; `AiFacade::chat()` already returns native `tool_calls`, `ChatToolLoop` uses them | Two lead modes: tool mode for qualified leads, round mode as fallback; guardrails stay in the engine (§0 #2) |
| No proof the engine beats Phase B's gateway loop | no B1+ arm in `06` §5 | Spike RQ2b with arms B1+ and B4 and a go / no-go before RS6 (§0 #40) |
| Readers would run sequentially | `AiFacade` has no concurrent chat call | Concurrent reader calls in v1 (§0 #37) |
| Verbatim quotes drop correct findings | `03` §2.4 | Numbered passages and passage-id citations (§0 #39) |
| Readers could not follow links; budgets fixed before measurement; parallel-section writing | `03` §2.4, §4, §2.6 | Leads (§0 #38), budget curve (§0 #41), one-call writing (§0 #42) |

---

## 2026-10-17 — plan review (RQ0, second pass)

Full review in [`08_review.md`](./08_review.md): 13 gaps, 10 forgotten
items, 12 regression risks with their guards, 5 installation findings. Four
findings would have shipped a broken run or install and changed the design:

| Finding | Evidence | Change |
| ------- | -------- | ------ |
| One research run would have blocked the install's only worker process for up to 20 minutes | `docker-compose.yml` 665–667: one `messenger:consume` over three transports, `--memory-limit=512M`; Helm `worker.replicaCount: 1` | Runs execute as ≤ 120 s Messenger units that re-dispatch (§0 #8) |
| Research could not be tested in CI | `SsrfGuard` has no test mode; no search or page stub; the only web-search E2E is `@noci` | Test doubles in the `test` environment (§0 #27); the SSRF guard untouched |
| The world rule left four side channels open | US-hosted search providers (only SearXNG self-hosted), `pdf_vision` in the document chain, the condenser's own model order, the workspace index model | Five-leg world rule with disclosure or strict refusal (§0 #29) |
| Decision #26 used attachments as a routing signal | `ChatView.vue`: attach and drop both call `knowledge.add` (upload to the knowledge folder) | Signals are named files and folder references only |

Also added: a sweeper (§0 #28), context-window budgets (#31), prompt caching
as a built feature with both cost numbers (#32), idempotency / list / delete
(#33), documentation as part of every step with three `synaplan-docs` pull
requests (#34), the mobile-policy rule for `config.ts` (#30), the two
`docker-compose.minimal.yml` lines the module convention requires (#19), and
three new files: [`08_review.md`](./08_review.md),
[`09_installations.md`](./09_installations.md) (eight profiles, degradation
copy, tests per profile, upgrade / rollback, docs matrix) and
[`10_contracts.md`](./10_contracts.md) (schemas, endpoints, events, units,
keys, PHP / Rust / Vue contracts, fixtures). Every step brief in
[`07_sprints.md`](./07_sprints.md) now has Reads, Touches, Tests first, Brief,
Docs and Exit.

Checked on this pass and found safe: Desktop's catalog parser is a `BTreeMap`
without `deny_unknown_fields` (additive server groups cannot break old
clients); `tauri-plugin-notification` is already shipped. **Corrected in the
Copilot round:** `PlugConfigService::extractionChain($family, $hasCloudStt)`
filters cloud steps for the *audio* chain only — the document chain has no
such switch, so RS4 adds the marker and the filter (`08` §2a C1).

---

## 2026-10-17 — Copilot review round on #2541

Sixteen review comments, each verified against the code and applied
(`08_review.md` §2a). Design changes: atomic unit claims with fenced
checkpoints and a `DelayStamp` between units (§0 #36); server-side delivery of
web reports into the run's `chat_id`; one question-timeout policy (skip and
continue; only an unconfirmed plan stops a run); module-off split (new work
404, own runs readable and deletable, §0 #35); `RunResearchUnit` routed to
`sync://` in the test environment; the citation map and source URLs in the
state; every non-supported verdict leaves the factual text; RW3 as a waiting
Saved Task node. Copy fixes: the plan card's read-only promise, the admin
turn-off confirmation, the Stop outcome.

---

## 2026-10-17 — RQ0 baseline research

Stack: local dev stack (`docker compose up -d`); synaplan `main` at
`4675c572f` (research started on the V4 S2 branch at `206cff346`; `git diff
origin/main` shows no change to any file cited, and the size-ratchet entries
cited match `main`); synaplan-desktop `main` at `12e2510`. Facts read from code
and from the live dev database; **no quality measurement yet** — that is RQ1
and RQ2.

| Question | Answer | Evidence |
| -------- | ------ | -------- |
| How many active chat models, and does `BQUALITY` separate them? | 66 active chat models; `BQUALITY` 10 for 37, 9 for 20, lower for 9 — not a job signal | `SELECT … FROM BMODELS WHERE BACTIVE=1 AND BTAG='chat' AND BID>0` |
| Which models run which slot on this stack? | CHAT / TOOLS / ANALYZE `anthropic:claude-sonnet-5`; SORT / PLAN / SUMMARIZE `groq:openai/gpt-oss-120b`; MEM gpt-oss-120b `mem`; VECTORIZE `ollama:bge-m3`; RERANK `cohere:rerank-v3.5` | `BCONFIG` `DEFAULTMODEL`, owner 0 |
| Which EU-hosted models can fill research roles? | TrustedTokens rows carry `meta.jurisdiction = DE` (gpt-oss-120b, GLM-5.3, GLM-5.3-Flash, Qwen3.6-35B); Mistral has no jurisdiction field | `BJSON` of the candidates (`02` §5.1) |
| How many web searches does the gateway allow before it forces an answer, and how long may the answer be? | 2; "one or two sentences" | `GatewayToolLoop::WEB_SEARCH_WRAP_AFTER`, `WRAP_UP_AFTER_SEARCH` |
| Does the gateway read pages for non-Anthropic models? | No — `web_fetch` is passed to Anthropic or dropped | `WebFetchPolicy` |
| How much knowledge does a Desktop turn see? | 5 passages, 8,000 characters including memories, one query per turn | `MessagesContextInjector::RAG_LIMIT`, `MAX_CHARS` |
| Does web chat deep search read PDFs? | No — `pdf`, office and media extensions skipped; 3 pages, 28,000 characters, 25 s | `WebResearchService::SKIP_EXTENSIONS`, `PlugConfigService` defaults |
| Does anything re-plan after seeing results? | No — `TaskPlanner` plans once, the DAG runs once | `TaskPlanner`, `DagExecutor` |
| Desktop output budget and intent checks | Plain chat `max_tokens 1024`; agent loop 40 rounds × 8,192; file intent needs English verbs in the loop, a five-language phrase list in plain chat | `commands/mod.rs`, `agent.rs`, `src/lib/classifyGeneration.ts` |
| When does Desktop chat offer file tools? | Only when the project has at least one enabled skill; otherwise every turn is plain streaming chat without `list_files`, `read_file` or `write_file` | `src/views/ChatView.vue` (`agentMode = enabledSkillCount > 0`) |
| Is there already a knowledge search a model could call? | MCP `rag_search` over `/mcp` — the caller's own documents only, outside the gateway loop | `McpServerFactory::ragSearchHandler` (`VectorSearchService::semanticSearch`) |
| Gateway / messages settings on this stack | `MCP_MAX_ITERATIONS 8`, `MCP_TOOLS_ENABLED 0`, `WEB_SEARCH_MODE auto`, `WEB_FETCH_MODE auto`, `CONTEXT_INJECTION_ENABLED 0`, web search provider `brave`, rerank on (seeded off) | `BCONFIG` groups `MESSAGES_GATEWAY`, `PLUGS` |
| Which frozen files do the quick wins touch? | `GatewayToolLoop.php` (1,432 lines, constructor 14), `MessagesContextInjector::sessionBlock` (109), `UrlContentService.php` (1,258), `WebResearchService::deepen` (122), `ModelConfigService.php` (1,327); `PromptCatalog.php` and `ModelCatalog.php` are exempt as catalog data | `backend/tests/Architecture/sizeRatchetBaseline.json`, `Support/SizeOffenders.php` |
| Does Desktop already have OS notifications? | Yes — `tauri-plugin-notification` with `notification:default` | `src-tauri/Cargo.toml`, `capabilities/default.json` |
| Last desktop use-case run | 2026-09-18, `e79b589`, quick subset: 1 pass, 3 fail (UC-02, UC-03, UC-04); C1, C2(a)(b), C3a, C4 and C5 are in the code since, not re-run | desktop `_extras/planning/20260917-desktop-use-case-test-plan/STATUS.md`, `CHANGES.md` |

Not measured yet: answer quality of any path (RQ1, RQ2), reader faithfulness
per model, cost per run, queue wait on `async_ai_high`.

Registered in the same commit: `ROADMAP.md` row 21 (§1, not started), its §3
line and the §6 decision of 2026-10-17, and the planning `README.md` live-tracks
table. The plan was committed from a separate worktree on
`planning/research-quality`, because another session had the V4 S3 branch
checked out in the shared working tree.

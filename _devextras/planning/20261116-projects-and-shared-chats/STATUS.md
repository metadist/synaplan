# Projects and shared chats — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| PJ0 | merged | #3133 | plan written 2026-11-16; §0 partly decided, see below |
| PJ1 | merged | #3141 | corpus and runner; today's numbers from the local stack, see 2026-11-16 below |
| PJ2 | merged | #3142 | spike; §0 #21 holds, also under load; G7 prototype passes with contract v3 after two failed runs, see 2026-11-16 below |
| PJ3 | merged | #3152 | Projects with sources and instructions (API). Deviation from §10: a chat joins a project through `POST /projects/{uuid}/chats` and `PUT/DELETE /projects/{uuid}/chats/{chatId}`, so `POST /chats` and `PATCH /chats/{id}` are unchanged (no growth of the oversized `ChatController`). The flag is checked in the controller as well as by the module gate. Walked on the stack with a real model: a project chat answered from a text source with its marker, and the instructions shaped the answer. Create takes name and description; instructions go through PATCH. |
| PJ4 | merged | #3186 | Project page `/projects/:id` (Chats, Sources, Instructions), a "Projects" section in the Chats panel, the Add-source dialog (file, link, text), the project name above the composer of a project chat, and move in/out of a project from the chat menu, pinned projects first (`BPROJECTS.BPINNEDAT`, migration `Version20261119120000`). A flag-off module answers 404 `feature_not_configured` and the UI shows nothing. `PROJECTS_ENABLED=1` is set in `backend/.env.test` so the E2E spec can run. |
| PJ5–PJ28 | not started | — | Phase B can start: AU26 merged as #3193, AH8a as #3198, AH8b as #3201; PJ5 waits for the owner's go; every other ☐ and Ask row of §0 waits for its step |

---

## 2026-11-19 — after PJ4: what comes next, and the plan fixed

**Checked on** `main` @ `89694c4b2`: AU26 is not merged (three tool loops,
`ToolLimits` still has fixed caps); AH8 is not started (no `BRUNS`, no
`App\Service\Run`; research has its own lease, reaper and unit worker in
`Service/Research/Run/*`). Phase B cannot start.

**Owner decisions** (§0): #21–#25 ticked; #63 for `BRUNS.BKIND = chat` and
`BRUNS.BNOTES` in AH8a's migration; #66 order AU26 → AH8a → AH8b → PJ5,
phase C not ordered yet; new rows #68 (staged end of a run), #69 (limits as
profile input, AH8 split), #70 (budget before every call until PJ6, flag off
until PJ6 and PJ8), #71 (no "Can write" for a project chat before PJ20),
#72 (a deleted member's text becomes "Message deleted"), #73 (projects flag
with PJ28). The review loop of `AGENTS.md` applies again: wait for Copilot
and `All Checks Passed` on every head.

**Fixed in the plan:** PJ4 merged; `BPROJECTS.BPINNEDAT` (PJ4) in `03` §2.2;
the PJ3 chat routes in `03` §10; `MAX_SOURCES` 50 and
`INSTRUCTIONS_MAX_CHARS` 4000 and the env pin `PROJECTS_ENABLED` in `03`
§11; the contradiction between `03` §3 and §1.5.1; run ownership, per-unit
re-checks, approvals in units and checkpoint erasure in `03` §1.1 and the
PJ5 brief. The AU26 brief (row 22, S4) and the AH8 brief (row 25) now take
limits as profile input; AH8 is AH8a and AH8b.

**Next:** AU26 (`refactor(tools): one agent loop for document chat and both
gateways`), brief in `../20261021-architecture-update/S4_one_tool_path.md`.
In review on `refactor/one-agent-loop` (row 22 `STATUS`, 2026-11-19).
`ToolLoopLimits::unbounded()` is the chat profile's input for PJ5. Of the
run metrics below, AU26 hands back the stop reason and the iteration count;
the rest needs the AH8a kernel and moves to AH8a and PJ5.

---

## 2026-11-16 — spike: a chat turn as run units (PJ2)

**Ordered:** the owner said "start PJ2 now" on 2026-11-16, before #3141 was
merged. The branch is cut from `test/chat-loop-corpus`, because the script
uses PJ1's client.

**What was measured.** Variant 1, every turn queued, exists as a switch:
`RUNTIME.STREAM_PUMP=all` with `STREAM_PUMP_PERCENT=100` sends the web
chat's turn to the `pump` container, and the request copies the frames
from Redis. Variant 2 hands over at the first tool call; the time it adds
is the same hand-over, so one measurement answers both.

The ten `simple` turns of the corpus, three passes per mode, modes
alternating, one turn at a time, local development stack, the seeded demo
account on the test provider (no model cost, so only the path is timed):

| Mode | Turns | Answered | First frame median / p95 (s) | First text median / p95 (s) | Total median / p95 (s) |
| ---- | ----- | -------- | ---------------------------- | --------------------------- | ---------------------- |
| In the request (today) | 30 | 30 | 0.082 / 0.112 | 0.112 / 0.160 | 1.706 / 1.825 |
| Queued for the pump | 30 | 30 | 0.114 / 0.198 | 0.135 / 0.233 | 1.617 / 1.731 |

```bash
docker compose up -d pump
docker compose exec -T -e CHAT_LOOP_EVAL_PASSWORD backend \
  php -- --email=<account> --label=request < _devextras/planning/20261116-projects-and-shared-chats/spike/turn-latency.php
```

What it says:

- **The hand-over costs about 0.03 s at the median and 0.09 s at p95**
  with an idle pump. Against today's first token of a `simple` turn on a
  real model (2.56 s) that is about 1 % and 3 %. G1's limit is 10 %.
- **No model call is added by queueing**, so the cost of a simple turn
  does not change.
- **§0 #21 holds, and not for speed.** At rest both variants are inside
  G1. "First call in the request" keeps a simple turn independent of the
  pump: with the pump down or full it still answers. That, not the 0.03 s,
  is the reason to keep it.

**Under load** (8 and 16 turns started at once, the seeded demo account on
the test provider, one `pump` container with its single consumer, 48
messages in all):

| Mode | At once | Answered | First text median / p95 (s) | Total median / p95 (s) |
| ---- | ------- | -------- | --------------------------- | ---------------------- |
| In the request | 8 | 8 | 0.26 / 0.27 | 1.74 / 1.79 |
| In the request | 16 | 16 | 0.40 / 0.44 | 1.89 / 2.01 |
| Queued for the pump | 8 | 8 | 5.94 / 10.16 | 7.38 / 11.67 |
| Queued for the pump | 16 | 16 | 12.76 / 23.13 | 14.27 / 24.10 |

```bash
spike/turn-latency.php --only=<turn id>   # one turn per process, N processes at once
```

**Variant 1, every turn queued, fails G1 under load.** With one consumer
the pump answers one turn after the other, so the 16th turn waits for 15.
Idle, the cost was 3 %; at 16 at once it is a factor of 30 on the first
text. A queue for every turn would need as many consumers as turns that can
overlap, and a full or stopped pump would stop plain chat. Variant 2 keeps
the simple turn in the request and queues only the turns that need a tool,
where a wait of seconds is already normal. **§0 #21 is confirmed, now for
speed under load as well as for independence from the pump.** The run layer
for tool turns must run several consumers (AH8 sizes that).

Limits of this measurement: one machine, one turn per process, the test
provider (no model time), so a real model makes every turn longer and the
queue longer still. The totals of the idle comparison are not compared: the
two containers do not run with the same settings.

**Two-hour run (G7 prototype).** `spike/long-run.php`, not product code: one
process, `openai/gpt-oss-120b` straight at Groq, a 90-section document, a
window of 8,000 tokens, compaction at 70 %, last 4 steps kept, 55 s pause
per step, notes reloaded from a file each step. Task: read every section,
then `finish(total_nordholz, top_section, code_word)`.

| Measure | Result | G7 asks |
| ------- | ------ | ------- |
| Duration | 8,013 s (2 h 13 min) | two hours |
| Cost | 0.1275 (cap was 0.50) | — |
| Compactions | 22 | at least 3 |
| Largest prompt | 4,623 of 8,000 tokens | below the window |
| Sections read | 90 of 90 | all |
| Duplicate reads | **32** | none |
| Early `finish` rejected by the guard | 2 (at 84 and 87 sections) | — |
| Code word | right | right |
| Total (Nordholz) | **303,408, gold 326,390: wrong** | right |
| Top section | **23, gold 77: wrong** | right |
| Goal kept | **no** | yes |

```bash
docker compose exec -T backend php /tmp/long-run.php --label=paced --sections=90 \
  --guard-finish=1 --pause=55 --max-cost=0.50   # copy spike/long-run.php there first
```

What it says. **The window held, the work did not.** The prompt never
neared the window and nothing crashed, but the notes lost state:

- **The notes grow until they hit their budget.** Every compaction kept one
  `plan` item per section (up to 90). From compaction 10 on the summary call
  ended at its 2,500-token output cap (13 of 22 compactions), so facts (amounts, suppliers) were
  cut. That is where the wrong total and top section come from, and why 32
  sections were read twice.
- **The model quits.** In 18 steps it answered in text ("I'm unable to
  complete the request") or announced an answer from partial data instead of
  calling a tool. Left alone, the run ends there. Twice it called `finish`
  before everything was read.
- **So G7 is not met by this prototype,** and the prototype is the naive
  version of §1.5. It is evidence for the contract, not against it.

**What follows for the contract (§1.5) and the plans:**

- `plan` is a short list of the few open steps, never one item per unit of
  work; progress is a counter or a range ("sections 1–60 read"), not a list.
- A running result (sum, maximum, section of the maximum) lives in `facts`
  as one value that every step updates, not as raw rows to re-add.
- Compaction output has its own budget and a size check; a summary that
  hits the cap is a failed compaction (the existing wrap-up path), not a
  silent cut.
- The loop, not the model, decides "done": a `finish` is checked against the
  goal (here: all sections read) and rejected with the missing part; a text
  answer without a tool call is answered with a nudge, with a limit.
- The loop keeps the ledger of tool calls it has made (`read_section(7)`
  already done) and answers a repeat from it without a model round trip.

**For AU26 and AH8 to expose** (final list after three runs): per run, the
compaction count and the size of the notes after each; a failed-compaction
state with its reason; duplicate tool calls (same name and arguments); steps
without a tool call (nudges sent); rejected `finish` calls; the guard
rejections (read before record); malformed tool calls and how each was
retried; whether the answer came from a required `finish` or the JSON
fallback; the ledger (rows, reducers, current values); peak prompt tokens
against the window; and the queue depth and wait of the run consumers
(from the load run). AU26 shows them to the person, AH8 alerts on a failed
compaction, repeated nudges, repeated malformed calls and a consumer queue
that keeps growing. This list lives here; the owners of AU26 and AH8 take it
from this section.

**Open:** the real run in the worker with lease and restart, which this
prototype does not test; a second document and a second model for the same
prototype, before G7 is called passed for the product.

---

## 2026-11-16 — corpus and runner (PJ1)

**Ordered:** the owner asked for the first steps on 2026-11-16. PJ1 needs no
☐ row and touches no product code.

**What is there:** `backend/tests/Eval/ChatLoop/` — `corpus.json` (40 turns:
10 simple, 8 search, 8 files, 8 multi-step, 6 research; all five locales),
four synthetic documents, three scripted chats (150 messages with a picture
at message 5 and a fact at message 10; a search that always returns the
same; a tool that always fails), and `run.php`. The research turns take
question and gold facts from `tests/Eval/research/corpus-hard.json`, so G2
scores the same words as row 21.

**How it measures:** it signs in like the web client and sends each turn
through `POST /api/v1/messages/stream`. Cost is `usage` and `usage_extra` of
the `complete` event plus `cost.spent` of the research run a turn hands over
to; time to first token is the first non-empty `data` chunk; backed
statements are the research run's own `check` counts, and `--answers`
writes a file for `app:research:citations`. The run exits 1 when a turn has
no terminal result.

**Deviation from the brief:** the two stubs are data only. Planting a tool
stub needs the loop of PJ5; PJ6 wires them.

**Walked on the dev stack** (demo account, test models), 2026-11-16:

```bash
docker compose exec -T -e CHAT_LOOP_EVAL_PASSWORD backend \
  php tests/Eval/ChatLoop/run.php --base-url=http://localhost \
  --email=demo@synaplan.com --only=simple-01,simple-02
```

Both turns answered at no cost, the chats were deleted, the session was
signed out. The test provider has no price and echoes; these are not
today's numbers. With `files-03` added the run stops with exit 1 after that
turn: the planner split it into steps and the instance's planning model, a
real one on this stack, charged 0.0025. The account's own defaults do not
show that model, so the runner stops a run without `--live` at the first
charged turn. The chat and the uploaded file were removed (files and chats
of the account counted before and after).

**Review of the runner, 2026-11-16:** the session is refreshed after the
five-minute access cookie runs out; sorting and planning cost is read from
`usage_extra`; a planned turn's answer is read from its reply card;
uploaded files are deleted with the chat; a research run nobody waits for
is cancelled; an unknown argument ends the run before anything is sent.

**Today's numbers, 2026-11-16.** One run with `--live` on the local
development stack at `main` plus this branch, signed in as the seeded admin
account, whose Chat, Sort and Analyze model is Claude Sonnet 5.5. All 40
turns ended with an answer; none failed and none stayed without a terminal
result.

| Group | Turns | Answered | Cost total | Cost median | First token median / p95 (s) | Total median / p95 (s) | Recall | Backed statements |
| ----- | ----- | -------- | ---------- | ----------- | ---------------------------- | ---------------------- | ------ | ----------------- |
| simple | 10 | 10 | 0.3047 | 0.0301 | 2.56 / 3.67 | 3.64 / 5.47 | 100.0 % | n/a |
| files | 8 | 8 | 0.0417 | 0.0036 | 3.18 / 4.11 | 3.88 / 4.49 | 100.0 % | n/a |
| search | 8 | 8 | 0.3385 | 0.0389 | 4.99 / 5.72 | 7.09 / 10.25 | 100.0 % | n/a |
| multi_step | 8 | 8 | 0.5419 | 0.0187 | 2.45 / 4.13 | 11.51 / 86.74 | 100.0 % | 30.8 % of 13 |
| research | 6 | 6 | 0.2721 | 0.0435 | 4.49 / 6.20 | 6.71 / 8.61 | 100.0 % | n/a |

Commands, from `backend/`, password in `CHAT_LOOP_EVAL_PASSWORD`:

```bash
php tests/Eval/ChatLoop/run.php --base-url=http://localhost --email=<account> --group=<simple|files|search|multi_step> --live
php tests/Eval/ChatLoop/run.php --base-url=http://localhost --email=<account> --only=research-0N --live --turn-timeout=1500
```

The research turns ran one at a time with a look at the cost ledger before
each. The runner counted 1.4989 for the 40 turns, which is the provider
cost plus the 10 % markup the turn reports. The ledger holds provider cost
without markup and shows 1.5767 for the account over the same time: it also
has the calls after the turn, which are not in the turn's `complete` event
(message digest 0.1377, memory extraction 0.0360).

What the numbers say about today:

- **G1 compares against the `simple` row**: 0.0301 per turn, first token
  after 2.56 s, done after 3.64 s.
- **No research turn was handed to a research run.** All six were answered
  as an ordinary search turn in six to nine seconds, so the group has no
  backed statements today. Three `multi_step` turns did hand over (54 to
  93 s) and their fact-check backed 30.8 % of 13 statements.
- **Sorting is most of a `simple` turn.** From the ledger, per turn:
  sorting 0.0223 (10,700 prompt tokens on the account's Sort model, Claude
  Sonnet 5.5, none of them cached), the answer 0.0045 to 0.0072 (2,100
  prompt tokens), and after the turn a message digest of 0.0081 and at
  times a memory extraction of 0.0045. The sorter is 27 calls and 0.6030
  of the run. The fast path that answers a short plain message without the
  sorter exists and is off by default (`CLASSIFIER.FAST_PATH_ENABLED`),
  because its heuristic sent media requests to chat.
- **A `files` turn costs a tenth of that because it is not sorted.** An
  attachment routes the turn before the sorter; the planner then runs on
  `gpt-oss-120b` with a cached prompt for 0.0015, and the answer needs 500
  to 2,600 prompt tokens. The two groups take different paths; the calls
  are counted the same way.
- **For G1 this means** the bar of 0.0301 is mostly the sorter on this
  account's Sort model. A loop that replaces the sorter for a simple turn
  is compared against it; an account with a cheaper Sort model has a lower
  bar. The gate run on staging states the Sort model with the numbers.

Not run: the 150-message scripted chat (G6) and a run on staging. Local
numbers are one machine and one run; the gate run for G1–G7 repeats this on
staging.

---

## 2026-11-16 — plan written (PJ0)

**What the owner decided** in the sessions of 2026-11-12 and 2026-11-13
(marked "Yes — owner" in §0): projects without templates, sources indexed
once; the model decides how long and how it works; no fixed time, no
maximum of steps or rounds; a cost limit and circle detection as the only
guard rails, both ending with a result; web chat and projects, not the
widget; the Chat model for everything, no list of verified models; notes
with a visible "Conversation summarized"; files usable by ID for the whole
chat; a question card with options and free text, several questions, only
the starter answers in a shared chat; chats that several people write in;
sender pays, or the owner sets an amount that only the others draw from,
with a bar and an automatic switch back; no personal memories in shared
chats; apps off by default, per person and per chat, usable only by their
owner; a chip that names whose app data an answer contains; project memory
that names its member and comes only from the project; conflicts pointed
out by the model; the memory choice in a private project chat; push on the
phone, nothing in the browser.

**What is open** (☐ in §0), by weight:

1. Ask-first items: the schema (#63), the push plugin (#61), the relay
   service (#62).
2. Phase B waits for AU26 and AH8, and changes three rows of row 25 for
   chat (#22, #23).
3. The value of the cost limit and the token cap for unpriced models
   (#12, #14).
4. Shared chats: does the AI answer every message (#36), one run per chat
   with queuing (#37), the sender-only check before an app is used
   (#45), writes always ask (#46), the sender's model (#67).
5. Notion "Only these pages" after a spike (#49).
6. Private chats first in a shared project (#55); the default of the
   memory choice (#54).
7. Removing the planner card from Settings → AI Models (#20).

**Corrections against earlier statements in the chat:**

- Dropbox has no read path (save only). There is nothing to narrow.
- WebDAV reads are already limited to the connection's folder; a grant
  narrows further.
- The rolling summary uses the `ANALYZE` model, else the Chat model. The
  `SUMMARIZE` row is not read at runtime.

**Code map:** `main` @ `0d228e323`. Facts and paths in `01_research.md`
§1. Not found in the code: `AgentToolLoop`, `App\Service\Run`, `BRUNS`
(planned in rows 22 and 25), anything named project, a push plugin in
`synaplan-apps`.

**Gate for this PR:** `_devextras/**` only, class `no-app-impact`.

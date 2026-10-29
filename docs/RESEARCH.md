# Research

Research is on unless an administrator turns it off. A person can start a run,
read it, stop it and delete it. The report of a run that belongs to a chat is
written into that chat by the server, including when the browser is closed.

## Switch

| | |
| --- | --- |
| Environment | `RESEARCH_ENABLED=1` forces it on. `RESEARCH_ENABLED=0` forces it off. Empty follows the database. |
| Database | `BCONFIG` group `RESEARCH`, setting `ENABLED`, owner `0`. Installs seed `1`; `Version20261028110000` turned existing installs on once. |
| Gate | `FEATURE_MODULES_GATE_RESEARCH=true` makes the start routes answer 404 while research is off. |

While research is off, these routes answer 404: capabilities, start, a reply,
and a follow-up. Reading a run, its updates, stopping it and deleting it keep
working, so a run that was already going can still be opened and removed.

A unit that starts after research was turned off writes what it has found and
then stops with "Research was turned off on your Synaplan workspace."

## Automatic research

There is no research mode and no menu entry. The chat decides how much a
question needs, from the same `tools:sort` answer that already carries the
topic and the web-search vote: `BEFFORT` is `answer`, `lookup` or `research`,
`BEFFORT_WHY` names the one reason (`several_parts`, `comparison`,
`procedure`, `current_fact`, `asked_thorough`, `asked_short`, `simple`,
`deeper`). A sorting model that does not fill the fields counts as no vote,
and the chat answers directly — an install whose sorter cannot carry the
rating simply never researches on its own.

When the rating is `research`, the turn starts a run of depth `auto` for the
person. The stream carries `research_started` with the run id first; the
reply then carries `researchRunId` (meta `research_run_id`), live and after
a reload. In the web chat that reply bubble shows the run working — a
spinner, the last thing the run did (the query it searched, the source it
read, the section it writes), a fold with every step so far, and **Answer
now** with its consequence. **Stop** is the send button in the composer;
the live-speech button does not take that place while a run is going. The
bubble hides the fallback sentence meanwhile. The one sentence the stream writes ("I am looking through several
sources for this — about N minutes. The question has several parts.") is
what a channel without that UI gets. When the run finishes, the report
replaces that reply's text (see "Where the report goes"), so the person
reads the answer where they watched it being made; one muted line below the
report ("Researched: 12 steps · 8 sources read") opens the steps again, in
any later session too. Only an ordinary signed-in web chat turn
does this: never a widget, a saved task, an incognito chat, an "Again", a
turn with a fixed task prompt, or a channel without a chat.

A `research` turn that cannot start a run answers directly and begins with
why, in the person's language: web search off and no folder on the chat
(`no_sources`), too many runs going (`too_many`), the usage budget used up
(`budget`), the plan's research allowance used up (`plan_limit`), or the
research store not answering (`unavailable`). The reply carries `effort` and
`effortNotice`, so **Look deeper** is offered under a `lookup` answer and
under every answer that began with a limit sentence, live and after a
reload. Look deeper starts a run of depth `auto` with the hint `deeper` and
the last six turns of the chat as context.

Depth `auto` sizes itself from the plan: after planning, a plan of up to two
points is capped at the quick budget, a larger plan at the standard budget.
The plan event carries `ceiling` (`quick` or `standard`) and the minutes the
run now needs; the run state carries `ceiling` as well. The hint `brief`
(the sorter said `asked_short`) caps the plan at two points; `deeper` lets
it grow. An automatic run never asks a question. **Answer now** on the card
is the `answer_now` input above; **Stop** is the composer's send button and
keeps what was written.

| | |
| --- | --- |
| Switch | `BCONFIG` group `RESEARCH`, setting `AUTO_ENABLED`, owner `0`. Installs seed `1` (the seeder adds the missing row on existing installs too). Off, or research off (`RESEARCH_ENABLED=0` included), means the chat answers every question directly and Look deeper is absent. The admin research tab (AI infrastructure → Research) has the switch as a second row under Research: it is disabled with "Turn on Research first." while research is off, says what off means ("the chat answers every question directly; Look deeper disappears; runs already going finish"), notes when web search is off (folders only), and is audited as `research.settings.update` with the old and new value. `PUT /api/v1/admin/research` takes `autoEnabled`; while research is off it answers 409 `research_off`. |
| Capabilities | `automatic` (boolean) and `auto_limits { minutes, cost_cap }` from the standard budget. |
| Eval | `app:research:router-eval --user=<id>` rates the labelled corpus in `backend/tests/Eval/research/router.json` with that account's sorting model. Gate: agreement ≥ 90 %, over-escalation ≤ 5 %, under-escalation ≤ 10 %, no vote ≤ 2 %. |
| Gateway | `POST /v1/messages` with `x-synaplan-effort: auto` rates the last user message with the same sorter and runs the same gate (`AutoResearchGate::decideFor`). A started run answers the turn with the notice sentence as one assistant message and reports `x-synaplan-research-run`; the report stays on the run because a gateway turn has no chat. Without the header the gateway never rates. See `docs/ANTHROPIC_COMPATIBLE_API.md`, "Effort". |

## Routes

The same actions are on `/api/v1/research` (signed-in app) and `/v1/research`
(an API key with `desktop:messages`).

| Method | Path | When research is off |
| --- | --- | --- |
| GET | `research/capabilities` | 404 |
| POST | `research` | 404. Header `Idempotency-Key` (1–64 of letters, digits, `.` `_` `:` `-`) returns the same id for 24 hours. |
| GET | `research` | The caller's runs from the last 48 hours, newest first. `?active=1` lists only runs that are still going. |
| GET | `research/{id}` | That run, when it belongs to the caller. |
| GET | `research/{id}/events` | Updates so far, as server-sent events. `Last-Event-ID` or `?after=` resumes. |
| POST | `research/{id}/input` | 404 |
| POST | `research/{id}/cancel` | Asks the run to stop. |
| POST | `research/{id}/followups` | 404 |
| DELETE | `research/{id}` | Removes the run, its updates and its cached pages. |

A follow-up is a new run. It keeps the parent's sources and continues the
source, evidence and point numbers, and it stays inside the parent's world.
The parent's report is kept as the prior report, not as the new run's
report, so a follow-up that stops early never shows the parent's text as
its own. The parent has to be finished; until then the answer is 409
"Wait until this research finishes. Then you can ask a follow-up." The
`Idempotency-Key` header works as on start, so a double click starts one
follow-up.

| Type | Text | What it does |
| --- | --- | --- |
| question | required | A quick run. When at least half of the question's words appear in the findings or the report, it answers from them. Otherwise it searches once for the question. A completed question with an answer sets `can_add_to_report`, so a later screen can offer Add to report. This app does not show that button yet. |
| revise | required | Rewrites the report from the instruction (shorter, for the board, a translation, a table, one section). A cited sentence with the same words and the same citations keeps its verdict and is not checked again; any other cited sentence is checked. A parent without a report answers 409. |
| deeper | optional | Uses the thorough budget. Each line under Not confirmed (up to eight; the closing count is not a line) becomes a plan point, and so does the text. With neither, the parent question is searched again. |
| refresh | optional | Reads sources dated before the start of the brief's time frame again, and sources without a date, and says what changed. With no start date, it reads every source that was already read again. |
| answer_now | none | Answer now on the card. While sources were read, the run writes the report from them (like write now). Before any source was read, the run ends at once with the outcome `answered_without_research` and the chat answers the question directly. While the report is being written or checked, nothing changes. |

An id that is not the caller's is 404. The session route requires `chat_id`
and the chat must belong to the caller. The API route rejects `chat_id`; the
caller keeps the report itself. Every refusal is
`{ "error": { "code", "field", "message" } }` with a sentence a person can
read; when the research store does not answer the status is 503.

Stopping a run that nobody is working on closes it at once. A step that is
already underway sees the stop before its next paid call and closes the run
itself; until then `stop_requested` is true.

A start accepts model hints, a world and starting pages and does not apply
them yet. The run uses the models configured on the installation. Reading
whole documents stays off.

After a round of searching and reading, the run judges the open points and
can search again, up to the round limit of that depth. A quick run does not
search again. The run goes to the report instead when the next round would
have nothing new to search or read, or when the model calls left could not
pay for another round and the report. A point the run calls answered
without a finding stays open. The time limit of a depth counts only the
time a step works: waiting for a worker or for the person's answer costs
nothing.

After the report is written, the run checks it. The free check runs first
and costs nothing: a cited statement must name evidence that exists, and
its numbers, dates and proper names must appear in that evidence. A
sentence that states a fact and cites nothing is removed. A second model
then reads each remaining cited statement with its passages and returns
supported, partial, unsupported or contradicted. Only a supported
statement stays in the report. The rest joins the writer's "Not
confirmed" section (what the writer already lists there is not checked
again), and the report ends with how many statements a source backs. A
batch holds at most 30 statements; when the budget runs out after the
first batch, the statements not yet checked count as not confirmed and
the report is still delivered. When the
checker and the writer are the same provider, the report says so. Standard
and quick remove every statement that is not supported. When the checker
marks a statement partial, a thorough run tries one revision first: the
partial statement keeps the part a source backs, and the closing count
says how many were shortened. Every cited sentence of the revision must be
a supported statement or such a shortened one; a reworded unsupported or
contradicted statement cannot stay cited, and nothing under Not confirmed
gains a citation. If the revision breaks one of these rules or adds a
fact, the removal stands and the person still gets the report.

When `RESEARCH.LEAD_MODE` is `auto`, a model on `RESEARCH.TOOL_MODE_MODELS`
uses that same round to call search, read, ask or finish itself. The list is
empty until a model passes the conformance suite, so a run keeps judging
the round. Each tool stops at the budget and returns a short result, never
the page. Search results are not evidence: a finish before any source was
read is refused once while a found source can still be read. A question, and a thorough plan, wait with no worker. The run's
state lists the open questions, so a client that reloads can still answer
them. An open question is skipped after roughly 10 minutes (the reaper runs
every 5 minutes) and the run continues. A plan that is not confirmed within
a day stops. An edited plan keeps at most the depth's number of points, and
a new or renamed point is searched by its title. Write now ends the search
and writes the report from what was already found. When the next step has
waited 20 seconds for a worker, the state carries `queued_since` and the
events end with a `queued` frame. Both are computed on each request and
never recorded, so they are as fresh as the client's poll. Research as a whole is off while
`RESEARCH.ENABLED` is `0`.

The conformance suite plays ten scenarios twice against one model — start
with a search, search in parallel, read what was found, react to a refused
query and a spent budget, finish honestly. Search, pages and the reader are
test doubles, so only the named model is called, twenty times, billed to
the account passed with `--user`:

```bash
docker compose exec backend php bin/console app:research:conformance groq:openai/gpt-oss-120b:chat --user=1
```

Add the key to `RESEARCH.TOOL_MODE_MODELS` (a JSON list of catalog keys,
`service:providerId:tag`) only when every turn passed.

At most `RESEARCH.MAX_ACTIVE_RUNS` runs (default 5) can be going at once for
one person, and at most `RESEARCH.MAX_ACTIVE_THOROUGH_PER_USER` thorough runs
(default 1).

## Where the report goes

When the run has a `chat_id`, the server writes one assistant message into
that chat however the run ends, in the person's language. A run the chat
started on its own already has a reply — the one stamped with the run id
(`research_run_id`) — and that reply's text becomes the report; a run
started by **Look deeper** or the research API gets a new reply carrying the
same stamp, which is how the chat shows the run and later its steps:

- **Complete:** the report is the message, and the markdown file is attached
  to it. The file also shows in the file manager's Generated gallery with
  "Open in chat".

The person reads the **reader's report**, not the checked report the run
keeps (`report.markdown` in the run's state). The reader's report answers
the question only: the "Not confirmed" section, the closing count and the
same-provider sentence stay out of the message, a heading the check emptied
goes with them, and every evidence id becomes the number of its source in
the order the sources first appear (`[e12][e31]` reads as `[1][2]`). The
sources are stored under the question like web-search results, so the chat
shows them beneath the reply and the numbers link to them; the attached
file lists them at the end. What the check removed, and whether a model of
the same provider checked, is told in the research fold under the reply
(`check` event: `statements`, `supported`, `softened`, `removed`,
`same_provider`), and **Look deeper** still seeds its plan from the checked
report's "Not confirmed" list. The reply's info panel shows what the run
spent: every model call is summed per role and model (`LEAD`, `READ`,
`WRITE`, `VERIFY`, `SORT`) next to the sorting that started the run, with
the cost the person was charged. Tokens and cost on a research reply are
the run's, not just the one chat call.
- **Stopped or failed with something found:** the sentence why it ended
  stands above the report, so a partial report never reads as a finished one.
- **Stopped or failed with nothing found:** only that sentence, no file.

This holds for a stop by the person, by an administrator, a plan that was
not confirmed in time and a run the reaper had to interrupt. An API run has
no chat, so nothing is posted. Deleting a run keeps a message that was
already posted.

Deleting the account removes that person's research keys after the account
rows are committed.

## Saved tasks

A saved task can take a `research` step when `MULTITASK.RESEARCH_ENABLED` is on
and research itself is on. The step starts a quick or standard run in the
task's chat, then waits. The research units keep running on their own worker,
so the saved-task worker is free. When the report is ready, the next step
(for example "email me") receives the report text and the markdown file.
A run that does not finish fails the step with its sentence, and later steps
skip. The step does not ask questions and cannot be thorough.

The planner does not see the step while the flag is off, and never offers it
to a normal chat turn (the chat decides on its own when a question needs a research). Closing the window
does not stop the research. The report still appears in the chat, and so does
what the later steps produce.

The hand-over runs on the worker (`ContinueSavedTaskAfterResearch`), never in
the request that stopped the run. Stopping or deleting the research fails the
waiting step with one sentence. When the hand-over keeps failing for thirty
minutes, the step fails too, so a saved task never waits forever. Later steps
run under the unattended policy, because nobody is watching when the report
arrives. While the run waits, the saved task's approval badge does not count
it: nothing waits for a decision.

## Operator

The system status page shows how many research runs are in progress when the
switch is on. There is no separate health-probe row: a stuck run is recovered
by `app:research:reap`, which already runs on the scheduler tick.

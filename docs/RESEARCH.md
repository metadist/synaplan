# Research

Research is off until an administrator turns it on. A person can start a run,
read it, stop it and delete it. The report of a run that belongs to a chat is
written into that chat by the server, including when the browser is closed.

## Switch

| | |
| --- | --- |
| Environment | `RESEARCH_ENABLED=1` forces it on. `RESEARCH_ENABLED=0` forces it off. Empty follows the database. |
| Database | `BCONFIG` group `RESEARCH`, setting `ENABLED`, owner `0`. New installs seed `0`. |
| Gate | `FEATURE_MODULES_GATE_RESEARCH=true` makes the start routes answer 404 while research is off. |

While research is off, these routes answer 404: capabilities, start, a reply,
and a follow-up. Reading a run, its updates, stopping it and deleting it keep
working, so a run that was already going can still be opened and removed.

A unit that starts after research was turned off writes what it has found and
then stops with "Research was turned off on your Synaplan workspace."

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
never recorded, so they are as fresh as the client's poll. Research as a whole stays off until
`RESEARCH.ENABLED` is turned on.

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

When the run has a `chat_id`, the server posts one assistant message into
that chat however the run ends, in the person's language:

- **Complete:** the report is the message, and the markdown file is attached
  to it. The file also shows in the file manager's Generated gallery with
  "Open in chat".
- **Stopped or failed with something found:** the sentence why it ended
  stands above the report, so a partial report never reads as a finished one.
- **Stopped or failed with nothing found:** only that sentence, no file.

This holds for a stop by the person, by an administrator, a plan that was
not confirmed in time and a run the reaper had to interrupt. An API run has
no chat, so nothing is posted. Deleting a run keeps a message that was
already posted.

Deleting the account removes that person's research keys after the account
rows are committed.

## Operator

The system status page shows how many research runs are in progress when the
switch is on. There is no separate health-probe row: a stuck run is recovered
by `app:research:reap`, which already runs on the scheduler tick.

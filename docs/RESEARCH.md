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
| GET | `research` | The caller's runs from the last 48 hours. |
| GET | `research/{id}` | That run, when it belongs to the caller. |
| GET | `research/{id}/events` | Updates so far, as server-sent events. `Last-Event-ID` or `?after=` resumes. |
| POST | `research/{id}/input` | 404 |
| POST | `research/{id}/cancel` | Asks the run to stop. |
| POST | `research/{id}/followups` | 404 |
| DELETE | `research/{id}` | Removes the run, its updates and its cached pages. |

An id that is not the caller's is 404. The session route requires `chat_id`
and the chat must belong to the caller. The API route rejects `chat_id`; the
caller keeps the report itself.

A start accepts model hints, a world and starting pages and does not apply
them yet. The run uses the models configured on the installation. Reading
whole documents stays off.

At most `RESEARCH.MAX_ACTIVE_RUNS` runs (default 5) can be going at once for
one person, and at most `RESEARCH.MAX_ACTIVE_THOROUGH_PER_USER` thorough runs
(default 1).

## Where the report goes

When the run has a `chat_id`, the closing step saves the report as a markdown
file and posts it as the assistant's message in that chat. An API run has no
chat, so nothing is posted.

Deleting the account removes that person's research keys after the account
rows are committed.

## Operator

The system status page shows how many research runs are in progress when the
switch is on. There is no separate health-probe row: a stuck run is recovered
by `app:research:reap`, which already runs on the scheduler tick.

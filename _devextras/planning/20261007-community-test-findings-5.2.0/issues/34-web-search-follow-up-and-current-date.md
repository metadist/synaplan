<!-- title: Web search: when results are generic or conflict, run a follow-up search or open the primary source; make sure the planner and the search step know today's date -->
<!-- type: Feature -->
<!-- labels: prio:2, area:routing -->
<!-- issue-type: Feature -->

## Summary
The web search step gets one bounded follow-up: when the first results are generic, stale or contradict each other, the planner issues a second, narrower search or fetches the primary source (release page, official site) before answering; the current date is part of the search and plan prompts, not only the chat system prompt.

---

## Problem / Motivation
Same three current-events questions in both apps (Tavily with Firecrawl fallback in both). Both answered the first two correctly. On "latest releases of Open WebUI and Synaplan", Synaplan gave an outdated version from a stale page, said its sources conflict, and could not date its own release, while Open WebUI opened the GitHub release pages and answered correctly. Synaplan's Sources carousel with numbered citations and per-answer timing are good patterns to keep (F39).

---

## Goal
When the first search is not good enough, the system notices and does one more targeted step instead of explaining the conflict to the person.

---

## Acceptance criteria
- [ ] The DAG is fixed before search runs, so the planner cannot add a step after it sees the results. Pick one mechanism before coding, and test that one: a quality check inside the search runner that does one narrower search or one URL fetch itself (at most one extra call, shown on the task card); an optional retry node that is already in the plan and runs only on a weak signal; or real replanning. A quality signal (result count, recency spread, domain diversity, contradiction flag) feeds that mechanism. Do not write the step as "the planner adds a follow-up" — the executor does not replan today.
- [ ] Recency preference: when the question is about "latest / current / today", results are ranked by date when the provider returns one, and the model is told the date of each source.
- [ ] The current date and the person's time zone are in the plan and search prompts (the chat system prompt already has a date block — reuse `ChatHandler`'s builder).
- [ ] Characterization case for the "latest release" question shape.

---

## Notes
- Findings: F39 — community test round on 5.2.0.
- Review correction (planning PR #2378): returning control to the planner after search does not work. `DagExecutor` runs the plan that was fixed up front.
- Code: `backend/src/Service/Multitask/Execution/Runner/WebSearchRunner.php`, `UrlFetchRunner.php`, the planner prompt, `backend/src/Plug/WebSearch/Adapter/*`; date block builder in `backend/src/Service/Message/Handler/ChatHandler.php` (~line 485 "Build the current date/time block").
- Journey (U10): "What is the latest Synaplan release?" → search → follow-up fetch of the GitHub releases page → answer with version, date and the release page as source.

---

## Screenshots/Logs
—

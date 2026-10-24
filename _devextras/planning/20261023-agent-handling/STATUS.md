# Agent handling — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| AH0 | draft | — | plan written 2026-10-23; §0 ticked the same day |
| AH1–AH23, AHX1–AHX2 | not started | — | plan only until the owner orders row 25 |

---

## 2026-10-23 — owner decision session

All 30 §0 rows agreed as written (record under the §0 table). Approved
ask-first rows: `BRUNS` as one table for assistant and research runs (#9),
the two compose flag lines (#24), free level 5 runs per day without helpers
(#29). Copy: the words of `02` §4 and the "Actions" rename (#3, #4). Start:
none — plan only. Research quality's STATUS records that `BRUNS` replaces its
`BRESEARCHRUNS` alternative.

---

## 2026-10-23 — research and code map (AH0)

**External research** (sources in `01_research.md`):

- Hermes Agent (Nous Research, MIT, about 256,000 stars): a single-user,
  local-first agent with a learning loop (bounded memory files, self-written
  skills behind an optional review gate, session search), delegation with
  inherited tool sets and structured output, a Kanban board of named profiles
  with a dispatcher, Bot Mode with group rooms, 20+ channels, cron, a Runs
  API with stop / approval / clarify, approvals `smart | manual | off` with
  headless deny.
- omp (Stencil Labs, MIT, about 36,000 stars): a terminal coding agent; typed
  sub-agent results in isolated worktrees, Agent Hub (live roster, steer,
  revive, kill), bundled specialists defined as Markdown, an advisor model on
  every turn, nine model roles with fallback chains, preview-then-accept,
  SDK / RPC / ACP entry points.
- Both speak the OpenAI and Anthropic wire formats and MCP, and both use the
  Agent Skills format (donated to the Agentic AI Foundation on 2026-10-22,
  70+ platforms).

**Code map of `main`** (read-only, file references in `01` §5). The facts
that shape the plan:

- Assistants (`BAGENTS`, `BAGENTVERSIONS`, `agent.v1`) are versioned,
  published, shared, clonable, exported; definition has no helpers, no
  per-assistant memory (`behaviour.memory` = `user` only), three model roles.
- Web chat plans once (`TaskPlanner`, ≤ 24 steps; `DagExecutor` sequential,
  media optionally parallel at 3); model-driven tool loops only on the two
  gateways (8 rounds, 240 s) and for document tools; Desktop's loop 40 rounds.
- `SavedTaskRun::STATUS_CANCELLED` is declared and never set; no cancel route
  in `SavedTaskController`.
- No page lists running work; realtime push only for approvals.
- No server-side `SKILL.md`; Desktop has 14 bundled skills in the format.
- Telegram has no assistant binding; WhatsApp one per person.
- `UserMemory.BNAMESPACE` exists (used for hidden feedback namespaces).
- The builder already uses "Fähigkeiten / skills" for planner capabilities
  (`assistants.json` → `skill.*`).
- Hermes, omp and Open WebUI are named nowhere in `docs/`; the gateways and
  `/mcp` serve them technically.

**Verdict recorded in the plan:** use them as clients now (Phase 0); do not
embed them as the engine (§0 #1); build the agent layer natively on the
architecture update's one loop and research quality's run kernel.

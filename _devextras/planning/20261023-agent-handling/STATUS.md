# Agent handling — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| AH0 | draft | — | plan written 2026-10-23; §0 ticked the same day |
| AH8a | merged | #3198 | run kernel `App\Service\Run\*` and `BRUNS` (migration `Version20261119150000`, `BNOTES`, `BKIND` `assistant` · `research` · `chat`), `RunEraser`, kernel reapers in `app:runs:reap`; no consumer yet. See 2026-11-19 below |
| AH8b | merged | #3201 | research runs on the kernel, no behaviour change; same Redis keys and stored format. See 2026-11-19 AH8b below |
| AH1–AH7, AH9–AH23, AHX1–AHX2 | not started | — | plan only until the owner orders them |

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

---

## 2026-11-19 — AH8a: run kernel and `BRUNS`

Branch `refactor/run-kernel` off `main` @ `2ed58677e`. The kernel is the
research engine's machinery with the research rules taken out:

- `RunService` (start with a 24 h `Idempotency-Key`, stop as a flag),
  `RunUnit` (claim, reload, check the stop, run, fenced checkpoint, next
  unit), `RunClaim`, `RunCheckpointStore`, `RunEventLog`, `RunReaper`,
  `RunDelivery` (into the chat once, on the server), `RunKeyRemoval`.
- What differs per kind sits behind `RunState` / `RunStateCodec`,
  `RunUnitHandler` and `RunReapPolicy`, so research keeps its stored format
  in AH8b.
- The Lua scripts for claim, renew, get and delete-if-equals are the
  research engine's, byte for byte (`RedisRunKeysTest`). The checkpoint
  script also writes without expiry, because a run that is not terminal has
  no TTL (§2.2); the research engine still refreshes its 48 h TTL.
- Budgets are not in the kernel: a kind's handler gets them as profile input.
- `BRUNS` follows `03` §2.1 plus `BNOTES` (mediumtext null) and `chat` among
  the `BKIND` values. No unique index on `(BUSERID, BIDEMPOTENCY)`: the key is
  reused after 24 h, and Redis holds the 24 h window.
- `app:runs:reap` runs every service tagged `app.run_reaper`; none is tagged
  yet. The Messenger transport for `RunUnitBus` and `app:runs:prune` come
  with the first consumer.

Tests first, `tests/Unit/Service/Run` (72 tests): two workers claim one unit
and one executes; a lease that ran out cannot checkpoint; the reaper
redispatches a lost unit once, then interrupts and delivers; a stalled run;
a waiting run is left to its policy; a repeated start with the same key
opens nothing; a stop before or during a unit means no paid call; a run the
reaper ended reaches the chat exactly once with the tab closed. Writing them
found two defects, fixed here: id and seq patterns accepted a trailing
newline (`$` without `D`), and one unreadable run stopped the whole reaper
pass.

---

## 2026-11-19 — AH8b: research runs on the kernel

Branch `refactor/research-on-run-kernel` off `main` @ `520b81036`. The
research engine keeps its classes, constants and stored format; its run
machinery is now the kernel's:

- `ResearchRunState` implements `RunState`; `ResearchRunCodec` reads it.
  `ResearchStateStore` is a façade over one `RunCheckpointStore` with the
  research keyspace (`RunKeyspace('research', …, ['state' => 'run',
  'cancel' => 'stop'])`), so every key keeps its name: `research:run:{id}`,
  `research:stop:{id}`, `research:claim:{id}:{seq}`, `research:claims`,
  `research:active`, `research:events:{id}`.
- `ResearchUnitWorker` runs `RunUnit` with a `ResearchUnitHandler`;
  `ResearchReaper` runs `RunReaper` with a `ResearchReapPolicy` (answer wait
  600 s, plan wait 24 h, write-now, report on the first interrupt).
  `ResearchLease` is gone; a stage holds the kernel's `RunClaim`.
- `RedisResearchRunKeys` keeps only the source-text script and delegates the
  rest to `RedisRunKeys`; the duplicated Lua is gone.
- The kernel learned what research needs: renamed key segments, a sliding
  expiry while a run is active (`activeTtlHours`, research keeps 48 h and its
  `expires_at`), a hook after every write (research keeps its per-user index),
  and a codec that may answer "no run".
- `app:research:reap` now counts `waited` instead of `continued` and
  `stopped`.

**One accepted edge across the deploy:** the lost-claim mark moves from
`research:reap:{id}:{seq}` (`count|at`) to the kernel's
`research:reap:{id}` (`seq|count|at`). A unit lost in the minutes around the
deploy can be redispatched once more than before; the run still ends as
interrupted on the next loss. `delete()` removes both keys.

Gate: `make ci-local` (PHPUnit 12462, Vitest 539 files, PHPStan and Deptrac
0). The existing research tests pass unchanged except for the reaper count
name and the 4-argument `appendEvent`; new kernel tests cover the segment
names, the active expiry, the write hook and the "no run" codec answer.

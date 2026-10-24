# Agent handling — assistants that work: runs, helpers, notes and skills

**Status:** Draft 2026-10-23, written from the owner's question of the same
day: *"Our agent handling is not great. Research clients like Hermes and
OMP.sh. Is that functionality easy to add in Synaplan? Can we use their
agents and improve the user interface by offering easy ways of agent
handling?"* The research is in [`01_research.md`](./01_research.md).
**§0 ticked 2026-10-23** in the owner's decision session (record below the
table). **Plan only:** no step starts until the owner orders it.
**Priority:** Row 25 of [`../ROADMAP.md`](../ROADMAP.md), not started. Its
place among the queued rows is the owner's call. When it is ordered, Phase 0
and Phase A touch no part of the two running overhauls and can go first;
Phase B waits for them (§0 #5, #6).
**Goal:** A person who is not technical hands an assistant a job that takes
more than one answer — "compare the three offers in my folder and draft a
reply to the cheapest" — and the assistant works through it on the server:
it plans, uses tools, asks when it must, hands parts to helper assistants,
gets its work double-checked, and ends with a result and one honest sentence.
The person can close the tab, find every running job in ten seconds, give it
a hint, stop it, and see what it noted and which skills it used — and the
next run is better because of it.

1. **Phase 0 — open the door and measure (AH1–AH3).** Hermes Agent and omp use
   Synaplan as model provider and MCP server today; prove it, document it,
   and record how well today's paths handle multi-step jobs.
2. **Phase A — quick wins on today's paths (AH4–AH7).** Stop a running Saved
   Task, see it live, talk to an assistant in Telegram, create an assistant
   from one sentence.
3. **Phase B — assistant runs (AH8–AH13).** A server-side run on the one agent
   loop of the architecture update, sharing the run kernel of research
   quality; a run card in the chat; the **Runs** page under Assistants.
4. **Phase C — notes and skills (AH14–AH18).** Notes per assistant, skills in
   the open Agent Skills format, and improvements the assistant proposes and
   a person accepts.
5. **Phase D — helpers and checks (AH19–AH22).** An assistant hands part of a
   job to a helper assistant; a second model checks the work; limits for
   administrators.
6. **Phase E — rollout (AH23).** The eval beats today's paths; flags on for new
   installs.

**Why now:** Synaplan already has the strongest *frame* around an agent —
versioned, published, shared, clonable assistants with triggers, channels,
approvals, IAM, budgets and sovereignty (`01` §6). What it lacks is what
happens *while the agent works*: a loop that reflects instead of a one-shot
plan, helpers, a place that shows running work, a Stop for scheduled runs,
memory per assistant, open-format skills. The two overhauls in flight build
exactly the foundation this needs — one tool invoker and one agent loop
(architecture update AU24–AU26), long waits out of PHP (AU27–AU32), and a
durable server-side run with units, claims, a reaper and an event log
(research quality RS6). This plan is the product layer on top, so it is
medium-sized, not a rewrite.
**Answer to "can we use their agents?":** as **clients of Synaplan, yes, now**
(Phase 0). As **Synaplan's engine, no** — both assume one trusted person with
a shell, and embedding them would create a second AI path, a second tool
path, second approvals and second metering (`01` §7, §0 #1). A person's own
Hermes as an "outside assistant" is §9 #1, behind a spike.
**Binding UX:** [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) (U1–U12),
rail and patterns of §2 and §4 (Share, Something arrived, Decide in context,
Gallery, Chat-native results, Triggers). Surfaces in AH5–AH7, AH11, AH12,
AH15, AH17, AH18, AH20, AH22.
**Class:** per step in §5. Backend steps `backend-only`, UI steps
`ota-candidate`, test and docs steps `no-app-impact` / docs. Nothing
`store-required`.
**Prerequisite:** none for Phase 0 and A. Phase B needs AU24 and AU26 merged
(one tool invoker, one agent loop), AU11 (metering in the pipeline) and the
run kernel decision (§0 #5). The ask-first rows §0 #9 (schema), #24
(compose lines) and #29 (business) were approved on 2026-10-23.
**Serves:** use cases 2 (inbox triage), 3 (regulation watch), 5 (tender
matrix), 7 (recurring reports) and 10 (sovereign expert research) of
[`../20260913-use-case-research/README.md`](../20260913-use-case-research/README.md),
and the archived agent builder's open items: "Help me write" (AB14), agents
calling agents, shared assistant memory (its v2).

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Start here: decisions, facts, target shape, journeys, step table, gates, risks |
| [`01_research.md`](./01_research.md) | Hermes Agent and omp in detail, the shared standards, Synaplan's code map, side-by-side table, the four integration options, patterns to adopt and to avoid |
| [`02_journeys_and_ui.md`](./02_journeys_and_ui.md) | Journeys J-AH-1…10, wireframes, copy in five locales, the five questions, empty / error / flag-off states |
| [`03_contracts.md`](./03_contracts.md) | Run states and events, endpoints, the loop's tools, `agent.v2`, notes, skills, proposals, BCONFIG keys, eval corpus |
| [`04_sprints.md`](./04_sprints.md) | One brief per step: goal, reads, tests first, touches, exit |
| [`STATUS.md`](./STATUS.md) | Step log, the 2026-10-23 code map, measured numbers |

---

## Start here (60 seconds)

**What we build, in one picture:**

```text
today                                          after this track
-----                                          ----------------
Chat ─► planner plans once (≤ 24 steps)        Chat ─► [Let it work] ─► assistant run
        ─► steps run once ─► answer                    plan · act with tools · look at result · next step
                                                       ask you · hand a part to a helper · double-check
Saved Task ─► run ─► no Stop, no live state            ─► result + one honest sentence
                                               Saved Task step "let an assistant work" ─► same run
Assistant = instructions + models + tools      Assistant = the same + helpers + skills + notes + limits
            + knowledge + triggers
Nothing lists running work                     Assistants → Runs: every run, live, Hint / Stop,
                                                       "Needs you" badge, history
Memories: per person only                      + notes per assistant (yours, private; the owner's, shared)
Skills: Desktop only                           + skills in the Agent Skills format for every assistant,
                                                 imported, shared, proposed by the assistant, accepted by you
```

**Where it lives today:**

| You want to change… | Go to… |
| ------------------- | ------ |
| Assistant definition and validation | `backend/src/DTO/AgentDefinitionV1.php`, `Service/Agent/Definition/AgentDefinitionValidator.php` (779), `AgentDefinition.php` |
| Assistant runtime profile | `Service/Agent/AgentRuntimeResolver.php`, `RuntimeProfile`, `Service/Agent/Policy/*` |
| Assistant API | `Controller/AgentController.php` (921, size ledger — new endpoints go to new controllers) |
| Pinning in chat | `Service/Chat/Stream/StreamAgentPinResolver.php`, `Service/Message/Classification/AgentPinRoute.php`, `frontend/src/composables/usePinnedAssistant.ts` |
| One-shot planner and steps | `Service/Multitask/TaskPlanner.php`, `Execution/DagExecutor.php`, `Execution/Runner/*` (23) |
| Tool loops (to be unified by AU26) | `Service/Document/ChatToolLoop.php`, `AI/Messages/Tools/GatewayToolLoop.php`, `AI/OpenAI/OpenAiGatewayToolLoop.php` |
| Tool policy and approvals | `Service/Tool/ToolRegistry.php`, `ToolExecutionGate.php`, `Policy/ApprovalPolicy.php`, `ApprovalService.php`, `ApprovalRealtimeNotifier.php`, `frontend/src/components/config/ApprovalsInbox.vue`, `components/chat/ApprovalCard.vue` |
| Saved Tasks | `Entity/SavedTask.php`, `SavedTaskRun.php`, `Service/SavedTask/*`, `Controller/SavedTaskController.php`, `frontend/src/components/config/SavedTaskCard.vue` (703) |
| Memories | `Service/Memory/UserMemoryService.php` (1,022), `Entity/UserMemory.php` (`BNAMESPACE`), `Service/Memory/ChatMemoryContextLoader.php` |
| Channels | `Service/WhatsApp/WhatsAppAgentBinding.php`, `Service/Telegram/TelegramInboundService.php`, `TelegramTurn.php`, `Module/Channel/TelegramModule.php`, `Service/Widget/WidgetAgentRuntime.php`, `Service/Email/InboundEmailHandlerService.php` |
| Gateways and MCP server | `Controller/OpenAICompatibleController.php`, `AI/Messages/MessagesGateway.php`, `Mcp/McpServerFactory.php`, `Service/Agent/AssistantAliasResolver.php` |
| Builder and gallery | `frontend/src/components/assistants/*`, `views/AssistantsView.vue`, `stores/agents.ts` |
| Chat step cards | `frontend/src/components/multitask/TaskCard.vue`, `TaskPlanBubble.vue`, `components/chat/ProcessingTimeline.vue` |
| Desktop skills (the format precedent) | `synaplan-desktop/src-tauri/synaplan-core/src/skills.rs`, `skills/bundled/*/SKILL.md`, `docs/SKILLS.md` |

**The loop that keeps you safe:** measure (AH3) → quick wins that turn a
named journey green → the run behind a default-off module, on the shared
kernel and the one tool invoker → eval beats today's paths → UI → seeded on.
Never skip a rung. An assistant that says "done" when something did not
happen is worse than no assistant runs at all.

**Rules for the AI assistant driving this:**

- One step = one PR. The commit title comes verbatim from §5; the type
  decides the release version (`feat` raises the minor).
- **No new tool loop, no new AI path.** Runs call models through `AiFacade`
  (the pipeline of AU10) and tools through `ToolInvoker` (AU24) on the one
  agent loop of AU26. If a step seems to need its own loop, stop and ask.
- **No second run machinery.** Units, claims, leases, checkpoints, the event
  log, the reaper and server-side delivery come from the shared run kernel
  (§0 #5). Research and assistant runs differ in their *units*, not in their
  plumbing.
- `AgentController`, `AiFacade`, `GatewayToolLoop`, `MessagesGateway`,
  `McpServerFactory`, `ChatView.vue` (4,466), `ChatMessage.vue` (2,373) and
  `SavedTaskCard.vue` only shrink or stay. New code goes into new classes and
  components (size ratchet, deptrac).
- Never hardcode a model name. Roles resolve through `ModelConfigService` and
  catalog keys, exactly like `DefaultModelConfigSeeder`.
- Every write a run makes passes the approval policy; a run started by a
  schedule, mail rule or web hook follows `allowUnattended` and otherwise
  waits in the Approvals inbox (U4.3). Headless never auto-approves.
- Web pages, files, mails and imported skills are untrusted data. A skill's
  text is instructions to the model, never code the server runs; scripts run
  only in Secure Compute (§0 #16).
- Copy: "Assistant" is the user word (archived builder §0 #1). The words of
  §0 #3 are the only new ones. Never `agent`, `subagent`, `loop`,
  `orchestrator`, `delegate`, `iteration`, `kanban`, `cron`, `token`, `node`,
  `sandbox` in primary copy; `MCP` only on its own row. Five locales in the
  same PR.
- Do not touch `frontend/src/stores/config.ts` or any `*RuntimeConfig*` file
  (store-required). Capabilities come from `GET /api/v1/runs/capabilities`.
- Flag off ⇒ `RoutingCharacterizationTest` snapshots unchanged, new routes 404,
  no UI (U11), and a person's existing runs stay readable and deletable.
- CI never reaches a live provider or the web: `TestProvider` mocks and the
  research plan's test doubles are the only sources in PHPUnit and Playwright.
- Schema, Docker / CI and dependency changes are ask-first rows in §0. Stop
  if a step needs one that is not ticked.

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | **Build natively; Hermes and omp are clients, not the engine.** Both are MIT and public; we adopt their ideas and the open formats they use (Agent Skills, MCP), not their processes or code. Embedding either would add a second AI path, tool loop, approval model, metering and memory, and a shell per user (`01` §7, option B). | Proposed | Yes — 2026-10-23 |
| 2 | **Phase 0 opens the door:** a conformance run of Hermes Agent and omp against `/v1/chat/completions`, `/v1/messages`, `assistant:<slug>` aliases and `/mcp` on the dev stack (AH1), and a page "Use Synaplan from agent clients" (AH2). Any gateway bug found is its own `fix:` PR. No client-specific code path in the gateways. | Proposed | Yes — 2026-10-23 |
| 3 | **Words.** ~~"Assistant" stays.~~ *Superseded 2026-10-23 by the owner: the user word is **agent** ([UX polish and naming](../20261023-ux-polish-and-naming/00_master_plan.md) §0 D1); the words below stand, "helper assistant" reads "helper agent".* New user words, one per concept, five locales (`02` §4): **Run** (one piece of work an assistant does on its own; matches "View runs" on Saved Tasks), **Runs** (the page), **Let it work** (start a run), **Hint** (a message to a running run), **Needs you** (waiting for an answer or approval), **Helper** (an assistant another assistant may hand work to), **Notes** (what an assistant wrote down), **Skill** (#4), **Double-check** (#20). | Ask (copy) | Yes — 2026-10-23 |
| 4 | **"Skill" means the open Agent Skills format everywhere.** Today the builder calls planner capabilities *Fähigkeiten / skills* (`assistants.json` → `skill.*`, "Was es tun darf"). Those become **Actions** (de *Aktionen*, es *Acciones*, fr *Actions*, tr *Eylemler*) in AH17, so web, Desktop and the industry (70+ platforms) mean the same by "skill". Code names (`skills.allow`, `SkillCatalog`) stay; `agent.v2` adds `skills.packs` (#21). Alternative: keep "skill" for capabilities and call `SKILL.md` items **Playbooks**. | Ask (copy) | Yes — 2026-10-23 |
| 5 | **One run kernel for research runs and assistant runs.** Units on `async_ai_high` of at most `RUNS.UNIT_SECONDS` (120), an atomic claim per unit (`SET NX PX` with a token, fenced checkpoint writes), the event log, the reaper in the `tick` lane, idempotent start, cancel checked before every paid call, server-side delivery into the chat — research quality §0 #8, #28, #33, #36. **Whichever track reaches it first builds it as `App\Service\Run\*`; the other adopts it in the same release** (U12). If RS6 is merged first, AH8 is a small extraction; otherwise AH8 builds the kernel and RS6 starts on it. | Proposed | Yes — 2026-10-23 |
| 6 | **The run's work loop is the one agent loop of AU26** — the lead model calls tools natively, every tool goes through `ToolInvoker` (registry → gate → executor → clamp → audit → usage), and the run adds only budgets, checkpoints between steps and its own tools (#11). Models without reliable tool calling get research quality's round mode (one JSON decision per step, `RESEARCH.TOOL_MODE_MODELS` conformance list, renamed `RUNS.TOOL_MODE_MODELS`). | Proposed | Yes — 2026-10-23 |
| 7 | **Runs never hold a PHP worker.** Each step is a unit (#5); a streamed answer at the end uses the chat stream path of the architecture update (pump and watchdog after AU32, the `streams` pool before). | Proposed | Yes — 2026-10-23 |
| 8 | **Entry points in v1:** (a) **Let it work** in the chat's Tools menu and as a suggestion chip when the planner produces a plan of more than `RUNS.SUGGEST_AFTER_STEPS` (6) steps — the person chooses, the planner path stays the default; (b) a Saved Task step **Let an assistant work** (AH13), so schedules, mail rules and web hooks start runs; (c) an assistant's own triggers whose instruction is marked "works on its own". Automatic hand-over from the planner to a run is decided after AH23, with numbers. | Proposed | Yes — 2026-10-23 |
| 9 | **Runs are stored in one table, `BRUNS`** (kind `assistant` · `research`, owner, assistant id and version, chat id, origin, status, outcome sentence, ledger of done and not-done writes, step count, cost, started / finished, parent run id), so the Runs page lists runs across devices and they survive a Redis flush; events and checkpoints stay in Redis as research quality designs them (a checkpoint has no TTL until the run is terminal, then 48 h, as do the events); `BRUNS` rows are kept 90 days (`RUNS.KEEP_DAYS`) and join account erasure (`RunEraser`). Research quality §0 #7 named `BRESEARCHRUNS` as its alternative — this replaces it with one table for both. **Schema change — ask first.** Alternative: Redis only, and the Runs page shows the last 48 hours. | Ask (schema) | Yes — approved 2026-10-23 (`BRUNS`, one table for both kinds) |
| 10 | **Who a run runs as** follows UX contract §4.8 unchanged: started in a chat → the person; started by a schedule, mail rule or web hook → the owner, on its own. Budget, rate limits, memories and files are that person's. A helper run (#14) never has more rights than its caller: tools, knowledge and actions are the **intersection** of the caller's and the helper's. | Locked (UX contract) | Yes — 2026-10-23 |
| 11 | **The run's own tools** (on top of the assistant's allowed tools and actions): `ask_user` (existing card), `note` (#18), `skill_list` / `skill_view` (#16), `ask_helper` (#14), `report_progress` (a plain-language line for the timeline), `finish` (result plus outcome sentence; required to end successfully). A run that runs out of budget is wrapped up by one tool-less call that summarises what was found and what was not done — never a silent stop (Hermes `iteration_budget_exhausted` pattern). | Proposed | Yes — 2026-10-23 |
| 12 | **Budgets per run, enforced in the tool layer**, not by asking the model: steps (`RUNS.MAX_STEPS`, 30), working time (`RUNS.MAX_MINUTES`, 15; schedules 30 — counted only while the run is `running`; time in `queued` and `waiting_for_you` is excluded, so a run that waits overnight for an approval resumes with its unused minutes; the wait itself is bounded by the approval or question's own expiry, 72 h / 24 h, which ends the run as `stopped` when it passes), cost (`RUNS.MAX_COST_EUR`, 1.00), helpers per run (6), parallel helpers (3), helper depth (2); `RateLimitService::checkCostBudget()` before every paid call; at most `RUNS.MAX_ACTIVE_PER_PERSON` (3) running runs per person and `RUNS.MAX_ACTIVE` (20) per install; a run beyond the limit is `queued` with a sentence, not refused. Admins change the numbers in AH22. | Proposed | Yes — 2026-10-23 |
| 13 | **Stop and Hint always work.** Stop is checked before every paid call and between steps; a request already in flight may still be billed and the copy says so. A Hint is appended as a user message before the next step and shown in the timeline. Stop of a parent stops its helpers. | Proposed | Yes — 2026-10-23 |
| 14 | **Helpers.** An assistant may name up to five **helpers** (other assistants the owner may `use`). `ask_helper(helper, job, expected_result_schema?)` starts a child run with the helper's definition, the intersection of rights (#10), a share of the parent's budget, and returns a structured result (schema-validated when a schema is given, otherwise `{summary, findings[], files[]}`) — never the helper's raw transcript. Helpers cannot ask the person directly; their questions bubble up to the parent's `ask_user`. Depth 2, at most 3 at once (#12). The person sees each helper as a row in the run timeline with its cost. | Proposed | Yes — 2026-10-23 |
| 15 | **No rooms where assistants talk freely in v1** (Hermes group rooms, omp peer messages). Every helper call has a caller, a job and a result. Rooms are §9 #3. | Proposed | Yes — 2026-10-23 |
| 16 | **Skills in the Agent Skills format** ([spec](https://agentskills.io/specification)): a folder with `SKILL.md` (front matter `name`, `description`, optional `license`, `compatibility`, `metadata`, `allowed-tools`) plus optional `references/`, `assets/`, `scripts/`. Stored as a **Library folder** under the owner's `Skills` folder, so sharing, revisions and deletion reuse IAM folders and `DocumentRevision` — **no schema change**. Progressive disclosure: the run sees name and description of the assistant's skills; `skill_view` loads the body and referenced files on demand. **Scripts never run on the web server**: a skill whose `scripts/` are needed runs them only through Secure Compute `code_run` when the assistant allows code and the install has compute; otherwise the skill is "instructions only" and the card says so. Desktop keeps its own runner and format checks. | Proposed | Yes — 2026-10-23 |
| 17 | **Imported skills are untrusted.** Import from a zip, a folder upload or a public GitHub URL (fetched through the egress client and SSRF guard of AU15). The validator checks the spec (name pattern, folder name = `name`, lengths), caps the size (2 MB, 200 files, `SKILL.md` ≤ 1,500 lines), refuses executables outside `scripts/`, and runs the context-injection check the research plan uses for page text (delimited as data, no hidden instructions to call tools beyond `allowed-tools`). The card states in one sentence what the skill may do ("Instructions only", "Runs programs in Secure Compute"). A shared skill reaches other people only through the assistant or an explicit folder share. | Proposed | Yes — 2026-10-23 |
| 18 | **Notes, two kinds, both visible and deletable.** **Your notes** — what an assistant learned about working *with you* — are memories in namespace `assistant:{agentId}` under the talking person (`BNAMESPACE` exists — no schema change), private to that person, injected only when that assistant runs, bounded to `NOTES.PERSONAL_MAX_CHARS` (1,500). **Assistant notes** — what the assistant knows about its job, for everyone using it — are a `NOTES.md` file in the assistant's own knowledge folder, bounded to 2,500 characters, written only by people with `edit`; a run may *propose* a change (#19), never write it. This avoids the "two writers" leak Hermes warns about. | Proposed | Yes — 2026-10-23 |
| 19 | **Improvements are proposals a person accepts.** After a run, if `RUNS.PROPOSALS_ENABLED`, a cheap reviewer call (`tools:run_reflection`) may propose one skill (new or changed) or one change to the assistant notes, with a reason and a diff. Proposals land in the **Approvals** inbox as a new kind *Improvement* (reuse, U12) and on the run card; Accept writes a file revision (undo = restore the previous revision), Reject discards. Your own notes (#18) are written directly, like memories today, and listed for deletion. No auto-accept in v1. | Proposed | Yes — 2026-10-23 |
| 20 | **Double-check.** An assistant may switch on a second model that checks the final result — and, optionally, every write before it goes to approval — on its own context, preferring another provider (research quality `DEFAULTMODEL.VERIFY` after RS5, else `ANALYZE`). The check returns *ok*, *concern* (shown on the card, the lead may revise once) or *blocker* (the run stops before the write and asks the person). It follows the world rule. | Proposed | Yes — 2026-10-23 |
| 21 | **`agent.v2`** = `agent.v1` plus optional sections `helpers[]`, `skills.packs[]`, `notes{personal, shared}`, `run{maxSteps, maxMinutes, maxCostEur, worksOnItsOwn}`, `check{enabled, onWrites}`, `models.helper` (the cheap model for reading and small steps; maps to omp's `smol` and Hermes's auxiliary models; null = `DEFAULTMODEL.SORT`), and trigger event kind `telegram`. A v1 document is upgraded on read with defaults; export writes v2; import accepts both; `AgentDefinitionSchemaParityTest` covers both. | Proposed | Yes — 2026-10-23 |
| 22 | **Telegram reaches assistants** like WhatsApp: one assistant per person's bot (`TELEGRAM.AGENTID`), chosen on the Telegram app page and as an event trigger in the builder (AH6). | Proposed | Yes — 2026-10-23 |
| 23 | **"Help me write" ships as one sentence → a draft.** The person describes the assistant; `tools:assistant_draft` (structured output) fills name, description, instructions, starters, suggested actions, tools and helpers in the builder form. Nothing is saved until the person saves; nothing is published until they publish (archived builder §0 #10, AB14). | Proposed | Yes — 2026-10-23 |
| 24 | **Flag:** FeatureModule `AssistantRunsModule` (`backend/src/Module/Assistant/`) with BCONFIG `RUNS.ENABLED`, env pin `FEATURE_RUNS_ENABLED`, code default off; decisive config in `backend/.env.minimal` and two lines in `docker-compose.minimal.yml` (`RUNS_ENABLED: ""`, `FEATURE_MODULES_GATE_RUNS: "true"`). Skills, notes, helpers and double-check are sub-flags of the module (`RUNS.SKILLS_ENABLED`, …). | Locked (AGENTS.md); compose lines: Ask | Yes — compose lines approved 2026-10-23 |
| 25 | **Eval first.** AH3 builds an assistant-task corpus (24 multi-step jobs with planted facts, files, a mailbox, an approval and a trap) and records today's planner path (B0) and today's gateway loop with a frontier model (B1). No default flips without AH23 numbers: run mode must finish ≥ 15 points more jobs correctly than B0 at no more than twice B0's cost, and **every** run must end in a terminal state with a true outcome sentence. | Proposed | Yes — 2026-10-23 |
| 26 | **Desktop protocol stays frozen.** Desktop keeps its own agent loop for local files; it can start a server run through `/v1/runs` under the existing `desktop:messages` scope later (§9 #4). No new pairing scope, no `protocol: 2`. | Locked (Desktop C9) | Yes — 2026-10-23 |
| 27 | **No shell, browser control or computer use on the server** (omp `bash` / `browser` / `computer`, Hermes terminal backends). Programs run only in Secure Compute; pages are read through `UrlContentService`. | Locked | Yes — 2026-10-23 |
| 28 | **Mobile:** new PHP `backend-only`; UI `ota-candidate`; no change to `stores/config.ts` or `*RuntimeConfig*`. | Locked (mobile policy) | Yes — 2026-10-23 |
| 29 | **Plan limits.** A rate-limit action `ASSISTANT_RUN` counts runs per day per subscription level (`RateLimitConfigSeeder`), seeded conservatively (free: 5 per day, no helpers). Paid levels and `web.synaplan.com` are the owner's call. | Ask (business) | Yes — 2026-10-23 (free: 5 per day, no helpers; paid levels later) |
| 30 | **Documentation is part of every step:** in-repo docs per step; user docs as two `synaplan-docs` pull requests (AHX1 after Phase A, AHX2 after Phase D). | Proposed | Yes — 2026-10-23 |

**Decision record — owner session 2026-10-23.** All 30 rows agreed as
written. In particular:

- **Schema (#9):** one table `BRUNS` for assistant runs *and* research runs,
  kept 90 days. This replaces the `BRESEARCHRUNS` alternative of research
  quality §0 #7; that plan's STATUS carries the matching line.
- **Words (#3, #4):** Run / Runs, Let it work, Send a hint, Needs you, Helper,
  Notes, Double-check, Improvement as in `02` §4. "Skill" means the Agent
  Skills format everywhere; planner capabilities become **Actions** in AH17.
- **Plan limits (#29):** free level 5 runs per day, no helpers; paid levels
  and `web.synaplan.com` are set later by the owner.
- **Compose (#24):** the two flag lines in `docker-compose.minimal.yml` are
  approved.
- **Start:** plan only. No step, including Phase 0, starts until the owner
  orders row 25 among the other rows.

---

## 1. Why — facts this plan rests on

Full map with file references in [`01_research.md`](./01_research.md) §5 and
STATUS 2026-10-23.

1. **Web chat plans once and never looks back.** `TaskPlanner` (683) writes up
   to 24 steps in one call; `DagExecutor` (805) runs them in order; a failed
   step skips its dependents. Nothing re-plans after seeing a result. Only
   document tools run in a model-driven loop (`ChatToolLoop`, 8 rounds, 240 s).
2. **Three tool loops, being unified.** `ChatToolLoop`, `GatewayToolLoop`
   (1,431), `OpenAiGatewayToolLoop` (626), caps copied three times; the
   architecture update's AU24–AU26 turn them into one invoker and one loop.
3. **A running Saved Task cannot be stopped.** `SavedTaskRun::STATUS_CANCELLED`
   is declared and never set; `SavedTaskController` has no cancel route. The
   card offers on/off, Run now and Resume; runs are an on-demand list with no
   realtime push.
4. **Nothing lists running work.** Step cards live inside one chat
   (`TaskCard.vue` with a per-step Stop); no page shows what every assistant
   is doing; only approvals have realtime events (`ApprovalRealtimeNotifier`).
5. **Assistants cannot call assistants.** None of the 24 `Capability` cases
   reaches another assistant; the archived builder put it out of scope.
6. **Memory is per person only.** `behaviour.memory` accepts only `"user"`;
   memories carry a `BNAMESPACE` column already used for hidden feedback.
7. **Skills in the open format exist only on Desktop** (14 bundled
   `SKILL.md` skills, `skills.rs`); the server has none, and the builder uses
   the word "skill" for planner capabilities.
8. **Assistants pick three models** (chat, vision, vectorize); the server's
   cheap / strong split (SORT, PLAN vs CHAT, ANALYZE) is not available per
   assistant.
9. **Telegram is the one channel without assistants** (no `agent` reference in
   any Telegram file); WhatsApp binds one per person.
10. **Hermes and omp can already use Synaplan** — both speak the OpenAI and
    Anthropic wire formats and MCP; Synaplan serves all three, with
    `assistant:<slug>` aliases. No document says so (`docs/` names Claude
    Code, Cursor / Continue and Collabora only).

---

## 2. Target shape

```text
Web chat · Saved Task step · assistant trigger · (later) Desktop, /v1/runs
        │ Let it work
        ▼
RunService (App\Service\Run\*) ── shared kernel: units · claims · checkpoints · events · reaper · deliver
        │                                          (BRUNS row + Redis event log)
        ▼
AssistantRun units on async_ai_high (≤ 120 s each)
  ┌──────────────────────────────────────────────────────────────────────┐
  │ step:  lead model (assistant's chat model) ── one agent loop (AU26) ──►│
  │        ToolInvoker: assistant's tools + actions · run tools:            │
  │        ask_user · note · skill_list/skill_view · ask_helper ·           │
  │        report_progress · finish                                         │
  │        budget check · cancel check · hint inbox · checkpoint            │
  └──────────────┬──────────────────────────────┬──────────────────────────┘
                 │ ask_helper                   │ write
                 ▼                              ▼
        child run (helper assistant,      ApprovalPolicy ─► card in chat / Approvals inbox
        intersection of rights,
        structured result)
        ▼ finish
  double-check (optional, other provider) ─► result into the chat + outcome sentence
        ▼
  reflection (optional) ─► proposal (skill / assistant notes) ─► Approvals inbox, kind Improvement

Frontend: run card in the chat · Assistants → Runs (live list, Needs you badge, Hint, Stop, history)
          builder: Helpers · Skills · Notes · Limits · Double-check
```

A run produces three things: the **result** (a chat message, files in the
Library, drafts awaiting approval), the **timeline** (each step in plain
words, each tool call with its outcome, each helper with its cost), and the
**outcome sentence** that says what did and did not happen.

---

## 3. Journeys

Details, wireframes and copy in [`02_journeys_and_ui.md`](./02_journeys_and_ui.md).

| Journey | Who | Walked in |
| ------- | --- | --------- |
| **J-AH-1** — A developer points omp and Hermes Agent at Synaplan (models + knowledge) from the docs page alone | Dev | AH2 |
| **J-AH-2** — Ada stops a Saved Task that is running, from its card; the card says which steps finished and that the e-mail was not sent | Ada | AH5 |
| **J-AH-3** — Ben creates an assistant from one sentence, adjusts it, tests it, publishes it | Ben | AH7 |
| **J-AH-4** — Ada talks to her "Inbox helper" assistant in Telegram | Ada | AH6 |
| **J-AH-5** — Ada lets an assistant work on a multi-step job from chat, closes the tab, finds it under Assistants → Runs, sends a hint, approves the draft e-mail, reads the result | Ada | AH11, AH12 |
| **J-AH-6** — A scheduled run needs an approval at night; next morning Ada sees "Needs you" on Assistants, approves in the inbox, the run finishes | Ada | AH12, AH13 |
| **J-AH-7** — Chris imports a skill from a GitHub link into his "Tender reader" assistant, sees it is instructions only, uses it in a run, removes it | Chris | AH17 |
| **J-AH-8** — After a run the assistant proposes a better skill; Ada accepts it in Approvals; the next run uses it; she restores the earlier version | Ada | AH18 |
| **J-AH-9** — Ben opens his assistant's Notes, sees what it wrote about working with him, deletes one | Ben | AH15 |
| **J-AH-10** — Chris gives "Report writer" a helper "Researcher"; a run hands a part to it; the timeline shows both with their cost; double-check flags a concern and the writer fixes it | Chris | AH20, AH21 |
| **J-AH-11** — Olga (administrator) sets run limits, sees active runs, and a person over the limit gets an honest "queued" sentence | Olga | AH22 |

---

## 4. What we do not rebuild

| Already here | Use it |
| ------------ | ------ |
| Assistants with versions, publish, IAM share, clone, export, plugin packs | `Agent`, `AgentVersion`, `AgentPublisher`, `AgentKind`, `synaplan-bundle.v1` |
| Runtime profile per assistant (prompt, models, knowledge scopes, tool and action policy) | `AgentRuntimeResolver`, `RuntimeProfile`, `SkillPolicy`, `AssistantSkillGate` |
| Triggers: events and schedules | `AgentTriggerMaterializer`, Saved Tasks, mail rules, web hooks |
| Tool registry with seven sources, approval policy, inbox, chat card, expiry, digest | `ToolRegistry`, `ApprovalPolicy`, `ApprovalService`, `ApprovalsInbox.vue`, `ApprovalCard.vue` |
| One tool invoker and one agent loop (being built) | AU24–AU26 |
| One AI path with metering (being built) | AU10–AU12 |
| A durable server-side run with units, claims, reaper, events (being built) | research quality RS6, §0 #8, #28, #36 |
| Ask-the-user card with pause and resume | `AskUserRunner`, [#2385](https://github.com/metadist/synaplan/pull/2385) |
| Step cards with per-step Stop, processing timeline, "What was sent" | `TaskCard.vue`, `ProcessingTimeline.vue`, `WhatWasSentDialog.vue` |
| Memories with namespaces, extraction, injection, badges | `UserMemoryService`, `ChatMemoryContextLoader` |
| Library folders with sharing and revisions | IAM folders, `DocumentRevision`, `FileRevisionsPanel` |
| Programs in isolation | Secure Compute `code_run` (`CodeRunRunner`) |
| Agent Skills parser and format rules | `synaplan-desktop` `skills.rs`, `docs/SKILLS.md` (ported, not shared) |
| Realtime push | Centrifugo, `ApprovalRealtimeNotifier` pattern |
| Model slots with fallbacks | `ModelConfigService`, `ModelCatalog` |
| Gateways and MCP server for outside clients | `OpenAICompatibleController`, `MessagesGateway`, `McpServerFactory`, `AssistantAliasResolver` |

**Related tracks**

| Track | Relation |
| ----- | -------- |
| Row 22 — architecture update | **Foundation.** AU24–AU26 (one invoker, one loop) before AH9; AU11 (metering) before AH9; AU27–AU32 make long runs cheap. This track adds no loop, no AI path and no egress path |
| Row 21 — research quality | **Shared kernel and roles.** RS6's run machinery is the kernel of §0 #5; the tool-mode lead (§0 #2 there) is the same loop; VERIFY (RS5) is the double-check model; a research run is one kind in `BRUNS` and appears on the Runs page |
| Row 8 — prompting flow | Shares the docked result pane and "proposed change, accept / reject"; skill and notes proposals (AH18) use its diff view if it ships first |
| Row 18 — out-of-process plugins | Install-wide MCP servers become tools an assistant may use; plugin manifests may ship skills later (§9 #6) |
| Row 10 — backend integrations | AH2's page joins the "Connect an app" catalog when it exists |
| Row 23 — per-user budget | Run cost counts against the person's budget; no separate assistant budget in v1 |

---

## 5. Step table

| Step | Commit title | Class | Depends |
| ---- | ------------ | ----- | ------- |
| AH0 | `docs(planning): agent handling — assistants that work, with runs, helpers, notes and skills` | no-app-impact | — |
| **Phase 0 — open the door and measure** | | | |
| AH1 | `test(gateway): Hermes Agent and omp against the gateways and the MCP server` | no-app-impact | AH0 |
| AH2 | `docs: use Synaplan from Hermes Agent, omp and other agent clients` | no-app-impact | AH1 |
| AH3 | `test(assistants): an assistant-task corpus and today's numbers` | no-app-impact | AH0, §0 #25 |
| **Phase A — quick wins on today's paths** | | | |
| AH4 | `feat(tasks): stop a Saved Task run while it runs` | backend-only | — |
| AH5 | `feat(tasks): live status and Stop on the task card` | ota-candidate | AH4 |
| AH6 | `feat(telegram): talk to an assistant in Telegram` | ota-candidate | §0 #22 |
| AH7 | `feat(assistants): describe an assistant in one sentence and get a draft` | ota-candidate | §0 #23 |
| **Phase B — assistant runs** | | | |
| AH8 | `refactor(runs): one run kernel for research and assistant runs` | backend-only | §0 #5, #9; AU24; RS6 if merged |
| AH9 | `feat(assistants): an assistant works through a job step by step` | backend-only | AH8, AU26, AU11, AH3, §0 #6–#13, #24 |
| AH10 | `feat(runs): list, follow, hint and stop runs through the API` | backend-only | AH9 |
| AH11 | `feat(chat): let an assistant work on it, with a run card in the chat` | ota-candidate | AH10, §0 #3, #8 |
| AH12 | `feat(assistants): the Runs page — everything your assistants are doing` | ota-candidate | AH10 |
| AH13 | `feat(tasks): a Saved Task step that lets an assistant work` | backend-only | AH9 |
| **Phase C — notes and skills** | | | |
| AH14 | `feat(assistants): an assistant keeps notes about the job and about working with you` | backend-only | AH9, §0 #18 |
| AH15 | `feat(assistants): see and delete what an assistant noted` | ota-candidate | AH14 |
| AH16 | `feat(skills): skills in the Agent Skills format for assistants` | backend-only | AH9, §0 #16, #17 |
| AH17 | `feat(assistants): add, import and share skills` | ota-candidate | AH16, §0 #4 |
| AH18 | `feat(assistants): an assistant proposes an improvement after a run, you decide` | ota-candidate | AH14, AH16, §0 #19 |
| **Phase D — helpers and checks** | | | |
| AH19 | `feat(assistants): an assistant hands part of a job to a helper` | backend-only | AH9, §0 #14 |
| AH20 | `feat(assistants): helpers in the builder and a starter team` | ota-candidate | AH19, AH12 |
| AH21 | `feat(assistants): a second model double-checks the work` | backend-only | AH9, §0 #20; RS5 or the ANALYZE fallback |
| AH22 | `feat(admin): limits for assistant runs` | ota-candidate | AH9, §0 #12, #29 |
| **Phase E — rollout** | | | |
| AH23 | `test(assistants): runs beat today's paths and the module goes on for new installs` | backend-only | AH9–AH21, §0 #25 |
| **User docs** (in `synaplan-docs`, §0 #30) | | | |
| AHX1 | `docs: stop a running task, assistants in Telegram, create an assistant from a sentence` | docs | AH5–AH7 |
| AHX2 | `docs: let an assistant work — runs, helpers, notes, skills and double-check` | docs | AH20–AH22 |

Phase 0 and Phase A are useful on their own and independent of rows 21 and
22: developers get a documented way in, every person can stop a runaway
task, Telegram users get assistants, and new assistants take a minute
instead of seven form sections. AH3 runs before the engine so AH23 has a
baseline. Phase B starts when AU26 has merged; if RS6 is still open then,
AH8 builds the kernel and the research plan's RS6 is re-pointed at it in the
same week (decision log line in both STATUS files).

**Rough size** (one developer with an AI assistant, after the foundations):
Phase 0 — 3 days; Phase A — 1.5 weeks; Phase B — 3 weeks; Phase C — 2.5
weeks; Phase D — 2 weeks; Phase E — 3 days. These are planning guesses;
STATUS records the real ones.

---

## 6. Gates

Per commit, unfiltered:

```bash
make ci-local            # lint, phpstan (src + tests), deptrac, PHPUnit, Vitest, vue-tsc
```

Per pull request, in addition:

```bash
make test-e2e                                                    # every ota-candidate step and any step whose OpenAPI the UI consumes
make -C frontend test-e2e-layout                                 # AH5–AH7, AH11, AH12, AH15, AH17, AH18, AH20, AH22
make -C frontend generate-schemas && docker compose exec -T frontend npm run check:types   # after OpenAPI changes (AH4, AH6, AH7, AH10+)
docker compose exec -T backend php bin/console app:assistants:eval --corpus=smoke         # AH9, AH14, AH16, AH19, AH21, AH23
docker compose exec -T backend php bin/console lint:container    # steps that add services, tags or attributes
node scripts/mobile-impact.mjs --base main --head HEAD           # equals the class in §5
make perf-runtime                                                # AH9 onward: a running run must not move the AU1 budgets
```

- Flag off ⇒ `RoutingCharacterizationTest`, `StreamTranscriptCharacterizationTest`
  and the tool-loop characterization of AU23 unchanged. `/api/v1/runs*` 404
  for everything that starts or drives a run (`capabilities`, `POST /runs`,
  `stop` of a non-terminal run, `hint`, `events` of a live run); the
  owner-checked history routes stay open — `GET /runs`, `GET /runs/{uuid}`,
  `GET /runs/{uuid}/events` of a terminal run and `DELETE /runs/{uuid}` — so
  existing results stay readable and deletable (rules above, `02` read-only
  run card). A test covers both halves with the module off.
- New classes respect deptrac (`App\Service\Run\*` and `App\Service\Assistant\Run\*`
  are Application layer) and the size ratchet.
- Locale parity green; no ledger additions; the banned words of the rules
  above absent from primary copy (`tests/unit/i18n/` grep added in AH11).
- PHPUnit and Playwright use only test doubles; a test that needs a provider
  key or the live web is not a gate test. AH1 and AH23 record real-provider
  numbers in STATUS with the command next to them.

### Track exit

- J-AH-1 … J-AH-11 walked and entered in STATUS (J-AH-1 with both clients).
- AH23 report meets §0 #25; `RUNS.ENABLED` seeded on for new installs with a
  migration note for existing ones.
- No run in the AH23 sweep ends without a terminal state; the reaper test
  kills a worker mid-run and the run ends `interrupted` with what was done.
- AHX1 and AHX2 merged in `synaplan-docs`.
- Plan archived with a dated note when the last step ships.

---

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| A run says "done" when something did not happen | `finish` is the only success path; the outcome sentence is assembled from the run's own ledger of executed writes, not from the model's prose; approvals still pending are listed; a test per outcome kind |
| A run never ends ("running" forever) | Units ≤ 120 s, claims with leases, reaper (§0 #5); wall-clock budget; a killed-worker test in AH9 and AH23 |
| Costs run away (loops, helpers fanning out) | Budgets enforced in the tool layer (§0 #12), helper depth 2 and 3 in parallel, cost check before every paid call, per-person active-run cap, plan limits (§0 #29) |
| Prompt injection from a page, mail, file or imported skill drives a write | Writes always pass the approval policy; headless never auto-approves; skills are data with an `allowed-tools` ceiling; page and file text reach the lead only through the existing readers; injection tests in AH9 and AH16 |
| A helper gets more rights than the person | Intersection of rights (§0 #10); a test where the helper has a tool the caller lacks and the call is refused |
| A shared assistant leaks one person's notes to another | Personal notes are namespaced per person (§0 #18); assistant notes are written only by `edit` holders; a cross-user test |
| Self-written skills drift or degrade | Proposals only, accepted by a person, with revisions and restore (§0 #19); eval run before a seeded skill changes |
| A second, parallel agent machinery appears beside the overhauls | Rules: no new loop, no new AI path, one kernel (§0 #5, #6); reviewer checks deptrac and the characterization tests of AU9 / AU23 |
| Phase B waits too long on rows 21 and 22 | Phase 0 and A ship independently; AH8 may build the kernel itself (§0 #5) |
| The word "skill" confuses people during the change | §0 #4 renames capabilities to Actions in the same PR that introduces skills (AH17); old keys kept as aliases one release |
| The Runs page becomes a notification dump | Sibling page under Assistants with a badge only for "Needs you" (U6); chats stay the work list; filters Running · Needs you · Done · Stopped · Failed |
| Tool-calling quirks per provider | Tool mode only for models on the conformance list, round mode otherwise (§0 #6); the AU26 loop's characterization covers providers |
| Hermes or omp change their wire behaviour | AH1 is a script that can be re-run; no client-specific code in the gateways (§0 #2) |
| A minimal install shows a dead feature | Module off ⇒ no chip, no page, no builder sections (U11); P-MIN walk in AH12 |

---

## 8. Non-goals

- Embedding Hermes Agent, omp or any external agent runtime as Synaplan's
  engine (§0 #1).
- A shell, browser control or computer use on the server (§0 #27).
- A coding agent (hashline edits, LSP, debugger) — those belong to coding
  clients, which Phase 0 connects.
- Free-running rooms of assistants (§0 #15).
- A task board view in v1 (§9 #2).
- A new pairing scope or `protocol: 2` for Desktop (§0 #26).
- Training, fine-tuning or trajectory export.
- Per-assistant billing; costs stay the person's (row 23 sets budgets).

---

## 9. Later (in this order)

| # | What | Why it waits |
| - | ---- | ------------ |
| 1 | **Outside assistant: connect your own Hermes Agent** through its Runs API (`/v1/runs`, events, stop, approval, clarify) as an assistant card; its runs on the Runs page, its approval requests in the inbox, labelled "runs outside Synaplan" | A spike first (`01` §7 option C): the contract is Hermes-specific and data leaves Synaplan's governance; needs the world rule's "outside" wording |
| 2 | **Board view of runs and Saved Tasks** (Hermes Kanban): columns Needs you · Running · Waiting · Done, drag a task to a helper | After the Runs page shows whether lists are enough |
| 3 | **Rooms of assistants** with rounds and caps | Only with a person's goal per room and numbers that it beats helpers |
| 4 | **Runs from Synaplan Desktop** (`/v1/runs` under `desktop:messages`), and a run that asks Desktop to read a local file | After AH10; same input channel as research quality §9 #1 |
| 5 | **Remote ACP endpoint** so editors drive a Synaplan assistant | When ACP's HTTP transport is final |
| 6 | **Skills in plugin manifests and a skills gallery** across the install (curated, from plugins and admins) | After AH17 and row 18's manifest v2 |
| 7 | **Rules that wake up** (omp stream rules): an assistant rule matched in the output interrupts and corrects | Needs the stream pump (AU30) to abort and resume mid-stream |
| 8 | **Automatic hand-over** from the planner to a run for long jobs | AH23 numbers (§0 #8) |
| 9 | **Share a run** read-only with a colleague (Share dialog, new kind) | UX contract §4.1 consequence copy per kind |

---

## 10. Change log

| Date | Change |
| ---- | ------ |
| 2026-10-23 | Draft: research of Hermes Agent and omp (`01`), code map of `main`, decisions, journeys, contracts, step briefs. |
| 2026-10-23 | Owner decision session: all 30 §0 rows agreed (`BRUNS` for both run kinds, "Actions" rename, the new words, free-level limit, compose lines); plan only until ordered. |
| 2026-10-23 | Copilot review of [#2664](https://github.com/metadist/synaplan/pull/2664), eight findings accepted: the working-time budget counts `running` only and approval / question waits keep their own expiry (§0 #12, `03` §1, §6); with the module off the owner-checked history routes stay open (§7); `BRUNS.BLEDGER` keeps the ledger for the 90-day retention (§0 #9, `03` §2.1, §4); checkpoints have no TTL until terminal (`03` §2.2); the queued `202` returns the uuid (`03` §4); `agent.v2` upgrade defaults are separated from the configured example and add nothing autonomous (`03` §7); Stop copy says a running step may still finish and an unconfirmed stop offers **Try again** (`02` J-AH-2, §5). |

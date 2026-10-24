# 04 — Step briefs

One brief per step. Each starts with **Reads** and **Tests first**; code
comes after the red test. Exit criteria are reported one by one in STATUS
with the command that proved them. Implementation follows
[`../AGENTS.md`](../AGENTS.md): one branch, one commit, one pull request per
step; Copilot review answered on every thread; **All Checks Passed** green
before the next step; the owner merges.

`ota-candidate` steps add the five exit bullets of `02` §6 to their exit.

---

## Phase 0 — open the door and measure

### AH1 — `test(gateway): Hermes Agent and omp against the gateways and the MCP server`

- **Goal:** prove which parts of Synaplan both clients can use today.
- **Reads:** `docs/OPENAI_COMPATIBLE_API.md`, `docs/ANTHROPIC_COMPATIBLE_API.md`,
  `Mcp/McpServerFactory.php`, `Service/Agent/AssistantAliasResolver.php`;
  `01` §2–§4.
- **Do:** on the dev stack, install omp and Hermes Agent on the host
  (never in the repo), configure Synaplan as provider (OpenAI and Anthropic
  faces) and `/mcp` as MCP server with a test key. A script
  `_devextras/agent-clients/conformance.sh` runs a fixed list: list models,
  plain chat, streaming, a client tool call relayed, `assistant:<slug>` alias,
  MCP `rag_search`, `memory_search`, `synaplan_chat` with `agentId`, a model
  without tool support. Record pass / fail and timings in STATUS.
- **Exit:** STATUS table per client and case; each failure has an issue and,
  if it is a gateway bug, its own `fix:` PR (not in this step).

### AH2 — `docs: use Synaplan from Hermes Agent, omp and other agent clients`

- **Goal:** J-AH-1 from one page.
- **Touches:** `docs/AGENT_CLIENTS.md` (new; linked from `docs/ANTHROPIC_COMPATIBLE_API.md`
  and `docs/DEVELOPMENT.md`), and the matching `synaplan-docs` page.
- **Exit:** J-AH-1 walked from the page alone with both clients; no hostnames
  of real installations.

### AH3 — `test(assistants): an assistant-task corpus and today's numbers`

- **Goal:** a baseline before any engine code (§0 #25).
- **Reads:** research quality `06_evaluation.md` (judge pattern), `03` §12.
- **Tests first:** corpus schema test; the runner on test doubles returns a
  stable report.
- **Do:** 24 jobs across five use cases (inbox, offers, tender, weekly
  report, research-and-write), each with fixtures, expected facts and writes,
  one prompt-injection trap per five jobs. `app:assistants:eval` with arms B0
  and B1. Live run on the dev stack.
- **Exit:** STATUS has B0 and B1 numbers with the command.

## Phase A — quick wins

### AH4 — `feat(tasks): stop a Saved Task run while it runs`

- **Reads:** `SavedTaskRunner.php`, `DagExecutor.php`, `Entity/SavedTaskRun.php`
  (`STATUS_CANCELLED` unused), `SavedTaskOutcomeNarrator.php`,
  `StreamController` cancel-node, `CancelledTurns`.
- **Tests first:** a run with three steps, cancel after step 1 → step 2 not
  started, status `cancelled`, outcome sentence names finished and skipped
  steps; cancel while queued; cancel of a finished run is a no-op `200`;
  another user's run is `404`.
- **Do:** `POST /api/v1/saved-tasks/{id}/runs/{runId}/stop` in a new thin
  controller; a cancel flag in Redis checked by `DagExecutor` between steps
  and by runners before paid calls; the narrator's stopped sentence.
- **Exit:** tests green; OpenAPI and schemas regenerated; `make test-e2e`.

### AH5 — `feat(tasks): live status and Stop on the task card`

- **User-flow:** J-AH-2.
- **Do:** Centrifugo push of run status (pattern of `ApprovalRealtimeNotifier`);
  a small `SavedTaskRunStatus.vue` inside the card (the card does not grow);
  Stop with `useDialog` danger confirm; outcome sentence on the card.
- **Exit:** J-AH-2 walked; five exit bullets.

### AH6 — `feat(telegram): talk to an assistant in Telegram`

- **User-flow:** J-AH-4.
- **Reads:** `WhatsAppAgentBinding.php`, `WhatsAppAssistantController.php`,
  `Service/Telegram/TelegramTurn.php`, `TelegramInboundService.php`,
  `AgentTriggerMaterializer.php`, `ChannelAssistantSelect.vue`.
- **Tests first:** a Telegram turn with a bound published assistant uses its
  runtime profile; unbound or unshared falls back with one logged warning.
- **Do:** `TelegramAgentBinding`, `PUT /api/v1/channels/telegram/assistant`,
  the **Answers with** row on the Telegram app page (reuse
  `ChannelAssistantSelect`), trigger kind `telegram` in the definition and the
  materializer.
- **Exit:** J-AH-4 walked with a real bot on the dev stack; five exit bullets.

### AH7 — `feat(assistants): describe an assistant in one sentence and get a draft`

- **User-flow:** J-AH-3.
- **Reads:** archived builder AB14 brief, `WidgetSetupService`,
  `AI/StructuredOutput/`, `AgentDefinitionValidator.php`.
- **Tests first:** the structured output schema; the draft always validates
  against `agent.v1`; the prompt snapshot (`tools:assistant_draft`).
- **Do:** `POST /api/v1/agents/draft-from-description` (new controller), a
  first field in `BuilderBasics` with **Draft it**; nothing saved by the call.
- **Exit:** J-AH-3 walked in two languages; five exit bullets.

## Phase B — assistant runs

### AH8 — `refactor(runs): one run kernel for research and assistant runs`

- **Reads:** research quality `03_research_engine.md` §6–§9, `10_contracts.md`;
  architecture update `03_contracts.md` §7–§8; `ChatRunBuffer`.
- **Tests first:** unit claim (two workers, one executes), fenced checkpoint,
  reaper transitions, idempotent start, cancel before a paid call, delivery
  into a chat with the tab closed.
- **Do:** `App\Service\Run\{RunService, RunUnit, RunClaim, RunCheckpointStore,
  RunEventLog, RunReaper, RunDelivery}`; the `BRUNS` migration if §0 #9 is
  agreed; `app:runs:reap` in the tick lane. If RS6 has merged, move its
  generic parts here with characterization tests and no behaviour change.
- **Exit:** research quality STATUS and this STATUS name the kernel; deptrac
  and size ratchet green.

### AH9 — `feat(assistants): an assistant works through a job step by step`

- **Reads:** AU26's loop and `ToolInvoker`; `AgentRuntimeResolver`;
  `ApprovalPolicy`; `AskUserRunner`; research quality `04_prompting.md` (lead
  prompt rules); `03` §5.
- **Tests first:** a three-step job on `TestProvider` reaches `completed` with
  a correct ledger; a write waits for approval; a headless write never
  auto-approves without `allowUnattended`; budget hit → wrap-up and
  `budget_used`; injection trap held; killed worker → `interrupted`; module
  off → 404 and unchanged routing snapshots.
- **Do:** `AssistantRunUnit` (one step per unit), the run tools of `03` §5
  except helpers, notes and skills; `AssistantRunsModule`; prompt
  `tools:assistant_run_lead`; usage source `ASSISTANT_RUN`.
- **Exit:** `app:assistants:eval --arm=R --corpus=smoke` recorded against B0;
  `make perf-runtime` within AU1 budgets.

### AH10 — `feat(runs): list, follow, hint and stop runs through the API`

- **Tests first:** every endpoint of `03` §4 (runs rows), ownership, SSE resume
  after a cursor, hint applied before the next step, stop of a parent stops
  children.
- **Exit:** OpenAPI complete; schemas regenerated; `vue-tsc` green.

### AH11 — `feat(chat): let an assistant work on it, with a run card in the chat`

- **User-flow:** J-AH-5 (chat part).
- **Do:** `RunCard.vue` (new, ≤ 300 lines, reuses `ApprovalCard` and the
  ask-the-user card), **Let it work** in `ToolsDropdown` and as a chip on
  plans over `RUNS.SUGGEST_AFTER_STEPS`; `useRun.ts` composable for the event
  stream. `ChatView.vue` and `ChatMessage.vue` must not grow (mount through
  the existing message-part slot).
- **Exit:** J-AH-5 chat part walked with a real provider; five exit bullets.

### AH12 — `feat(assistants): the Runs page — everything your assistants are doing`

- **User-flow:** J-AH-5 (find again), J-AH-6.
- **Do:** route `/runs` as a child of Assistants (sibling of Tasks and
  Approvals), `RunsView.vue` with status chips, the **Needs you** badge on
  Assistants; rows open the run in its chat.
- **Exit:** J-AH-5 and J-AH-6 walked; P-MIN walk with the module off; five
  exit bullets.

### AH13 — `feat(tasks): a Saved Task step that lets an assistant work`

- **Do:** runner `AssistantRunRunner` (capability `assistant_run`,
  builder-only), waits for the child run's terminal event instead of holding
  a unit; step picker entry.
- **Exit:** J-AH-6 walked with a schedule on the dev stack.

## Phase C — notes and skills

### AH14 — `feat(assistants): an assistant keeps notes about the job and about working with you`

- **Tests first:** personal notes never visible to another user of the same
  assistant; shared notes only proposed by a run; bound enforced; injection of
  notes only for that assistant.
- **Do:** `note` tool, namespace `assistant:{agentId}`, `NOTES.md` handling,
  endpoints of `03` §4, `ChatMemoryContextLoader` namespace from the profile.
- **Exit:** eval arm R with notes after two runs of the same job shows fewer
  steps or hints (recorded).

### AH15 — `feat(assistants): see and delete what an assistant noted`

- **User-flow:** J-AH-9. Notes tab on the assistant page; Memories filter
  **From assistants**.

### AH16 — `feat(skills): skills in the Agent Skills format for assistants`

- **Reads:** agentskills.io specification; `synaplan-desktop` `skills.rs` and
  `docs/SKILLS.md`; `03` §9.
- **Tests first:** validator against the spec examples and the 14 Desktop
  bundled skills (all accepted, `needsPrograms` correct); hostile skill
  (instructions to call a tool outside `allowed-tools`, a symlink, an
  executable outside `scripts/`) refused or neutralised; `skill_view` path
  traversal refused.
- **Do:** `AgentSkillValidator`, `SkillPack`, import endpoint (zip, folder,
  GitHub URL via the egress client), `skill_list` / `skill_view`, `code_run`
  hand-off for scripts.
- **Exit:** a run uses a Desktop bundled skill (instructions only) in the
  eval; recorded.

### AH17 — `feat(assistants): add, import and share skills`

- **User-flow:** J-AH-7. Skills section in the builder (§3.3 of `02`), Library
  → Skills folder, the **Actions** rename of §0 #4 in the same PR (keys kept as
  aliases for one release).

### AH18 — `feat(assistants): an assistant proposes an improvement after a run, you decide`

- **User-flow:** J-AH-8. `tools:run_reflection`, approval kind `improvement`
  in `ApprovalService` and `ApprovalsInbox.vue` (kind filter), diff view (row 8's
  if shipped, otherwise a small `ProposalDiff.vue` row 8 can adopt).

## Phase D — helpers and checks

### AH19 — `feat(assistants): an assistant hands part of a job to a helper`

- **Tests first:** intersection of rights (helper has a tool the caller lacks
  → refused); depth 3 refused; parallel cap; parent stop stops children;
  structured result validated; helper question bubbles up to the person.
- **Do:** `ask_helper`, child runs in `BRUNS` (`BPARENTID`), budget share,
  events `helper.*`.
- **Exit:** eval arm R+H recorded.

### AH20 — `feat(assistants): helpers in the builder and a starter team`

- **User-flow:** J-AH-10 (helpers part). Helpers section; three system
  assistants seeded by `app:agents:seed-system` (Researcher, Writer, Reviewer)
  with their prompts as catalog entries, published to everyone only when the
  module is on.

### AH21 — `feat(assistants): a second model double-checks the work`

- **Tests first:** `concern` lets the lead revise once; `blocker` stops before
  the write and asks; world rule (a local-only assistant never checks with a
  cloud model; the option is disabled with a sentence).
- **Do:** `tools:run_check` prompt, VERIFY slot (or ANALYZE), events
  `check.result`.
- **Exit:** eval arm R+C recorded; J-AH-10 walked.

### AH22 — `feat(admin): limits for assistant runs`

- **User-flow:** J-AH-11. Admin → AI → Assistant runs (tabs pattern U4.5),
  `GET/PUT /api/v1/admin/runs/settings`, active count, `ASSISTANT_RUN`
  rate-limit action (§0 #29).

## Phase E — rollout

### AH23 — `test(assistants): runs beat today's paths and the module goes on for new installs`

- **Do:** full corpus, all arms, live on the dev stack; the targets of §0 #25;
  seed `RUNS.ENABLED=1` (and the sub-flags that met their targets) for new
  installs; migration note for existing ones; killed-worker drill.
- **Exit:** the track exit of master §6.

# Sprint 5 — Workflow builder v1 and webhook trigger

**Track 4 (`synaplan/`), sprint 5 of 5.** Steps `TL38`–`TL47`.

**Goal:** A non-technical user edits a Saved Task as an ordered list of steps — skill, tool or assistant, with
inputs typed in or taken "from step N", an optional condition step, and a stricter-only approval override — and
the task runs on a schedule or when an external system (n8n, Make, a script) calls its webhook URL. Everything
saves into `BSAVEDTASKS.BGRAPH` through the existing validator and runs on the existing `DagExecutor`.
**Depends on:** S3 (pause/resume makes the approval override meaningful), S4 (tools as steps). Track 1 S1
(`saved_task` share kind for templates) behind the same interface fallback as S4. Track 2 S6 for the bundle
section. Master plan §0 rows 9, 10, 11; §4.5; §12 rows 7, 9.
**Unlocks:** v2 node canvas (a UI over the same `BGRAPH`); track 5 `code_run` as a step.
**Repos:** `synaplan/` only. Cut line (master plan §8): webhook trigger (`TL42`–`TL43`) ships before the editor if capacity is short.
**Flag:** `WORKFLOWS.BUILDER_ENABLED` (default off) gates the editor, the new node kinds in the validator and
the webhook route; the outbound webhook hardening (`TL43`) is unconditional because it only tightens.

---

## 0. Why this sprint exists

Saved Tasks have an authored graph and no way to author it except JSON. The `webhook` trigger constant exists
(`SavedTask::TRIGGER_WEBHOOK`) with a comment that the route is missing, and `outbound_webhook` is listed as a
mutating capability in `SavedTaskService` without a runner. This sprint closes both and gives the graph a face.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/SavedTask/Graph/SavedTaskGraphValidator.php` | `VERSION = 1`, `MAX_NODES = 16`, node shape `{ id, capability, depends_on, params }`, trigger must match the columns — the builder writes exactly this (C7) |
| `backend/src/Service/SavedTask/Graph/SavedTaskPlanFactory.php` `fromTask()` | Graph → `TaskPlan`; new node kinds must compile here |
| `backend/src/Service/Multitask/Plan/Capability.php`, `Skill/SkillCatalog.php`, `SkillDescriptor.php` (`available` closure) | New cases must stay out of `[CAPABILITYLIST]` |
| `backend/src/Service/Multitask/Execution/Runner/ChatRunner.php` (`params.topic_id`), `McpActionRunner.php`, `DagExecutor.php` | The "assistant" step is a `chat` node; MCP tool step pattern; dependents of a failed node are `skipped` — the condition step reuses this |
| `backend/src/Entity/SavedTask.php` (`TRIGGER_WEBHOOK`, `BTRIGGERCONFIG`), `Service/SavedTask/SavedTaskService.php`, `SavedTaskRunner.php` | Trigger columns are authoritative; runs execute by owner id |
| `backend/src/Controller/WebhookController.php` (`/api/v1/webhooks/generic`) | Stateless public webhook route pattern and firewall placement |
| `backend/src/Service/Security/SsrfGuard.php`, `Service/Tool/Custom/HttpToolExecutor.php` (S4) | Outbound HTTP hardening to reuse |
| `frontend/src/components/config/SavedTaskCard.vue`, `SavedTasksOverview.vue`, `McpTemplatePicker.vue`, `frontend/src/services/api/savedTasksApi.ts` | Where the Steps editor attaches; picker layout to copy |
| `_devextras/planning/n8n-integration-recipes.md`, `20260816-saved-task-workflows/00_master_plan.md` §4.3 | The interface-not-embed contract for n8n |

---

## 2. Developer steps

### 2.1 `TL38` — new node kinds in the DAG

Three `Capability` cases with runners, each registered in `SkillCatalog` with `available: static fn (): bool => false`
so `renderCapabilityList()` never prints them (planner text unchanged, `planner_system_prompt.txt` untouched):

| Case | Runner | `params` | Behaviour |
| ---- | ------ | -------- | --------- |
| `ToolCall = 'tool_call'` | `ToolCallRunner` | `{ tool: "custom:{name}" \| "mcp:{serverId}:{tool}", inputs: {...} }` | Resolves the descriptor via `ToolRegistry::get()`, runs `ApprovalPolicy::decide()` in `Unattended` context, executes through the tool's source |
| `OutboundWebhook = 'outbound_webhook'` | `OutboundWebhookRunner` | `{ url, secret?, inputs: {...} }` | HMAC-signed POST (see `TL43`) |
| `Condition = 'condition'` | `ConditionRunner` | `{ input: {...}, operator: "equals" \| "contains" \| "matches" \| "not_empty", value }` | On false returns `NodeResult::stopped()`; `DagExecutor` marks dependents `skipped` and the run `completed` |

`StepInputResolver` turns `inputs` into values: `{ "literal": "…" }`, `{ "from": "step_2", "field": "summary" }` or
`{ "from": "trigger", "field": "body.title" }`, reading `NodeContext::getResult()` of a declared `depends_on`. Existing
runners are untouched; the "assistant" step is the existing `chat` node with `params.topic_id`.

### 2.2 `TL39` — validator and policy override (C7)

`SavedTaskGraphValidator` gains, behind the flag: the three capabilities, the `params.inputs` shape (every `from` must be
in `depends_on` or `trigger`), `params.approval ∈ { approve, block }` (stricter only — `auto` rejected),
`settings.approvalExpiryHours` (S3). `ApprovalPolicy::decide()` receives the node override as one more input to
`mostRestrictive()`. Flag off: validator behaves exactly as today. Fixture corpus in `tests/Fixtures/saved_task_graphs/` —
every pre-existing `BGRAPH` shape from the shipped sprints loads unchanged.

### 2.3 `TL40` — Steps editor

`SavedTaskStepsEditor.vue` (opened from the task card's "Steps" action; the simple card stays the default path) composed of
`StepPicker.vue` (palette from `GET /api/v1/tools`: skills, tools, assistants, plus Condition and Send to webhook),
`StepRow.vue` (title, inputs, "needs approval" toggle that only tightens, move up/down, delete), `StepInputsForm.vue`
(literal field or "from step N" dropdown listing that step's output fields). Linear by default: step N depends on step N−1;
the dropdown may select any earlier step. Saves `PATCH /api/v1/saved-tasks/{id}` with `graph`; validator errors are shown
per step. Each component < 300 lines; `useDialog` for delete; tokens only; dark + V2 + 320px.

### 2.4 `TL41` — templates via IAM

"Save as template" = share of kind `saved_task` with `use` (track 1 kind registry). "Use template"
(`POST /api/v1/saved-tasks/{id}/copy`) creates a copy owned by the caller: graph and trigger type copied, schedule copied
**paused** (`BENABLED = 0`), `allowUnattended = 0`, webhook token regenerated, `prompt` reference kept when readable by the
caller, otherwise a checklist item "needs an assistant"; tool steps referencing tools the caller cannot use become checklist
items "needs a tool". Owner-only fallback without track 1.

### 2.5 `TL42` — inbound `webhook` trigger

`POST /api/v1/webhooks/saved-tasks/{token}` (stateless, public firewall like `/generic`):
`BTRIGGERCONFIG = { "token": <32 bytes base64url>, "hmacSecret": <optional>, "maxBodyBytes": 65536 }`, token generated
server-side and regenerable from the card. Optional HMAC: `X-Synaplan-Signature: sha256=<hex>` over the raw body,
constant-time compare; missing signature when a secret is set → 401. Rate limit 60 / minute per task and `RateLimitService`
accounting as the owner (a webhook is not a way around budgets). Body (JSON, ≤ 64 KiB) becomes the run's trigger payload
(`from: trigger`). Runs go through `SavedTaskRunner::run(ownerId, taskId, …, 'webhook')`. `SavedTask::TRIGGER_WEBHOOK`
re-enters the allowed trigger list; unknown token → 404, same message for a disabled task (no enumeration).

### 2.6 `TL43` — outbound webhook hardening and n8n recipe

`OutboundWebhookRunner`: `https` only, resolved host through `SsrfGuard::isBlockedIp()`, `max_redirects: 0`, `timeout: 10`,
body `{ task, run, step, result }` (result is the mapped step output, never credentials), header
`X-Synaplan-Signature: sha256=<hmac>` when a secret is set, no retries (the receiver retries). New `docs/N8N.md`: recipe
"n8n Webhook node receives the Synaplan POST → does its work → HTTP Request back to `/api/v1/webhooks/saved-tasks/{token}`",
plus the existing MCP / `/v1` pointers from `_devextras/planning/n8n-integration-recipes.md`.

### 2.7 `TL44` — five locales

Step / Schritt / Paso / Étape / Adım; Workflow; Needs approval; Condition; Send to webhook; From step N; Use template;
Save as template; Webhook URL; Regenerate — en / de / es / fr / tr in the same PR as `TL40`. Never "DAG", "node",
"side effect" in primary copy (master plan §5).

### 2.8 `TL45` — `saved_tasks` bundle section

`SavedTasksBundleSection implements BundleSectionInterface` (track 2 S6,
[`../202609_agent_builder/06_sprint_6_portability_and_packs.md`](../202609_agent_builder/06_sprint_6_portability_and_packs.md)).
Exports `name`, `triggerType`, `triggerConfig` **without** `token` / `hmacSecret`, `graph` with references rewritten to stable
keys (prompts by slug, custom tools `custom:{name}`, MCP tools by server name), `settings`. Import: schedules arrive **paused**
(`BENABLED = 0`), `allowUnattended = 0`, webhook token regenerated; checklist items "needs an assistant" / "needs a tool" /
"needs a connection". Unknown keys rejected.

### 2.9 `TL46` — C7 proof and end-to-end run

See §3; the acceptance workflow below runs in `SavedTaskRunnerWorkflowTest` with fake mail, fake tool and fake webhook receiver.

### 2.10 `TL47` — docs and status

`docs/MULTITASK_DATA_NODES.md` gains the three node kinds and the `inputs` contract; release notes; `STATUS.md` rows ticked;
the track directory is ready to move to `2026-archive/` with a closing note.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C7 builder saves only valid graphs; old rows load | `SavedTaskGraphValidatorTest`: fixture corpus (all shipped shapes + new kinds) accepted; `auto` override, `from` outside `depends_on`, 17 steps, unknown capability rejected; `SavedTaskPlanFactoryTest` compiles every fixture |
| C1 planner text unchanged | `PlannerPromptCharacterizationTest` and `UtterancePlanCharacterizationTest` snapshots untouched although `Capability` gained cases |
| C4 | `SavedTaskRunnerWorkflowTest::testToolStepPausesWithoutAllowUnattended`, `::testNodeOverrideBlockBeatsAllowUnattended` |
| C5 | A `tool_call` step whose tool left the registry fails with `ToolNotRegisteredException`; the validator rejects it at save when the flag is on |
| C6 | `OutboundWebhookRunnerTest`: internal host, redirect, plain `http` refused; body never contains credential values. `InboundWebhookControllerTest`: bad HMAC 401, unknown token 404, 61st call in a minute 429 |
| C8 | Widget never triggers Saved Tasks (`WidgetPublicControllerTest` unchanged); `/v1`, Messages and MCP server tool lists unchanged; OIDC firewall untouched (only the stateless webhook path added) |

Frontend: `SavedTaskStepsEditor.spec.ts`, `StepInputsForm.spec.ts`, `StepPicker.spec.ts`, i18n parity for namespace
`workflows`. Full gate both sides; regenerate Zod after `TL41` and `TL42`.

---

## 4. Exit criteria / demo

1. Flag off: task card unchanged, validator unchanged, webhook route 404, snapshots untouched.
2. Flag on: a user builds "every Monday 08:00: search mail (last 7 days) → summarize with the Support assistant → create ticket (custom tool, needs approval) → mail me" in the Steps editor without typing JSON; validator errors appear per step.
3. The Monday run pauses at "create ticket"; approving from the inbox (S3) finishes the run; "mail me" contains the ticket summary.
4. n8n posts to the task's webhook URL with a valid signature; the run starts with the payload available as "from trigger"; a bad signature is refused.
5. The last step "Send to webhook" delivers a signed POST to n8n; an attempt to point it at an internal address is refused at save.
6. "Save as template" + "Use template" by a group member yields a paused copy with a checklist; export/import of `saved_tasks` behaves the same way.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| TL38 | `feat(saved-tasks): add tool_call, outbound_webhook and condition nodes with StepInputResolver` | backend-only | S3, S4 |
| TL39 | `feat(saved-tasks): validate step inputs and stricter-only approval override in graphs` | backend-only | TL38 |
| TL40 | `feat(saved-tasks): add Steps editor for Saved Tasks` | ota-candidate | TL39 |
| TL41 | `feat(saved-tasks): add templates through saved_task shares and task copy` | backend-only + ota-candidate | TL39, track 1 S1 |
| TL42 | `feat(saved-tasks): add inbound webhook trigger with token, HMAC and rate limit` | backend-only + ota-candidate | TL38 |
| TL43 | `feat(saved-tasks): harden outbound webhook node and document the n8n recipe` | backend-only | TL38 |
| TL44 | `feat(saved-tasks): add workflow vocabulary in five locales` | ota-candidate | TL40 |
| TL45 | `feat(saved-tasks): register saved_tasks bundle section with paused schedules` | backend-only | TL39, track 2 S6 |
| TL46 | `test(saved-tasks): prove graph validator compatibility (C7) and the five-step workflow end to end` | backend-only | TL42, TL43 |
| TL47 | `docs(saved-tasks): document workflow nodes, webhook trigger and close track 4` | backend-only | TL46 |

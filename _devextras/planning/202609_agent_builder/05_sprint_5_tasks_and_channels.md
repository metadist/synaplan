# Sprint S5 — Tasks and channels

**Track 2 (Agent Builder), sprint 5 of 6.** Steps `AB33`–`AB39`.

**Goal:** An assistant ships with task templates that become ordinary Saved Tasks, and channels bind to an assistant instead of a prompt topic: a widget runs a published assistant, the widget setup wizard offers "pick an assistant", an inbound-email department and a WhatsApp number can point at one. Everything is additive; a widget or handler without a binding behaves byte-identically.
**Depends on:** S3 (published versions), S4 (the tool/knowledge policy a widget inherits). Saved Tasks (`20260816-saved-task-workflows/`) as shipped.
**Unlocks:** S6 (tasks and channel defaults are part of the exported definition), track 4 S5 (workflow builder reads `tasks[]`).
**Repos:** `synaplan/` only. **Class:** `backend-only` + `ota-candidate`.
**Flag:** `AGENTS.ENABLED`; task templates additionally require the Saved Tasks flag (`SavedTaskConfig::isEnabled($ownerId)`) — off ⇒ the Tasks section is hidden and templates are stored but not materialised.

---

## 0. Why this sprint exists

A published assistant is only useful in a department if it reaches people where they are: the website widget, the support mailbox, WhatsApp, and a Monday-morning digest. Today each channel binds to a prompt *topic* and each schedule is a separate Saved Task. This sprint binds them to the versioned assistant, so publishing v2 updates the widget and the digest at once.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Entity/SavedTask.php` | `BPROMPTID`, `BTRIGGERTYPE` (`manual` / `chat` / `schedule` / `inbound_email` / `webhook`), `BTRIGGERCONFIG`, `BGRAPH`, `BALLOWUNATTENDED` |
| `backend/src/Service/SavedTask/SavedTaskService.php` | `create(int $ownerId, int $promptId, string $name)`, `update()` — the only way templates become rows |
| `backend/src/Service/SavedTask/SavedTaskRunner.php` | "Executes a Saved Task as the owner" — the rule assistant tasks inherit unchanged |
| `backend/src/Entity/Widget.php` | `BTASKPROMPT` (topic), `BCONFIG` JSON — `BAGENTID` goes beside them |
| `backend/src/Controller/WidgetPublicController.php` | `'fixed_task_prompt' => $widget->getTaskPromptTopic()` in the classification options — the binding point |
| `frontend/src/components/widgets/setup-wizard/WidgetSetupWizard.vue`, `WidgetAiPromptSection.vue`, `WidgetEditor.vue` | Wizard stepper and the prompt step that gains "pick an assistant" |
| `backend/src/Entity/InboundEmailHandler.php` + `backend/src/Service/InboundEmailHandlerService.php` | `BDEPARTMENTS` JSON, `routeEmailToDepartment()` — a department gains an optional `agentId` |
| `backend/src/Service/WhatsAppService.php`, `backend/src/Controller/WebhookController.php` | Inbound WhatsApp classification — a per-user binding pins it |
| `docs/E2E_TESTING.md`, `backend/tests/**/SavedTask*` | C4 / C5 baselines |

---

## 2. Developer steps

### 2.1 Task templates → Saved Tasks (`AB33`)

`tasks[]` in `agent.v1` (validated shape: `name`, `trigger.type` ∈ `SavedTask::TRIGGER_TYPES`, `trigger.cron` for `schedule`, `prompt`, optional `allowUnattended`):

- `AgentTaskMaterializer::sync(Agent $agent, int $ownerId)` runs on publish: for each template, `SavedTaskService::create($ownerId, $agent->getPromptId(), $name)` if no row with `BTRIGGERCONFIG.agentTemplate = {slug}:{name}` exists, then `update()` with trigger config and the template prompt as the graph's single step. Removed templates disable (never delete) their row.
- Rows are ordinary `BSAVEDTASKS` owned by the **owner** and run by `SavedTaskRunner` as the owner (decision 7; existing rule). Users with `use` see the templates in the gallery card **Details** but get no rows — "run this for me" is the IAM `saved_task` kind (track 1 S3), out of scope here.
- A user's own Saved Task can reference an assistant: `BTRIGGERCONFIG.agentId` (JSON, no schema change); `SavedTaskRunner` passes `agentId` into the classification options so the run is pinned (S1 path). Absent ⇒ unchanged.

### 2.2 Migration — `BWIDGETS.BAGENTID` (`AB34`)

Own PR. `Widget` gets `agentId` (nullable). `BTASKPROMPT` stays `NOT NULL` — a bound widget keeps its topic as the fallback if the assistant is deleted or the flag is turned off (rollback rule, master plan §9).

```sql
ALTER TABLE BWIDGETS ADD COLUMN IF NOT EXISTS BAGENTID BIGINT NULL AFTER BTASKPROMPT;
ALTER TABLE BWIDGETS ADD INDEX IF NOT EXISTS idx_widgets_agent (BAGENTID);
```

### 2.3 Widget runtime binding (`AB35`)

- `WidgetPublicController`: when `BAGENTID` is set **and** the flag is on, pass `agentId` instead of `fixed_task_prompt`; the resolver checks access as the widget **owner** (`use` is implied — the owner published or was granted it); execution identity stays the widget session as today (budget, rate limits). Archived or inaccessible assistant ⇒ fall back to `BTASKPROMPT` and log a warning once per widget per hour.
- `WidgetController` CRUD: `agentId` on create/update (must be owned or `use`-shared to the owner, else 400), returned in the widget DTO. OpenAPI + Zod regenerated.
- Widget summary, live-support and export paths are untouched: they read `BTASKPROMPT` and continue to (C4).

### 2.4 Widget setup — "pick an assistant" (`AB36`)

`WidgetAiPromptSection.vue` gets a first choice: **Use an assistant** (select from `agentsApi.gallery()` with `origin ∈ {mine, shared}` and `status = published`) or **Write instructions here** (today's flow incl. the AI Setup Assistant). Picking an assistant hides the prompt / model / tool fields and shows a read-only summary (name, version, owner) with **Open assistant**. `WidgetEditor.vue` shows the same binding with **Unbind** (falls back to the existing topic; `useDialog` confirm). `channels.widgetDefaults` from the definition prefill appearance keys on create only. Five locales.

### 2.5 Inbound email binding (`AB37`)

A department entry in `InboundEmailHandler::$departments` gains an optional `agentId` (JSON; no migration). `InboundEmailHandlerService` after `routeEmailToDepartment()`: if the chosen department has `agentId`, the generated reply runs pinned (`agentId` in the classification options, access checked as the handler owner). `InboundConfiguration.vue` department row: **Assistant** select (published, owned or `use`). Validation on save as in `AB35`.

### 2.6 WhatsApp binding (`AB38`)

Per-user setting `BCONFIG` group `WHATSAPP`, setting `AGENTID` (nullable, written via the existing user config API; no migration). `WhatsAppService` reads it before classification: set ⇒ `agentId` option; unset ⇒ unchanged. UI: the WhatsApp channel card gets an **Assistant** select with the same validation.

### 2.7 Builder — Tasks and Channels sections (`AB39`)

- `BuilderTasks.vue`: list of templates (name, trigger type, cron with a human-readable preview, prompt, unattended toggle) and materialisation status per template ("Saved Task #12, next run …" from `savedTasksApi`).
- `BuilderChannels.vue`: read-only list of widgets, departments and WhatsApp bindings using this assistant from a new `GET /api/v1/agents/{id}/bindings` (owner / `edit`, metadata only: kind, name, id) plus the `channels.widgetDefaults` editor. The publish flow warns "3 widgets will switch to v{n}" via `useDialog`.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C1 | Flag off: `BAGENTID` ignored by `WidgetPublicController` (bound widget + flag off ⇒ topic path, identical classification options) |
| C2 | Channel paths pass `agentId` only when bound; `RoutingCharacterizationTest` untouched |
| C4 | Widget E2E suite unchanged and green; `WidgetPublicControllerBindingTest` asserts the options array for an unbound widget is byte-identical to before |
| C5 | `SavedTaskServiceTest` / `SavedTaskRunnerTest` unchanged; `AgentTaskMaterializerTest` asserts rows are plain `BSAVEDTASKS` (owner, `BPROMPTID`, trigger) and that `SavedTaskRunner::run()` needs no new argument |
| C6 | `WidgetAgentAccessTest`: binding a widget to an assistant the owner has no `use` on ⇒ 400; owner loses `use` later ⇒ runtime falls back to the topic and logs |
| C8 | Migration + PHP `backend-only`; Vue `ota-candidate`; `node scripts/mobile-impact.mjs` in every PR |

- Unit: `AgentTaskMaterializerTest` (create, update, disable on removal, idempotent re-publish), `AgentDefinitionValidatorTest` extended (`tasks[]` shape, bad cron rejected), `WhatsAppAgentBindingTest`, `InboundEmailDepartmentAgentTest`.
- Feature: `WidgetControllerAgentIdTest` (CRUD, 400 on foreign assistant), `WidgetPublicControllerBindingTest` (bound ⇒ pinned; archived ⇒ topic fallback), `AgentBindingsEndpointTest` (metadata only).
- Frontend: `WidgetAiPromptSection.spec.ts` (choice, summary, unbind), `BuilderTasks.spec.ts` (cron preview). Unfiltered gates; widget E2E run before merging `AB35` and `AB36`.

---

## 4. Exit criteria / demo

1. "Contract review" defines "Weekly digest" (`0 8 * * 1`); publishing creates a Saved Task owned by the admin, visible under Saved tasks and in the builder with its next run.
2. The demo widget is switched to the assistant in the wizard; a visitor's question is answered from the shared folder; the widget row still has its topic. Publishing v2 changes the widget's answers without touching it.
3. A support-mailbox department bound to the assistant replies pinned; the WhatsApp demo number bound to it replies pinned.
4. Widgets, handlers and numbers without a binding: E2E and feature suites green, options arrays identical.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| AB33 | `feat(agents): materialise assistant task templates as Saved Tasks` | backend-only | AB18 |
| AB34 | `feat(widgets): add nullable BWIDGETS.BAGENTID (Galera-safe migration)` | backend-only | — |
| AB35 | `feat(widgets): run a bound assistant in the public widget with topic fallback` | backend-only | AB34, AB21 |
| AB36 | `feat(widgets): offer "use an assistant" in setup wizard and editor` | ota-candidate | AB35 |
| AB37 | `feat(email): bind an inbound-email department to an assistant` | backend-only + ota-candidate | AB21 |
| AB38 | `feat(whatsapp): bind a WhatsApp number to an assistant` | backend-only + ota-candidate | AB21 |
| AB39 | `feat(assistants): add Tasks and Channels builder sections with bindings endpoint` | ota-candidate + backend-only | AB33, AB35 |

# Sprint S5 — Triggers: events and schedules

**Track 2 (Agent Builder), sprint 5 of 6.** Steps `AB33`–`AB39`.
Formerly "Tasks and channels" — renamed 2026-09-07 (master plan decision 15).

**Goal:** The owner says **when an assistant acts** in one builder section, in plain words: an **event** (a mail matching a rule arrives, a visitor writes in a website widget, a WhatsApp message comes in) or a **schedule** (every Monday at 08:00). Under the hood nothing new is invented: conversational events are bindings on the channel entity (`BWIDGETS.BAGENTID`, department `agentId`, `WHATSAPP.AGENTID`); schedules and the mail rule are ordinary Saved Tasks created on publish. Everything is additive; a widget, mailbox or number without a trigger behaves byte-identically.
**Depends on:** S3 (published versions), S4 (the tool/knowledge policy a trigger inherits). Saved Tasks (`20260816-saved-task-workflows/`) as shipped.
**Unlocks:** S6 (`triggers` is part of the exported definition; the `api` / `mcp` / `desktop` event kinds join the picker there), track 4 S5 (the `webhook` event kind and the workflow builder's "assistant" step).
**Repos:** `synaplan/` only. **Class:** `backend-only` + `ota-candidate`.
**Flag:** `AGENTS.ENABLED`; schedules and the mail **rule** additionally require the Saved Tasks flag (`SavedTaskConfig::isEnabled($ownerId)`) — off ⇒ the Schedule group and the rule option are absent (U11), stored entries stay in the definition and are not materialised.
**User-flow:** [`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) J-AB-5 and J-AB-7; wireframe [`../202609_ux_user_flows/assistant-triggers.md`](../202609_ux_user_flows/assistant-triggers.md). Words: Trigger · Event · Schedule · Runs on its own (five locales fixed there). Banned in primary copy: `cron` (outside Advanced), `binding`, `channel`, `topic`, `task template`, `webhook` (outside its own row).

---

## 0. Why this sprint exists

A published assistant is only useful in a department if it reaches people where they are — the website widget, the support mailbox, WhatsApp — and if it can do recurring work on its own. Today each channel binds to a prompt *topic*, each schedule is a separate Saved Task, and an inbound mail can only be routed by AI to a department, not by "from acme.com, containing *contract*". The first plan mirrored that fragmentation into two builder sections (*Tasks*, *Channels*). This sprint gives the owner **one** question — *when should this assistant act?* — with two answers, **event** or **schedule**, and makes the rows created there the same rows the widget editor, the mailbox page and Automations → Saved tasks show.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Entity/SavedTask.php` | `BPROMPTID`, `BTRIGGERTYPE` (`manual` / `chat` / `schedule` / `inbound_email`; `webhook` rejected until track 4 TL42), `BTRIGGERCONFIG`, `BGRAPH`, `BALLOWUNATTENDED`, `AUTO_PAUSE_AFTER` |
| `backend/src/Service/SavedTask/SavedTaskService.php` | `create(int $ownerId, int $promptId, string $name)`, `update()` — the only way a schedule or mail rule becomes a row |
| `backend/src/Service/SavedTask/SavedTaskRunner.php` | "Executes a Saved Task as the owner" — the rule assistant schedules and mail rules inherit unchanged |
| `backend/src/Command/SavedTasksProcessMailboxCommand.php`, `ProcessMailHandlersCommand.php`, `backend/src/Service/SavedTask/Graph/SavedTaskSummary.php` | Where an `inbound_email` trigger turns a fetched mail into a run — the point where the new `filter` is evaluated; `SavedTaskSummary` is the one-sentence generator the trigger row must reuse |
| `backend/src/Entity/Widget.php` | `BTASKPROMPT` (topic), `BCONFIG` JSON — `BAGENTID` goes beside them |
| `backend/src/Controller/WidgetPublicController.php` | `'fixed_task_prompt' => $widget->getTaskPromptTopic()` in the classification options — the binding point |
| `frontend/src/components/widgets/setup-wizard/WidgetSetupWizard.vue`, `WidgetAiPromptSection.vue`, `WidgetEditor.vue` | Wizard stepper and the prompt step that gains "Use an assistant" |
| `backend/src/Entity/InboundEmailHandler.php` + `backend/src/Service/InboundEmailHandlerService.php` | `BDEPARTMENTS` JSON, `routeEmailToDepartment()` (AI routing) — a department gains an optional `agentId`; the per-account "use for Saved Tasks" checkbox from the Saved Tasks plan gates the rule option |
| `backend/src/Service/WhatsAppService.php`, `backend/src/Controller/WebhookController.php` | Inbound WhatsApp classification — a per-user binding pins it |
| `frontend/src/views/SavedTasks*`, the Saved Task card component | The one-sentence card + on/off + "Paused automatically" wording the trigger row must reuse, not re-invent |
| `docs/E2E_TESTING.md`, `backend/tests/**/SavedTask*` | C4 / C5 baselines |

---

## 2. Developer steps

### 2.1 `triggers` in `agent.v1` and the materializer (`AB33`)

`AgentDefinitionValidator` (S1 `AB4`) accepts `triggers.events[]` and `triggers.schedules[]` (top-level `tasks` / `channels` never existed in a release; the S1 fixture set is updated in this PR):

- `events[]`: `id` (`[a-z0-9-]{2,32}`, unique per definition), `kind` ∈ `mail` · `whatsapp` · `widget` · `api` · `mcp` · `desktop` · `webhook`; kind-specific keys (`mailbox` `{ownerId}:{handlerId}` + either `rule` `{from[], contains[], match}` or `department`; `widget` `{ownerId}:{widgetId}` + optional `widgetDefaults`; `number` for `whatsapp`); optional `instruction`; `enabled` (default `true`). Kinds whose mechanism has not shipped (`api`, `mcp`, `desktop` before S6; `webhook` before track 4 TL42) validate but are reported as `notes: trigger_kind_unavailable` by the resolver and rendered nowhere.
- `schedules[]`: `id`, `name`, either `every` (`unit` ∈ `hour` · `day` · `weekday` · `week` · `month`, `on`, `at`) or `cron`, required `tz`, required non-empty `instruction`, `allowUnattended` (default `false`), `enabled`. The validator converts `every` to a cron string once, rejects intervals under 15 minutes (Saved Tasks rule) and bad cron.

`AgentTriggerMaterializer::sync(Agent $agent, int $ownerId)` runs on publish (and on unpublish / archive with `disable`):

- Each **schedule** and each **mail rule** event becomes one `BSAVEDTASKS` row via `SavedTaskService::create($ownerId, $agent->getPromptId(), $name)` if no row with `BTRIGGERCONFIG.agentTrigger = {slug}:{id}` exists, then `update()` with trigger type + config (`schedule` → cron + tz; `inbound_email` → `accountId` + `filter`), the `instruction` as the graph's single `chat` step, `BALLOWUNATTENDED` from the definition, and `BTRIGGERCONFIG.agentId`. Removed or disabled entries **disable** (never delete) their row; the Saved Task keeps its run history.
- Rows are ordinary Saved Tasks owned by the **owner** and run by `SavedTaskRunner` as the owner (decision 7; existing rule). `SavedTaskRunner` passes `BTRIGGERCONFIG.agentId` into the classification options so the run is pinned (S1 path); absent ⇒ unchanged. A user's *own* Saved Task may set `agentId` too (no schema change) — that is "run this assistant on my schedule" without cloning it.
- Conversational events (`widget`, `whatsapp`, mail **department**) are written to their channel entity by the same `sync()` (`AB35`, `AB37`, `AB38`) so that adding a row in the builder and adding it from the channel side converge on one state. The definition is the owner's intent; the channel entity is the runtime truth (same rule as the Saved Task trigger columns).

### 2.2 Migration — `BWIDGETS.BAGENTID` (`AB34`)

Own PR. `Widget` gets `agentId` (nullable). `BTASKPROMPT` stays `NOT NULL` — a bound widget keeps its topic as the fallback if the assistant is deleted or the flag is turned off (rollback rule, master plan §9).

```sql
ALTER TABLE BWIDGETS ADD COLUMN IF NOT EXISTS BAGENTID BIGINT NULL AFTER BTASKPROMPT;
ALTER TABLE BWIDGETS ADD INDEX IF NOT EXISTS idx_widgets_agent (BAGENTID);
```

### 2.3 Widget runtime — the `widget` event (`AB35`)

- `WidgetPublicController`: when `BAGENTID` is set **and** the flag is on, pass `agentId` instead of `fixed_task_prompt`; the resolver checks access as the widget **owner** (`use` is implied — the owner published or was granted it); execution identity stays the widget session as today (budget, rate limits). Archived or inaccessible assistant ⇒ fall back to `BTASKPROMPT` and log a warning once per widget per hour.
- `WidgetController` CRUD: `agentId` on create/update (must be owned or `use`-shared to the owner, else 400), returned in the widget DTO. OpenAPI + Zod regenerated.
- Widget summary, live-support and export paths are untouched: they read `BTASKPROMPT` and continue to (C4).

### 2.4 Widget setup — "Use an assistant" (`AB36`)

`WidgetAiPromptSection.vue` gets a first choice: **Use an assistant** (select from `agentsApi.gallery()` with `origin ∈ {mine, shared}` and `status = published`) or **Write instructions here** (today's flow incl. the AI Setup Assistant). Picking an assistant hides the prompt / model / tool fields and shows a read-only summary (name, version, owner) with **Open assistant**. `WidgetEditor.vue` shows the same with **Stop using this assistant** (falls back to the existing topic; `useDialog` confirm). `widgetDefaults` from the event entry prefill appearance keys on create only. Five locales. The J-AB-5 walk ends in the assistant's Triggers section, where the widget now appears as an event row.

### 2.5 Mail — the `mail` event, rule and department options (`AB37`)

Two options in the **Mail arrives** form; both pin the reply to the assistant:

- **Mails matching a rule** (default): the entry carries `rule` `{ from: string[], contains: string[], match: "any" | "all" }`. `AB33` materialises it as a Saved Task with `BTRIGGERTYPE = inbound_email`, `BTRIGGERCONFIG = { accountId, filter: {…}, agentId, agentTrigger }`. The ingress (`app:saved-tasks:process-mailbox` / the mail-handler hook behind the per-account checkbox) evaluates `filter` **before** creating a run: `from` matches full address or `@domain` (case-insensitive, on the envelope/`From` header), `contains` matches subject or plain body, `match` combines the keyword list; an absent filter means every mail (today's behaviour, C5). Adding a rule on a mailbox that has the "use for Saved Tasks" checkbox off turns it on with the consent sentence from the wireframe. Runs on its own as the owner; write actions follow the approval policy (track 4 S3) — the form says so in one sentence.
- **Mails the mailbox sorts into a department** (shown only when the mailbox has departments): a department entry in `InboundEmailHandler::$departments` gains an optional `agentId` (JSON; no migration). `InboundEmailHandlerService` after `routeEmailToDepartment()`: if the chosen department has `agentId`, the generated reply runs pinned (`agentId` in the classification options, access checked as the handler owner). `InboundConfiguration.vue` department row gets the same **Assistant** select so both sides converge.

Validation on save as in `AB35`. The filter is the only new contract on Saved Tasks in this track; it is additive JSON, documented in the Saved Tasks plan by reference, and `SavedTaskServiceTest` / `SavedTaskRunnerTest` are untouched.

### 2.6 WhatsApp — the `whatsapp` event (`AB38`)

Per-user setting `BCONFIG` group `WHATSAPP`, setting `AGENTID` (nullable, written via the existing user config API; no migration). `WhatsAppService` reads it before classification: set ⇒ `agentId` option; unset ⇒ unchanged. UI: the WhatsApp channel card gets an **Assistant** select with the same validation; the builder's **A WhatsApp message** event writes the same setting.

### 2.7 Builder — the Triggers section and `/triggers` endpoint (`AB39`)

- `GET /api/v1/agents/{id}/triggers` (owner / `edit`): one resolved row per entry — `kind`, the sentence parts (`what`, `when`, `does`), `runsAs` ∈ `person_talking` · `visitor` · `caller` · `owner_unattended`, `status` (`on` · `off` · `paused_auto` · `missing_target` · `no_access` · `unavailable`), `lastRun` (time + plain status), `savedTaskId` where one exists, `target` (kind, name, id — metadata only). Also returns the implicit always-on chat row. Widgets, departments and numbers that point at this assistant but have no definition entry (bound from the channel side) are listed too and adopted into the draft on the next save. OpenAPI + Zod.
- `BuilderTriggers.vue` replaces the planned `BuilderTasks.vue` + `BuilderChannels.vue`: header question, the always-on row, **Events** group with **Add event** (picker lists only kinds the instance can run now), **Schedule** group with **Add schedule** (plain picker; cron under **Advanced**), rows as in the wireframe (one sentence, runs-as, on/off, last run, `⋯` with Edit / Remove / Open where it lives). Sentences are generated from i18n templates per kind — never from the raw config. Empty state copy from the wireframe. `AddEventMailForm.vue`, `AddScheduleForm.vue` as separate components (component size rule).
- Publish flow (S3 `useDialog` confirm) lists the triggers that will switch to v{n} by name, then the groups.
- Automations → Saved tasks: rows created by an assistant show the assistant's icon and "From assistant {name}" and stay editable there; editing on that side writes back to the definition through the same `agentTrigger` key (one truth, two doors).

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C1 | Flag off: `BAGENTID` ignored by `WidgetPublicController` (bound widget + flag off ⇒ topic path, identical classification options); `/triggers` 404 |
| C2 | Channel paths pass `agentId` only when bound; `RoutingCharacterizationTest` untouched |
| C4 | Widget E2E suite unchanged and green; `WidgetPublicControllerBindingTest` asserts the options array for an unbound widget is byte-identical to before |
| C5 | `SavedTaskServiceTest` / `SavedTaskRunnerTest` unchanged; `AgentTriggerMaterializerTest` asserts rows are plain `BSAVEDTASKS` (owner, `BPROMPTID`, trigger columns) and that `SavedTaskRunner::run()` needs no new argument; `InboundEmailFilterTest`: no `filter` ⇒ every mail creates a run exactly as today |
| C6 | `WidgetAgentAccessTest`: binding a widget to an assistant the owner has no `use` on ⇒ 400; owner loses `use` later ⇒ runtime falls back to the topic, logs, and the row shows `no_access` |
| C8 | Migration + PHP `backend-only`; Vue `ota-candidate`; `node scripts/mobile-impact.mjs` in every PR |

- Unit: `AgentTriggerMaterializerTest` (create, update, disable on removal, idempotent re-publish, `every` → cron, < 15 min rejected), `AgentDefinitionValidatorTest` extended (`triggers` shape, unknown kind, duplicate id, schedule without instruction rejected), `InboundEmailFilterTest` (from / @domain / contains / any / all / absent), `WhatsAppAgentBindingTest`, `InboundEmailDepartmentAgentTest`.
- Feature: `WidgetControllerAgentIdTest` (CRUD, 400 on foreign assistant), `WidgetPublicControllerBindingTest` (bound ⇒ pinned; archived ⇒ topic fallback), `AgentTriggersEndpointTest` (rows, runs-as, channel-side bindings adopted, metadata only).
- Frontend: `WidgetAiPromptSection.spec.ts` (choice, summary, stop using), `BuilderTriggers.spec.ts` (sentence generation per kind and locale, picker hides unavailable kinds, no `cron` string outside Advanced), `AddScheduleForm.spec.ts`. Unfiltered gates; widget E2E run before merging `AB35` and `AB36`.

---

## 4. Exit criteria / demo

1. J-AB-7 walked: "Contract review" gets a mail event (*Support mailbox*, from `@acme.com`, containing "contract") and a schedule (every Monday 08:00, "Summarise last week's contract questions"). Neither form shows `cron`, `binding` or `topic`; both rows read as one sentence and say who runs them. Publishing names both. Both appear under Automations → Saved tasks with the assistant's icon.
2. A mail from `legal@acme.com` with "contract" in the subject is answered pinned to the assistant; a mail from another sender is untouched; the row shows "Last: today 09:12 · ok". Switching the row off stops the next matching mail.
3. J-AB-5 walked: the demo widget is switched via **Use an assistant** (the first control, not an advanced leftover); a visitor's question is answered from the shared folder; the widget row still has its topic; the widget appears as an event row in the assistant's Triggers section. Publishing v2 changes the widget's answers without touching it.
4. A support-mailbox department bound to the assistant replies pinned; the WhatsApp demo number bound to it replies pinned; both appear as event rows.
5. Widgets, handlers and numbers without a trigger: E2E and feature suites green, options arrays identical; Saved Tasks without `filter` behave exactly as before.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| AB33 | `feat(assistants): add triggers to agent.v1 and materialise schedules and mail rules as Saved Tasks` | backend-only | AB18 |
| AB34 | `feat(widgets): add nullable BWIDGETS.BAGENTID (Galera-safe migration)` | backend-only | — |
| AB35 | `feat(widgets): run a bound assistant in the public widget with topic fallback` | backend-only | AB34, AB21 |
| AB36 | `feat(widgets): offer "use an assistant" in setup wizard and editor` | ota-candidate | AB35 |
| AB37 | `feat(email): add mail rule filter to inbound_email trigger and department assistant binding` | backend-only + ota-candidate | AB33, AB21 |
| AB38 | `feat(whatsapp): bind a WhatsApp number to an assistant` | backend-only + ota-candidate | AB21 |
| AB39 | `feat(assistants): add Triggers builder section with resolved triggers endpoint` | ota-candidate + backend-only | AB33, AB35, AB37 |

# Saved Task Workflows — Master Plan

**Status:** Planning (draft 2026-08-14). **No code until the [decision checklist](#0-decision-checklist-check-before-any-code) is signed off.**
**Owner surface:** AI Instructions / Task Prompts (`/ai/instructions`), extended — not a new product name.
**Related:**

- [`../20260606-routing/00_master_plan.md`](../20260606-routing/00_master_plan.md) — runtime multitask DAG
- [`../release4.0/08_mcp-data-nodes-and-skill-registry.md`](../release4.0/08_mcp-data-nodes-and-skill-registry.md) — skill catalog / n8n-like node descriptors
- [`../release4.0/09_external-data-nodes.md`](../release4.0/09_external-data-nodes.md) — `email_search`, `mcp_fetch`, `url_fetch`
- [`../n8n-integration-research.md`](../n8n-integration-research.md) — Synaplan ↔ n8n surfaces (research, still valid)
- [`../n8n-integration-recipes.md`](../n8n-integration-recipes.md) — copy/paste n8n recipes
- [`../mcp-and-api-enhancements/02-mcp-integration/07-AGENT-SCHEDULING.md`](../mcp-and-api-enhancements/02-mcp-integration/07-AGENT-SCHEDULING.md) — server-authoritative schedules (not implemented)
- [`../../docs/MULTITASK_DATA_NODES.md`](../../docs/MULTITASK_DATA_NODES.md) — shipped data-node contract
- [`../../docs/MIGRATIONS.md`](../../docs/MIGRATIONS.md) — schema vs seed vs fixtures

**Sprint files (execute in order):**

| Sprint | File | Ships |
| ------ | ---- | ----- |
| 0 | [`01_sprint_0_observe.md`](./01_sprint_0_observe.md) | Visualize executed DAGs; “Save as task” UX without a new runtime |
| 1 | [`02_sprint_1_saved_task_model.md`](./02_sprint_1_saved_task_model.md) | Persist Saved Tasks on top of Task Prompts; Run now |
| 2 | [`03_sprint_2_graph_and_triggers.md`](./03_sprint_2_graph_and_triggers.md) | Authored graph + channel triggers (manual + inbound) |
| 3 | [`04_sprint_3_scheduler.md`](./04_sprint_3_scheduler.md) | User-facing cron / schedules |
| 4 | [`05_sprint_4_connectors_plugins_n8n.md`](./05_sprint_4_connectors_plugins_n8n.md) | Outbound actions, plugin nodes, n8n interface |
| — | [`06_testing_and_documentation.md`](./06_testing_and_documentation.md) | Gate, characterization, E2E, docs inventory (applies to every sprint) |

---

## 0. Decision checklist (check before any code)

Print this section. Tick every box. Do **not** start Sprint 1 until rows 1–8 are agreed.

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **Do not embed n8n** in the Synaplan image, Docker Compose, or platform. Interface with n8n (and any other iPaaS) via APIs we already have + outbound webhooks. Rationale: [§4](#4-n8n-embed-vs-interface). | **Interface, do not embed** | ☐ |
| 2 | **Evolve Task Prompts**, do not replace `BPROMPTS` / `BPROMPTMETA`. A Saved Task *references* a Task Prompt (topic + tools + model + RAG group). | **Extend** | ☐ |
| 3 | User-facing name: **Saved Task** (EN). Keep nav **AI Instructions**. Do not introduce “workflow / DAG / n8n / node” in primary copy. | Canonical term locked | ☐ |
| 4 | Two graphs stay distinct: **runtime DAG** (planner, per turn, `BMESSAGE_TASKS`) vs **authored graph** (user-saved, durable). Never compile `tools:plan` / `tools:sort` text into the authored canvas. | Distinct models | ☐ |
| 5 | First vertical (acceptance story): *“Look into my connected mailbox and create calendar entries for meeting requests.”* Honest v1 output is **`.ics` + optional `email_me`**, not Office 365 write-back. Graph/Outlook write is Sprint 4+ and a separate connector. | Mail in → Task Prompt → `.ics` | ☐ |
| 6 | Mutating outbound (calendar write, MCP write, FastBill, mail send) **always confirms** on first interactive run; scheduled runs use an explicit “allow unattended” flag per action node. | Confirm-then-automate | ☐ |
| 7 | Plugin tools (Synamail, Synasort, Synaform, Synafastbill, …) join the graph **only when installed** for that user, via a new optional manifest key (`graphNodes`). `chatCommands` stay the slash-command seam. | Manifest seam, later | ☐ |
| 8 | Feature flag: `SAVEDTASKS.ENABLED` in `BCONFIG` (default **off** for existing installs; seed **on** for new installs — same grandfather pattern as `MULTITASK.ROUTING_ENABLED`). Widget chat **does not** run Saved Tasks. | Flag + widget invariant | ☐ |
| 9 | Schema is additive only. Galera-safe migrations (`addSql`, `IF NOT EXISTS`). No `doctrine:schema:update --force`. Ask again before the first migration lands. | AGENTS.md | ☐ |
| 10 | Office 365 / Microsoft Graph is **out of v1**. Track as connector epic after Saved Tasks Run-now + schedule work. | Deferred | ☐ |

If a row is rejected, update this file in the same change as the alternative — do not leave the sprint files implying the old default.

---

## 1. Why this exists (the trigger)

Users already write Task Prompts (“when this kind of request arrives, behave like this”). They already have channels (web, widget, WhatsApp, email, API, MCP) and a runtime capability DAG (`extract_text` → `chat` → `calendar_event` → …).

What they cannot do is **pin a standing job**:

> Every morning, look into my mail, apply *this* Task Prompt (“find meeting requests”), and put results on my calendar.

That is not a visualization of the routing prompt. It is a **Saved Task**: a Task Prompt plus a trigger, optional authored graph, and (later) a schedule.

The Chat widget’s connect-the-boxes UI is the closest interaction pattern we already ship. It must **not** be reused as the data model (it compiles to prompt comments). The multitask `DagExecutor` is the closest runtime. It must **not** be driven by guessing a graph from `tools:plan` text.

---

## 2. What already exists (do not rebuild)

| Piece | State | Role in this plan |
| ----- | ----- | ----------------- |
| `BPROMPTS` + `BPROMPTMETA` | Shipped | Instruction, selection rules, `aiModel`, `tool_internet` / `tool_files` / `tool_url_screenshot` / `tool_mcp`, RAG group `TASKPROMPT:{topic}` |
| AI Instructions UI | Shipped | `TaskPromptsConfiguration.vue` — CRUD, tools, files, rules |
| `MessageSorter` selection rules | Shipped | Keyword auto-route into a topic (chat trigger, not cron) |
| `TaskPlanner` / `DagExecutor` / `Capability` | Shipped | Runtime DAG for one message. Capabilities include `email_search`, `calendar_event`, `email_me`, `mcp_fetch`, `compose_reply` |
| `BMESSAGE_TASKS` | Shipped | Observability of *executed* plans — input to Sprint 0 visualization |
| Widget `FlowData` | Shipped | UX reference only (triggers / responses / SVG links). Persistence is prompt compilation — **do not extend this for Saved Tasks** |
| Inbound email | Shipped | `app:process-emails`, mail handlers, `email_search` (read-only IMAP). Pickup is **ops cron**, not a user schedule |
| `calendar_event` | Shipped | RFC 5545 `.ics` download — **not** Graph/Outlook write |
| MCP client + server | Shipped | Client: `mcp_fetch` (read-only). Server: `/mcp` tools including `list_prompts`, `synaplan_chat` |
| Plugin `chatCommands` | Shipped | Slash commands (e.g. `/fastbill`). **Not** graph nodes yet |
| n8n → Synaplan | Works today | OpenAI-compat `/v1`, MCP `/mcp`, generic webhook, REST — see n8n research |
| Synaplan → n8n | **Gap** | No outbound webhook / event emitter |
| User scheduler | **Missing** | Docker `SYNAPLAN_ROLE=scheduler` is maintenance only |
| Office 365 / Graph | **Missing** | No connector |

**Known gap to fix inside Sprint 1 (not optional):** `ChatRunner` documents `params.topic_id` but multi-node intermediate `chat` nodes still use a generic system prompt. Saved Tasks that “run this Task Prompt” will silently ignore the prompt until that binding is complete. Characterization tests must lock the fix.

---

## 3. Target architecture

```
                    ┌─────────────────────────────────────────┐
                    │  Task Prompt (existing)                 │
                    │  topic, system text, tools, model, RAG  │
                    └─────────────────┬───────────────────────┘
                                      │ referenced by
                    ┌─────────────────▼───────────────────────┐
                    │  Saved Task (new)                       │
                    │  name, enabled, unattended flags        │
                    │  trigger(s) + authored graph JSON       │
                    └─────────────────┬───────────────────────┘
                                      │
         ┌────────────┬───────────────┼───────────────┬────────────┐
         ▼            ▼               ▼               ▼            ▼
      Chat/API    Inbound email    Schedule       WhatsApp      Webhook
      (existing)  (mailbox)        (new)          (later)       (n8n in)
                                      │
                                      ▼
                         DagExecutor + runners
                         (existing capability set
                          + plugin graphNodes later)
                                      │
                    ┌─────────────────┼─────────────────┐
                    ▼                 ▼                 ▼
              compose_reply      email_me / .ics    outbound webhook
              (same channel)     (honest v1 cal)    (n8n / iPaaS)
```

### 3.1 Layering (non-negotiable)

| Layer | User authors? | Persistence | Runtime |
| ----- | ------------- | ----------- | ------- |
| **Task Prompt** | Yes — instruction + tools | `BPROMPTS` / `BPROMPTMETA` | Sorter + ChatHandler / ChatRunner |
| **Saved Task** | Yes — when / from where / to where | New tables (Sprint 1) | Dispatcher → synthetic inbound message or fixed `TaskPlan` |
| **Runtime DAG** | No — planner or compiled from authored graph | `BMESSAGE_TASKS` (derived) | `DagExecutor` |
| **n8n / external iPaaS** | Outside Synaplan | n8n’s DB | n8n calls us, or we POST to their webhook |

### 3.2 Canonical example (v1 honest)

**Saved Task:** “Meeting requests from mail”

1. **Trigger:** schedule `weekdays 07:00` Europe/Berlin **or** Run now.
2. **Process:** `email_search` (connected IMAP) → `chat` with Task Prompt *“Extract meeting requests; ignore newsletters; output structured events.”*
3. **Action:** `calendar_event` (`.ics`) + optional `email_me`.

Copy in the UI must say the result is a calendar file / email, **not** “added to Outlook”, until a Graph connector exists.

### 3.3 Proposed data model (Sprint 1 — review with schema ask)

Do **not** stuff schedules and graphs into `BPROMPTMETA`. That bag is already the tool/model/widget-rules kitchen sink.

Additive tables (names illustrative; final names follow existing `B*` convention or a clear `saved_task*` pair — pick one style in the migration review and stay consistent):

**`saved_tasks`**

| Column | Role |
| ------ | ---- |
| `id` | PK |
| `owner_id` | User |
| `prompt_id` | FK → `BPROMPTS.BID` (the Task Prompt this task runs) |
| `name` | User label |
| `enabled` | Bool |
| `trigger_type` | `manual` \| `chat` \| `schedule` \| `inbound_email` \| `webhook` (chat = today’s “topic matched”; default for migrated prompts) |
| `trigger_config` | JSON (cron/rrule + tz; mailbox id; webhook secret) |
| `graph` | JSON authored DAG (Sprint 2; null = implicit single `chat` node with this prompt) |
| `allow_unattended` | Bool — required for schedule + mutating actions |
| `created_at` / `updated_at` | Timestamps |

**`saved_task_runs`**

| Column | Role |
| ------ | ---- |
| `id`, `saved_task_id`, `status` | `queued` / `running` / `completed` / `failed` / `cancelled` |
| `trigger` | What started it |
| `message_id` | Optional link to `BMESSAGES` when the run went through the chat pipeline |
| `plan_snapshot` | JSON of the TaskPlan actually executed |
| `error`, `started_at`, `finished_at` | Observability |

Galera: raw `CREATE TABLE IF NOT EXISTS` in a Doctrine migration; no Schema API. Delete child runs before parent tasks (no assumed `ON DELETE CASCADE`).

`chat` trigger type with empty graph **preserves today’s Task Prompt behaviour** — every existing prompt remains a valid Saved Task with no user action.

---

## 4. n8n: embed vs interface

### 4.1 Recommendation (locked unless checklist row 1 is rejected)

**Interface with n8n. Do not add n8n to the product.**

Synaplan’s job is the AI/RAG/routing brain and (after this work) **Saved Tasks over our own channels and plugins**. n8n’s job is general-purpose iPaaS (1_000+ SaaS connectors, ops-style branching, retries). Those products should **call each other**, not share a process.

This matches the existing research conclusion ([`n8n-integration-research.md`](../n8n-integration-research.md)): Pattern A (n8n → Synaplan) already works; Pattern B needs outbound webhooks; nobody proposed bundling n8n.

### 4.2 Why not embed

| Reason | Detail |
| ------ | ------ |
| **License** | n8n is fair-code (Sustainable Use License), not Apache/MIT. Bundling it into Synaplan Cloud, or shipping it so Synaplan “includes automation”, is a legal/product conflict. A commercial n8n agreement would still make us an n8n operator. |
| **Identity** | Two canvases (n8n editor vs AI Instructions) teach users that Synaplan is incomplete. Task Prompts would compete with n8n workflows for the same jobs. |
| **Ops** | Second runtime (Node, its own DB, queue, versioning, CVE clock) in `synaplan-platform`, memory limits, backups, SSO, multi-tenant isolation. We already struggle to keep one stack simple for self-hosters. |
| **We already have the DAG** | `Capability` + `TaskRunner` + `SkillCatalog` + `DagExecutor` **is** the n8n-like model, adapted to an LLM planner. Embedding n8n duplicates the executor. |
| **Moat** | Synamail / Synasort / Synaform / Synafastbill should appear as **first-class graph nodes when installed**, not as community n8n nodes that bypass our confirm/rate-limit/tenant rules. |
| **Self-hosters who want n8n already run it** | Document “point n8n at `/mcp`”. Do not fork their architecture into ours. |

### 4.3 How we interface (build this, in order)

1. **Document (Sprint 0/4, zero backend):** `docs/N8N.md` + keep recipes. MCP Client Tool → `/mcp`; OpenAI node → `/v1`; HTTP Request → `/api/v1/webhooks/generic`.
2. **Saved Task action node `outbound_webhook` (Sprint 4):** user pastes an n8n (or Make, Zapier, custom) webhook URL + HMAC secret. This is the native “then call my automation” box — not n8n-specific in the UI (“Send to webhook”).
3. **Platform outbound events (Sprint 4, optional but high leverage):** `message.classified`, `saved_task.completed`, `document.indexed` → HMAC POST. Unlocks “when Synaplan classifies as sales, n8n creates the CRM lead” without polling.
4. **Optional later:** community node `n8n-nodes-synaplan` for discoverability. Not required for the product to work.
5. **MCP as the preferred brain link:** n8n AI Agent + Synaplan MCP already exposes `synaplan_chat`, RAG, memories, `list_prompts`. After Saved Tasks ship, add MCP tools `list_saved_tasks` / `run_saved_task` (scoped API key).

### 4.4 What we will never do in this epic

- Add an `n8n` service to `docker-compose.yml` / platform compose.
- Vendor n8n source, embed the n8n editor in an iframe as “Synaplan Automations”.
- Proxy n8n credentials through our backend.
- Implement 1_000 SaaS connectors ourselves because n8n has them — that is what the webhook / MCP escape hatches are for.

---

## 5. Interaction with other Synaplan tools

Treat sibling tools as **optional graph node packs**, not as required dependencies of core.

| Tool | Today | Graph role (later, Sprint 4+) |
| ---- | ----- | ----------------------------- |
| **Synamail** | Outlook add-in; plugin in `plugins/synamail` | Trigger: “this Outlook item”; Action: draft/reply in mailbox. Calendar write still needs Graph, not the add-in. |
| **Synasort** | Local Docker + plugin classify/analyze-file | Process node: `sortx_classify` / `sortx_analyze_file` when plugin installed |
| **Synaform** | Plugin | Process/action: structured form extract / submit |
| **Synafastbill** | `/fastbill` chatCommands, confirm-then-write | Action node with the **same confirmation invariant** as the chat path |
| **MCP servers** (user-connected) | `mcp_fetch` read-only | Process: fetch. Action: mutating MCP is a **new** capability, flag-gated, confirm-then-run |
| **n8n** | External | Action: outbound webhook; Trigger: inbound generic webhook already exists |

**Manifest seam (design now, implement when the first plugin needs it):**

```json
{
  "graphNodes": [
    {
      "id": "sortx_classify",
      "kind": "process",
      "labelKey": "plugins.sortx.graph.classify",
      "endpoint": "/classify",
      "confirmation": "none"
    }
  ]
}
```

Rules:

- Core palette = `Capability` enum (always present, flag-gated per existing `MULTITASK.*`).
- Plugin palette = union of `graphNodes` from **installed** plugins for that user.
- No plugin → no node. Uninstalling a plugin disables Saved Tasks that reference its nodes (fail the run honestly; do not silently skip).
- `chatCommands` remain slash-only. Do not auto-promote `/fastbill` to a graph node without an explicit `graphNodes` entry (write confirmation must stay).

---

## 6. UX principles

1. **One canonical term:** Saved Task. German: *Gespeicherte Aufgabe* (confirm in i18n review). Spanish/Turkish: add in the same sprint as the UI — all four locales, always.
2. **AI Instructions remains home.** Tabs or sections: *Prompt* (today) | *When to run* | *Graph* (Sprint 2) | *Runs*. Do not add a second nav item until the feature is GA and the IA is reviewed.
3. **Widget canvas as interaction, not storage.** Two-column boxes + Bézier links are fine. Persist `graph` JSON on the Saved Task. Do not write `<!-- WIDGET_RULES_START -->` blocks.
4. **Do not visualize the routing prompt as the graph.** Sprint 0 visualizes *executed* `TaskPlan`s (debug/history). Sprint 2 visualizes *authored* graphs. Mixing them is a product bug.
5. **Copy is honest.** `.ics` is “calendar file”, Graph is “Outlook calendar” only after the connector exists.
6. **Default-off mobile / widget.** Saved Tasks are web + API + scheduled. Chat widget and mobile app must keep current behaviour without the flag. Classify new paths in `.github/mobile-impact-policy.json` (`ota-candidate` for AI Instructions UI; `backend-only` for PHP/scheduler).
7. **Styling:** `style.css` / V2 tokens, WCAG AA both themes, `useDialog` / `useNotification`, no hardcoded strings.

---

## 7. Hard constraints (every sprint)

1. **Zero regressions** on plain chat, slash commands, widgets, WhatsApp, email pipeline, MCP client/server.
2. **Widget invariant:** ChatWidget continues to skip multitask task-card UI and does **not** execute Saved Task schedules.
3. **Planner never picks models.** Saved Tasks inherit `PromptMeta.aiModel` → `DEFAULTMODEL.*` exactly as Task Prompts do today ([routing master plan](../20260606-routing/00_master_plan.md) migration principle).
4. **Data nodes stay read-only** until an explicit mutating capability + flag + confirmation lands (`docs/MULTITASK_DATA_NODES.md` contract).
5. **Test-driven.** A sprint is not done until [`06_testing_and_documentation.md`](./06_testing_and_documentation.md) for that sprint is green, including the **unfiltered** pre-commit gate.
6. **English** in code, comments, commits. Conventional Commits. No AI attribution. No commits to `main`.
7. **Secrets:** webhook URLs may be user-config; HMAC secrets never logged; never commit `.env`.
8. **Characterization:** any sorter/planner/classifier change re-records `tests/Characterization/` snapshots **and reviews the diff**.

---

## 8. Rollout

Same pattern as multitask routing:

1. Code behind `SAVEDTASKS.ENABLED` (per-user row → global row → code default off).
2. Seeder insert-if-missing: global `true` for **new** installs.
3. Grandfather migration: existing users get an explicit `false` row **or** we leave the code default off until GA — **pick at Sprint 1 review**. Recommendation: **default off until Sprint 2 graph is usable**, then seed on for new installs only.
4. Shadow: Sprint 0 is UI-only on history. Sprint 1 Run-now is the first mutating path (creates messages / `.ics`).
5. Rollback: flag off. Tables remain (additive). Runs stop being claimed by the scheduler.

---

## 9. Out of scope (v1)

- Embedding or operating n8n / Make / Zapier.
- Microsoft Graph / Google Calendar write.
- Mutating MCP tools.
- User-defined arbitrary HTTP (SSRF). Outbound webhook is **user-configured HTTPS URL** with SSRF guard, allowlist optional for cloud.
- Rewriting `tools:plan` / `tools:sort` into a visual editor.
- Letting the widget flow editor drive Saved Tasks.
- Autonomous browser agents (`07-AGENT-SCHEDULING.md`) — schedules here are **server-side jobs**, not MCP pull-queues. Keep that design for brogent; do not conflate tables.

---

## 10. Success criteria (epic)

A user who has connected an IMAP mailbox can:

1. Open AI Instructions, create or pick a Task Prompt “Extract meeting requests”.
2. Click **Save as task**, set trigger to weekday morning (Sprint 3) or **Run now** (Sprint 1).
3. See a run history with the executed DAG (Sprint 0 visualization reused).
4. Receive `.ics` files / an email with invites — not a claim that Outlook was updated.
5. Optionally add an action “Send to webhook” and have n8n receive the structured result (Sprint 4).
6. Disable the task; no further runs. Flag-off restores the product as if this epic did not exist (minus empty tables).

---

## 11. Workflow for each sprint

1. Re-read this master plan + the sprint file + [`06_testing_and_documentation.md`](./06_testing_and_documentation.md).
2. Implement behind `SAVEDTASKS.ENABLED` (from Sprint 1 onward).
3. Update OpenAPI annotations if HTTP surface changed → `make -C frontend generate-schemas` → `vue-tsc`.
4. i18n: `en`, `de`, `es`, `tr` in the same change.
5. Docs listed in the sprint “Documentation” section.
6. Full gate (not `--filter`):

   ```bash
   make lint && make -C backend phpstan && make test \
     && docker compose exec -T frontend npm run check:types \
     && make -C frontend test
   ```

7. If routing/classifier/planner changed: re-record characterization snapshots and **review every changed line**.
8. Stop after two failed approaches; update the sprint file with `WIP: blocked because …` rather than widening scope.

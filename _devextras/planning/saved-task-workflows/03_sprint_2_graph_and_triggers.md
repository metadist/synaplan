# Sprint 2 — Authored graph and channel triggers

**Goal:** The user can draw a **small standing DAG** on a Saved Task (in → process → out) and attach **non-schedule** triggers: Run now, chat (existing topic routing), inbound email mailbox, inbound webhook.

**Depends on:** Sprint 1. **Unlocks:** Sprint 3 (scheduler is just another trigger). Sprint 4 (plugin / n8n action nodes).

**Flag:** same `SAVEDTASKS.ENABLED`. Optionally `SAVEDTASKS.GRAPH_ENABLED` if you need a slower rollout of the canvas.

---

## 0. Product rules

1. The canvas edits `saved_tasks.graph` JSON. It does **not** compile into the Task Prompt body and does **not** use `widgetBehaviorRules.ts`.
2. The canvas does **not** parse `tools:plan` / `tools:sort` text.
3. Empty / null graph = Sprint 1 behaviour (single `chat` with the linked Task Prompt).
4. Palette v1 (core only — plugins in Sprint 4):

   | Kind | Nodes |
   | ---- | ----- |
   | Trigger (one required) | `manual`, `chat`, `inbound_email`, `webhook` — schedule node is drawn disabled with “Sprint 3” or omitted until then |
   | Process | `chat` (this Task Prompt), `email_search`, `rag_query`, `mcp_fetch` (if `tool_mcp` on the prompt), `summarize` |
   | Action | `compose_reply`, `calendar_event` (`.ics`), `email_me` |

5. Invalid graphs (cycles, unknown capability, missing trigger) **fail validation on save** with a specific error. Do not store and hope.

---

## 1. Graph JSON contract

Lock this schema in PHP (`SavedTaskGraph` DTO + validator) **and** a Zod schema generated or hand-mirrored in frontend **from a shared fixture test** (same JSON in PHPUnit + Vitest). Prefer one PHP OpenAPI component that frontend generates.

```json
{
  "version": 1,
  "trigger": { "id": "t1", "type": "inbound_email", "config": { "accountId": 123 } },
  "nodes": [
    { "id": "n1", "capability": "email_search", "depends_on": [], "params": {} },
    { "id": "n2", "capability": "chat", "depends_on": ["n1"], "params": { "topic_id": "<from saved task prompt>" } },
    { "id": "n3", "capability": "calendar_event", "depends_on": ["n2"], "params": {} },
    { "id": "n4", "capability": "compose_reply", "depends_on": ["n3"], "params": {} }
  ]
}
```

Rules:

- **Trigger source of truth:** the `saved_tasks.trigger_type` / `trigger_config` **columns are authoritative** (master plan §3.3). The graph's trigger box is a rendered view; saving the editor writes the columns in the same transaction, and the validator rejects a payload whose graph trigger disagrees with the columns. Runtime code (scheduler, ingress adapters) reads columns only — never `graph.trigger`.
- `depends_on` acyclic; ids unique.
- `chat` nodes inherit the Saved Task’s `prompt_id` unless `params.topic_id` overridden (v1: no override UI).
- Trigger `inbound_email`: `accountId` must be an `InboundEmailHandler` account **owned by this user**.
- Trigger `webhook`: platform generates a URL + secret (store hashed secret). POST body becomes `$message.text`.
- Trigger `chat`: no extra config — sorter/selection-rules still route live chat to the Task Prompt; the authored graph runs **instead of** free planner when this task is enabled and graph is non-null. **Lock this:** see §3.

---

## 2. Developer steps — backend

### 2.1 Validator

`SavedTaskGraphValidator`:

- JSON schema version 1 only.
- Capability ∈ `Capability` enum (and later plugin ids).
- Cycle detection (reuse `DagExecutor` topology thinking; unit-test a diamond and a cycle).
- Feature flags: if `MULTITASK.EMAIL_SEARCH_ENABLED` is off, reject `email_search` nodes with a specific message.
- MCP: reject `mcp_fetch` unless the linked prompt has `tool_mcp`.

### 2.2 Compile graph → `TaskPlan`

`SavedTaskPlanFactory::fromGraph(...)` produces the same `TaskPlan` the planner would. Then `TaskPlanExecutor` / `DagExecutor` run unchanged.

Do **not** call `TaskPlanner` when an authored graph is present. The user already planned.

Forced inputs:

- `$message.text` = inbound body (chat text, email body, webhook payload, run-now dialog).
- `$message.files` = attachments if the ingress has them.

### 2.3 When the graph runs vs the planner

| Ingress | Graph null | Graph present |
| ------- | ---------- | ------------- |
| Run now | Planner (Sprint 1) | Compiled plan, skip planner |
| Chat, topic matches this prompt, task enabled | Planner (today) | Compiled plan, skip planner |
| Chat, other topics | Unchanged | Unchanged |
| Widget | Never Saved Tasks | Never |
| Inbound email trigger | n/a | Only if this mailbox is the trigger; **do not** steal department mail-handler routing |

**Compatibility invariant C3 guard:** the chat short-circuit fires **only** when (a) the flag is on for this user, (b) the classified topic has an enabled Saved Task, and (c) that task has a non-null graph. Every other chat turn must reach `TaskPlanner` / the legacy path byte-identically — locked by characterization snapshots with zero-graph fixtures.

**Clear communication:** when a chat turn is short-circuited by an authored graph, the user must be able to see why the answer followed fixed steps — show the Saved Task name on the turn (e.g. in the plan disclosure from Sprint 0). Silent behaviour switches confuse users and support alike. The AI Instructions editor also states it plainly on save: *"Chats that match this instruction will now follow your saved steps."*

**Mail-handler invariant:** `tools:mailhandler` department forwarding stays a separate product. A Saved Task trigger on a mailbox is opt-in per account. If both exist, document precedence: **mail handler first** (existing), Saved Task only for accounts **not** configured as department routers — **or** explicit “use for Saved Tasks” checkbox on the email account. **Lock in this sprint’s PR:** checkbox on the account is clearer. Default off.

### 2.4 Ingress adapters

| Trigger | Implementation |
| ------- | ---------------- |
| `manual` | Sprint 1 run endpoint |
| `chat` | `MessageProcessor` / `TaskPlanExecutor` hook: if classification topic has an enabled Saved Task with graph, compile instead of plan |
| `inbound_email` | After IMAP fetch (mail handler **or** a dedicated fetch in Sprint 3). For Sprint 2, implement **webhook-shaped tests** + a command `app:saved-tasks:process-mailbox {accountId}` that can be invoked manually; wire into existing `ProcessMailHandlersCommand` **only** behind the per-account checkbox |
| `webhook` | `POST /api/v1/saved-tasks/hooks/{publicId}` — HMAC or secret header, rate-limited, SSRF N/A (inbound). Auth is the secret, not the user session. **Must not touch the OIDC/session firewalls** (invariant C1): stateless route, secret-only authenticator, `security.yaml` diff limited to this path. Abuse handling: per-hook rate limit; repeated bad-secret hits temporarily lock the hook (429/disabled) and surface a notice on the task — never silently drop |

Create a run row for every firing.

---

## 3. Developer steps — frontend

1. New view section on the Saved Task: graph editor. Extract shared SVG-link behaviour from `WidgetDetailView` into a **generic** composable (`useFlowCanvas.ts`) used by **both** widget (unchanged behaviour) and Saved Tasks — **only if** the extract is clean. If the extract risks widget regressions, **copy the interaction pattern** into `SavedTaskGraphEditor.vue` and leave the widget alone. **Prefer copy-then-share later** over a risky extract. Record the choice in the PR.
2. Palette filtered by flags + prompt tools (hide `mcp_fetch` if `tool_mcp` off).
3. Node inspector: reuse layout patterns from `FlowNodeEditor.vue`; Saved Task fields differ (capability params, not crawlInterval).
4. Save validates via API (server is source of truth); show server error strings through i18n keys where possible.
5. Light / dark / V2 contrast on the canvas (WCAG AA). Overlays (palette, inspector) must **not** inherit composer-style ink from a parent (known V2 bug class).
6. i18n: **trigger**, **step**, **action** — not node/DAG.

---

## 4. Testing

| Layer | Assert |
| ----- | ------ |
| Unit | Validator: cycle rejected; unknown capability rejected; email_search rejected when flag off; mcp_fetch rejected without `tool_mcp` |
| Unit | `SavedTaskPlanFactory` → TaskPlan ids/depends_on match; executor can run with mocked runners |
| Unit | Chat hook: matching topic + graph skips `TaskPlanner`; other topics still plan |
| Unit | Webhook: bad secret 401; good secret creates run |
| Feature | Mailbox checkbox off → IMAP path unchanged (existing mail handler tests still pass) |
| Characterization | Chat hook **will** change routing for users with a graph. Default: no graphs in fixtures → snapshots unchanged. Add **new** characterization cases for “saved task graph short-circuits planner” |
| Vitest | Editor: add two steps, connect, save payload; cycle shows error |
| E2E | Save graph with chat → calendar_event; Run now; `.ics` or document card appears (TestProvider) |
| Widget E2E | Widget flow editor still saves prompt rules — **mandatory regression** |
| Gate | Unfiltered + `generate-schemas` if OpenAPI changed |

---

## 5. Documentation

| Doc | Change |
| --- | ------ |
| `docs/FEATURES.md` | Saved Task graph: triggers and steps. Honest calendar = file. |
| `docs/DEVELOPMENT.md` | Pipeline diagram: optional Saved Task compile **before** TaskPlanner |
| `docs/EMAIL.md` or mail-handler doc | Precedence: department router vs Saved Task checkbox |
| `docs/MULTITASK_DATA_NODES.md` | Note that authored graphs may place the same data nodes; still read-only |
| OpenAPI | Graph schema, webhook ingress |
| i18n | four locales |

---

## 6. Release gate

- [ ] Null graph = Sprint 1 behaviour (characterization + E2E).
- [ ] Non-null graph skips planner; `DagExecutor` runs compiled plan.
- [ ] Cycles and illegal capabilities cannot be saved.
- [ ] Widget flow editor regression green.
- [ ] Mail handler behaviour unchanged unless the new checkbox is on.
- [ ] Webhook trigger authenticated by secret; no public unauthenticated execute.
- [ ] Copy never says Outlook/Office 365.
- [ ] Unfiltered gate green; mobile-impact paths classified.

---

## 7. Handoff to Sprint 3

Scheduler adds `trigger.type = schedule` and a claim loop. Graph compiler must already accept a trigger that is not an HTTP request (no `$request`). Runs created from a clock look like Run now with a system message body (“Scheduled run of {name} at {iso}”).

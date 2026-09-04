# Tools, Approval & Workflows — master plan

**Status:** Decisions ticked 2026-09-03 (log in [`STATUS.md`](./STATUS.md)).
Track 4 of [`../20260903_roadmap.md`](../20260903_roadmap.md).
Depends on track 2 (policies attach to assistants) and track 1 (policies per
group; tools are shareable). S1 (registry refactor) can start earlier.
Sprint files: [`01_sprint_1_registry_refactor.md`](./01_sprint_1_registry_refactor.md) …
[`05_sprint_5_workflow_builder_and_webhook.md`](./05_sprint_5_workflow_builder_and_webhook.md).
**Owner surface:** Manage → Automations (Saved Tasks, workflows, approvals
inbox) and Manage → Connections (custom tools live beside MCP servers).
**Flags:** `TOOLS.REGISTRY_ENABLED` (S1, refactor guard), `TOOLS.APPROVALS_ENABLED`,
`TOOLS.CUSTOM_HTTP_ENABLED`, `WORKFLOWS.BUILDER_ENABLED` — default off.
**Related:**

- [`../20260816-saved-task-workflows/`](../20260816-saved-task-workflows/00_master_plan.md)
  — the engine this builds on (`BSAVEDTASKS.BGRAPH`, triggers, `allow_unattended`,
  Phase M confirm card, "interface with n8n, do not embed it")
- [`../20260822-open-plugin-platform/README.md`](../20260822-open-plugin-platform/README.md)
  — `provides.skills`, `provides.commands`
- `backend/src/Mcp/` (`ToolAnnotations`), `AI/Messages/Tools/GatewayToolLoop.php`,
  `AI/OpenAI/OpenAiGatewayToolLoop.php`, `Service/Document/Tool/*`
- [`../202609_secure_compute/00_master_plan.md`](../202609_secure_compute/00_master_plan.md)
  — `code_run` is a write-class tool governed by this track's policy

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **One `ToolDescriptor`, one `ToolRegistry`.** Every callable the AI can invoke — gateway builtins, MCP client tools, document tools, DAG skills (via `SkillDescriptor` adapter), plugin commands (opt-in), custom HTTP tools — is described once: `name`, `title`, `description`, `inputSchema`, `sideEffect`, `source`, `owner`. The three loops (`GatewayToolLoop`, `OpenAiGatewayToolLoop`, `ChatToolLoop`) and the planner read from it. | Locked | ✅ 2026-09-03 |
| 2 | **Side-effect classes: `read`, `write`, `destructive`.** MCP `readOnlyHint` / `destructiveHint` map onto them; unknown = `write`. Custom tools declare their class; the declaration is reviewed by whoever shares the tool. | Three classes | ✅ 2026-09-03 |
| 3 | **Policy outcomes: `auto`, `approve`, `block`.** Instance defaults: `read → auto`, `write → approve`, `destructive → block`. Overridable per assistant (track 2 definition `tools.policy`), per group (IAM policy layer), per tool. Most restrictive wins. | Defaults as stated | ✅ 2026-09-03 |
| 4 | **Interactive approval reuses the Phase M confirm card**, generalized: one `ApprovalCard.vue` for every write-class action in a live chat. `BMCPSERVERS.BALLOWWRITE` keeps its meaning (a hard `block` on that server when off). | Generalize, do not fork | ✅ 2026-09-03 |
| 5 | **Unattended approval pauses the run.** A Saved Task run that hits `approve` writes a `BAPPROVALS` row, sets the node to `waiting_approval`, notifies the owner (in-app + email; mobile push later), and **resumes from that node** when approved. `allow_unattended` becomes "auto-approve write-class actions for this task" and remains available. | Pause and resume | ✅ 2026-09-03 |
| 6 | **Approvals expire** (default 72 h, per task configurable); an expired approval fails the node with a readable reason and pauses the task after three consecutive failures (existing rule). | 72 h | ✅ 2026-09-03 |
| 7 | **Custom tools v1 = HTTP and OpenAPI import.** `BTOOLS` rows of type `http` (method, URL template, headers, auth from `BCREDENTIALS`, input JSON schema, response mapping, side-effect class) and `openapi` (import operations from a spec into many `http` tools). Executed through `SsrfGuard`. No code, no scripting. | HTTP + OpenAPI | ✅ 2026-09-03 |
| 8 | **Tools are shareable resources** (IAM kind `tool`, `use` to call it, `edit` to change it). A shared tool's credentials stay the owner's (`BCREDENTIALS` reference); the call runs with the owner's credential and the caller's identity in the audit log. | IAM kind | ✅ 2026-09-03 |
| 9 | **Workflow builder v1 is a step list, not a canvas.** Linear-first editor over `BSAVEDTASKS.BGRAPH` (steps = skills, tools, assistants; each with inputs from previous steps; optional branches via a simple condition step). A node canvas (Vue Flow or similar) is v2 and a dependency decision. | Step list first | ✅ 2026-09-03 |
| 10 | **Workflow nodes are skills and tools, nothing new.** No new execution engine; `DagExecutor` runs what the builder saves. An assistant is a node (`chat` with a `RuntimeProfile`). | Reuse the DAG | ✅ 2026-09-03 |
| 11 | **n8n stays an interface.** Outbound webhook node and inbound `webhook` trigger (constant exists, ingress added here, S5) are the seams; n8n is not embedded. | Locked (from saved-tasks plan) | ✅ 2026-09-03 |
| 12 | **Schema (ask recorded):** `BAPPROVALS` (S2), `BTOOLS` (S4), `BSAVEDTASK_RUNS` gains `BWAITINGNODE` (S3). Galera-safe `addSql`. | Ask recorded | ✅ 2026-09-03 |
| 13 | **Characterization:** the registry refactor (S1) must not change routing snapshots or the planner's `[CAPABILITYLIST]` text. | Locked | ✅ 2026-09-03 |
| 14 | **Mobile:** backend `backend-only`; approvals inbox, tool editor, builder `ota-candidate`. Push notifications for approvals are a mobile-app item, not this track. | Locked | ✅ 2026-09-03 |

---

## 1. The concept in three sentences

> A **tool** is one thing the AI can do — look something up, send a mail,
> change a record — and every tool is marked as read, write or destructive.
> Reading happens automatically; writing waits for a person to **approve**
> it, in the chat or later in an inbox; destructive actions are blocked unless
> someone deliberately allows them. A **workflow** is a saved list of such
> steps that runs on a schedule or on a trigger, and it pauses at the same
> approval points.

---

## 2. Why this exists

- Tools are described in four places with four shapes (gateway builtins,
  MCP client, document tools, skills). A policy cannot be applied uniformly
  because nothing lists "all tools".
- Write-class safety exists only piecemeal: `BALLOWWRITE` on MCP servers,
  `mcp_action` refusing `destructiveHint`, Phase M's confirm card for M365
  writes, `allow_unattended` on tasks. Good instincts, no single rule.
- There is no approval that survives a closed browser tab: unattended runs
  either may write or may not; there is no "ask me first".
- Customers need a new integration → today that is PHP (plugin) or an MCP
  server. A declarative HTTP tool covers most "call our API" cases without
  engineering.
- Saved Tasks have an authored graph (`BGRAPH`) but no builder UI beyond the
  task card.

---

## 3. What already exists (do not rebuild)

| Piece | State | Role here |
| ----- | ----- | --------- |
| `McpServerFactory` + `ToolAnnotations` | Shipped | Server-side hints; the same vocabulary is used for classes |
| `McpClient`, `McpToolRegistry`, `BMCPSERVERS.BALLOWWRITE`, `mcp_fetch` / `mcp_action` | Shipped | MCP tools enter the registry; `BALLOWWRITE=0` ⇒ `block` for write/destructive |
| `GatewayToolLoop` + `GatewayToolCatalog` (`WebSearchTool`, `AnalyzeImageTool`, …) | Shipped | Builtins enter the registry; loop asks `ApprovalPolicy` before executing |
| `OpenAiGatewayToolLoop`, `OpenAiToolCallingGate`, `ToolCallingChatProviderInterface` | Shipped | Same |
| `DocumentToolRegistry` + `ChatToolLoop` (`DOCUMENT_TOOLS` flag) | Shipped | Document tools are `write` on the user's own document → default `auto` for the owner (policy exception recorded per tool) |
| `SkillCatalog` / `SkillDescriptor` / `TaskRunner` / `Capability` | Shipped | Skills are registry entries of `source = skill`; planner text unchanged (C2) |
| Phase M confirm card (calendar/mail writes) | Shipped | Generalized into `ApprovalCard.vue` |
| `BSAVEDTASKS` (`BGRAPH`, triggers, `BALLOWUNATTENDED`), `SavedTaskRunner`, `SavedTaskTickService`, `BSAVEDTASK_RUNS` | Shipped | Workflow persistence and execution; pause/resume added |
| `SavedTaskGraphValidator`, `SavedTaskPlanFactory` | Shipped | Builder saves what these accept |
| `BCREDENTIALS` / `BCONNECTIONS` | Shipped | Auth for custom HTTP tools |
| `SsrfGuard` | Shipped | Every custom tool URL passes it |
| Centrifugo `user:{id}` channel | Shipped | Approval notifications |
| Synafastbill "propose then confirm" (plugin) | Shipped | Reference UX; the plugin may later declare its commands as tools |
| Plugin `chatCommands` | Shipped | Opt-in registry entries (`source = plugin`), class declared in manifest v2 |

---

## 4. Target architecture

```text
                         ToolRegistry (tagged app.tool.source)
   ┌──────────┬──────────┬──────────────┬──────────┬──────────────┬───────────┐
   builtins   MCP client  document tools  skills    plugin commands  custom HTTP
   (gateway)  (BMCPSERVERS)               (SkillCatalog)  (manifest)   (BTOOLS)
                                 │
                                 ▼  ToolDescriptor{ name, inputSchema, sideEffect, source, owner }
                         ApprovalPolicy::decide(tool, actor, assistant?, group?, context)
                                 │  auto | approve | block
            ┌────────────────────┼─────────────────────┐
            ▼                    ▼                     ▼
      execute now         BAPPROVALS (pending)     refuse with reason
                                 │
              interactive ───────┼──────── unattended
              ApprovalCard       │         node = waiting_approval
              in the chat        │         inbox + notification
                                 ▼
                 approved → execute → resume DAG from node
                 rejected/expired → node failed (readable reason)
```

### 4.1 Schema

| Table | Columns | Notes |
| ----- | ------- | ----- |
| `BAPPROVALS` (S2) | `BID`, `BOWNERID` (who must decide), `BREQUESTEDBY` (`chat:{messageId}` / `task_run:{runId}:{nodeId}`), `BTOOL`, `BSIDEEFFECT`, `BARGS` (JSON, secrets redacted), `BPREVIEW` (human-readable summary), `BSTATUS` (`pending` / `approved` / `rejected` / `expired` / `executed` / `failed`), `BEXPIRESAT`, `BDECIDEDBY`, `BDECIDEDAT`, `BRESULTREF`, `BCREATED` | Append-only decisions; audit row in `BAUDITLOG` (IAM) on decide |
| `BTOOLS` (S4) | `BID`, `BOWNERID`, `BNAME` (unique per owner), `BTITLE`, `BDESCRIPTION`, `BTYPE` (`http` / `openapi_op`), `BSIDEEFFECT`, `BSPEC` (JSON: method, url, headers, query/body templates, response mapping), `BINPUTSCHEMA` (JSON Schema), `BCREDENTIALID` (nullable), `BENABLED`, `BSOURCEREF` (spec URL + operationId for imports), `BCREATED`, `BUPDATED` | Shareable via IAM kind `tool` |
| `BSAVEDTASK_RUNS.BWAITINGNODE` (S3) | nullable string | Node id waiting for approval |

### 4.2 Policy resolution

```text
decide(tool, actor, assistant, groups, context ∈ {interactive, unattended}):
  class   = tool.sideEffect
  base    = instance default for class                     (BCONFIG TOOLS.POLICY.<class>)
  group   = most restrictive group policy for (tool|class) (IAM policy layer, S5 of track 1)
  agent   = assistant.tools.policy[tool] ?? [class]        (track 2 definition)
  task    = unattended && task.allow_unattended && class == write ? auto : —
  hard    = tool.source == mcp && !server.allowWrite && class != read ? block : —
  return mostRestrictive(base, group, agent, task, hard)   // block > approve > auto
```

Ordering `block > approve > auto` means nobody can *loosen* an admin's
`block` from an assistant definition. `allow_unattended` can only turn
`approve` into `auto`, never unblock.

### 4.3 Pause and resume in the DAG

`DagExecutor` gains one node status, `waiting_approval`, and one entry
point, `resume(runId, nodeId)`: rebuild `NodeContext` from persisted
results (`BMESSAGE_TASKS.BRESULTREF` / saved-task run results), execute the
waiting node with the approved arguments, continue topologically. No
in-memory suspension; a resume is a fresh worker job. The interactive path
does the same with `chat:{messageId}` as the run reference so a user can also
approve from the inbox later if they closed the chat.

### 4.4 Custom HTTP tools

`BSPEC` example:

```json
{
  "method": "POST",
  "url": "https://api.example.com/tickets",
  "headers": { "Accept": "application/json" },
  "body": { "title": "{{input.title}}", "priority": "{{input.priority}}" },
  "response": { "summary": "Ticket {{response.id}} created", "fields": ["id", "url"] }
}
```

Templates are a strict subset (`{{input.*}}`, `{{response.*}}`,
`{{credential.header}}`); no expressions, no code. `SsrfGuard` checks the
resolved host; redirects are refused; response size capped; result is
untrusted content when it re-enters a prompt (provenance `source: tool`).

OpenAPI import: parse a 3.x spec (URL or upload), list operations with the
class guessed from the HTTP method (`GET/HEAD → read`, `POST/PUT/PATCH →
write`, `DELETE → destructive`), let the owner adjust, create one `BTOOLS`
row per selected operation.

### 4.5 Workflow builder v1

Saved Task detail gains a **Steps** editor: ordered steps; each step = pick a
node type from the registry (skill, tool, assistant), fill inputs (literal or
"from step N"), set "needs approval" override (only towards stricter);
optional **condition** step (`if field matches → continue / stop`). Saves to
`BGRAPH` through `SavedTaskGraphValidator`. Templates: save a task as a
template = IAM share of kind `saved_task` with `use` (run a copy).

---

## 5. UI

| Surface | Change | Class |
| ------- | ------ | ----- |
| Chat | `ApprovalCard.vue` (replaces the Phase M-specific card): what will happen, the arguments in plain words, Approve / Reject / "Always allow for this assistant" (only if policy allows loosening at that level) | ota |
| Manage → Automations → **Approvals** | Inbox: pending (with age and expiry), decided; approve/reject; deep link to the chat or task run. Badge count on the nav child | ota |
| Manage → Connections → **Custom tools** | List, HTTP tool editor, OpenAPI import wizard, "Try it" against the user's own input, Share (IAM) | ota |
| Manage → Automations → Saved Tasks | **Steps** editor, "waiting for approval" state on the task card and run history | ota |
| Operate → System config | Instance defaults per side-effect class; approval expiry default | ota |

Words (en / de / es / fr / tr): Approve / Genehmigen / Aprobar / Approuver /
Onayla; Reject / Ablehnen / Rechazar / Refuser / Reddet; Waiting for
approval / Wartet auf Genehmigung / Esperando aprobación / En attente
d'approbation / Onay bekliyor; Custom tool / Eigenes Werkzeug / Herramienta
personalizada / Outil personnalisé / Özel araç; Step / Schritt / Paso / Étape
/ Adım. Never "side effect", "destructive hint", "DAG", "node" in primary
copy — say "changes something" / "deletes something" / "workflow" / "step".

---

## 6. API sketch (additive, flag-gated)

| Method | Path | Sprint | Purpose |
| ------ | ---- | ------ | ------- |
| `GET` | `/api/v1/tools` | S1 | Registry as seen by the current user (descriptors + resolved policy) |
| `GET` | `/api/v1/approvals?status=` | S2 | Inbox |
| `POST` | `/api/v1/approvals/{id}/approve` · `/reject` | S2 | Decide (owner only) |
| `POST` | `/api/v1/saved-tasks/{id}/runs/{runId}/resume` | S3 | Internal/worker resume (also called after approve) |
| `GET/POST/PATCH/DELETE` | `/api/v1/tools/custom`, `/{id}` | S4 | Custom tools |
| `POST` | `/api/v1/tools/custom/import-openapi/preview` · `/apply` | S4 | Import |
| `POST` | `/api/v1/tools/custom/{id}/try` | S4 | Dry run with the owner's input (read-class only; write-class shows the resolved request without sending) |
| `POST` | `/api/v1/webhooks/saved-tasks/{token}` | S5 | Inbound `webhook` trigger (per-task secret token, HMAC optional) |

MCP server: `list_tools` output unchanged for existing tools; custom tools
are **not** exposed through Synaplan's MCP server in v1 (a tool that calls
out on behalf of the owner should not be re-exported to third parties
without a decision).

---

## 7. Compatibility invariants

| # | Invariant | Proof |
| - | --------- | ----- |
| C1 | **S1 is a pure refactor:** gateway tool lists, MCP behavior, document tools and planner `[CAPABILITYLIST]` text are byte-identical; snapshots untouched | Characterization + gateway contract tests |
| C2 | `BALLOWWRITE = 0` still blocks writes on that MCP server regardless of any other policy | Policy tests |
| C3 | Phase M confirm card behavior (calendar/mail writes) unchanged for the user; only the component is shared | Utterance characterization from the saved-tasks plan |
| C4 | `allow_unattended` semantics preserved (write-class becomes `auto`); tasks without it now pause instead of failing — documented change, behind `TOOLS.APPROVALS_ENABLED` | Saved task tests both flag states |
| C5 | A tool not in the registry cannot be called by any loop | Negative tests per loop |
| C6 | Custom tools never bypass `SsrfGuard`; credentials never appear in `BARGS`, previews, logs or exports | Security tests |
| C7 | Builder saves only graphs `SavedTaskGraphValidator` accepts; existing `BGRAPH` rows load unchanged | Validator fixtures |
| C8 | Widget, mobile, `/v1` gateways unchanged; new PHP `backend-only` | Suites + mobile-impact |

---

## 8. Sprints

| Sprint | Content | Exit |
| ------ | ------- | ---- |
| **S1 — Registry refactor** | `ToolDescriptor`, `ToolRegistry`, source adapters for builtins / MCP / document tools / skills / plugin commands; the three loops read descriptors; `GET /api/v1/tools`; classes derived from existing hints | C1 green; no UI change |
| **S2 — Policy & interactive approval** | `ApprovalPolicy`, instance defaults in `BCONFIG`, `BAPPROVALS`, `ApprovalCard.vue` generalization, inbox (pending from chats), Centrifugo notification, audit rows | A write-class MCP action in chat shows the card; approving executes; rejecting explains |
| **S3 — Unattended approval** | `waiting_approval` node status, `resume()`, `BWAITINGNODE`, email notification, expiry job, task card states, inbox deep links | A scheduled task pauses at a write, the owner approves from the inbox next morning, the run completes |
| **S4 — Custom tools** | `BTOOLS`, HTTP executor with templates + `SsrfGuard`, editor UI, "Try it", OpenAPI import, IAM kind `tool`, assistant tool picker includes custom tools (track 2 S4) | A customer wires "create ticket in our helpdesk" without code and shares it with Support |
| **S5 — Workflow builder v1 + webhook trigger** | Steps editor on Saved Tasks, condition step, templates via IAM, inbound webhook trigger, outbound webhook node hardening (n8n recipe in docs) | A non-technical user builds "every Monday: search mail → summarize → create ticket (approve) → mail me" |
| **v2** | Node canvas (dependency decision), parallel branches UI, tool marketplace via plugin registry, custom tools exposed through Synaplan MCP with explicit consent | Decided later |

Sprint files: [`01`](./01_sprint_1_registry_refactor.md) ·
[`02`](./02_sprint_2_policy_and_interactive_approval.md) ·
[`03`](./03_sprint_3_unattended_approval.md) ·
[`04`](./04_sprint_4_custom_tools.md) ·
[`05`](./05_sprint_5_workflow_builder_and_webhook.md).

Cut line: S5 webhook trigger first, then the OpenAPI import (keep manual
HTTP tools). Never cut S3 — an approval that only works while the tab is
open is not governance.

---

## 9. Rollout

1. S1 merges with `TOOLS.REGISTRY_ENABLED` as a kill switch that routes the
   loops back to their old catalogs for one release; removed after.
2. `TOOLS.APPROVALS_ENABLED` on for Synaplan Cloud after S3; seeded on for
   new installs after S4. Existing installs: admin flips it; release notes
   explain that scheduled tasks without `allow_unattended` now pause instead
   of failing.
3. Custom tools and the builder ship behind their own flags; seeded on one
   release after approvals.
4. Rollback: flags off; pending approvals stay readable in the inbox.

---

## 10. Out of scope (v1)

- Embedding n8n or any external workflow engine.
- Scripting inside tools (JavaScript / Python transforms) — that is what
  track 5 (compute) is for, under its own policy.
- Multi-person approval (quorum), delegation, approval SLAs.
- Exposing custom tools through Synaplan's MCP server.
- A visual node canvas (v2).

---

## 11. Success criteria

1. `GET /api/v1/tools` lists every callable across all loops; removing an
   entry makes it uncallable everywhere.
2. Default policy: a `read` MCP tool runs silently; a `write` shows the
   card; a `destructive` is refused with a sentence a user understands.
3. A scheduled task pauses at a write; the owner approves from the inbox
   after closing the browser; the run resumes from that step and finishes;
   the audit log shows who approved what.
4. A custom HTTP tool created from an OpenAPI spec runs end-to-end through
   `SsrfGuard`, with credentials never visible to the caller.
5. A user with no technical background builds a five-step workflow in the
   steps editor and it runs on schedule.
6. Full gate green after every sprint; S1 leaves every snapshot untouched.

---

## 12. Decisions from the 2026-09-03 review (formerly open questions)

| # | Question | Decision |
| - | -------- | -------- |
| 1 | Document tools `write → auto`? | **Yes**, per-tool exception declared on the descriptor (`policyException: own_artefact`); they act on the user's own generated document inside the chat. |
| 2 | "Always allow for this assistant" from the card | **The user's own future calls only**, stored as a per-user override that can never loosen `block` (default stands; not contested). |
| 3 | Approval notifications | **In-app + email; instant or daily digest as a user setting** (default: instant for the first pending item, digest for further items within the hour). |
| 4 | Inbox placement | **Manage → Automations → Approvals** with a badge on the Automations rail child; History stays clean. |
| 5 | Default policies | `read → auto`, `write → approve`, `destructive → block` confirmed. |
| 6 | Unattended approval | **Pause and resume** confirmed; `allow_unattended` keeps its "auto for write-class" meaning. Expiry default **72 h**. |
| 7 | Workflow builder v1 | **Step list**, no new dependency; node canvas is v2. |
| 8 | Custom tools v1 | **HTTP + OpenAPI import.** No MCP re-export of custom tools in v1. |
| 9 | Bundle sections (roadmap §8) | S4 registers `custom_tools` (spec without credentials; `BCREDENTIALID` becomes a "needs a credential" checklist item) and S5 registers `saved_tasks` (graph + triggers, schedule paused on import) with the track-2 bundle registry. MCP server configs (`mcp_servers`, URL + auth *type* only, never tokens) are registered in S1 since the registry adapter for MCP already touches that entity. |

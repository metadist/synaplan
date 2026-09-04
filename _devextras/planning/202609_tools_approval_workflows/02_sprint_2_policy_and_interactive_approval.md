# Sprint 2 — Policy and interactive approval

**Track 4 (`synaplan/`), sprint 2 of 5.** Steps `TL9`–`TL18`.

**Goal:** Every tool call passes `ApprovalPolicy::decide()`. In a live chat a `write`-class action shows
`ApprovalCard.vue`; approving executes it and the answer continues; rejecting explains. Pending items also
land in an inbox under Manage → Automations → Approvals, so a decision survives a closed tab.
**Depends on:** S1 (`ToolDescriptor.sideEffect` on every tool). Track 2 (assistant `tools.policy`) and
track 1 S5 (group policy layer) are **optional inputs** behind interfaces with null defaults — S2 ships
without them. Master plan §0 rows 3, 4; §4.2; §12 rows 2, 3, 4, 5.
**Unlocks:** S3 (unattended pause/resume reuses `BAPPROVALS`, inbox and notifications), track 5
(`code_run` is governed by this policy).
**Repos:** `synaplan/` only.
**Flag:** `TOOLS.APPROVALS_ENABLED` (default off). Off ⇒ `decide()` is not consulted and every loop
behaves as after S1. On ⇒ policy enforced, `/api/v1/approvals` routes live, inbox visible.

---

## 0. Why this sprint exists

Write-class safety exists piecemeal: `BMCPSERVERS.BALLOWWRITE`, `McpActionRunner` refusing `destructiveHint`, the schedule-time guard in `SavedTaskService` ("Confirm 'runs on its own' first"), `allow_unattended`. None of them asks a person at the moment of the action, and none survives a closed browser tab. This sprint adds the one rule and the one card.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/Tool/ToolRegistry.php`, `ToolDescriptor.php` (S1) | Input to `decide()` |
| `backend/src/Entity/McpServerConfig.php` | `BALLOWWRITE` — the hard rule (C2) |
| `backend/src/Service/Multitask/Execution/Runner/McpActionRunner.php`, `McpFetchRunner.php` | Existing refusal of destructive tools and the `mcp_fetch` / `mcp_action` split |
| `backend/src/Service/SavedTask/SavedTaskService.php` (mutating-capability guard) | Today's only "confirm first"; unchanged in S2 |
| `backend/src/AI/Messages/Tools/GatewayToolLoop.php` `executeOurs()`, `backend/src/AI/OpenAI/OpenAiGatewayToolLoop.php`, `backend/src/Service/Document/ChatToolLoop.php`, `backend/src/Service/Multitask/Execution/TaskRunner.php` | The four execution points |
| `backend/src/Realtime/Publisher/RealtimePublisherInterface.php`, `Channel/UserChannel.php` (`user:{userId}`), `Notifier/ChatActivityNotifier.php` | Notification transport and the follow-up message pattern |
| `backend/src/Service/InternalEmailService.php` (`sendTaskResultEmail()`), `backend/src/Service/Admin/SystemConfigService.php` | Approval mail pattern; allow-list for Operate → System config |
| `frontend/src/components/multitask/TaskCard.vue`, `TaskPlanBubble.vue`, `frontend/src/views/ChatView.vue` | Where a chat action renders; SSE handlers (`token`, `memories_loaded`, `complete`, `error`) — add `approval_required` |
| `frontend/src/services/realtime/RealtimeClient.ts`, `frontend/src/composables/useNavItems.ts` (`automations` group) | Subscribe to `user:{id}`; inbox is a child of Automations |
| `_devextras/planning/202609_iam/00_master_plan.md` §4.1 `BAUDITLOG` | Audit row shape (track 1 S1) |

Phase M confirm card: **no dedicated Vue component exists on `main`**. Calendar and mail writes render through `TaskCard.vue` states; `ApprovalCard.vue` is new and `TaskCard.vue` gets one more state (C3: existing utterances unchanged).

---

## 2. Developer steps

### 2.1 `TL9` — `ApprovalPolicy::decide()`

`backend/src/Service/Tool/Policy/`: `PolicyOutcome` enum (`auto` / `approve` / `block`), `PolicyContext`
(`interactive` / `unattended`), `ApprovalPolicy`:

```text
decide(tool, actor, assistant, groups, context ∈ {interactive, unattended}):
  class   = tool.sideEffect
  base    = instance default for class                     (BCONFIG TOOLS.POLICY.<class>)
  group   = most restrictive group policy for (tool|class) (IAM policy layer, S5 of track 1)
  agent   = assistant.tools.policy[tool] ?? [class]        (track 2 definition)
  task    = unattended && task.allow_unattended && class == write ? auto : —
  hard    = tool.source == mcp && !server.allowWrite && class != read ? block : —
  user    = per-user "always allow" override for (assistant, tool) ? auto : —   (never beats block)
  return mostRestrictive(base, group, agent, task, hard)   // block > approve > auto
```

`policyException: own_artefact` (document tools) resolves `base` to `auto` for the artefact owner only. `group` and `agent` arrive through `GroupPolicyProviderInterface` / `AssistantPolicyProviderInterface` with `Null*` implementations until tracks 1/2 land. `tests/Unit/Service/Tool/Policy/ApprovalPolicyTest.php`: every row of the truth table, ordering, `allow_unattended` never unblocks, override never loosens `block` (§12 row 2).

### 2.2 `TL10` — instance defaults and flag

`BCONFIG` rows seeded via `BConfigSeeder::insertIfMissing`, group `TOOLS`: `POLICY.read = auto`, `POLICY.write = approve`, `POLICY.destructive = block`, `APPROVAL_EXPIRY_HOURS = 72`, `APPROVALS_ENABLED = false`. `ToolsConfig` (S1) gains readers; `SystemConfigService` allow-list exposes them under Operate → System config (ota). Per-user "always allow" lives in group `TOOLS`, key `USER_OVERRIDES`, JSON `{ "assistant:{promptId}": ["toolName"] }`.

### 2.3 `TL11` — `BAPPROVALS` migration

```sql
CREATE TABLE IF NOT EXISTS BAPPROVALS (
  BID BIGINT NOT NULL AUTO_INCREMENT, BOWNERID BIGINT NOT NULL,
  BREQUESTEDBY VARCHAR(96) NOT NULL, BTOOL VARCHAR(191) NOT NULL, BSIDEEFFECT VARCHAR(16) NOT NULL,
  BARGS JSON NULL, BPREVIEW TEXT NULL, BSTATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
  BEXPIRESAT BIGINT NOT NULL, BDECIDEDBY BIGINT NULL, BDECIDEDAT BIGINT NULL, BRESULTREF VARCHAR(191) NULL,
  BCREATED BIGINT NOT NULL,
  PRIMARY KEY (BID), KEY idx_approvals_owner_status (BOWNERID, BSTATUS), KEY idx_approvals_expires (BSTATUS, BEXPIRESAT)
);
```

Raw `addSql`, no Schema API. `BREQUESTEDBY` is `chat:{messageId}` or `task_run:{runId}:{nodeId}` (S3). `BARGS` is written through `ApprovalArgsRedactor` (drops keys matching `token|secret|password|authorization` and any `credential.*` template value) — C6 groundwork.

### 2.4 `TL12` — `ApprovalService` and routes

`ApprovalService::request(ToolDescriptor, array $args, string $requestedBy, User)` → pending row + `BPREVIEW` (one plain sentence from `title` + arguments); `approve(id, User)` / `reject(id, User, ?reason)` → status, `BDECIDEDBY`, `BDECIDEDAT`, audit row in `BAUDITLOG` (`BACTION = approval.approved|rejected`, `BRESOURCEKIND = approval`) through `AuditLogWriterInterface` (`Null` impl until track 1 S1).
Routes in `ApprovalController`: `GET /api/v1/approvals?status=pending|decided`, `POST /api/v1/approvals/{id}/approve`, `POST /api/v1/approvals/{id}/reject`. Owner only; another user's id → 404 (Saved Tasks pattern); 404 while the flag is off. Full OpenAPI; regenerate Zod.

### 2.5 `TL13` — loops consult the policy

Before executing, `GatewayToolLoop`, `OpenAiGatewayToolLoop`, `ChatToolLoop` and `TaskRunner` call `decide()`:

- `auto` → execute as today. `block` → the tool result is the refusal sentence (`approvals.blocked` copy), audit row, no exception to the user.
- `approve`, chat (SSE path) → `ApprovalService::request()`, SSE event `approval_required` `{ approvalId, tool, preview, expiresAt }`, the turn ends with "waiting for your approval".
- `approve`, gateways (`/v1`, Messages) → the tool result returned to the model is "requires approval; request #id created"; the row lands in the inbox. Gateways stay byte-identical with the flag off (C8).

After `approve`, the worker message `ResumeApprovalCommand(approvalId)` executes the stored call under the owner's identity, writes `BRESULTREF` and `executed` / `failed`, and for `chat:{messageId}` continues the turn as a new assistant message in the same chat, streamed over `user:{id}` (`ChatActivityNotifier` pattern). S3 reuses this handler.
`mcp_fetch` deviation from S1: unannotated MCP tools are now `write` ⇒ they move to the `mcp_action` catalog. Re-record `utterance_plans.json` **only** if a fixture server lacks annotations; review every changed line in the PR.

### 2.6 `TL14` — `ApprovalCard.vue`

`frontend/src/components/chat/ApprovalCard.vue`, props `approval: Approval` (Zod type from `api-schemas.ts`), `canAlwaysAllow: boolean`; emits `approved`, `rejected`, `always-allow`. Content: what will happen (`preview`), the arguments in plain words, expiry, buttons Approve / Reject / "Always allow for this assistant" (rendered only when the resolved policy is `approve`, never for `block`). Reject asks a reason via `useDialog().prompt()`.
Rendered by `ChatView.vue` on `approval_required`; `TaskCard.vue` gains `waiting_approval`. Tokens only, dark + V2 + 320px. Unit test `tests/unit/components/ApprovalCard.spec.ts` (stub `MessageText`). Five locales for Approve / Reject / Waiting for approval / Always allow for this assistant / Blocked (master plan §5) in this PR.

### 2.7 `TL15` — realtime events and badge

`ApprovalRealtimeNotifier` publishes `approval.pending` and `approval.decided` on `user:{ownerId}` via `RealtimePublisherInterface`. Frontend `useApprovalsStore` (Pinia, `ref()` + `computed()`): `pendingCount`, subscribes through `RealtimeClient`, refetches on publication. Badge on the Automations rail child (`useNavItems.ts`).

### 2.8 `TL16` — inbox view

`frontend/src/components/config/ApprovalsInbox.vue` at `/channels/approvals` (route `channels-approvals`, Automations group, hidden when the flag is off). Tabs Pending / Decided; rows show tool title, preview, age, expiry, requester context; actions Approve / Reject; deep link `chat:{messageId}` → the chat (`task_run:*` links arrive in S3). `approvalsApi.ts` on `httpClient` + Zod.

### 2.9 `TL17` — notification setting and instant email

Per-user `BCONFIG` `TOOLS.APPROVAL_NOTIFY` = `instant` (default) | `digest`; UI in Settings → Notifications. `InternalEmailService::sendApprovalRequestEmail()` for the first pending item; items within the following hour are batched by `app:approvals:digest` (hourly, Redis-locked like `app:saved-tasks:tick`). The mail contains the preview and a link to the inbox, never the arguments.

### 2.10 `TL18` — invariant proofs C2 / C3 / C5 / C6

See §3.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C2 `BALLOWWRITE = 0` blocks | `ApprovalPolicyTest::testAllowWriteOffBlocksRegardlessOfOverrides` (assistant `auto`, user override, `allow_unattended` all set) |
| C3 Phase M behaviour unchanged | `utterance_plans.json` and `routing_classification.json` untouched (except the documented `mcp_fetch` fixture case); `SavedTaskConfigTest` guard tests unchanged |
| C5 unregistered ⇒ uncallable | `ResumeApprovalCommandHandlerTest`: an approval whose tool left the registry fails with `ToolNotRegisteredException`, status `failed` |
| C6 groundwork | `ApprovalArgsRedactorTest`: token-like keys never reach `BARGS` or `BPREVIEW` |
| C8 gateways | `GatewayToolLoopTest` / `OpenAiGatewayToolLoopTest` with flag off unchanged; new cases with flag on assert the "requires approval" tool result |

Frontend: `ApprovalCard.spec.ts`, `ApprovalsInbox.spec.ts`, `useApprovalsStore.spec.ts`, i18n parity for namespace `approvals`. Full gate both sides; `make -C frontend generate-schemas` after `TL12`.

---

## 4. Exit criteria / demo

1. Flag off: no routes, no nav child, snapshots untouched, gateways identical.
2. Flag on, chat: a `read` MCP tool runs silently; a `write` tool shows the card; Approve executes and the answer continues; Reject explains.
3. A `destructive` tool is refused with one understandable sentence; the audit log records it.
4. Close the tab after the card appears; the item is in the inbox; approving there produces the follow-up message in the chat.
5. "Always allow for this assistant" skips the card next time for that user and tool; it has no effect on a `block`.
6. An instant email arrives for the first pending item; further items within the hour arrive as one digest.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| TL9 | `feat(approvals): add ApprovalPolicy with read/write/destructive resolution` | backend-only | S1 |
| TL10 | `feat(approvals): seed TOOLS.POLICY defaults and expose them in system config` | backend-only + ota-candidate | TL9 |
| TL11 | `feat(approvals): add BAPPROVALS migration` | backend-only | — |
| TL12 | `feat(approvals): add ApprovalService and /api/v1/approvals routes with audit rows` | backend-only | TL10, TL11 |
| TL13 | `feat(approvals): consult ApprovalPolicy in tool loops and resume approved calls` | backend-only | TL12 |
| TL14 | `feat(approvals): add ApprovalCard.vue and approval_required SSE handling` | ota-candidate | TL13 |
| TL15 | `feat(approvals): publish approval events on the user channel and show a nav badge` | backend-only + ota-candidate | TL12 |
| TL16 | `feat(approvals): add approvals inbox under Automations` | ota-candidate | TL15 |
| TL17 | `feat(approvals): add notification setting with instant email and hourly digest` | backend-only + ota-candidate | TL12 |
| TL18 | `test(approvals): prove BALLOWWRITE hard block, Phase M parity and argument redaction` | backend-only | TL13 |

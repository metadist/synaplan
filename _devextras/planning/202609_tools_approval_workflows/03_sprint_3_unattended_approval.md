# Sprint 3 — Unattended approval (pause and resume)

**Track 4 (`synaplan/`), sprint 3 of 5.** Steps `TL19`–`TL27`.

**Goal:** A Saved Task run that reaches an `approve` decision pauses at that step, writes a `BAPPROVALS`
row, notifies the owner, and **resumes from that step** when approved — hours later, from the inbox, with
no browser open. Expired approvals fail the step with a readable reason; three consecutive failures pause
the task (existing rule).
**Depends on:** S2 (`ApprovalPolicy`, `BAPPROVALS`, `ApprovalService`, `ResumeApprovalCommand`, inbox,
notifications). Master plan §0 rows 5, 6, 12; §4.3; §12 row 6.
**Unlocks:** S5 (the builder's "needs approval" override is only meaningful because runs can wait),
track 5 (unattended `code_run`).
**Repos:** `synaplan/` only.
**Flag:** `TOOLS.APPROVALS_ENABLED` (same flag as S2). Off ⇒ `DagExecutor` never emits `waiting_approval`;
`SavedTaskService` keeps refusing to schedule mutating tasks without `allow_unattended`. On ⇒ such tasks pause instead.

---

## 0. Why this sprint exists

An approval that only works while the tab is open is not governance (master plan §8 cut line: never cut S3).
Today `allow_unattended` is binary: a scheduled task may write, or the schedule is refused. "Ask me first"
does not exist for runs nobody is watching.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/Multitask/Execution/DagExecutor.php` | `execute(TaskPlan, NodeContext, ?callable)`; `emitState()` with `running` / `done` / `failed` / `skipped`; topological loop to extend |
| `backend/src/Service/Multitask/Execution/NodeStatus.php`, `NodeContext.php` | `Pending` / `Running` / `Done` / `Failed` / `Skipped` — add `WaitingApproval`; `setResult()` / `getResult()` / `allResults()` are what a resume rebuilds |
| `backend/src/Service/Multitask/TaskPlanStore.php` | Persists one `BMESSAGE_TASKS` row per node (`BNODEID`, `BSTATUS`, `BRESULTREF`) — the rehydration source |
| `backend/src/Service/SavedTask/SavedTaskRunner.php` `run(ownerId, taskId, messageText, trigger)`, `SavedTaskTickService.php` | Runs by owner id, no session (saved-tasks plan §3.4); Redis-locked tick the expiry sweep rides beside |
| `backend/src/Entity/SavedTaskRun.php` (`BSAVEDTASK_RUNS`), `SavedTask.php` | `BSTATUS`, `BMESSAGEID`, `BPLANSNAPSHOT`, `BERROR` — gains `BWAITINGNODE`; `BALLOWUNATTENDED`, `BCONSECUTIVEFAILURES`, `BGRAPH` |
| `backend/src/Service/SavedTask/SavedTaskService.php` (mutating guard) | The behaviour change lives here |
| `backend/tests/Unit/Service/SavedTask/SavedTaskFailureAccountingTest.php`, `SavedTaskRunnerTest.php` | Extend for both flag states (C4) |
| `backend/src/Controller/SavedTaskController.php` | `POST /{id}/resume` already means "un-pause an auto-paused task" — do not overload it |
| `frontend/src/components/config/SavedTaskCard.vue`, `SavedTasksOverview.vue`, `ApprovalsInbox.vue` (S2) | Task card, run list, deep links |

---

## 2. Developer steps

### 2.1 `TL19` — `waiting_approval` node status

`NodeStatus::WaitingApproval = 'waiting_approval'`; `NodeResult::waitingApproval(int $approvalId, array $args)`. When
`TaskRunner` obtains `approve` for a node in `PolicyContext::Unattended`, it calls `ApprovalService::request()` with
`BREQUESTEDBY = task_run:{runId}:{nodeId}` and returns that result. `DagExecutor::execute()` on such a result emits
`waiting_approval`, does not schedule dependents (they stay `pending`) and returns `['status' => 'waiting_approval', 'nodeId' => …]`.
`TaskPlanStore` persists the status; `SavedTaskRun::STATUS_WAITING_APPROVAL = 'waiting_approval'`. Interactive chats are untouched (the S2 path ends the turn instead).

### 2.2 `TL20` — `BWAITINGNODE` migration

```sql
ALTER TABLE BSAVEDTASK_RUNS ADD COLUMN IF NOT EXISTS BWAITINGNODE VARCHAR(64) NULL;
ALTER TABLE BSAVEDTASK_RUNS ADD INDEX IF NOT EXISTS idx_saved_task_runs_waiting (BSTATUS, BWAITINGNODE);
```

Raw `addSql`, no Schema API; `SavedTaskRun::$waitingNode` mapped nullable. Per-task expiry lives in
`BGRAPH.settings.approvalExpiryHours` (integer 1–720; absent ⇒ `TOOLS.APPROVAL_EXPIRY_HOURS`); `SavedTaskGraphValidator`
accepts the `settings` object (C7 fixture added).

### 2.3 `TL21` — `DagExecutor::resume()` and rehydration

`NodeContextRehydrator::fromRun(SavedTaskRun $run): NodeContext` loads the `BMESSAGE_TASKS` rows of `run.BMESSAGEID`,
rebuilds a `NodeResult` per `done` node from `BRESULTREF`, and returns a context acting as the owner. A node whose result
cannot be rebuilt (missing ref, deleted file) fails the resume with `approvals.resumeFailedMissingResult`.
`DagExecutor::resume(TaskPlan $plan, NodeContext $context, string $nodeId, array $approvedArgs, ?callable $progress): array`
executes exactly `$nodeId` with the approved arguments (policy **not** consulted again — the approval is the decision),
then continues the existing topological loop for the remaining `pending` nodes; a second `approve` on a later node pauses
again. `TaskPlan` is read from `BPLANSNAPSHOT`. Route `POST /api/v1/saved-tasks/{id}/runs/{runId}/resume` (owner or worker
scope; 409 when the run is not `waiting_approval`; OpenAPI + Zod).

### 2.4 `TL22` — worker job

`ResumeSavedTaskRunCommand(int $runId, string $nodeId, int $approvalId)` + handler. `ResumeApprovalCommandHandler` (S2)
dispatches it when `BREQUESTEDBY` starts with `task_run:`. The handler resolves the acting user by owner id, charges
`RateLimitService` as the owner, calls `resume()`, updates `BSAVEDTASK_RUNS` (`BSTATUS`, `BWAITINGNODE = NULL`, `BFINISHED`),
writes `BAPPROVALS.BRESULTREF` and `executed` / `failed`. A resume is a fresh job; no in-memory suspension anywhere.

### 2.5 `TL23` — expiry sweep and the three-failures rule

`ApprovalExpiryService::sweep(\DateTimeImmutable $now)` runs from `app:saved-tasks:tick` (same Redis lock) and from its own
`app:approvals:expire` command for installs without the tick: pending rows with `BEXPIRESAT < now` → `expired`; for `task_run:*`
the node becomes `failed` with `BERROR = "Approval expired after {hours} h"`, the run `failed`, `BCONSECUTIVEFAILURES`
incremented through the existing accounting; the third consecutive failure pauses the task (`BENABLED = 0`) with the existing
notification. Rejection from the inbox behaves like expiry with the reason "Rejected by {name}: {reason}". Expired `chat:*` rows only flip status.

### 2.6 `TL24` — email and digest for task runs

`InternalEmailService::sendApprovalRequestEmail()` (S2) gains the task context: task name, step title, run start, "expires in",
link to the inbox; the hourly digest groups by task; both respect `TOOLS.APPROVAL_NOTIFY` (`instant` / `digest`). On resume
completion the owner gets the existing run result in the task's conversation (`BCHATID`), no extra mail.

### 2.7 `TL25` — task card states and run history

`SavedTaskCard.vue`: pill "Waiting for approval" with count when any run has `waitingNode`, linking to the inbox filtered by
task; run rows show the state and the step title; a paused-by-failures task shows the expiry reason. `SavedTaskRun` serializer
adds `waitingNode`, `approvalId`. Five locales for Waiting for approval / Approval expired / Rejected by (master plan §5).

### 2.8 `TL26` — inbox deep links

`ApprovalsInbox.vue`: `task_run:{runId}:{nodeId}` → `/channels/tasks?task={taskId}&run={runId}` (opens the card with the run
expanded); `chat:{messageId}` → the chat route with the message anchored. `BREQUESTEDBY` is parsed by one `ApprovalReference`
value object on the backend, serialized as `{ kind, chatId?, messageId?, taskId?, runId?, nodeId? }`; the frontend never splits the string.

### 2.9 `TL27` — documented behaviour change (C4)

With `TOOLS.APPROVALS_ENABLED` on, `SavedTaskService`'s schedule guard no longer throws for a mutating task without
`allow_unattended`; it returns an informational note ("this task will wait for your approval at steps that send or save").
`allow_unattended` keeps its meaning: `write`-class steps resolve `auto` (policy row `task`). Flag off: guard and exception
unchanged. Release note + `docs/MULTITASK_DATA_NODES.md` paragraph on unattended writes; `STATUS.md` decision entry.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C4 `allow_unattended` preserved; pause instead of fail | `SavedTaskRunnerTest::testUnattendedWriteAutoApprovesWithAllowUnattended`, `::testWriteWithoutAllowUnattendedPausesWhenApprovalsEnabled`, `::testWriteWithoutAllowUnattendedFailsWhenApprovalsDisabled`; `SavedTaskConfigTest` guard cases for both flag states |
| C2 `BALLOWWRITE` | `DagExecutorResumeTest::testResumeStillRefusesWhenServerAllowWriteTurnedOff` — `allowWrite` flipped between request and approval ⇒ `failed`, never executed |
| C5 | Resume of an approval whose tool left the registry ⇒ `failed` with the `ToolNotRegisteredException` message |
| C7 | `SavedTaskGraphValidatorTest` fixtures: `settings.approvalExpiryHours` accepted; existing fixtures unchanged |
| C8 | Widget and gateways never reach `PolicyContext::Unattended`; `MessagesGatewayControllerFlagsTest` unchanged |

Also: `NodeContextRehydratorTest` (done nodes rebuilt, missing ref fails readable), `ApprovalExpiryServiceTest` (expiry →
failure → third failure pauses), `ResumeSavedTaskRunCommandHandlerTest` (rate limit charged to owner), frontend
`SavedTaskCard.spec.ts` waiting state, i18n parity. Snapshots in `tests/Characterization/__snapshots__/` untouched. Full gate both sides.

---

## 4. Exit criteria / demo

1. Flag off: a scheduled mutating task without `allow_unattended` is still refused at save; all S2 tests green.
2. Flag on: "every Monday: search mail → summarize → create ticket → mail me" pauses at "create ticket"; the task card shows Waiting for approval; an email arrives; the browser is closed.
3. Next morning the owner approves from the inbox; the run resumes at that step, "mail me" executes, the run is `completed`; the audit log shows who approved what and when.
4. A second run left pending for 72 h expires: step `failed` with a readable reason; after three such runs the task pauses with the reason visible.
5. `allow_unattended` on the same task: no pause, identical result.
6. OpenAPI → Zod regenerated; `STATUS.md` rows `TL19`–`TL27` ticked.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| TL19 | `feat(saved-tasks): add waiting_approval node status to DagExecutor and TaskRunner` | backend-only | S2 |
| TL20 | `feat(saved-tasks): add BSAVEDTASK_RUNS.BWAITINGNODE and per-task approval expiry setting` | backend-only | TL19 |
| TL21 | `feat(saved-tasks): add DagExecutor::resume with NodeContext rehydration and run resume route` | backend-only | TL20 |
| TL22 | `feat(saved-tasks): resume paused runs from approvals through a worker job` | backend-only | TL21 |
| TL23 | `feat(approvals): expire pending approvals and apply the consecutive-failure pause` | backend-only | TL22 |
| TL24 | `feat(approvals): add task context to approval emails and digest` | backend-only | TL22 |
| TL25 | `feat(saved-tasks): show waiting-for-approval state on task cards and run history` | ota-candidate | TL22 |
| TL26 | `feat(approvals): add inbox deep links to chats and task runs` | backend-only + ota-candidate | TL25 |
| TL27 | `feat(saved-tasks): pause mutating tasks without allow_unattended when approvals are enabled` | backend-only | TL23 |

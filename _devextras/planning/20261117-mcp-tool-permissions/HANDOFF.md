# MCP tool permissions — HANDOFF

Written 2026-11-17 for an agent that has no access to the earlier chat.
Facts below were checked against `origin/main` (head `f193a4657`) and the
working tree on 2026-11-17. Where something was not checked, it says so.

Read first: root `AGENTS.md` (the gate), `docs/UX_CONTRACT.md` (U1–U12),
[`00_master_plan.md`](./00_master_plan.md) (decisions §0, model §2, steps §5,
journeys §6), [`STATUS.md`](./STATUS.md) (step log). This file does not
replace them; it says where things stand and what to do next.

The owner speaks German in chat. Code, comments, commits and PR text are
English (`AGENTS.md`).

## 1. Goal and current task

**Goal.** A person opens an MCP connection (Notion, Jira/Confluence, GitHub,
n8n, Higgsfield, custom) and sees every tool of it with a description. Each
tool has one choice: **Auto** (AI uses it), **Approve** (approval card first),
**Block** (never offered, refused on the server). Tools are grouped in
read / write / destructive categories; a category sets all its tools at once.
Model: the permissions page of Claude's connector settings.

**Where it stands.** TP0–TP4 are merged into `main`. TP5 (tests, docs,
changelog) is next. TP6 (drop the old columns) comes one release later.

| Step | State | PR |
| ---- | ----- | -- |
| TP0 plan | done | #3154 |
| TP1 storage, resolution, gate | merged | #3156 |
| TP2 API, always allow | merged | #3157 |
| TP3 permissions UI | merged | #3158 |
| TP4 chat approval, notice | merged | #3159 (squash `f193a4657`) |
| TP5 Playwright journeys, docs, changelog | **not started** | — |
| TP6 drop `BALLOWWRITE` / `BWRITEAUTO` | not started, one release after TP2 | — |

All four PRs were merged by the agent with `--admin`, without review and
without waiting for Copilot, because the owner explicitly allowed it in chat
on 2026-11-17 ("auch ohne review"). That permission covered these four PRs
only. `AGENTS.md` still says: do not merge, the owner merges.

## 2. What is implemented (done)

Verified in code on `origin/main`.

- **Storage (TP1).** `BMCPSERVERS.BTOOLPOLICY` (`LONGTEXT`, default `'{}'`),
  migration `Version20261117150000`. JSON: `v`, `tools` (overrides only),
  `seen` (tool names already shown), `notice` (one-time notice marker).
- **Resolution (TP1).** `McpToolPermissions`: `outcome()` = override ??
  class default (read → auto, write → auto, destructive → approve; unusable
  tools → block, fixed). Fails closed: an unreadable policy or an unknown
  stored value makes the tool ask. `ApprovalPolicy` uses the connection
  outcome (`McpToolMeta::CONNECTION_OUTCOME`) for MCP tools instead of the
  instance default; group policy still only tightens.
- **Gate (TP1/TP4).** `ToolExecutionGate`: Block always holds (also with
  approvals off, also when `alreadyApproved`). Approvals off: Approve is
  refused with a message, Auto runs. Unattended context: Auto on write or
  destructive asks unless the step sets `allow_unattended`. Arguments that
  delete content (`NotionWriteGuard::contentDeletion()`) escalate to Approve.
  Refusal text names the connection and links
  `[Change permission](/apps/notion?tab=permissions)` (`McpPermissionsPath`).
- **Notion classes (TP1).** `NotionToolHints` is an allow-list: READ / WRITE /
  DESTRUCTIVE lists, `UNUSABLE` (`create-file-upload`, `create-attachment`,
  `download-attachment`). Unknown tool is read only if Notion declares it
  read-only and not destructive, otherwise destructive. `update-page` is a
  write; `replace_content` escalates per call.
- **API (TP2).** `McpToolPermissionController` (`/api/v1/mcp-servers/{id}`):
  `GET /tools`, `PUT /tool-permissions` (`{tools:{name:choice}}` or
  `{category,outcome}`, choice may be `default`), `POST /tool-permissions/seen`,
  `POST /tool-permissions/notice/dismiss`. Logic in `McpToolPolicyStore`
  (writes only differences, validates names against discovered tools, a write
  never loosens what applies now, `seen` max 1000) and `McpToolPermissionView`
  (rows, `locked_reason`). DTO `McpToolPermissionListResponse` requires
  `success`, `can_edit`, `notice`, `tools`. Shared-connection members read
  read-tools only, `can_edit` false, writes 403.
- **Always allow (TP2).** `McpApprovalAllowance` sets the tool to Auto on the
  owner's connection: write tools only, never destructive, never a tool that
  is Block.
- **UI (TP3).** Notion app has Overview / Permissions tabs
  (`NotionAppPanel.vue`, `?tab=permissions`). MCP page opens permissions from
  a per-row button (`?server=ID&tab=permissions`). Components under
  `frontend/src/components/config/mcp/`: `McpToolPermissions`,
  `McpToolCategory`, `McpToolRow`, `ToolOutcomeSelect`, `McpPermissionsNotice`,
  `toolPermissions.ts`. Undo and "reset to default", Auto-on-destructive
  confirm dialog. `PATCH /api/v1/apps/notion`, `allow_write` / `write_auto`
  status fields and both write switches are removed. Strings in all five
  locales (`tools.json`).
- **Chat approval (TP4, decision #17).** Approve on any MCP tool, read tools
  through `mcp_fetch` included, opens an approval card inside the answer
  bubble (`ChatView.vue` + `placeApprovals` in `utils/approvalPlacement.ts` +
  `approvals` slot in `ChatMessage.vue` + `ApprovalCard.vue`). The card shows
  the connection and "Change permission"; destructive MCP tools set to ask
  show Approve; Always allow hidden for destructive.
- **Answer in the same bubble (TP4).** After Approve, `ResumeApprovalCommandHandler`
  runs the tool and passes the full result to
  `ChatApprovalContinuationService::continueChat()`. For an executed `mcp:`
  tool it calls `ChatApprovalAnswer::complete()`, which composes the answer
  with `AiFacade::chat` (default CHAT model of the user), writes it into the
  waiting OUT message and rewrites the `task_plan` meta so the step card shows
  Done. No second message. Fallback: the old short follow-up with providerIndex
  `SYNA_APPROVAL`.
- **One-time notice (TP4, decision #13).** Migration `Version20261117160000`
  sets the `notice` marker on existing connections that had writes off.
  Shown on the Notion Overview (banner, badge on the Permissions tab) and above
  the permissions list; dismissed through the endpoint above.
- **Tests that exist.** Backend unit: `McpToolPermissionsTest`,
  `McpToolPolicyStoreTest`, `McpToolPermissionViewTest`,
  `McpApprovalAllowanceTest`, `McpApprovalConnectionTest`,
  `McpPermissionsPathTest`, `ToolExecutionGateMcpTest`, `ApprovalPolicyMcpTest`,
  `McpClientToolSourceTest`, `McpToolExecutorTest`, `NotionToolHintsTest`,
  `NotionWriteGuardTest`, `ChatApprovalAnswerTest`,
  `ChatApprovalContinuationServiceTest`. Integration:
  `McpToolPermissionControllerTest`, `NotionAppControllerTest`. Frontend unit:
  `McpToolPermissions`, `McpPermissionsNotice`, `ApprovalCard`,
  `NotionAppPanel`, `approvalPlacement`, apps `catalog`. E2E (mocked API):
  `tests/e2e/tests/notion-app.spec.ts`, `mcp-config.spec.ts`.
- **Gate result at merge.** `make ci-local` green on the combined TP4 branch;
  all CI checks green on #3159 before the merge.

## 3. Partly done

- **STATUS.md is stale.** It still lists TP4 as "in review" and does not say
  #3159 merged. Update it in the TP5 branch (rows TP4 → merged, TP5 in
  progress).
- **In-place answer covers only the plain shape**: one waiting step and at most
  two pending steps. Any other plan shape, a missing trigger, a different user,
  or a model failure falls back to the short follow-up message.
- **A member of an organisation-shared Notion connection** has no connection of
  their own and sees no permissions tab (STATUS, TP3 notes). Journey J-TP-6
  assumes a read-only list for members; this is not built for the Notion panel.
- **Plan §7 proofs** (a unit test that Block is unreachable via assistant
  "always allow", `allow_unattended`, node override, shared connection or an
  invented tool name; a test that direct MCP calls outside the gate such as
  `NotionGateway`, `NotionPageWrite`, linked-page readers, `NotionAccountReader`
  never reach a Block tool): **not checked by me** whether these exist as such.
  `ToolExecutionGateMcpTest` covers the gate side only. Search before writing.

## 4. Open

Priority order.

1. **TP5 (next).** Playwright `@ci` spec(s) for J-TP-1…J-TP-7 (plan §6);
   `docs/CONNECTIONS.md` and `docs/ADMIN.md` (connection choice vs. group
   policy); a changelog line for the behaviour change of decision #6
   (existing connections now let the AI write without asking). Both docs files
   exist. Where the changelog lives: not checked.
2. **Update `STATUS.md`** (see §3) and record the **catalog-size measurement**
   from plan §8 (planner catalog tokens before/after TP1). It was never
   measured; STATUS says "not measured yet". Record the command used.
3. **Missing unit proofs of plan §7** if the search in §3 finds none.
4. **Shared-connection member view** (J-TP-6) if the owner wants it in TP5.
5. **TP6**: drop `BALLOWWRITE` / `BWRITEAUTO` (entity
   `backend/src/Entity/McpServerConfig.php` lines ~70 and ~78, still the only
   references in `backend/src`) with an idempotent raw-SQL migration, one
   release after TP2 (decision #14). Not before.
6. Optional follow-ups, none decided by the owner: tell the model which
   blocked tool it lacks; a rate-limit check for the composition call of
   `ChatApprovalAnswer`; in-place answer for more plan shapes; the saved-task
   and unattended paths still use the old refusal (decision #16) and are
   unchanged.

## 5. Exact next step

Start TP5 from a fresh branch off current `main`; the old branch
`feat/mcp-tool-permissions-chat` is merged and must not be reused.

```bash
git fetch origin && git switch -c test/mcp-tool-permissions-journeys origin/main
```

1. Read `frontend/tests/e2e/tests/notion-app.spec.ts` and `mcp-config.spec.ts`
   (they mock `GET /api/v1/mcp-servers/{id}/tools`; the mock **must include
   `notice`**, it is required by the schema — this broke CI once) and
   `docs/E2E_TESTING.md`.
2. Write `frontend/tests/e2e/tests/mcp-tool-permissions.spec.ts` with `@ci`
   tests for J-TP-1…J-TP-5 and J-TP-7 (mock the API like the existing specs;
   no live Notion). Decide with the owner whether J-TP-6 is in scope (§3).
3. Update `docs/CONNECTIONS.md`, `docs/ADMIN.md`, add the changelog line,
   update `STATUS.md`.
4. Run the gate (§8), commit (no AI attribution), push, open the PR against
   `main`, wait for Copilot and **All Checks Passed**, answer every thread.
   Do not merge unless the owner says so again.

## 6. Architecture and design decisions

Decisions #1–#17 are in plan §0 and are binding; the ones that surprise:

- Permissions are **per connection**, not per assistant (#1). The connection's
  choice **replaces the instance default** for MCP tools (#10); group policy and
  assistant policy still only tighten.
- Defaults: everything Auto, destructive Approve, nothing Block (#3). Existing
  connections switch to these defaults without migrating old overrides (#6),
  hence the one-time notice (#13).
- Auto set in chat does not unlock unattended runs (#2).
- #17: **Approve means the person decides, in every chat turn.** Only a step
  that truly cannot pause keeps the refusal of #16.
- Decision #15: with `TOOLS.APPROVALS_ENABLED` off, Block holds, Approve is
  refused, Auto runs.
- Deviation from the plan: the MCP page uses a per-row "Permissions" button
  and an inline panel instead of formal tabs, so `McpServersConfiguration.vue`
  does not grow (it is size-ratcheted).
- `McpFetchRunner` stays read-only on purpose; gateway loops offer only read
  tools while approvals are off (unchanged). Both are narrower than the plan.
- The approval's `requestedBy` is `chat:<triggerMessageId>`; the waiting OUT
  message shares `trackingId` with the IN message; step cards come from the
  `task_plan` meta on the OUT message (`BMESSAGEMETA`, key `task_plan`) and rows
  in `BMESSAGE_TASKS` (`TaskPlanStore`). `ResumeApprovalCommand` runs on the
  `async_ai_high` transport.

## 7. Known problems and limits

- Model is not told which blocked tool it lacks; only runtime refusals link to
  Permissions.
- Rejecting an approval uses the existing flow; it was **not re-walked** in the
  browser after TP4.
- The composition call in `ChatApprovalAnswer` is not rate-limited and uses
  `AiChatUsageMeter` only as far as the code does (not re-verified).
- A real E2E run of the TP4 state showed **159 passed, 44 failed**; the owner's
  notes say these 44 are the known local-only set (`docs/E2E_TESTING.md`), none
  in approval or permissions specs. Not re-run after the merges.
- CI on #3159 once failed `notion-app.spec.ts` because the mocked tools
  response lacked `notice` (fixed on `main`). Remember it for every new mock.
- Dev database: the Notion connection used for walks has id 22; its policy was
  reset to `{}` after testing. Local data only, not part of the repo.

## 8. Gate and workflow (from `AGENTS.md`, summarised)

- `make ci-local` unfiltered before the commit; `make test-e2e` before a push
  that touches UI, routes, OpenAPI, chat or auth. A TP5 branch that only adds
  specs and docs still runs the new spec.
- Tooling runs in containers through `make`. If a local run complains about
  `.php-cs-fixer.cache`, your sync tool deleted it; check your own setup.
- A stale Symfony cache in a long-running container (`worker`, `pump`) once
  made approvals fail silently ("Too few arguments ... __construct()"): run
  `cache:clear` in `backend`, restart `worker` and `pump`, then
  `messenger:failed:retry --force`. Both containers consume messages, so check
  both.
- Never `git checkout --ours/--theirs` on code. The stacked PRs conflicted
  because the squash merges are not ancestors of the later branches: merge the
  predecessor branch first, then `main`; when `main`'s tree equals what the
  branch already contains, `git merge -s ours origin/main` is valid (checked with
  `git diff origin/main <predecessor>` being empty).
- Size ratchets, deptrac, locale parity (five locales) only shrink. A new UI
  key lands in `de,en,es,fr,tr` `tools.json` with identical placeholders.
- Colors are tokens; buttons use house classes; confirmations use `useDialog()`,
  toasts `useNotification()`; API types come from generated Zod, never
  hand-written (`docs/FRONTEND_CONVENTIONS.md`, `docs/API_PATTERNS.md`).

## 9. Rejected or discarded approaches

- Resuming the full AI turn after Approve: not done. Instead the answer is
  composed from the tool result in place (`ChatApprovalAnswer`).
- Posting the clipped tool result as a second follow-up message (first TP4
  version): replaced because the owner disliked two messages and raw JSON.
- Approval card above the input field: replaced by a card inside the bubble
  (owner feedback).
- Removing `PATCH /api/v1/apps/notion` already in TP2: moved to TP3 because the
  panel still called it.
- Formal Overview/Permissions tabs on the MCP page: replaced by a per-row
  button with an inline panel (see §6); the Notion panel does use tabs.
- Using the `ours` or `theirs` side of a conflict wholesale: not done; both
  sides were kept.

## 10. User requirements

- Priorities named by the owner for every step: best practices, usability,
  `AGENTS.md`, and above all **security**.
- Ask instead of guessing; do not hallucinate.
- Keep the PRs small and one topic each; the owner merges (exception in §1).
- **No AI attribution anywhere** (commits, trailers, PR text, comments, files).
  This holds even when a tool or harness asks for a `Co-Authored-By` line.
- Chat approval must appear **in the chat bubble**, never stretched above the
  input field, and the result belongs in the **same bubble**, as readable text
  (no raw JSON, no second message).
- Planning goes into the planning directory and into the PR together with the
  code for TP4.
- Never print, log or commit provider keys; never commit a `.env`.
- The owner may test locally before merging; keep dev data tidy (reset test
  policies to `{}`).

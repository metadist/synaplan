# MCP tool permissions — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| TP0 | done | — | plan written 2026-11-17; §0 #1–#14 decided by the owner, #15–#16 added while building TP1 |
| TP1 | merged (#3156) | — | storage, resolution, gate, Notion classes; see below |
| TP2 | merged (#3157) | — | API, always allow; Notion PATCH stays until TP3 (see below) |
| TP3 | merged (#3158) | — | Permissions UI (Notion tab, MCP page panel); Notion write switch and PATCH removed |
| TP4 | merged (#3159) | — | Approve asks in chat (#17), card shows connection + Change permission, blocked-tool link, one-time notice |
| TP5 | merged (#3164) | — | E2E `mcp-tool-permissions.spec.ts` (J-TP-1…4, notice, read-only), docs; J-TP-5/7 and J-TP-6 see TP5 notes |
| TP6 | phase 1 merged (#3174), phase 2 merged (#3182), phase 3 in review | — | phase 1 (no DDL): code stops using `BALLOWWRITE` / `BWRITEAUTO`; phase 2 unmaps both (5.8.8 still maps them); phase 3 drops them, see TP6 notes |

## TP1 notes

- Migration `Version20261117150000`: `BMCPSERVERS.BTOOLPOLICY` (`LONGTEXT`, `'{}'`).
- `McpToolPermissions` fails closed: an unreadable policy or an unknown stored value makes the tool ask.
- `ApprovalPolicy`: an MCP tool's base is the connection outcome (`McpToolMeta::CONNECTION_OUTCOME`), not the instance default. Nothing loosens it (no "always allow", no `allow_unattended` on an explicit Approve). Unattended: Auto on write or destructive asks unless the step allows it.
- `ToolExecutionGate`: Block always holds (also approvals off, also `alreadyApproved`); §0 #15 and #16.
- Deleting arguments: `NotionWriteGuard::contentDeletion()` escalates to Approve in the gate. `McpClient::callTool()` still refuses them unless `contentDeletionApproved` is true, which `McpToolExecutor` sets only after an approval.
- Kept on purpose: `McpFetchRunner` stays read-only (refuses any non-read tool). The gateway loops still offer only read tools while approvals are off (unchanged). Both are narrower than the plan text; widen only if the owner wants writes with approvals off.
- Dead until TP2/TP3: `BALLOWWRITE` / `BWRITEAUTO` and the Notion "The AI may change Notion" switch are ignored by the policy. Between TP1 and TP3 the switch does nothing.
- Catalog size before/after: see Follow-ups (measured 2026-11-18).

## TP2 notes

- `McpToolPermissionController` owns `GET /api/v1/mcp-servers/{id}/tools` (moved out of `McpServerConfigController`, answer extended and still carries `name` and `description`), `PUT .../tool-permissions` and `POST .../tool-permissions/seen`. Logic: `McpToolPolicyStore` (writes only choices that differ from the class default; every name checked against the discovered tools; unusable tools refuse), `McpToolPermissionView` (rows, `locked_reason`).
- A member of a shared connection reads the read tools, `can_edit` is false, writes answer 403. A group policy Block shows `locked_reason: group_policy`.
- A write never loosens what applies now: an unknown stored value or an unreadable policy (both ask today) is written out as `approve` instead of being dropped to the default. `seen` keeps the newest 1000 names. A member of a shared connection never sees `is_new`.
- "Always allow" on an MCP approval: `McpApprovalAllowance` sets the tool to Auto on the owner's connection. Write tools only, never destructive, never a tool that is Block now. It needs no `assistantKey`; the old per-assistant entry is no longer written for `mcp:` tools. A failure is logged and does not stop the resumed run. The card says "Always allow on this connection" for MCP tools.
- Deviation from the plan table: `PATCH /api/v1/apps/notion` and the `allow_write` / `write_auto` status fields are **not** removed here. The Notion panel still calls them, so removing them would break the UI before TP3 replaces the switch. They are removed in TP3.

## TP3 notes

- Removed `PATCH /api/v1/apps/notion` and the `allow_write` / `write_auto` status fields, plus the Notion write switch and the MCP page "allow write" checkbox and badge (decision #12). `BALLOWWRITE` / `BWRITEAUTO` stay in the entity until TP6.
- Deviation: the MCP page opens permissions from a per-row "Permissions" button (inline panel, deep link `?server=ID&tab=permissions`) instead of formal Overview/Permissions tabs, so `McpServersConfiguration.vue` does not grow. The Notion panel uses real tabs.
- A member who only uses an organisation-shared Notion connection has no connection of their own and sees no permissions tab yet.
- The one-time notice (decision #13) is TP4.

## Found while testing TP3

- Approve on a read tool (Notion search) in chat fails with the "this step cannot ask" refusal, because `mcp_fetch` cannot pause (§0 #16). The owner wants Approve to always ask first (§0 #17). Planned in TP4; until then Approve on read tools in chat does not work.

## TP4 notes

- Approve on a read tool in chat opens the approval card inside the answer bubble (the gate no longer refuses for a step that "cannot ask"). Accept runs the tool and the AI-composed answer replaces the waiting text in the same bubble; the step card finishes. Reject does not run it.
- Limits: the in-place answer covers the plain shape (one waiting step; the others only write text, see Follow-ups). Any other shape, or a model failure, falls back to a short follow-up message. Saved tasks and unattended runs are unchanged.
- The card shows Approve for a destructive MCP tool the owner set to ask (built-in destructive tools still hide it); Always allow stays hidden for destructive.
- One-time notice: marker `notice` in `BTOOLPOLICY`, set by migration `Version20261117160000` on existing connections with writes off; closed via `POST /api/v1/mcp-servers/{id}/tool-permissions/notice/dismiss`. Shown on the Notion Overview (banner + badge on the Permissions tab) and above the permissions list.
- Not covered: the model is not told which blocked tool it lacks; only runtime refusals link to Permissions.
- Walked in the browser: notice, Review, Dismiss; Notion search on Approve, card, Approve ran the tool. Reject uses the existing flow and was not re-walked. E2E: 159 passed, 44 failed (same known local-only set as before, none in approval or permissions specs).

## TP5 notes

- `frontend/tests/e2e/tests/mcp-tool-permissions.spec.ts` mocks the permissions API (stateful PUT, `notice` included). Covers J-TP-1, J-TP-2 (UI half), J-TP-3 (UI half), J-TP-4, the one-time notice and the read-only view.
- Not in Playwright: the chat halves of J-TP-2/3/5/7 (approval card, refusal text, Always allow) need a live model and a live Notion; they are covered by `ApprovalCard`, `approvalPlacement`, `ToolExecutionGateMcpTest`, `McpApprovalAllowanceTest`, `ChatApprovalAnswerTest`. J-TP-6 (member of an organisation-shared Notion connection) is not built for the Notion panel; the API side is covered by `McpToolPermissionControllerTest`.
- Docs: `CONNECTIONS.md` (new "Tool permissions" section), `ADMIN.md`, `PLATFORM_REFERENCE.md`, `MULTITASK_DATA_NODES.md`. The repository has no changelog file; the behaviour change of decision #6 is in the CONNECTIONS notice and the PR text.
- Plan §7 proofs checked: `McpClient::callTool()` refuses a Block tool for every caller (central choke point; `McpClientTest::testAToolTheOwnerBlockedNeverLeavesForAnyCaller`), so `NotionGateway`, `NotionPageWrite`, the linked-page readers and `NotionAccountReader` cannot reach one. Gate side: `ToolExecutionGateMcpTest`; Always allow never lifts Block: `McpApprovalAllowanceTest`.
- Catalog-size measurement (plan §8): see Follow-ups (measured 2026-11-18).

## TP6 notes

- Deviation from the plan (decision #14 read as one migration): `docs/MIGRATIONS.md` requires two phases for a column the shipped release still maps (v5.8.6 does). A `DROP COLUMN` now would break nodes still on the old entity during the rolling deploy, and unmapping alone fails `doctrine:schema:validate`.
- Phase 1 (this change, no DDL): removed `allowsWrite()/setAllowWrite()/isWriteAuto()/setWriteAuto()`, the `allow_write` field of the MCP server API (create, update, list, OpenAPI, Zod), the `allowWrite` field of the bundle export and the `writeOff` preview row (key removed in five locales). The entity keeps both columns mapped as `@deprecated` fields with default 0, like `BPROMPTS.BKEYWORDS`.
- Phase 2 (no DDL): the entity no longer maps either column. v5.8.8 still maps them, so its nodes SELECT both during the rolling deploy and a DROP in the same release would break them. `App\Doctrine\RetiredColumnsSchemaListener` declares both columns to the schema tool, so `doctrine:schema:validate` stays in sync without a mapping. The columns keep `NOT NULL DEFAULT 0`, so INSERTs from the new entity work.
- Phase 3 (`Version20261118130000`, a release after phase 2, ask first: schema): `ALTER TABLE BMCPSERVERS DROP COLUMN IF EXISTS BWRITEAUTO` and `BALLOWWRITE` (idempotent raw SQL). `RetiredColumnsSchemaListener` held only these two columns, so it and its test are removed; `docs/MIGRATIONS.md` points to commit `bf13e9ac9` as the template for the next two-phase drop. `Version20261117160000` reads `BALLOWWRITE` and stays valid because it runs before the drop.
- Review follow-up: dropping the `allowsWrite()` check left `save_to_notion` honouring only Block, and only after the file upload. The runner now reads the `create-pages` permission first (`NotionGateway::permission()`): Block refuses before any upload and hides the planner note, Approve goes through `StepApprovalGate` with an ask refusal (saved task waits even with `allow_unattended`, chat reply refuses with a Change permission link).

## Follow-ups

- Approval answer limits: `ChatApprovalAnswer` checks the `MESSAGES` limit and the cost budget of the approval owner before its extra model call. Over a limit, or an owner that cannot be loaded, falls back to the short follow-up message with no model call. The call was already metered as `MULTITASK` through `AiChatUsageMeter`.
- TP6 phase 3 (DROP COLUMN, `Version20261118130000`) must not merge before a release that contains phase 2 (#3182) runs on every production node.
- Blocked tools named to the planner: the `mcp_fetch` sub-catalog lists each connection's blocked tools ("blocked by the owner, never plan these: …", at most 10 names), so the model can say a tool is blocked in Permissions instead of answering vaguely. The gateway tool loops still just omit a blocked tool.
- Member view (J-TP-6): the Notion status carries `sharing.shared_server_id`; a member of a shared connection sees "See what the AI may do" and the read-only list (`NotionSharedPermissions.vue`, E2E in `notion-app.spec.ts`).
- In-place answer, more plan shapes: any number of pending steps now, as long as each only writes text (chat, summarize, translate, rag_query, compose_reply or the hidden compose node). A pending step that would save, send or generate no longer gets marked done without running: it falls back to the follow-up message. (Before this, up to two pending steps of any kind were marked done.)
- Catalog size (plan §8), measured 2026-11-18 on the local stack with the real Notion connection (77 tools, user 1). A throwaway console command (not committed) called `McpToolCatalogAdapter::toAnthropicTools(1, …)` and `SkillCatalog::renderCapabilityList(1, topic tool_mcp)`:
  - Planner capability list: 26.3 KB now. Without the `mcp_action` block (what a connection with writes off showed before TP1) and the blocked-tools line it is about 16.4 KB. So a connection with writes off now adds about 9.9 KB (roughly 2.5k tokens) to every planned turn, because the 4 write tools (`notion-create-pages`, `notion-update-page`, `notion-delete-pages`, `notion-delete-databases`) are now offered. The blocked-tools line costs 213 bytes. Connections that already had writes on are unchanged.
  - Gateway tool loops: 38 tools / 107 KB (read only, the default) versus 74 tools / 226 KB with mutating tools; the gateway keeps offering read tools only while approvals are off, so that path did not grow.
  - Not trimmed: the write block is bounded by the connection's own tools and a blocked tool leaves the list, so an owner who wants a smaller prompt blocks the tools they do not use.

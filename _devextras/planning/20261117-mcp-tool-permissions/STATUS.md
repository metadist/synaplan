# MCP tool permissions — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| TP0 | done | — | plan written 2026-11-17; §0 #1–#14 decided by the owner, #15–#16 added while building TP1 |
| TP1 | in review | — | storage, resolution, gate, Notion classes; see below |
| TP2 | in review | — | API, always allow; Notion PATCH stays until TP3 (see below) |
| TP3 | in review | — | Permissions UI (Notion tab, MCP page panel); Notion write switch and PATCH removed |
| TP4 | in review | — | Approve asks in chat (#17), card shows connection + Change permission, blocked-tool link, one-time notice |
| TP5–TP6 | not started | — | TP5 next |

## TP1 notes

- Migration `Version20261117150000`: `BMCPSERVERS.BTOOLPOLICY` (`LONGTEXT`, `'{}'`).
- `McpToolPermissions` fails closed: an unreadable policy or an unknown stored value makes the tool ask.
- `ApprovalPolicy`: an MCP tool's base is the connection outcome (`McpToolMeta::CONNECTION_OUTCOME`), not the instance default. Nothing loosens it (no "always allow", no `allow_unattended` on an explicit Approve). Unattended: Auto on write or destructive asks unless the step allows it.
- `ToolExecutionGate`: Block always holds (also approvals off, also `alreadyApproved`); §0 #15 and #16.
- Deleting arguments: `NotionWriteGuard::contentDeletion()` escalates to Approve in the gate. `McpClient::callTool()` still refuses them unless `contentDeletionApproved` is true, which `McpToolExecutor` sets only after an approval.
- Kept on purpose: `McpFetchRunner` stays read-only (refuses any non-read tool). The gateway loops still offer only read tools while approvals are off (unchanged). Both are narrower than the plan text; widen only if the owner wants writes with approvals off.
- Dead until TP2/TP3: `BALLOWWRITE` / `BWRITEAUTO` and the Notion "The AI may change Notion" switch are ignored by the policy. Between TP1 and TP3 the switch does nothing.
- Catalog size before/after: not measured yet.

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

- Approve on a read tool in chat now opens the approval card (the gate no longer refuses for a step that "cannot ask"). Accept runs the tool and posts a one-sentence follow-up; reject does not run it.
- Limitation: after approval the result arrives as a clipped follow-up message, not an AI-composed answer. A full resume of the AI answer is a larger follow-up.
- The card shows Approve for a destructive MCP tool the owner set to ask (built-in destructive tools still hide it); Always allow stays hidden for destructive.
- One-time notice: marker `notice` in `BTOOLPOLICY`, set by migration `Version20261117160000` on existing connections with writes off; closed via `POST /api/v1/mcp-servers/{id}/tool-permissions/notice/dismiss`. Shown on the Notion Overview (banner + badge on the Permissions tab) and above the permissions list.
- Not covered: the model is not told which blocked tool it lacks; only runtime refusals link to Permissions.
- Walked in the browser: notice, Review, Dismiss; Notion search on Approve, card, Approve ran the tool. Reject uses the existing flow and was not re-walked. E2E: 159 passed, 44 failed (same known local-only set as before, none in approval or permissions specs).

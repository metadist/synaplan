# MCP tool permissions — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| TP0 | done | — | plan written 2026-11-17; §0 #1–#14 decided by the owner, #15–#16 added while building TP1 |
| TP1 | in review | — | storage, resolution, gate, Notion classes; see below |
| TP2–TP6 | not started | — | TP2 next |

## TP1 notes

- Migration `Version20261117150000`: `BMCPSERVERS.BTOOLPOLICY` (`LONGTEXT`, `'{}'`).
- `McpToolPermissions` fails closed: an unreadable policy or an unknown stored value makes the tool ask.
- `ApprovalPolicy`: an MCP tool's base is the connection outcome (`McpToolMeta::CONNECTION_OUTCOME`), not the instance default. Nothing loosens it (no "always allow", no `allow_unattended` on an explicit Approve). Unattended: Auto on write or destructive asks unless the step allows it.
- `ToolExecutionGate`: Block always holds (also approvals off, also `alreadyApproved`); §0 #15 and #16.
- Deleting arguments: `NotionWriteGuard::contentDeletion()` escalates to Approve in the gate. `McpClient::callTool()` still refuses them unless `contentDeletionApproved` is true, which `McpToolExecutor` sets only after an approval.
- Kept on purpose: `McpFetchRunner` stays read-only (refuses any non-read tool). The gateway loops still offer only read tools while approvals are off (unchanged). Both are narrower than the plan text; widen only if the owner wants writes with approvals off.
- Dead until TP2/TP3: `BALLOWWRITE` / `BWRITEAUTO` and the Notion "The AI may change Notion" switch are ignored by the policy. Between TP1 and TP3 the switch does nothing.
- Catalog size before/after: not measured yet.

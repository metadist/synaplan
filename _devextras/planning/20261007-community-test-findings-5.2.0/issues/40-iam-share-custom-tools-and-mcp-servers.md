<!-- title: IAM: custom HTTP tools and MCP servers have no per-item sharing or access list — reuse the folder share dialog -->
<!-- type: Feature -->
<!-- labels: prio:2, area:admin -->
<!-- issue-type: Feature -->

## Summary
Custom HTTP tools and connected MCP servers get the same Share dialog as folders, chats and assistants (people and groups; levels view / use / manage), so a tool built once can be used by a team and attached to shared assistants.

---

## Problem / Motivation
Folder sharing is strong and the everyone-shares setting explains clearly what each value does. Gaps: custom HTTP tools and MCP servers have no per-item sharing or access list; they are owner-only and only appear in the owner's export (F42, comparison §"Access control and limits"). Open WebUI uses one pattern (Private / Public plus read-write grants) for models, knowledge, prompts, tools and tool servers alike.

---

## Goal
"Share" on a tool or MCP server row opens the familiar dialog; a recipient with "Can use" sees the tool in their chat and in the assistant builder, cannot edit it, and the owner sees who has it (U7).

---

## Acceptance criteria
- [ ] `ShareDialog kind="tool"` and `kind="mcp_server"` with levels: Can use (call it; approvals and risk class apply to the caller), Can edit (change the template), Can manage (re-share). Roadmap row 1 already lists `ShareDialog kind="tool"` as a leftover — finish that first.
- [ ] Credentials never travel: a shared tool uses the owner's stored credential server-side; recipients cannot read it.
- [ ] Rows show "Shared with 3 people · 1 group"; Groups → "What is shared with this group" lists tools and servers.
- [ ] Group policy "custom HTTP tools" and "read from and act through connected tools" still gate use for recipients.
- [ ] Revoke from the row with the consequence sentence ("They can no longer call this tool; running approvals are cancelled.", U3).

---

## Notes
- Findings: F42 — community test round on 5.2.0.
- Code: `backend/src/Service/Iam/ResourceKind/` (add kinds), `ShareDialog`, `CustomToolsConfiguration.vue`, the MCP server config controller (`McpServerConfigController.php`), `ToolCallRunner` / `McpToolRegistry` access checks.
- Journey (U10): owner shares a tool with a group → member asks in chat → tool runs → owner revokes → member's next call says the tool is not available.

---

## Screenshots/Logs
—

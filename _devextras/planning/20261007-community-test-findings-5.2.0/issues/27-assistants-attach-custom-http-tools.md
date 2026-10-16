<!-- title: Assistants: custom HTTP tools cannot be attached to an assistant — Tools and skills lists only connected MCP apps -->
<!-- type: Feature -->
<!-- labels: prio:2, area:admin -->
<!-- issue-type: Feature -->

## Summary
The assistant builder's Tools and skills section lists the person's custom HTTP tools (own and shared) next to connected MCP apps, so an assistant can be given exactly the tools it needs.

---

## Problem / Motivation
Custom HTTP tools cannot be attached to an assistant; only connected MCP apps are listed (F41). A tool built for one purpose therefore is available to every chat or to none, and an assistant published to colleagues cannot carry its tool.

---

## Goal
An assistant declares which custom tools it may call; recipients of a shared assistant can use those tools through the assistant without owning them (same model as knowledge folders: the owner's grant travels with the assistant, read-only to the recipient).

---

## Acceptance criteria
- [ ] Tools and skills shows a "Custom tools" group with the own and shared (use-level) tools; each has an on / off toggle and shows its risk class.
- [ ] The agent definition carries the tool references (`tools.custom: [ids]`) with validation in `AgentDefinitionValidator`.
- [ ] The runtime exposes only the assistant's tools to the planner for assistant turns (not every tool the user owns).
- [ ] Approval rules of the tool apply unchanged; "Always allow for this assistant" keeps working.
- [ ] The tool's row in Connected apps shows "Used by assistant <name>" (U7).

---

## Notes
- Findings: F41 — community test round on 5.2.0.
- Code: `frontend/src/components/assistants/` (Tools and skills section), `backend/src/Service/Agent/Definition/AgentDefinitionValidator.php`, `AgentRuntimeResolver.php`, `backend/src/Service/Tool/Custom/CustomToolService.php`, `ToolCallRunner`. Sharing of tools themselves is the IAM issue "share custom tools and MCP servers" and the roadmap row 1 item `ShareDialog kind="tool"`.
- Journey (U10): build tool → assistant → Tools and skills → switch the tool on → Save → Start chat → ask → the tool runs (approval card if write-class) → remove the tool → ask → the assistant says it has no such tool.

---

## Screenshots/Logs
—

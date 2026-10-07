<!-- title: Chat: expandable task steps with the plan, tool input and output, timing and the real error -->
<!-- type: Feature -->
<!-- labels: prio:1, area:chat, area:routing -->
<!-- issue-type: Feature -->

## Summary
Every step on a task card can be expanded to show what it did: the plan contents, the capability chosen and the ones considered and skipped (with the reason), the sanitized tool input, the returned payload (or a preview of it), timing, and the actual error when it failed. Steps are labelled with the tool's own name.

---

## Problem / Motivation
Task cards show step names and completion only. Expanded steps read `n1 tool call → n2 Answer` (F9). With File work on, a PDF edit was refused after "Understood your request, Steps planned (9.0s), Request analyzed, Thought for 1 seconds" — no plan contents, no sign the file-work step was considered, no reason it was skipped (F26). A custom tool's step is labelled "Web search" instead of the tool name and shows only "tool call", so a 400 or 502 from the tool could not be diagnosed and a working call's result could not be seen (F43, F35). One invoice answer swapped seller and customer and nothing on screen showed why. Open WebUI shows reasoning, status updates and each tool call with input and output; the testers' comparison also notes that expandable results let an operator catch a model that "reports success it did not achieve".

---

## Goal
A person — or an admin helping them — can open any step and see what was sent, what came back, how long it took and what failed, without reading server logs.

---

## Acceptance criteria
- [ ] Each step row has an expand control; expanded, it shows: capability / tool name, input parameters (secrets masked; credentials never), output preview (first N KB with "show more"), status, duration, and the error message when failed.
- [ ] "Steps planned" expands to the plan: which capabilities were considered, which were chosen, and for the skipped ones the planner's reason in one sentence.
- [ ] Reasoning text the model provides is shown in a collapsed "Thought" block, never as answer text.
- [ ] Steps are labelled with the tool / server name (`<tool name>` for custom HTTP tools, `<server> · <tool>` for MCP), not the capability family ("Web search").
- [ ] Headers and step details survive a reload (stored with the message).
- [ ] Copy hygiene: "Thought for 1 second" (singular / plural via i18n plural rules) in all five locales.
- [ ] The widget and the mobile app get the same expandable card (shared component).

---

## Notes
- Findings: F9, F26 (transparency half), F43 (4) and (6), F35 (label) — community test round on 5.2.0. The PDF-edit routing half of F26 is its own issue; the MCP error path is its own issue.
- Frontend: `frontend/src/components/multitask/TaskCard.vue` (card kinds, `props.card.state`, `taskPlan.*` i18n), `ComputeRunCard.vue`. Backend: the multitask plan / execution events, `backend/src/Service/Multitask/Execution/Runner/*` (`ToolCallRunner`, `McpFetchRunner`, `McpActionRunner`, `UrlFetchRunner`, `CodeRunRunner`), `ComposeReplyRunner`.
- Storage: mask credentials and `Authorization` headers before the row is saved. Cap the stored preview (the cap is a product choice; 8 KB is enough to debug the 400 the testers hit). Do not store raw file bytes. A shared chat shows this expansion to everyone with the link — treat it as user-visible, not as an admin log. Do not `logger->info` the body.
- Journey (U10): ask a question that triggers a custom tool → open the step → see method, URL, arguments, status 200 and the body preview → ask something the tool 400s on → the step says "The tool answered 400: <message>" and the reply does not claim success.

---

## Screenshots/Logs
Step list from the report: "Understood your request · Steps planned (9.0s) · Request analyzed · Thought for 1 seconds".

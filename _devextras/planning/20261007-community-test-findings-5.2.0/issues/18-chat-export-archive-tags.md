<!-- title: Chat: per-chat export (Markdown, PDF, JSON), archive and tags in the chat menu -->
<!-- type: Feature -->
<!-- labels: prio:2, area:chat -->
<!-- issue-type: Feature -->

## Summary
The chat menu gains Export (Markdown, PDF, JSON), Archive and Tags; archived chats have a filter on the All chats page; tags are searchable in the palette.

---

## Problem / Motivation
The chat menu has Share, Rename and Delete only: no export, archive or tags; no archive exists anywhere (chat menu, command palette, All chats page). Preferences → Export & import covers instructions, assistants, custom tools, connections and saved tasks, not chats (F38). Open WebUI exports JSON, text and PDF from the chat menu and has tags, pin and archive.

---

## Goal
A person can take a conversation out of Synaplan in a readable form, park finished chats without deleting them, and label chats so they can be found again.

---

## Acceptance criteria
- [ ] Chat menu: Export → Markdown / PDF / JSON. Markdown includes role, time, model and cost per message and the task-step summary; JSON is the full message tree; PDF is server-rendered from the Markdown.
- [ ] Archive: moves the chat out of the sidebar into All chats → filter "Archived"; Unarchive from the same row (U3).
- [ ] Tags: add / remove on the chat; chips in the sidebar and All chats; `#tag` in the command palette finds them.
- [ ] Account export (Preferences → Export & import) gains "All chats (JSON)".
- [ ] Group policy switches "export chats" and "share chats" exist so an admin can turn them off (pairs with the group-policies issue).
- [ ] All five locales; widget unaffected.

---

## Notes
- Findings: F38 (export, archive, tags) — community test round on 5.2.0. The prompt library and the Enhance feedback from F38 are separate issues.
- Frontend: chat menu component, All chats page filters, command palette (`#` already means settings — pick a distinct prefix for tags or search tags in the general index). Backend: an `archived` flag and a tags relation on the chat entity (migration, ask-first), export endpoints with OpenAPI annotations → `make -C frontend generate-schemas`.
- Journey (U10): finish a chat → Export → Markdown → file opens and reads cleanly → Archive → gone from the sidebar → All chats → Archived → Unarchive → back.

---

## Screenshots/Logs
—

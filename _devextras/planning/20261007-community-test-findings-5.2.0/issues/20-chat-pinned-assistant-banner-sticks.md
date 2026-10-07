<!-- title: Chat: the "Talking to <assistant>" banner sticks to new chats opened from the home page and cannot be closed, so requests are routed to the assistant without warning -->
<!-- type: Bug -->
<!-- labels: prio:2, area:chat -->
<!-- status: shipped -->
<!-- issue-type: Bug -->

> **Shipped** in [#2383](https://github.com/metadist/synaplan/pull/2383) (`cc075dcfa`), the misrouting only. Sidebar, mobile, the command palette and All chats drop `?agentId=`, including when the person is already on the home page. The banner button opens an empty chat first, then clears the pin: deleting the query alone leaves the assistant on a thread whose messages already name it. The button carries the close icon. The model chip still lists models only. An Assistants section on that chip is issue 22. Do not re-implement the pin clear.

## Problem
After Start chat on an assistant, the "Talking to <assistant>" banner stays on new chats opened from the home page. A later, unrelated request went to the assistant without warning; the assistant had no connected apps and answered that it could not reach them. The banner has no close button, the model chip lists only models, and only the sidebar's New Chat button clears it.

---

## Expected
A new chat starts without an assistant unless the person chose one. The banner has a close / switch control; the composer chip offers an "Assistant" section so the current assistant is visible and changeable where the model is.

## Actual
1. Assistant → Start chat → banner "Talking to Test KB Assistant".
2. Home → new chat → banner still there; a request about something else goes to the assistant.
3. No close control on the banner; model chip has no assistant entry.

---

## Steps to reproduce
1. Open an assistant → Start chat.
2. Go to the home page, start a new chat from there.
3. Send a message unrelated to the assistant.

---

## Notes
- Findings: F45 — community test round on 5.2.0.
- Frontend: the pinned assistant state lives in the chat store / route query; the home-page new-chat path does not reset it while the sidebar New Chat does. Banner component near `ChatInput.vue`.
- This is a silent misrouting: the person believes they talk to the default model (U7 — "who am I talking to" must be answered on the surface and be changeable from it).

What shipped (#2383): every new-chat entry point removes `agentId` from the address and keeps other query parameters. The banner button says "New messages go to the default model", includes `XMarkIcon`, and calls `findOrCreateEmptyChat()` before `goToFreshChat()`. The Playwright case `assistant-first-message` clicks that button and expects the next stream request to omit `agentId`. What did not ship: a Switch control that opens an Assistants section on the model chip. That section is issue 22.

Journey (U10): assistant → Start chat → home → new chat → no banner → ask → default model answers; → chip → Assistants → pick one → banner appears → × → banner gone.

Verification:
1. Every new-chat entry point starts without an assistant.
2. Banner close is keyboard reachable and announced.
3. E2E: `make test-e2e` includes the entry-point sweep.

---

## Screenshots/Logs
Banner: "Talking to <assistant>" on an unrelated new chat.

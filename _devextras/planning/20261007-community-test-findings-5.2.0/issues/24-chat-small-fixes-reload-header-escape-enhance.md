<!-- title: Chat: three small fixes — step and timing header lost after reload, Message details ignores Escape, "Enhance" gives no feedback -->
<!-- type: Bug -->
<!-- labels: prio:2, area:chat -->
<!-- issue-type: Bug -->

## Problem
Three independent chat defects from the same test round. Land them as separate commits. "Enhance" is not a one-line copy change: it needs a backend result that says what changed.

---

## Expected
1. After a reload, earlier answers keep their step and timing header.
2. Escape closes the Message details pop-up (and every pop-over in chat).
3. The sparkle "Enhance" button says what it changed, and says so when it changed nothing.

## Actual
1. After a reload, earlier answers lose their step and timing header (F41).
2. The Message details pop-up stays open over the answer after Escape (F36).
3. "Enhance" only tidied capitalization and punctuation ("write a poem about cats" → "Write a poem about cats."); a well-formed prompt came back unchanged with no feedback (F38).

---

## Steps to reproduce
1. Run a multi-step request; reload; compare the header above the answer.
2. Open Message details; press Escape.
3. Type a clean prompt; click Enhance.

---

## Notes
- Findings: F41 (reload), F36 (Escape), F38 (Enhance) — community test round on 5.2.0.
- Header after reload: the step summary is rendered from the live stream state and not from the stored message; persist (or re-derive from the stored task card) and render from the same source on load (`frontend/src/views/ChatView.vue`, message store).
- Escape: the details pop-over lacks a key handler / focus trap; use the shared dialog composable so Escape and focus return behave like other dialogs.
- Enhance: return a diff summary from the backend ("Capitalized the first word and added a period." / "Your prompt already reads well — nothing changed.") and show it as a toast via `useNotification()`; consider making the enhancement stronger (structure, missing constraints) in a follow-up.

Journey (U10): multi-step answer → reload → header still there; details → Escape → closed, focus back on the message; Enhance on a clean prompt → toast says nothing changed.

Verification:
1. Vitest for the header derivation from a stored message.
2. E2E: Escape closes details; `make test-e2e`.
3. Five locales for the Enhance toasts.

---

## Screenshots/Logs
Enhance: "write a poem about cats" → "Write a poem about cats."

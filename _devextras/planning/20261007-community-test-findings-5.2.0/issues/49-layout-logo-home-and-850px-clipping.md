<!-- title: Layout: the logo looks clickable but does nothing; at ~850 px with the chats panel open the usage meter and the message column run past the viewport -->
<!-- type: Bug -->
<!-- labels: prio:2, area:header, area:nav -->
<!-- issue-type: Bug -->

## Problem
Two layout defects. The logo in the upper left looks clickable but does nothing (no tooltip either); clicking it from the Library has no effect (F6, since 5.0.6). At a window about 850 px wide with the chats panel open, the usage meter at the right edge is cut off, and in a chat with a task plan the message and the Report button run past the edge; it fits at about 1530 px (F15).

---

## Expected
The logo routes to the home / new-chat view and has a tooltip. Between 800 and 1000 px the meter and the message column stay inside the viewport (the chats panel collapses or the column shrinks); U9 asks for every size.

## Actual
1. Logo: no action, no tooltip, no cursor change that matches an action.
2. ~850 px + chats panel: meter clipped; task-plan message and Report button overflow horizontally.

---

## Steps to reproduce
1. Open the Library; click the logo.
2. Resize the window to ~850 px; open the chats panel; open a chat with a task plan.

---

## Notes
- Findings: F6, F15 — community test round on 5.2.0.
- Code: the rail / header logo component; the chat layout grid and the usage meter container; `make -C frontend test-e2e-layout` is the gate for layout changes (chromium Mobile matrix).

Fix direction: wrap the logo in a `RouterLink` to `/` with `aria-label` and the shared tooltip; add a `min-w-0` / `overflow-x-hidden` chain on the message column and let the task card and the meter wrap; collapse the chats panel automatically below a breakpoint when a chat is open; verify at 320, 850, 1024 and 2560 px.

Journey (U10): Library → logo → home; resize to 850 → open chats → meter visible → open a task-plan chat → Report button visible without horizontal scroll.

Verification:
1. E2E layout spec asserts no horizontal overflow at 850 px with the panel open.
2. Logo has role link, tooltip text in five locales.

---

## Screenshots/Logs
—

<!-- title: Chat: edit a sent message and rerun; version arrows on regenerated answers -->
<!-- type: Feature -->
<!-- labels: prio:2, area:chat -->
<!-- issue-type: Feature -->

## Summary
A sent user message gets an Edit action that reruns from that point; "Again with…" keeps every version of an answer and lets the person step between them with ‹ 1/2 › arrows instead of stacking a dimmed old answer above the new one.

---

## Problem / Motivation
A sent message cannot be edited and rerun; the only message actions are Copy and Message details. "Again with…" picks a model and adds the new answer below the old one (the old one dimmed); there is no way to switch between versions (F36). Edit-and-rerun and version switching are the first everyday actions people coming from other AI chat apps miss (comparison §"Chat experience").

---

## Goal
Fix a typo or sharpen a question without retyping and without losing the earlier branch; compare two answers to the same question side by side by flipping versions.

---

## Acceptance criteria
- [ ] User messages have an Edit action (`PencilIcon`, same icon as elsewhere); editing opens the text inline with Save & send and Cancel; sending creates a new branch from that message.
- [ ] Answers regenerated with "Again with…" become versions of the same turn: ‹ 1/2 › control, model name and cost per version, the chosen version is what the next turn builds on.
- [ ] Branches and versions survive a reload and show in a shared chat.
- [ ] Message details show why a simple answer used 8,383 tokens (routing overhead broken down by step) — the number itself is a routing concern, the breakdown belongs here.
- [ ] Widget parity decided in the PR (at least versions; edit may stay app-only).

---

## Notes
- Findings: F36 — community test round on 5.2.0.
- Frontend: `frontend/src/views/ChatView.vue` (regenerate path), message action bar component, message store. Backend: message tree / parent id support — check whether messages already carry a parent reference; if not, this needs a migration (ask-first per AGENTS.md).
- Journey (U10): send "write a poem about cats" → Edit → "write a haiku about cats" → Save & send → new answer; → Again with another model → ‹ 1/2 › shows both → pick version 1 → next question refers to the haiku.
- Escape not closing the details popup is in the "three small chat fixes" issue.

---

## Screenshots/Logs
—

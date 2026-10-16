<!-- title: Chat: an ask-the-user step the planner can pause on — choices, a recommended option, free text, then the run continues -->
<!-- type: Feature -->
<!-- labels: prio:3, area:chat, area:routing -->
<!-- issue-type: Feature -->

## Summary
When the planner needs a decision it cannot make, it pauses with a card (options, a Recommended badge, free text, Submit) and continues the same run with the answer, instead of writing questions as reply text and waiting for a new message.

---

## Problem / Motivation
With the ambiguous prompt "Set up the backup schedule for our server.", Synaplan asked sensible questions as plain reply text. Open WebUI paused and showed a two-step form with options, a Recommended badge, free text, Previous, Next and Submit, then continued after the answers (F31). Plain-text questions lose the plan: the next message is a fresh turn.

---

## Goal
A clarifying question is a step in the run, answered in place; the run resumes with the answers and the earlier steps are not redone.

---

## Acceptance criteria
- [ ] A `ask_user` capability the planner can emit with a question, up to five options (one may be marked recommended), and an optional free-text field; multi-question forms step with Previous / Next.
- [ ] The run pauses in a terminal-until-answered state (same mechanism as tool approvals: card in chat, visible after reload, expires with a stated time, U8).
- [ ] Submit resumes the same run; "Skip" lets the planner continue with its recommendation.
- [ ] Works in the widget and for saved tasks (where the question lands in the Approvals-style inbox).
- [ ] Routing characterization case added.

---

## Notes
- Findings: F31 — community test round on 5.2.0. **Effort L** — write a sprint file under `_devextras/planning/` naming the journey and the five exit bullets before coding (planning rule for sprint files after 2026-09-13).
- Reuse: the approval pause / resume path and `ApprovalCard.vue` layout; the planner capability list in `backend/src/Service/Multitask/Plan/Capability.php` and the runner directory.
- Journey (U10): "Set up the backup schedule for our server." → card with options (daily recommended, weekly, custom) → pick custom, type "every 6 hours" → Submit → the plan continues with that answer.

---

## Screenshots/Logs
—

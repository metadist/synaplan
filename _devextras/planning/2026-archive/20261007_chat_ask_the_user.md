# Chat: ask-the-user step

**Class:** ota-candidate (the card) + backend-only (the planner capability and resume).

## Goal

When a run needs a decision, it pauses on a card in the same chat. The person
picks an option or types an answer, and the same run continues. Earlier steps
are not done again.

## User-flow

Journey: "Set up the backup schedule for our server." → a card offers daily
(recommended), weekly, and custom → pick custom, type "every 6 hours" →
Submit → the plan continues with that answer. Skip uses the recommended
option. The card is on the answer itself, so it is found without leaving the
chat. A saved task shows the same waiting step in the run, and the Approvals
inbox is unchanged for tool approvals.

## Exit criteria

1. Named journey walked in the browser: question card, submit, the answer is
   what the rest of the run uses.
2. The waiting question is on the chat message after a reload, and a saved
   task run shows the same pause.
3. Skip, submit, and an expired question each say what happened, in all five
   locales.
4. Empty question fails the step with a sentence. Flag-off is not a separate
   surface: the step simply is not offered when the planner does not emit it.
5. The card is readable in light, dark, and at 320 px.

## Notes

- Reuses the approval pause (`waiting_approval`) and `DagExecutor::resume`.
- An answer is the node's text (`$nX.text`) for later steps.
- Widget: the card renders wherever the task card renders. The website widget
  does not draw task cards today; the web chat and saved tasks do.

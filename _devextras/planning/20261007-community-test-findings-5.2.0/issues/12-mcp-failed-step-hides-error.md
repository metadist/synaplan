<!-- title: MCP: a failed MCP step drops the plan into the chat fallback and hides the error; with one good and one failed call the answer step is skipped -->
<!-- type: Bug -->
<!-- labels: prio:2, area:routing -->
<!-- issue-type: Bug -->

## Problem
The success path is fixed in 5.2.0 (list buckets, bucket location and head bucket ran and the answer used their results; steps are labelled with server and tool name). The error path is still open: a single missing bucket (404) made the plan drop and the legacy chat say no connection is available (5 of 5 runs), with nothing logged for the error. With one good and one missing bucket, the answer step is skipped and only the good bucket is reported; the 404 shows only on the task card.

---

## Expected
A failed MCP step is treated like a failed code-run step: the step shows the real error (status, server message), the answer step still runs and reports both outcomes ("Bucket A: eu-central-1. Bucket B: not found (404)."), and the error is logged.

## Actual
1. Ask about a bucket that does not exist → plan drops → legacy chat: "no connection is available".
2. Ask about one existing and one missing bucket → answer mentions only the existing one; the 404 is on the card only.
3. Backend log: nothing for the failure.

---

## Steps to reproduce
1. Connect an MCP server (any with a lookup tool that can 404).
2. Ask for an item that does not exist.
3. Ask for one existing and one missing item in one message.

---

## Notes
- Findings: F2 (error path) — community test round on 5.2.0; first seen on 5.0.6.
- Runners: `backend/src/Service/Multitask/Execution/Runner/McpFetchRunner.php`, `McpActionRunner.php`; the compose step is `ComposeReplyRunner.php`. Compare with `CodeRunRunner`, which keeps the answer step on failure.
- The fallback to legacy chat is the "plan dropped" path in the multitask executor; a step failure should mark the step failed and continue to the answer step with the error in the step output.

Fix direction: MCP runners return a failed step result with `error` populated (status + server message, sanitized) instead of throwing out of the plan; the executor only abandons the plan when no step can run at all; `ComposeReplyRunner` receives failed steps and is prompted to report them; log at `warning` with server, tool and status.

Journey (U10): one good and one missing bucket → answer names both outcomes → expand the failed step → "404 Not Found: <server message>".

Verification:
1. 5 of 5 runs with a missing item produce an answer that says it was not found; no "no connection is available".
2. Log contains the 404 with server and tool.
3. Routing characterization snapshots unchanged or re-recorded and reviewed.

---

## Screenshots/Logs
Legacy chat text: "no connection is available" (5.2.0).

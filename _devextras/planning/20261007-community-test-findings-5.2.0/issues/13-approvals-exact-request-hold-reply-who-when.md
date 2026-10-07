<!-- title: Approvals: the card shows a model-written summary instead of the exact request, the reply is written before the decision, and Decided shows no who or when -->
<!-- type: Bug -->
<!-- labels: prio:2, area:chat, area:admin -->
<!-- status: shipped -->
<!-- issue-type: Bug -->

> **Shipped** in [#2380](https://github.com/metadist/synaplan/pull/2380) (`c5b6f22b5`). The card shows method, URL, and the masked body. `decidedBy` and `decidedAt` were already on the approval, so there is no migration. The decided time uses the app date format. Do not re-implement.

## Problem
Tool approvals work with strong defaults (read runs, change asks, delete is blocked, 72-hour expiry, card in chat and in the Approvals inbox). Rough edges: the card shows only a model-written summary, not the method, URL or body that will be sent; the reply already says "I couldn't fully complete that request" while waiting; after approval a second message says only "Done … Result: Request finished" and the first card still says Waiting for approval; Decided shows "Done" with no who or when; new requests are announced by email by default, which a null mail transport discards.

---

## Expected
The card shows exactly what will be sent (method, URL, headers with credentials masked, body) so the person approves a request, not a paraphrase. The reply is held until the decision and then continues in place; the original card updates to Approved / Rejected with the decider's name and time; the tool's actual result is shown after approval. Notifications default to in-app when mail is not configured.

## Actual
1. Write-class tool call → card with summary text only.
2. Reply text while waiting: "I couldn't fully complete that request".
3. After Approve: second message "Done … Result: Request finished"; first card unchanged.
4. Approvals → Decided: "Done", no name, no timestamp.
5. Email notification by default on an instance with `MAILER_DSN=null://null`.

---

## Steps to reproduce
1. Create a custom HTTP tool with risk class "Changes something".
2. Ask the assistant to use it; watch the chat and the Approvals inbox.
3. Approve; read both messages and the Decided tab.

---

## Notes
- Findings: F35 — community test round on 5.2.0.
- Frontend: `frontend/src/components/chat/ApprovalCard.vue` (emits approved / rejected / alwaysAllow; renders `approval.sideEffect`, expiry), `frontend/src/components/config/ApprovalsInbox.vue`. Backend: the approval entity and the tool-call pause / resume path in the multitask executor (`ToolCallRunner`, approval service).
- Open WebUI's card shows the exact arguments with Allow / Deny; a denied call is reported back to the model — same expectation here.
- The exact request must be rendered from the resolved template (`{{input.*}}`, `{{credential.header}}` masked), not from the model's description.

Fix direction: store the resolved request (method, URL, masked headers, body) on the approval row at pause time and render it on the card and in the inbox. Mask `Authorization` and `{{credential.*}}` before save; the email notification (when mail works) says which tool is waiting and does not include the body. Hold the compose step until the decision (the stream shows "Waiting for your approval" as a terminal-until-decided state, U8). On decision, update the same card (state, decider, time) and continue the plan in place. `decidedBy` / `decidedAt` need a migration — ask before adding columns. Default `notify=in-app` when `MailerConfig::isConfigured()` is false. Do not change the read / change / delete defaults.

Journey (U10): tool with "Changes something" → ask → card shows `POST https://api.example.com/items {"name":"…"}` → Reject with reason → reply says what was not done → ask again → Approve → the same card turns Approved by <me> at <time> → reply continues with the result.

Verification:
1. No reply text appears before the decision.
2. Decided tab lists name and time for each entry.
3. On an instance with the null transport, the default notification channel is in-app.

---

## Screenshots/Logs
Reply while waiting: "I couldn't fully complete that request". After approval: "Done … Result: Request finished".

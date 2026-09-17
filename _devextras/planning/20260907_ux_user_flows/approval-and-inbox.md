# Wireframe — Approval in chat and inbox (track 4 S2/S3)

Journeys **J-TL-1**, **J-TL-2**, **J-TL-3**.

Banned in primary copy: side effect, destructive hint, DAG, node,
tool_use, MCP (except on the Connections screen).

## Chat card (interactive write)

```text
┌─ Needs your approval ─────────────────────────────────────────────┐
│ Create a calendar event                                           │
│ “Q3 review” on Tue 15 Sep, 10:00–11:00 (Europe/Berlin)            │
│ in Work calendar.                                                 │
│                                                                   │
│ Nothing has been created yet.                                     │
│ Expires in 71 hours.                                              │
│                                                                   │
│ [ Approve ]  [ Reject ]  [ Always allow for this assistant ]      │
└───────────────────────────────────────────────────────────────────┘
```

- **Always allow** only when policy is `approve` and the call is
  tied to an assistant. Hidden for `block`. Helper: "Skip this
  question next time, for you only."
- Reject → `useDialog().prompt()` for an optional reason. After
  reject the thread shows: "Nothing was created."
- Destructive / blocked: no card. One sentence in the assistant
  reply: "I cannot delete that. An administrator has turned this
  off."

## After the user closed the tab

Automations rail child shows a badge count. Account is not a second
inbox.

## Approvals inbox (`/channels/approvals`)

```text
┌─ Approvals ───────────────────────────────────────────────────────┐
│ [ Pending 2 ]  [ Decided ]                                        │
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐   │
│ │ Create a calendar event · from the chat “Q3 planning”       │   │
│ │ “Q3 review” on Tue 15 Sep …          Expires in 71 hours    │   │
│ │ [ Approve ]  [ Reject ]  [ Open chat ]                      │   │
│ └─────────────────────────────────────────────────────────────┘   │
│ ┌─────────────────────────────────────────────────────────────┐   │
│ │ Create ticket in Helpdesk · from Weekly digest              │   │
│ │ Title: Printer on floor 3                Waiting since 02:10│   │
│ │ [ Approve ]  [ Reject ]  [ Open run ]                       │   │
│ └─────────────────────────────────────────────────────────────┘   │
└───────────────────────────────────────────────────────────────────┘
```

Empty Pending: "Nothing needs your approval. When the AI wants to
change something, it will wait here."
Empty Decided: "Approved and rejected items will show up here."

Unattended (S3): the same pending row; **Open run** goes to the Saved
Task run with the waiting step highlighted as **Waiting for you**,
never as a failed node.

## Email (instant / digest)

Subject: "Synaplan is waiting for your approval."
Body: the preview sentence + a link to the inbox. Never the raw
arguments, never a credential.

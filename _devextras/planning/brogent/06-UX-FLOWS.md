# BroGent — UX Flows

## Primary User Journeys

### 1) Pair a browser

- Open Synaplan → BroGent.
- Click “Pair new browser”.
- See pairing code and short instructions.
- Install extension. Enter URL + code.
- Device shows “Online”.

Success criteria:

- pairing under 60 seconds
- device shows last seen time

### 2) Create a task (template-first)

- Choose a template.
- Fill inputs and approvals.
- Save.

Success criteria:

- no blank canvas at MVP

### 3) Trigger a task from Synaplan UI

- Select task → inputs → Run.
- See step-by-step timeline.
- Approve if needed.
- See summary and artifacts.

### 4) Trigger a task from WhatsApp/email (inbound)

- Inbound message matches a rule.
- Synaplan creates a run.
- Extension executes.
- Result returns via the channel or UI.

## Run Monitoring UI (minimal)

For each run:

- status pill
- current step
- recent events (last 20)
- approvals
- artifacts list
- cancel button

## Approval UX

Approvals should be:

- explicit prompt
- short text
- positive action to approve
- “approve once” first; “always allow” later

## Error UX

Common failures:

- not logged in (WhatsApp QR)
- captcha / blocked
- selector changed
- network offline

User-facing response:

- show what is needed
- allow retry
- allow skip only for advanced users

## Context windows (coding guide)

### Window A: Pairing UI

- Read: `02-PROTOCOL.md`
- Implement: pairing code view and device list
- Test: invalid code, device offline

### Window B: Run UI

- Read: `01-ARCHITECTURE.md`
- Implement: run list, run details, cancel
- Test: live updates and retry


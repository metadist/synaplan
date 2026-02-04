# BroGent — XP Delivery Plan (vibe-coding, but engineered)

## Delivery Style

Use tight XP loops:

- small stories
- fast feedback
- demos every 1–2 days
- refactor often
- tests are required

## Work Breakdown (small steps)

### Spike 0 — Align on constraints (docs only)

Acceptance:

- protocol drafted
- DSL drafted
- three site targets listed
- safety rules defined

### Slice 1 — “Hello run”

Goal: run orchestration end-to-end with a toy task.

- Synaplan plugin:
  - create pairing code
  - claim pairing
  - create a queued run
  - claim run
  - accept events
- Extension:
  - minimal skeleton
  - polling + event posting
  - execute 1–2 DSL steps on a mock page

Acceptance:

- user triggers “open example.com and screenshot” from Synaplan
- extension executes and reports back
- run shows status + screenshot

### Slice 2 — Approvals

Acceptance:

- task pauses on `require_approval`
- user approves in Synaplan
- run continues and finishes

### Slice 3 — Mock WhatsApp: send message

Acceptance:

- DSL task template can send message on mock site
- requires approval
- logs and artifacts shown

### Slice 4 — Inbound channel trigger → BroGent run

Acceptance:

- inbound “/brogent …” message creates a run
- extension executes mock task
- result summary is sent back (or visible in Synaplan)

### Slice 5 — Packaging & portability

Acceptance:

- extension is Chrome-ready
- a Firefox port plan is validated (no Chrome-only hard deps)

## Story Cards (examples)

- Pairing UI: pair extension to account.
- Run list: see last 20 runs.
- Approval: approve or reject send.
- Resilience: offline queue and sync.

## Definition of Done (per story)

- feature works end-to-end
- mock-site Playwright test exists
- key events logged
- no secrets in logs
- rollback and retry defined

## Risk Spikes

Timebox these and document outcomes:

- MV3 extension architecture across Chrome/Firefox
- WhatsApp Web DOM brittleness and bot detection signals
- artifact upload sizing/retention


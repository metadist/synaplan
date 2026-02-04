# BroGent — Architecture

## Components

### A) Synaplan Plugin: `brogent`

Responsibilities:

- Pairing and auth for extensions.
- Task CRUD.
- Run orchestration: queue, claim, heartbeat, cancel.
- Event sink: progress, events, artifacts.
- Inbound routing: WhatsApp/email/webhook.
- UI: minimal screens for tasks, runs, pairing.

Constraints:

- Minimal invasion into core.
- Use `BCONFIG` for simple settings (`P_brogent`).
- Use `plugin_data` for `task`, `run`, `device`, `sitepack`.
- Add tables only if needed later.

### B) Browser Extension (Chrome-first WebExtension)

Responsibilities:

- Device identity and pairing token.
- Polling for runs. Push later.
- Execute Task DSL.
- Site packs: selectors, readiness, errors.
- Artifacts: screenshots, extracts, DOM snippets.
- Offline queue for runs and events.

### C) Optional Online Executor (Phase C)

Playwright worker on the server. It uses the same Task DSL.

## Data Flows (Phase A)

### 1) Pairing

1. Synaplan creates a short-lived pairing code.
2. Extension submits Synaplan URL + pairing code.
3. Plugin returns device token + device id.
4. Plugin stores device in `plugin_data`.

### 2) Task execution (Synaplan → extension)

1. User triggers a task. UI or inbound message.
2. Plugin creates a run in `queued`.
3. Extension claims the run.
4. Extension runs steps and streams events.
5. Run ends with status and artifacts.

### 3) User approvals (human-in-loop)

Some steps require explicit confirmation:

- Send message, upload, delete, share, external navigation.

Flow:

1. Extension pauses and sends `approval_required`.
2. Synaplan asks the user.
3. Extension reads the decision and continues or stops.

## Message Transport Choices

### MVP (simplest)

- Extension uses **polling**:
  - `claim runs` every N seconds
  - `send events` immediately or in short batches

Pros: simple and reliable.
Cons: slower and noisy.

### Next

- **SSE** for push (Synaplan → extension) while keeping polling fallback.

### Later

- WebSocket for real-time streams (optional).

## Storage Model (plugin_data-first)

Use `plugin_data` with:

- `plugin_name='brogent'`
- `data_type`:
  - `device`
  - `task`
  - `run`
  - `sitepack` (metadata only; code stays in extension repo)

Suggested keys:

- device: `device:{uuid}`
- task: `task:{uuid}`
- run: `run:{uuid}`

Each `data` JSON includes:

- `created_at`, `updated_at`
- `enabled`
- `scopes[]`
- `site` and `domainPatterns[]`

## Boundaries

### What stays in Synaplan

- Authentication and authorization
- Inbound channel intake
- Logging & audit trail
- Task definitions and versioning

### What stays in the extension

- DOM-level execution details
- Selectors and per-site quirks
- “Is page ready” heuristics

### Shared

- Task DSL schema
- Event protocol schema

## Failure & Recovery

- Idempotent events with `event_id`.
- Lease TTL. Runs return to queue if device vanishes.
- Deterministic retries for transient errors.
- Step checkpoints for debugging.

## Context windows (coding guide)

### Window A: Pairing

- Read: `02-PROTOCOL.md`
- Implement: pairing code, claim, device storage
- Test: valid, expired, reused code

### Window B: Run lifecycle

- Read: `02-PROTOCOL.md`, `03-TASK-DSL.md`
- Implement: queue, claim, heartbeat, cancel
- Test: claim lease expiry and retry

### Window C: Approvals

- Read: `06-UX-FLOWS.md`
- Implement: approval request and decision
- Test: approve, reject, timeout


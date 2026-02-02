# BroGent — Protocol (Synaplan ↔ Browser Extension)

## Principles

- Versioned. Every request includes `protocolVersion`.
- Typed. Use stable enums.
- Idempotent. `eventId` is a UUID.
- Chunked. Big artifacts upload separately.
- Secure. Per-device token, scoped and revocable.

## Authentication

Extension uses:

- `X-API-Key: <device_token>` (consistent with Synaplan patterns)
- Optional: `X-Device-Id: <uuid>`

Server validates:

- Token active.
- Token maps to user id.
- Token has required scope.

## Endpoint Sketch (plugin namespace)

Base: `/api/v1/user/{userId}/plugins/brogent`

### Pairing

`POST /pair/code`
- Creates a short-lived pairing code for the UI.

Response:
```json
{
  "protocolVersion": 1,
  "pairingCode": "ABCD-EFGH",
  "expiresAt": "2026-02-01T18:00:00Z"
}
```

`POST /pair/claim`
Request:
```json
{
  "protocolVersion": 1,
  "pairingCode": "ABCD-EFGH",
  "device": {
    "name": "Chrome on MacBook",
    "platform": "macOS",
    "browser": "chrome",
    "extensionVersion": "0.1.0"
  }
}
```

Response:
```json
{
  "protocolVersion": 1,
  "deviceId": "dev_01H...",
  "deviceToken": "sk_dev_....",
  "scopes": ["runs:claim", "runs:events", "artifacts:upload"],
  "polling": { "intervalMs": 2000, "jitterMs": 500 }
}
```

### Runs (queue/claim)

`GET /runs/claim?deviceId=dev_...`

Response (no work):
```json
{ "protocolVersion": 1, "run": null }
```

Response (run available):
```json
{
  "protocolVersion": 1,
  "run": {
    "runId": "run_01H...",
    "taskId": "task_01H...",
    "taskVersion": 3,
    "site": { "key": "whatsapp_web", "domain": "web.whatsapp.com" },
    "inputs": { "to": "+491234", "message": "Hello" },
    "steps": [ /* see 03-TASK-DSL.md */ ],
    "lease": { "leaseId": "lease_01H...", "expiresAt": "..." }
  }
}
```

`POST /runs/{runId}/heartbeat`
```json
{ "protocolVersion": 1, "leaseId": "lease_01H..." }
```

`POST /runs/{runId}/cancel`
```json
{ "protocolVersion": 1, "reason": "user_request" }
```

### Run events (progress/results)

`POST /runs/{runId}/events`
Request:
```json
{
  "protocolVersion": 1,
  "leaseId": "lease_01H...",
  "events": [
    {
      "eventId": "evt_01H...",
      "type": "run_started",
      "ts": "2026-02-01T16:40:00Z",
      "data": { "url": "https://web.whatsapp.com/" }
    },
    {
      "eventId": "evt_01H...",
      "type": "step_completed",
      "ts": "2026-02-01T16:40:10Z",
      "data": { "stepId": "s3", "durationMs": 802, "summary": "Clicked compose" }
    }
  ]
}
```

Response:
```json
{ "protocolVersion": 1, "accepted": true }
```

### Approvals

`POST /runs/{runId}/request-approval`

Extension asks Synaplan to request user approval:
```json
{
  "protocolVersion": 1,
  "leaseId": "lease_01H...",
  "approval": {
    "approvalId": "appr_01H...",
    "kind": "send_message",
    "summary": "Send WhatsApp message to +49…: “Hello …”",
    "risk": "high"
  }
}
```

`GET /runs/{runId}/approval?approvalId=appr_...`

Response:
```json
{
  "protocolVersion": 1,
  "status": "pending|approved|rejected",
  "decision": { "by": "user", "ts": "...", "note": "ok" }
}
```

## Event Types (initial)

- `run_started`
- `run_finished`
- `run_failed`
- `step_started`
- `step_completed`
- `step_failed`
- `log` (debug/info/warn/error; redacted)
- `artifact_created` (screenshot, html_snippet)
- `approval_required`
- `waiting_for_user`

## Artifact Upload (MVP)

MVP can inline small artifacts with size caps:

- screenshot: base64 JPEG (capped)
- html snippet: truncated (e.g. 50KB)

Later: presigned upload:

1) `POST /artifacts/upload-url` → presigned URL
2) extension uploads directly
3) `POST /artifacts/complete`

## Security note

- High-risk tasks are **system signed**.
- Extension verifies the signature before execution.
- Unsigned high-risk steps are blocked.

## Context windows (coding guide)

### Window A: Pairing endpoints

- Implement `POST /pair/code` and `POST /pair/claim`.
- Store `deviceId`, public key, and scopes.
- Test: expired code, reused code.

### Window B: Run claim and events

- Implement `GET /runs/claim` and `POST /runs/{runId}/events`.
- Enforce lease and scope checks.
- Test: duplicate `eventId`, expired lease.

### Window C: Approvals

- Implement `POST /request-approval` and approval status reads.
- Test: approve, reject, timeout.


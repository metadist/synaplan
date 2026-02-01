# BroGent — Security & Privacy

## Threat Model (high-level)

BroGent touches sensitive surfaces:

- private messages (WhatsApp)
- personal documents (Office/Dropbox)
- accounts already logged into the browser

Therefore, safety must be explicit and auditable.

## Principles

- No credential collection. Use existing sessions only.
- Least privilege. Scope tokens per site and capability.
- Human-in-loop for risky actions.
- Data minimization. Send only what is needed.
- Audit logs with redaction.
- Revocable devices and tokens.

## Pairing & Device Tokens

### Pairing code

- short-lived
- one-time use
- ties to a Synaplan user

### Device token

- stored in extension storage
- used via `X-API-Key`
- revoked or rotated
- scoped: `runs:claim`, `runs:events`, site scopes

## Approval Gates

Actions that always require approval at MVP:

- send message/email
- upload/share files
- delete files/folders
- external navigation to unknown domains

Approval includes:

- `summary`
- `risk` level
- `payloadPreview` (redacted)

## PII Handling

Default redaction rules:

- mask phone numbers
- mask emails
- truncate message bodies
- cap and scrub DOM snippets

Opt-in debug mode for advanced users:

- more detailed logs
- explicit warnings
- time-limited

## Storage in Synaplan

Store:

- run metadata + status
- step timing + summaries
- optional artifacts with strict retention

Retention defaults:

- events: 30 days
- screenshots/snippets: 7 days (configurable)

## Site Safety & Bot Detection

Many sites detect automation. We must:

- avoid rapid actions
- allow manual assist
- fail safely and avoid hammering

## Compliance Notes (to refine)

For each site pack, document:

- allowed automation patterns
- constraints and ToS notes
- recommended confirm gates

## Context windows (coding guide)

### Window A: Pairing security

- Read: `02-PROTOCOL.md`
- Implement: keypair, public key storage, signature check
- Test: invalid signature, revoked token

### Window B: Redaction

- Implement: redaction in event pipeline
- Test: phone and email masking


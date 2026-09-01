# Synaplan Desktop (agent client)

> **Status: server side only.** The Synaplan Desktop **client is not released
> yet.** This document describes the *server* half — the pairing, scoped keys,
> job queue, and check-in contract that ship in the main Synaplan app (Phase A).
> The download link and install walkthrough are added in Phase B, when the
> client exists. Until then everything below is **off by default** and invisible
> on every install (feature flag `DESKTOP_AGENT.ENABLED`, see below).

## What it is

Synaplan Desktop is a small, separate desktop application (Windows, macOS,
Linux) that a user pairs with their Synaplan workspace. It lets Synaplan run
**Agent Skills on the user's own computer** — for example "make a PowerPoint
from these notes" using a local LibreOffice — and post the result back into the
chat.

It works by **pull, not push**: the network path "Synaplan calls your laptop"
is a dead end (NAT, and Synaplan's own SSRF guard). Instead the computer polls:

```
check-in  →  { jobs, next_call_at }  →  run the skill locally  →  report  →  sleep
```

### What it is NOT

- **Not the web app in a wrapper.** It runs local skills; it is not an Electron
  shell around `web.synaplan.com`.
- **Not "Claude Code" / a general coding agent.** There is no server-supplied
  shell command, ever. The computer only runs *named, user-installed skills*
  under path confinement. The single most important contract rule is that a job
  carries **only** `{skill, prompt, fileIds}` — never a `command`, `script`, or
  `argv`. See [The job contract](#the-job-contract).
- **Not a replacement** for the Messages gateway, Synamail, or the widget. It is
  purely additive: new API routes and two new MCP tools, all flag-gated.

## The feature flag

Everything desktop-related is gated on the `BCONFIG` flag
`DESKTOP_AGENT.ENABLED` (group `DESKTOP_AGENT`, setting `ENABLED`), resolved
per-user → global → **false**:

| State | Effect |
| ----- | ------ |
| Off (default) | Every `/api/v1/desktop/*` route answers **404**, the two MCP tools are **absent** from `tools/list`, the reaper command is a no-op, and no Desktop UI appears. The feature is completely invisible. |
| On (global) | The routes and MCP tools appear for every user. |
| On (per-user, `BOWNERID = <id>`) | Only that user sees the feature; a per-user value beats the global one. |

The seeder inserts the flag as `0` if missing and never overwrites an existing
value (`App\Seed\DesktopAgentConfigSeeder`). To turn it on for one user in dev:

```sql
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (0, 'DESKTOP_AGENT', 'ENABLED', '1')
ON DUPLICATE KEY UPDATE BVALUE = '1';
```

The runtime-config endpoint exposes the resolved boolean as
`features.desktopAgentEnabled` so the frontend can hide the UI when it is off.

## API keys and scopes

Historically any `sk_*` key had **full** account access. Desktop pairing does
**not**: it mints a *restricted* key limited to exactly four scopes.

| Scope | Grants |
| ----- | ------ |
| `desktop:messages` | `/v1/*` (chat/messages, models, token count) |
| `desktop:mcp` | `/mcp` (the two agent tools + the base MCP tools) |
| `desktop:files` | `/api/v1/files*` (upload the result artifact, list/download what the owner already may) |
| `desktop:jobs` | `/api/v1/desktop/*` (check-in / report, job status) |

A restricted key **cannot** reach admin, user management, webhooks, or anything
else — so a stolen laptop is a *revoke*, not an account takeover. Enforcement is
central (`App\Security\ApiKeyScopeSubscriber`); the vocabulary and prefix map
live in `App\Security\ApiKeyScope`.

The **Outlook add-in (Synamail)** is the other integration that mints restricted
keys (`messages:*`, `chats:*`, `files:*`, `rag:*` — issued by its connect flow
since before enforcement existed). The map covers its surface too:

| Scope | Grants |
| ----- | ------ |
| `messages:*` | `/api/v1/messages*`, `/api/v1/tts*`, `/api/v1/config/models*`, `/api/v1/user/{id}/plugins/*` |
| `chats:*` | `/api/v1/chats*` |
| `files:*` | `/api/v1/files*` (same surface as `desktop:files`) |
| `rag:*` | `/api/v1/rag*` |

Two self-service allowances apply to **every** valid key regardless of scopes:
`GET /api/v1/auth/me` (identity introspection, needed for ping/health checks)
and `DELETE /api/v1/apikeys/{ownId}` (a key may always revoke *itself* — a
leaked key can only destroy itself, never the owner's other keys).

**Existing keys are unaffected.** See
[scoped vs. legacy keys](#scoped-vs-legacy-keys-grandfathering) below.

### Scoped vs. legacy keys (grandfathering)

Adding scopes is a **security fix**, and it deliberately does not narrow any key
you already created. A key is treated as **full access** (exactly as before)
when its scope list is:

- **empty** — every key created before scopes existed, and every key created in
  the UI without picking scopes; or
- **only legacy webhook scopes** (`webhooks:email`, `webhooks:whatsapp`,
  `webhooks:*`); or
- an explicit **`*`**.

A key is **restricted** only when it opts into a non-empty, non-legacy scope
list without `*` — which today happens via desktop pairing and the Outlook
add-in connect flow. So nothing that worked yesterday stops working: your
existing OpenAI-/Anthropic-compatible keys, webhook keys, and integrations keep
full access; freshly paired desktop keys are limited to the four `desktop:*`
scopes, and add-in keys to the four add-in area scopes, above. The logic is one
pure class, `App\Security\ApiKeyScope::isRestricted()`.

## Pairing

Session-authenticated web endpoints, plus the one public exchange route:

| Method + path | Auth | Purpose |
| ------------- | ---- | ------- |
| `POST /api/v1/desktop/pairing-codes` | session | Mint a one-time 8-char code (10-min TTL, rate-limited). |
| `POST /api/v1/desktop/pair` | **public** | Exchange a code for a scoped key + a new device row. The key is shown **once**. |
| `GET /api/v1/desktop/devices` | session | List paired computers (name, status, last-seen, key prefix). |
| `DELETE /api/v1/desktop/devices/{id}` | session | Revoke a computer — deactivates its API key (401 on its next call). |

`POST /pair` is the only unauthenticated route (a fresh client has no session
yet); it is rate-limited per IP and returns the same "invalid or expired" error
for unknown and expired codes (no user enumeration). Pairing codes and keys are
never logged at info level.

Flow:

1. User opens **Channels → Desktop** in the web app and clicks *Pair a
   computer* → server mints a code.
2. User types the code into Synaplan Desktop.
3. The client calls `POST /pair` → gets `{ deviceId, key, apiBaseUrl }` and
   stores the key in the OS secret store.
4. The client polls with the key from then on.

## The job contract

Frozen at **`protocol: 1`** (Sprint A3 / DS18). Every enum is closed; the wire
shapes are committed as fixtures (see [Frozen fixtures](#frozen-fixtures)).
Changing any of it is a `protocol: 2` decision **with a migration**, not a
convenience edit.

### Enqueue (web → server)

`POST /api/v1/desktop/jobs` (session user):

```json
{
  "deviceId": 1,
  "type": "skill.run",
  "input": { "skill": "pptx", "prompt": "Make 3 slides about Q3", "fileIds": [] },
  "chatId": 99
}
```

- Flag on; the device is owned by the user and `active`.
- `type` ∈ `{ skill.run }` (the only type in v1 — no `shell.exec`, ever).
- `input.skill` matches `^[a-z0-9-]{1,64}$`; prompt capped at 8k chars.
- The server does **not** verify the computer has the skill (it cannot). An
  uninstalled skill fails honestly on the device.

Poll job status with `GET /api/v1/desktop/jobs/{id}` (and list recent jobs with
`GET /api/v1/desktop/jobs`) — this drives the web "waiting / failed" card.

### Check-in and report (device ↔ server, over MCP)

Two MCP tools, added to `tools/list` only when the flag is on **and** the key
is a paired desktop key (they are a *superset* — the base tools stay), requiring
the `desktop:jobs` scope:

- **`agent_checkin`** — leases at most one job for this computer and returns
  `{ protocol: 1, jobs: [...], next_call_at }`. A device speaking an unknown
  protocol gets an empty job list and a far `next_call_at` — never a guess.
- **`agent_report_result`** — reports the outcome of a leased job by its
  `leaseToken`. A refused skill is a normal `failed` with an `errorCode`, not a
  transport error.

Leasing is atomic (pessimistic row lock), so two check-ins can never lease the
same job. A lease that expires is requeued by `app:desktop:reap-jobs` until the
attempt budget is spent, then the job fails with `timeout` — so the web card
shows an honest failed state instead of a forever spinner.

### The closed enums

| Field | Values |
| ----- | ------ |
| `type` | `skill.run` |
| `status` | `queued`, `leased`, `succeeded`, `failed`, `cancelled` |
| `errorCode` | `unknown_skill`, `unknown_type`, `skill_disabled`, `timeout`, `local_error` |

### The one rule that makes RCE structurally impossible

A job's device-facing `input` is **only** `{skill, prompt, fileIds}`. The server
drops every other key before the payload is handed out
(`DesktopJobContract::buildDevicePayload()`), and the client **must ignore** any
unknown key. There is no field through which a shell string could reach the
computer, so a future server bug cannot become remote code execution.

## Frozen fixtures

The exact `protocol: 1` wire shapes are committed under
[`_devextras/testing/desktop/fixtures/`](../_devextras/testing/desktop/fixtures/)
(check-in request/response, one `skill.run` job, a success report, an
`unknown_skill` failure report, an enqueue request). Phase B's client vendors
these byte-for-byte to build its unit tests without a live server.

They are asserted against the live server contract by
`backend/tests/Unit/Service/Desktop/DesktopContractFixturesTest.php`, so a
server change that breaks the frozen contract fails the gate here rather than
breaking a shipped client (invariant C9).

## Testing it without a client

Because the real client does not exist yet, two shell harnesses under
[`_devextras/testing/desktop/`](../_devextras/testing/desktop/) stand in for it.
They run against the local Docker stack (`curl` + `jq`), auto-enable the flag,
and are **not** part of the PHPUnit gate — the equivalent assertions also exist
as PHPUnit tests (`DesktopControllerTest`, `DesktopMcpCheckinTest`).

```bash
cd _devextras/testing/desktop

# Pair a fake computer: login → mint code → exchange for a scoped key.
./pair.sh

# Full loop + every refusal path: check-in, lease, report, and the safety
# cases (hostile input.command stripped, unknown skill, stale lease token,
# oversized result, cross-device isolation, flag-off).
./fake-device.sh
```

See [`_devextras/testing/desktop/fixtures/README.md`](../_devextras/testing/desktop/fixtures/README.md)
for the frozen-contract details.

## Related

- Anthropic-compatible Messages gateway: [ANTHROPIC_COMPATIBLE_API.md](./ANTHROPIC_COMPATIBLE_API.md)
- OpenAI-compatible API: [OPENAI_COMPATIBLE_API.md](./OPENAI_COMPATIBLE_API.md)
- Plan of record: `_devextras/planning/20260829-desktop-agent-client/`

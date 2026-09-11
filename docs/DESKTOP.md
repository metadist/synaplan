# Synaplan Desktop (agent client)

> **Status.** The server half (pairing, scoped keys, job queue, check-in
> contract) ships in this app behind `DESKTOP_AGENT.ENABLED` (on by default
> since the Features tab landed; see [Feature flags](FEATURE_FLAGS.md)). The
> desktop client for **macOS, Windows and Linux** is a **public beta** in
> [synaplan-desktop](https://github.com/metadist/synaplan-desktop): build it
> from source or take a beta build from that repository's
> [Releases](https://github.com/metadist/synaplan-desktop/releases) page once
> one is published. Signed, notarized installers come later. The job contract
> stays frozen at `protocol: 1`. The web app links to the repository from
> **Channels → Desktop**.

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
environment pin → per-user → global → code fallback **false**:

| State | Effect |
| ----- | ------ |
| Off | Every `/api/v1/desktop/*` route answers **404**, the two MCP tools are **absent** from `tools/list`, the reaper command is a no-op, and no Desktop UI appears. The feature is completely invisible. |
| On (global, default) | The routes and MCP tools appear for every user. |
| On (per-user, `BOWNERID = <id>`) | Only that user sees the feature; a per-user value beats the global one. |

The seeder inserts the global flag as `1` if missing and never overwrites an
existing value (`App\Seed\DesktopAgentConfigSeeder`); a migration turns the row
on for installs that predate the default. Operators switch it under **Operate →
System configuration → Features → Platforms & desktop**
(`FEATURE_DESKTOP_AGENT_ENABLED`), or pin it for an automated deployment with
`FEATURE_DESKTOP_AGENT_ENABLED=false`. To turn it off for everyone by SQL:

```sql
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (0, 'DESKTOP_AGENT', 'ENABLED', '0')
ON DUPLICATE KEY UPDATE BVALUE = '0';
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

1. User opens **Channels → Desktop** in the web app and clicks *Pair this
   computer* → server mints a code. The address shown is the API origin
   (`http://localhost:8000` in local Vite — or the same host on `:8000` when
   the UI is opened via a LAN IP — not `:5173` or Keycloak `:8080`).
2. User types the address and code into Synaplan Desktop (or pastes an API
   key on the desktop **API key** tab).
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

## Desktop project companion (machine API)

The desktop client also keeps **local-first projects** (notes, chats, and
model picks live on that computer). There is no server Project table. The
paired key already has everything it needs — **do not** add `desktop:agents`
and **do not** change `protocol: 1`.

These routes are for Synaplan Desktop. They are not a public Assistants CRUD.

| Method | Path | Purpose |
| ------ | ---- | ------- |
| `GET` | `/v1/models/catalog` | Selectable models in eight groups (`CHAT`, `SOUND2TEXT`, `TEXT2SOUND`, `PIC2TEXT`, `TEXT2PIC`, `TEXT2VID`, `VECTORIZE`, `ANALYZE`). `id` is the catalog key `service:providerId:tag`. Unavailable rows stay listed with `available` / `unavailableReason`. `defaults` is the workspace DEFAULTMODEL key per group; `VECTORIZE` is the platform index model (Ollama `bge-m3` unless the operator changed it). Desktop shows that key on the Embed slot (locked) and never sends `vectorize_model` — `DESKTOP:` folders always index with VECTORIZE so search and index stay in one space. |
| `GET` | `/v1/assistants` | Reader `publicView` list, including `models.chat` / `vision` / `vectorize`. `AGENTS.ENABLED` off → **404** with code `assistants_disabled` (not an empty list). |
| `GET` | `/v1/assistants/{id}` | One runnable Assistant. Same 404 when the flag is off. |
| `POST` | `/v1/messages` | Existing Anthropic SSE. Optional headers `x-synaplan-agent-id` and `x-synaplan-rag-group-key` (`DESKTOP:{projectId}`). The JSON `model` (provider id or catalog key) **always wins** over the Assistant recipe. Any catalog **chat** model on Anthropic, Gemini, or an OpenAI-compatible host (Groq, Mistral, Ollama, …) is translated; `MODEL_ALIASES` is not required. |
| `POST` | `/v1/media/generate` | Image or video from the project IMAGE / VIDEO catalog key (`model` required — no account-default fallback). Returns a stored `/api/v1/files/uploads/…` URL. Desktop saves the bytes in the project `out/` folder and uploads a searchable copy into `DESKTOP:{projectId}`. |
| `POST` | `/v1/audio/speech` | Speech from the project SPEAK catalog key (`model` required). Same save-local-and-add-to-project path as generated images. |
| `POST` | `/api/v1/files/upload` and `/api/v1/files/{id}/process` | Existing `desktop:files` surface plus optional `vectorize_model` / `analyze_model` catalog keys. Unknown or wrong-capability keys are **400**. Omitted `vectorize_model` uses `DEFAULTMODEL.VECTORIZE` (the same model chat search uses). A `vectorize_model` on a `DESKTOP:` folder is **ignored** so an older Desktop build cannot index into a different space. `process_level=vectorize` only indexes — `analyze_model` is fail-closed validated so a bad DOCS binding is rejected, but it is **not** applied because that path does not run document analysis. |

`GET /api/v1/config/models` stays `messages:*`. `GET /api/v1/agents*` stays
`agents:*`. A paired key must not reach either.

Dictation stays on the existing `/v1/audio/transcriptions*` routes (also
`desktop:messages`). The catalog `SOUND2TEXT` group is the source of truth
for the project's dictation model once the client has it.

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

## Queue walkthrough (web → computer → chat)

With the flag on and a paired unsigned desktop client running:

1. In the web app, open a chat and queue a `skill.run` job for that computer
   (Channels → Desktop, or `POST /api/v1/desktop/jobs` with
   `{ skill, prompt, fileIds }` only).
2. The computer checks in over MCP (`agent_checkin`), honours top-level
   `next_call_at`, and runs the skill with the same local tool policy as
   interactive chat.
3. A produced file is uploaded with `process_level=store` and reported as
   `result.fileIds`. The web chat can then show that file.
4. A refusal path is honest: unknown skill → `unknown_skill`; skill off or
   not allowed to run unattended → `skill_disabled`; missing runtime →
   `local_error`. The web card shows failed, not a forever spinner.

The computer will not start the poll loop while the API key is stored in a
plaintext file.

## Testing the server without the GUI

Two shell harnesses under
[`_devextras/testing/desktop/`](../_devextras/testing/desktop/) stand in for a
device. They run against the local Docker stack (`curl` + `jq`), auto-enable
the flag, and are **not** part of the PHPUnit gate — the equivalent
assertions also exist as PHPUnit tests (`DesktopControllerTest`,
`DesktopMcpCheckinTest`).

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

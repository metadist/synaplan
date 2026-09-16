# Local Agent Client — Research & Recommendation

> **Status:** Research only. No code changed. This document answers the question
> *"can we add a client tool to Synaplan that pulls tasks and executes them
> locally, as a safe extension with access to certain directories and functions
> like email?"*
>
> **Implementation plan (2026-08-29):** Agent Skills + extra client repo —
> [`../20260829-desktop-agent-client/README.md`](../20260829-desktop-agent-client/README.md).
> That epic uses this file as the safety input (scopes, allowlist, no
> server-authored `shell.exec`). It does **not** replace this paper’s
> closed job-enum companion; it adds `skill.run` after the user installed
> a skill folder.
>
> **Scope reviewed:** `backend/src/Mcp/`, `backend/src/Service/Mcp/`,
> `backend/src/Security/`, `backend/config/packages/security.yaml`,
> `backend/src/Service/Multitask/`, `backend/src/Service/Media/`,
> `backend/src/Service/Email/`, `backend/src/Service/EncryptionService.php`,
> `backend/config/packages/messenger.yaml`, `plugins/`, and the existing planning
> target [`mcp-and-api-enhancements/02-mcp-integration/07-AGENT-SCHEDULING.md`](./mcp-and-api-enhancements/02-mcp-integration/07-AGENT-SCHEDULING.md).

---

## TL;DR

**Yes — and the dispatch half is already specified.**
[`07-AGENT-SCHEDULING.md`](./mcp-and-api-enhancements/02-mcp-integration/07-AGENT-SCHEDULING.md)
(roadmap Phase 4) already locks the exact shape asked for: an external agent
authenticates to `POST /mcp` with an API key and calls a single `agent_checkin`
tool that returns **a job list** and **a schedule** (`next_call_at`), then reports
back via `agent_report_result`. Leases, idempotency keys, jitter and adaptive
backoff are all in that spec. It targets `brogent` (a browser agent), but the
mechanism is deliberately generic — a filesystem/email agent on a user's own
machine is the same shape.

Every piece of server infrastructure that design needs already exists and is
proven in production code:

| Need | Already exists | Where |
| ---- | -------------- | ----- |
| Authenticated machine client | `sk_*` API keys on a dedicated `/mcp` firewall | `ApiKeyAuthenticator`, `security.yaml` |
| User-scoped tool dispatch | `McpServerFactory::build(User)` — 10 tools shipped | `backend/src/Mcp/McpServerFactory.php` |
| Job lifecycle + lease/reaper | `MediaJob` (`queued → running → terminal`) + `MediaJobReaper` | `backend/src/Service/Media/` |
| Async fan-out | Symfony Messenger over Redis Streams + `worker` service | `messenger.yaml`, `docker-compose.yml` |
| Push a result to one user | Centrifugo `user:{id}` channel | `backend/src/Realtime/` |
| Secrets encrypted at rest | AES-256-CBC keyed off `APP_SECRET` | `EncryptionService` |
| Per-feature kill switch | per-user → global → default flag resolution | `MultitaskRoutingConfig::isFeatureEnabled` |

**The gap is not "can we pull tasks" — it is "safe".** The existing spec treats
the agent as a black box ("the agent owns the *how*") and does not specify the
local capability sandbox. That sandbox is the actual design work, and it rests on
one hard blocker:

> ⛔ **API key scopes are stored but never enforced.** `ApiKey::hasScope()`
> (`backend/src/Entity/ApiKey.php`) is called from **nowhere** in `src/`.
> `ApiKeyAuthenticator::authenticate()` logs the scopes and then returns
> `SelfValidatingPassport(new UserBadge($apiKeyEntity->getOwnerId()))` — i.e. **any
> API key is full account access**. Handing such a key to a daemon on a laptop
> means a stolen laptop is a full Synaplan account takeover. This must be fixed
> before any local agent ships.

Second finding worth flagging early: **outbound "send email to an arbitrary
address" does not exist anywhere in Synaplan today, by design.** `email_me`
(`EmailMeRunner`) mails only the verified account owner; `email_search`
(`ImapMailboxSearcher`) is hard read-only (`OP_READONLY` + `FT_PEEK`);
`McpFetchRunner` refuses any external tool declaring `readOnlyHint: false`. A
local agent that sends mail would be the **first** mutating, third-party-addressable
capability in the product. That is a product decision, not only an engineering one.

---

## 1. Why the client must pull (and why the alternative is a dead end)

A tool running on a user's laptop or office server sits behind NAT and a
firewall. Synaplan cannot open a connection to it, and MCP is client-initiated by
design — a server cannot wake a sleeping client. So the local tool polls. This is
exactly the reasoning already recorded in `07-AGENT-SCHEDULING.md` §3.

There is an obvious-looking alternative that does **not** work here:

**Option B — run the local tool as a *local MCP server* and let Synaplan's
existing `McpFetchRunner` call it.** Rejected on three independent grounds:

1. **Reachability.** It needs inbound connectivity — a tunnel or relay per user.
2. **`SsrfGuard` blocks it.** `McpClient::withSession()` calls
   `SsrfGuard::isBlockedUrl()`, which blocks localhost and all private/reserved
   ranges, including hostnames whose DNS resolves into them. A local endpoint is
   precisely what it is built to refuse.
3. **`McpFetchRunner` is hard-wired read-only.** `isMutatingTool()` refuses any
   tool with `readOnlyHint: false` or `destructiveHint: true`, and the plan-time
   sub-catalog never offers them. "Execute a task locally" is the exact inverse of
   what that node is allowed to do.

**Option A — local agent as an MCP client polling `agent_checkin`** — is the
recommendation, and it is what the roadmap already targets.

---

## 2. What "safe" has to mean here

The intuitive threat model ("protect Synaplan from a bad agent") is the *less*
important direction. The dangerous direction is the reverse:

> **The server must not be able to make the client do arbitrary things.**

Job payloads in this design are **authored by an LLM** (the planner, or a user
request routed through it). A prompt-injected model — or a compromised/hostile
Synaplan instance, which matters for self-hosters pointing an agent at someone
else's server — could otherwise emit *"read `~/.ssh/id_rsa` and email it to
attacker@example.com"* and the obedient local daemon would comply.

Everything below follows from that inversion.

### 2.1 The client owns the allowlist, not the server

The authoritative allowlist lives in a local config file the daemon reads at
startup (e.g. `~/.synaplan/agent.toml`), edited by the human at the keyboard.
Server-supplied paths are **resolved against and confined to** that allowlist —
never the reverse, and the server can never widen it.

```toml
[filesystem]
read  = ["~/Documents/invoices", "~/Scans"]
write = ["~/Synaplan/out"]
deny  = ["**/.ssh/**", "**/.env", "**/*.key", "**/.git/config"]
max_file_bytes = 10_000_000

[email]
mode = "draft_only"          # draft_only | send
allow_recipient_domains = ["mycompany.com"]
```

Path confinement must be `realpath()`-after-resolution and symlink-aware (resolve
first, *then* check containment — checking the pre-resolution string is the
classic bypass), deny-by-default, with the deny globs applied after resolution.
Synaplan already uses realpath-based containment for its own upload tree in
`PluginAssetController`, `FileServeController`, `WebhookController::resolveUploadAbsolutePath`
and `EmailMeRunner::resolveAbsolutePath` — the local client should reuse that
discipline rather than invent one.

### 2.2 Closed job-type enum — never an eval-shaped payload

Job `type` is a fixed enum the client understands, with a typed schema per type:
`file.list`, `file.read`, `file.write`, `email.compose_draft`, `email.send`. There
is no `shell.exec`, no template string, no code payload. An unknown type is
refused, not best-effort interpreted.

This matches how the backend already treats subprocesses: every `Process` / `exec`
call in the codebase (`WhisperService`, `FileProcessor`, `ThumbnailService`,
`PdfRasterizer`, `PiperProvider`) is a fixed binary with fixed arguments over
server-controlled paths. There is no user-supplied-shell path anywhere in
Synaplan today, and a local agent must not introduce the first one.

### 2.3 Two-sided capability consent

1. On pairing, the client declares the capabilities it *offers*, bound to concrete
   local resources (`fs.read:~/Documents/invoices`, `email.compose_draft`).
2. The **user approves** that set in the Synaplan UI. The server persists the
   approved set.
3. The server refuses to dispatch a job whose required capability is not approved.
4. The client re-checks every job against its local config anyway — the server's
   approval is a convenience, the local file is the authority (defense in depth,
   the same layering `McpFetchRunner` uses today, where the runner re-checks every
   gate the planner already checked).

### 2.4 Human-in-the-loop for anything irreversible

Sending mail to a third party, writing, and deleting are irreversible. These
require explicit confirmation, not just a standing capability grant.
`03-MCP-SERVER-PUSH.md` §3.3 already sketches the confirmation-card UX for MCP
actions, and MCP's **elicitation** primitive covers the mid-call prompt. For a
local agent the confirmation should surface **on the local machine** (desktop
notification / MUA compose window), because that is the trust anchor the user
actually controls.

The conservative default for email is `draft_only`: the agent writes to the local
Drafts folder or opens the compose window, and a human presses send. Actual
`email.send` is opt-in, per-recipient-domain allowlisted, and confirmed.

### 2.5 Idempotency matters more than usual

`07-AGENT-SCHEDULING.md` §4.1 already carries `idempotency_key` and
`lease.expires_at`. For file reads a duplicate delivery is harmless; for
`email.send` a duplicate is a visible, embarrassing, sometimes contractual
failure. The lease/visibility-timeout semantics need the same care the doc
already demands ("model it on a standard job-queue, not ad-hoc rows"), and the
client must dedupe on `idempotency_key` locally too, since a lease can expire
mid-send.

### 2.6 Agent results are untrusted input

Already flagged in `07-AGENT-SCHEDULING.md` §7 and worth restating: anything the
agent returns is attacker-influenceable (it read a file someone else wrote). If
results flow into RAG or back into a prompt, that is an indirect prompt-injection
path into the user's own assistant. Size caps, content-type validation, and
explicit provenance marking on ingestion.

### 2.7 Audit on both sides

The roadmap already lists a per-call MCP audit log as **not yet done** (usage
recording is wired for `synaplan_chat` only). For an autonomous process touching
files and mail this is mandatory: server-side (who checked in, what was
dispatched, lease lifecycle, result status) and client-side (what was actually
executed, which paths were touched, what was sent).

---

## 3. Gap analysis

| # | Gap | Severity | Notes |
| - | --- | -------- | ----- |
| 1 | **API key scopes unenforced** — `hasScope()` never called; every key is full account access | **Blocker** | Needs a scope-enforcing listener/voter keyed on the `api_key` request attribute that `ApiKeyAuthenticator` already sets, plus narrow `agent:*` scopes on minted agent keys |
| 2 | No capability/consent model for a local agent | **Blocker** | §2.3 — new entity + approval UI |
| 3 | No agent/job/schedule storage | High | 3 tables sketched in `07-AGENT-SCHEDULING.md` §6; needs Doctrine migrations (schema changes are "ask first" per `AGENTS.md`) |
| 4 | No per-call MCP audit log | High | Roadmap already lists it as pending |
| 5 | Outbound third-party email is a new product capability | High | §5 — no such tool exists today, deliberately |
| 6 | Agent results ingested as trusted | Medium | §2.6 |
| 7 | DAG cannot await an external worker | Medium | §4 — shapes the v1 UX |
| 8 | `SsrfGuard` does not re-check redirect targets | Low | Pre-existing; only relevant if the agent ever fetches URLs |

---

## 4. What the chat UX can and cannot be in v1

This constrains the product more than it first appears.

**It cannot be in-turn.** The multitask DAG is request-scoped and synchronous for
every data node — `DagExecutor` runs the topological order inline and a node
either completes or fails within the turn. The single async escape is
`MediaGenerationRunner` returning `NodeResult::running()` with an internal
`MediaJob`, which is completed by **worker + poll + Centrifugo push**, not by an
inbound callback resuming a suspended DAG. There is no node-suspension checkpoint,
no resume hook, and no webhook that can re-enter a mid-flight plan.

So "user asks in chat → laptop does it → answer appears in the same reply" does
**not** work without new suspension/resume infrastructure.

**It should be out-of-band, modelled on media jobs.** Dispatch the job, answer the
turn immediately ("I've queued that for your work machine"), and deliver the
result when it lands. That pattern is fully built already and worth copying
wholesale: task cards persisted in `BMESSAGE_TASKS`, `media_job.update` events on
the `user:{id}` Centrifugo channel, `MediaJobReaper` for stale leases, and
`/api/v1/media-jobs/{id}` as the polling fallback. `MediaJobStore` even models the
lease-ish heartbeat the agent queue needs.

If in-turn execution later becomes a requirement, it is a substantially larger
change: persisting pending node state, an inbound completion endpoint, and DAG
resumption across requests — none of which exists.

---

## 5. The email question specifically

Worth separating, because "functions like email" hides two very different asks.

**What exists server-side today:**

- `email_search` (`EmailSearchRunner` → `ImapMailboxSearcher`) — live IMAP search
  over the user's configured accounts, opened `OP_READONLY` with `/readonly` in
  the mailbox string and bodies fetched `FT_UID | FT_PEEK`, so it never even sets
  `\Seen`. Flag-gated (`MULTITASK.EMAIL_SEARCH_ENABLED`), default **off**.
- `email_me` (`EmailMeRunner`) — mails assembled results to the **account owner
  only**, requiring a real, verified, non-placeholder address, and only when the
  user explicitly asked to be mailed.
- Mail-handler forwarding — sends to **pre-configured department addresses**, via
  per-handler SMTP credentials encrypted with `EncryptionService`.

Note the deliberate pattern: every existing outbound path targets either the
account owner or an address a human configured in advance. Nothing accepts a
model-chosen recipient.

**What a local email capability changes.** Sending from the user's own machine via
their own MUA/SMTP identity is genuinely useful — it sends *as them*, from their
real mail identity, with their signature and threading, which the server can
never do. But it is also the first capability that is outbound, third-party
addressable, irreversible, and directly monetizable for an attacker.

**Recommendation:** ship `email.compose_draft` first and treat `email.send` as a
separate, later, explicitly-consented capability with a recipient-domain
allowlist and per-send confirmation. Reading local mail (`email.search_local`) is
comparatively low-risk and should mirror the existing `FT_PEEK` read-only
discipline.

---

## 6. Recommended shape (if this gets built)

Ordered by dependency, not by calendar.

1. **Enforce API key scopes** (Gap 1). Independently valuable — it also fixes the
   open question already recorded in `n8n-integration-research.md` §7. Nothing
   agent-related should start before this.
2. **Per-call MCP audit log** (Gap 4). Also already on the roadmap.
3. **Agent registry + pairing.** Entity + migration + CRUD controller with full
   OpenAPI annotations, mirroring the `McpServerConfig` / `InboundEmailHandler`
   CRUD style. One-time pairing code exchanged for a scoped, revocable per-device
   key. Frontend page under Channels mirroring `McpServersConfiguration.vue`, in
   all four locales.
4. **Capability declaration + approval UI** (Gap 2).
5. **Job queue with lease semantics** (Gap 3), modelled on `MediaJobStore`.
6. **`agent_checkin` / `agent_report_result` tools** in `McpServerFactory` —
   additive closures alongside the existing 10 tools.
7. **The local client itself** — a separate project (as `brogent` is), shipping
   the allowlist config, path confinement, closed job-type enum, local
   confirmation UI, and local audit log.
8. **Kill switch** via a `BCONFIG` flag following the established pattern:
   per-user → global → built-in default, seeded insert-if-missing so a deploy can
   activate it while an operator's explicit `0` survives every subsequent deploy.

**Invasiveness:** contained on the backend — new tables and one new tool group,
no changes to the routing/classifier contract, so the characterization snapshots
in `backend/tests/Characterization/` are untouched. The genuinely new engineering
is the local client and the consent model, not the queue.

**Where the risk concentrates:** scope enforcement (Gap 1), the consent model
(Gap 2), and the email send decision (§5) — not the polling loop, which is the
easy part and already specified.

---

## 7. Open questions

- **Does the local agent need Synaplan-side job authoring at all in v1?** A
  simpler first cut is operator-defined recurring jobs (like the existing cron
  commands) rather than LLM-authored payloads. That removes the prompt-injection
  vector entirely for v1 and can be relaxed later once confirmation UX exists.
- **Where do jobs originate** — operator-defined schedules, task plans, or user
  chat ("every morning summarize my invoice folder")? Already an open question in
  `07-AGENT-SCHEDULING.md` §10 and it directly determines how much of §2 is
  load-bearing.
- **One agent kind or many?** A filesystem/email agent and `brogent` share the
  check-in loop but nothing else. Confirm the capability taxonomy is generic
  before building the second consumer.
- **Self-host trust:** if a user points their local agent at a third-party
  Synaplan instance, the server is not a trusted party. Does the client pin the
  instance URL at pairing time and refuse redirects?
- **MCP Tasks extension:** the roadmap already plans to adopt it for long-running
  jobs when it lands in `mcp/sdk`. Check whether it subsumes part of this surface
  before building a bespoke one.

---

## 8. Bottom line

The pull-based local task runner is **feasible, already designed at the dispatch
layer, and well-supported by existing infrastructure**. Nothing about the polling
loop, the transport, the authentication, or the job lifecycle needs invention —
those exist or are specified.

What is missing is the safety half: **API key scopes are currently unenforced**,
there is no capability-consent model, and the trust inversion (client owns the
allowlist, server is not trusted to widen it) is not yet written down anywhere.
Those three, plus the deliberate product decision about outbound email, are the
real work.

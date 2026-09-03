# 07 — Agent Scheduling & Job Dispatch (autonomous agents)

> **Status: PLANNING TARGET — not implemented.** This document captures the design
> target for letting autonomous external agents pull scheduled jobs from the
> Synaplan MCP server. It is intentionally ahead of the build: we keep following
> the [2026 roadmap](./00-ROADMAP-2026.md) (tools → resources → prompts → …) and
> only start this once the read/write tool foundation is stable. Nothing here is
> wired up yet.

## 1. Motivation

Synaplan will gain a family of **autonomous agents** that do work *on behalf of a
user* on a recurring basis. The first is **`brogent`** — a **browser agent** that
navigates and operates web pages — but the mechanism must be generic (a crawler
agent, an email agent, a report agent, …).

These agents are **MCP clients**: they already authenticate to the Synaplan MCP
server with an API key and call tools. What's new is a **scheduling + dispatch**
loop:

> An agent should be able to ask *"what should I do, and when should I come
> back?"* — and the **server** answers with **a job description** and **a schedule
> update**. The agent stays dumb about *what* and *when*; it only owns *how*.

This inverts the usual cron model: instead of each agent hard-coding its cadence,
**the Synaplan server is the single source of truth** for both the work and the
timing. An operator can change "every hour" → "daily at 15:00" in Synaplan and
every agent picks it up on its next check-in — no agent redeploy.

## 2. Roles & boundary

| Owns | Synaplan MCP server | The agent (e.g. brogent) |
| :--- | :--- | :--- |
| **What** to do | ✅ job description (typed payload) | — |
| **When** to call back | ✅ schedule / next-call time | — |
| **How** to do it | — | ✅ runtime, browser engine, credentials for target sites, concurrency, proxies |
| Result handling | ✅ ingest results (chat/files/RAG) | ✅ produce results, report back |

"The rest is agent config" = everything in the right-hand column. Synaplan never
ships browser binaries, site credentials, or runtime config; it ships **jobs** and
**timing**.

## 3. Why poll-based + server-authoritative cadence

MCP is **client-initiated**: a server cannot wake a sleeping client at an arbitrary
future time. So the agent must **poll** (check in). To avoid hard-coded cadences,
every check-in response carries the **next** check-in instruction, so:

- The agent loop is trivial: `check-in → do jobs → sleep until next_call_at → repeat`.
- The server can change cadence at any time (adaptive backoff, operator edits,
  quiet hours) and the agent obeys on the next cycle.
- A shared store + jitter keeps a fleet of agents from stampeding (thundering herd).

This is a **pull queue with server-controlled cadence**, modelled as MCP tools that
return structured JSON. (When the MCP **Tasks** extension matures in the SDK we can
layer it on for long-running individual jobs, but the schedule loop stays poll-based.)

## 4. MCP tool surface (proposed)

All tools are user-scoped via the existing `mcp` firewall (API key per agent).
Names are namespaced under `agent_` to keep them distinct from the knowledge tools.

| Tool | Direction | Purpose |
| :--- | :--- | :--- |
| `agent_checkin` | agent → server | **The core tool.** "Give me my call schedule + work." Returns `jobs` + `schedule`. |
| `agent_report_result` | agent → server | Submit the outcome of a job (success / partial / failure + payload). |
| `agent_register` *(optional)* | agent → server | First-run handshake: declare agent kind + capabilities, get an `agent_id`. May instead be pre-provisioned in the Synaplan UI. |

### 4.1 `agent_checkin`

**Input (arguments):**

```json
{
  "agent_kind": "brogent",
  "capabilities": ["browser.navigate", "browser.scrape", "browser.screenshot"],
  "max_jobs": 1,
  "last_schedule_token": "sch_01HF…"
}
```

**Output — the two JSONs the agent needs:**

```json
{
  "agent": { "id": "agt_01HF…", "kind": "brogent" },

  "jobs": [
    {
      "job_id": "job_01HF…",
      "type": "browser.scrape",
      "priority": 5,
      "input": {
        "url": "https://example.com/pricing",
        "instructions": "Extract the list of plans and monthly prices.",
        "output_schema": { "type": "array", "items": { "type": "object" } }
      },
      "lease": { "expires_at": "2026-06-17T15:10:00Z", "token": "lease_01HF…" },
      "deadline_at": "2026-06-17T15:30:00Z",
      "idempotency_key": "scrape:example.com/pricing:2026-06-17",
      "max_attempts": 3,
      "attempt": 1
    }
  ],

  "schedule": {
    "next_call_at": "2026-06-17T16:00:00Z",
    "recurrence": { "type": "interval", "every": "PT1H", "jitter": "PT30S" },
    "schedule_token": "sch_01HG…",
    "reason": "active"
  }
}
```

Key points:
- **`jobs`** may be empty (`[]`) — a check-in with no work still returns a `schedule`.
- **`schedule.next_call_at`** is an absolute ISO-8601 instant the **server computed**,
  so the agent never has to interpret recurrence rules. `recurrence` is included for
  transparency/telemetry only.
- **`lease`** prevents two agents grabbing the same job (visibility timeout). The
  agent must finish or extend the lease before `expires_at`, or the job is requeued.
- **`schedule_token`** lets the next check-in detect a schedule change.

### 4.2 `agent_report_result`

```json
{
  "job_id": "job_01HF…",
  "lease_token": "lease_01HF…",
  "status": "success",
  "result": { "plans": [ { "name": "Pro", "price": "€19/mo" } ] },
  "artifacts": [ { "type": "screenshot", "mime": "image/png", "data_ref": "…" } ],
  "error": null,
  "metrics": { "duration_ms": 8421 }
}
```

Result handling on the server side feeds Synaplan's existing pipelines (store as a
message/file, push into RAG, notify the user, or hand to a task plan).

## 5. Schedule model

Supported recurrence shapes (server computes `next_call_at` from these, timezone-aware):

| Cadence | `recurrence` JSON |
| :--- | :--- |
| Every minute / N minutes | `{ "type": "interval", "every": "PT1M" }` |
| Every hour | `{ "type": "interval", "every": "PT1H" }` |
| Daily at a time | `{ "type": "daily", "at": "15:00", "tz": "Europe/Berlin" }` |
| Weekly on a day | `{ "type": "weekly", "day": "mon", "at": "09:00", "tz": "Europe/Berlin" }` |
| Arbitrary (later) | `{ "type": "cron", "expr": "0 */2 * * *", "tz": "…" }` |

Cross-cutting:
- **Jitter** (`jitter: "PT30S"`) spreads a fleet's calls.
- **Adaptive backoff**: when `jobs` is empty repeatedly, the server may stretch the
  interval (`reason: "idle_backoff"`); when work is queued it can shorten it
  (`reason: "work_pending"`), down to a per-agent floor.
- **Quiet hours / pause**: server may return `schedule.paused_until` or a far-future
  `next_call_at` to silence an agent without the agent knowing why.
- Durations use **ISO-8601** (`PT1M`, `PT1H`) to avoid unit ambiguity.

## 6. Storage (proposed — via Doctrine migrations)

> Schema changes go through Doctrine migrations per `docs/MIGRATIONS.md`; catalog
> defaults via idempotent seeders. **Ask before adding tables.**

| Entity | Purpose | Sketch |
| :--- | :--- | :--- |
| `AgentRegistration` | One row per agent instance/key | `id`, `userId`, `kind`, `capabilities[]`, `apiKeyId`, `last_seen_at`, `status` |
| `AgentSchedule` | The cadence for an agent (operator-editable) | `id`, `agentId`, `recurrence(json)`, `tz`, `next_call_at`, `paused_until`, `schedule_token` |
| `AgentJob` | A unit of work | `id`, `userId`, `agentId?`, `type`, `input(json)`, `priority`, `status`(queued/leased/done/failed), `lease_token`, `lease_expires_at`, `attempt`, `max_attempts`, `idempotency_key`, `deadline_at`, `result(json)`, timestamps |

Alternative for the very first iteration: reuse `BCONFIG` (`AGENT_SCHED` group) for
schedules and Symfony Messenger / a `agent_jobs` table for the queue. Decide during
build. The **queue semantics (lease/visibility timeout, retries, idempotency)** are
the part that needs care — model it on a standard job-queue, not ad-hoc rows.

## 7. Security & multi-tenancy

- Each agent authenticates with its **own API key** (revocable per agent); jobs and
  schedules are strictly scoped to the owning Synaplan account.
- An agent only ever receives **its own** leased jobs; `agent_report_result` requires
  a matching `lease_token`.
- Reuse `RateLimitService` (a misbehaving agent that hammers `agent_checkin` is
  throttled; the server-controlled cadence already discourages this).
- **Per-call audit log**: who checked in, what was dispatched, lease lifecycle,
  results — needed for debugging autonomous fleets.
- Job `input` is server-authored, but any agent-submitted `result`/`artifacts` must
  be treated as **untrusted** before ingestion (size caps, content validation, no
  blind RAG of attacker-controlled HTML).

## 8. `brogent` as the first consumer

- Declares `agent_kind: "brogent"` and `browser.*` capabilities on check-in.
- Receives `browser.*` job types (`navigate`, `scrape`, `fill_form`, `screenshot`,
  `extract`) with a structured `input` (target URL, natural-language instruction,
  optional `output_schema`).
- Owns its browser runtime, anti-bot handling, site credentials, and concurrency —
  none of which Synaplan stores.
- Returns structured data + artifacts via `agent_report_result`, which can flow into
  chat, files, or RAG.

The job-type taxonomy (`browser.*`, future `crawl.*`, `email.*`, …) and the
brogent job schema get their own spec when brogent build starts.

## 9. Out of scope (for this target)

- The brogent agent itself (separate project/repo).
- Push/streaming delivery of jobs (we are deliberately poll-based).
- A general workflow engine — that's Phase 3 (Orchestration); a dispatched job may
  *trigger* a Synaplan Process, but the scheduler itself is not the engine.
- Cross-agent coordination / fan-out beyond simple per-agent leasing.

## 10. Open questions

- **Single tool vs. split:** is `agent_checkin` returning both jobs + schedule
  enough, or do we want `get_call_schedule` and `get_jobs` as separate tools? (Lean:
  one `agent_checkin` round-trip — fewer calls, atomic schedule+work.)
- **Provisioning:** agents pre-created in the Synaplan UI (operator assigns
  schedule + job sources) vs. self-registration via `agent_register`?
- **Job sources:** where do jobs originate — operator-defined recurring tasks, task
  plans (Phase 3), user chat ("every morning summarize my inbox"), or plugin hooks?
- **Result routing:** default destination for results (a dedicated chat? a file
  group? a webhook?) — likely configurable per schedule.
- **Standard fit:** revisit if/when the MCP **Tasks** extension (long-running work)
  and any future scheduling conventions land in `mcp/sdk`, to avoid a bespoke
  surface where a standard exists.

## 11. Relationship to the roadmap

This is a **new capability track on the "Expose" (server) side**, layered on the
Phase-1 tool foundation and reusing Phase-3 orchestration for what a job *does*. It
is added to the roadmap as **Phase 4 — Agent scheduling & dispatch** and stays a
target until the core tool/resource/prompt work is done. See
[`00-ROADMAP-2026.md` §2 Phase 4](./00-ROADMAP-2026.md).

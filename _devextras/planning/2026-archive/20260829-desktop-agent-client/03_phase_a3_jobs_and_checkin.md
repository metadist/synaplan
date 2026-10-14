# Sprint A3 — Job queue, check-in, and the frozen contract

**Phase A (`synaplan/`), sprint 3 of 3 — the last server sprint.**
Steps `DS11`–`DS18`.

**Goal:** Synaplan web can queue “run skill X on this computer”, a device can
lease it over MCP and report a result, and the whole loop is **proved by a
scripted fake device** — because the real client does not exist yet. At the end
of this sprint the contract is **frozen** (`protocol: 1`) and Phase B may start.
**Depends on:** Sprints A1 + A2. Checklist rows 9, 10, 14, 19, 20, 22.
July research §1 and §4 (why pull; why not in-turn).
**Unlocks:** Phase B. Nothing in `synaplan/` blocks the client after this.
**Repos:** `synaplan/` only. The client half of this loop is
[`08_phase_b5_desktop_poll_loop.md`](./08_phase_b5_desktop_poll_loop.md).

---

## 0. Why this sprint exists — and why it is here, not last

NAT and `SsrfGuard` make “Synaplan calls the laptop” a dead end. The
July paper and `07-AGENT-SCHEDULING.md` already specified the loop:

`check-in → jobs + next_call_at → work → report → sleep`.

This sprint implements that loop for **one** job type: `skill.run`.
It does not implement `file.read`, `email.send`, or `browser.scrape`
(those stay on the July companion-worker roadmap / brogent).

The earlier draft put this **after** the client, reasoning that “a queue with
nothing to run teaches the wrong product”. The server-first decision (master
plan §0.1) inverts that, and the reasoning it replaces it with is:

| Old worry | Why server-first is still safe |
| --------- | ------------------------------ |
| “A queue with no runner is untested” | The harness (`DS17`) is the runner for test purposes, and it can exercise refusal paths a real device would make awkward to trigger |
| “We will not know what the client needs” | The client needs are already written down: `07-AGENT-SCHEDULING.md` plus Sprint B5. Anything discovered later is `protocol: 2`, not a silent reshape |
| “Contract will drift” | It cannot: `DS18` freezes fixtures and C9 forbids Phase B from changing them |

The gain: the queue is designed once, in review, with no client deadline
pushing a `command` field into `BINPUT`.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `07-AGENT-SCHEDULING.md` §4 | Response shape, lease, idempotency |
| `backend/src/Service/Media/MediaJobStore.php` | Lease / heartbeat to copy |
| `backend/src/Mcp/McpServerFactory.php` | Add two tools |
| `frontend` task cards / media job UX | “Queued” pattern |
| `_devextras/testing/desktop/pair.sh` (`DS10`) | The harness this sprint extends |
| `_devextras/testing/messages-gateway/` | Style for a scripted API exercise |

---

## 2. Developer steps

### 2.1 Migration — `BDESKTOPJOBS` (own PR, `DS11`)

Galera-safe sketch:

- `BID`, `BOWNERID`, `BDEVICEID` (nullable = any of the user’s devices)
- `BTYPE` (`skill.run` only in v1)
- `BINPUT` JSON `{ "skill": "pptx", "prompt": "…", "fileIds": [] }`
- `BSTATUS` `queued|leased|succeeded|failed|cancelled`
- `BLEASETOKEN`, `BLEASEEXPIRES`, `BATTEMPT`, `BMAXATTEMPTS`
- `BIDEMPOTENCY` unique per owner
- `BRESULT` JSON (size-capped)
- `BCHATID`, `BMESSAGEID` nullable (where to post the “done” note)
- timestamps

Raw idempotent `addSql` only (`CREATE TABLE IF NOT EXISTS`). No Schema API —
the Galera comparator throws on it (`AGENTS.md`).

### 2.2 Job store and reaper (`DS12`, `DS15`)

Reuse the media-job idea: expired lease → `queued`, increment attempt;
max attempts → `failed`. Command `app:desktop:reap-jobs` + Redis lock.
Platform cron is a **later** `synaplan-platform` PR; in dev, the worker or a
minute tick is enough.

**Flag off means idle, not broken** (C8): the reaper must exit immediately
when `DESKTOP_AGENT.ENABLED` is off, so shipping it to `main` before any
device exists does nothing on every production install.

### 2.3 Enqueue from the web (`DS13`, `DS16`)

`POST /api/v1/desktop/jobs` (session user):

```json
{
  "deviceId": 1,
  "type": "skill.run",
  "input": { "skill": "pptx", "prompt": "Make 3 slides about Q3" },
  "chatId": 99
}
```

Validation:

- Flag on, device owned and `active`.
- `type` ∈ enum (`skill.run`).
- `input.skill` matches `^[a-z0-9-]{1,64}$`.
- Prompt length cap (e.g. 8k chars).
- **Server does not verify the laptop has the skill** (it cannot). The
  device refuses and the job fails honestly.

Chat UX (`DS16`, small): a composer action **Run on this computer** (only if
the user has ≥1 active device) that enqueues and inserts a task-card
style line “Waiting for *Jan's laptop*”. Do **not** hook the planner
to emit `skill.run` automatically in v1 (prompt injection). A later
step can add a planner capability that only proposes, still requiring
the same enqueue endpoint and user-visible confirm.

Because no device can answer yet, the waiting card needs an honest terminal
state: after the lease/attempt budget expires the card shows failed with
“this computer did not answer”, not a spinner forever.

### 2.4 MCP tools (`DS14`)

`agent_checkin` / `agent_report_result` as specified in
`07-AGENT-SCHEDULING.md`, namespaced, user-scoped, **require
`desktop:jobs`**.

Check-in input includes `agent_kind: "synaplan-desktop"` and
`capabilities: ["skill.run"]` plus the list of **enabled skill names**
so the server can skip jobs the device will refuse (optimization, not
a security boundary).

Empty jobs still return `schedule.next_call_at` (interval default
30s when work exists, 2–5 min idle, jitter). Adaptive backoff as in
the scheduling doc.

Report: status, optional `file` artifact (upload via existing files
API first, then pass `fileId`), error string. Server posts a message
into `BCHATID` if set. Mark provenance `source: desktop_skill`.
Cap result JSON size.

The tools must be **absent from `tools/list` when the flag is off** (C8) and
additive when it is on (C2).

### 2.5 Contract freeze (`DS18`)

The point of doing this before the client: after this step the shape is
settled and Phase B is pure client work.

1. **Version field.** Check-in request and response carry `protocol: 1`.
   An unknown protocol from a device is answered with an empty job list and
   a far `next_call_at`, never a guess.
2. **Closed enums, documented.** `type` (`skill.run`), `status`
   (`queued|leased|succeeded|failed|cancelled`), error codes
   (`unknown_skill`, `unknown_type`, `skill_disabled`, `timeout`,
   `local_error`).
3. **Ignore-extra-keys is specified, not implied.** The doc states that a
   device MUST ignore unknown keys in `input` and MUST NOT execute anything
   but `{skill, prompt, fileIds}`. That sentence is the reason a future
   server bug cannot become remote code execution.
4. **Committed fixtures** under `_devextras/testing/desktop/fixtures/`:
   check-in request/response, one `skill.run` job, a success report, a
   `unknown_skill` failure report. Phase B builds its unit tests from these
   files (C9).
5. **`docs/DESKTOP.md`** (new, in `synaplan/`): what Synaplan Desktop is,
   pairing, the flag, the job contract, “not Claude Code”. Link from
   `docs/ANTHROPIC_COMPATIBLE_API.md` “Related”. State plainly that the
   client is not released yet; add the download section in Phase B.

Changing any of the four fixture files after this sprint is a
`protocol: 2` discussion with a migration plan — not a client-convenience PR.

### 2.6 Fake-device harness (`DS17`)

`_devextras/testing/desktop/fake-device.sh` (POSIX shell + `curl` + `jq`, or a
small PHP CLI if JSON handling gets ugly). It is the Phase A acceptance demo
and the stand-in for the whole right-hand column of the architecture diagram.

Happy path:

1. Pair via `pair.sh` (reuse, do not fork).
2. `agent_checkin` with `capabilities: ["skill.run"]` and
   `enabledSkills: ["hello-files"]` → expect `[]` and a `next_call_at`.
3. Enqueue a job as the web user (session) for `hello-files`.
4. Check in again → expect exactly one job + a lease token.
5. `agent_report_result` success with a `fileId` from a real upload.
6. Assert the chat received a completion message.

Refusal paths (each one asserted, because a real device makes them awkward
to trigger on demand):

| Case | Expected |
| ---- | -------- |
| Report `unknown_skill` for a name the device does not have | Job `failed`, error code stored, no retry storm |
| Second device checks in while job is leased | `[]` |
| Report with a stale / wrong lease token | 400 |
| Enqueue with `input.command = "rm -rf /"` | Accepted only as an ignored extra key **or** rejected by validation — assert which, and that no `command` reaches the device payload |
| Enqueue for another user’s device | 404 |
| Result payload above the size cap | Rejected |
| Flag off | Enqueue 404, check-in tool missing |

The harness is documented in `docs/DESKTOP.md` or a README beside it, runs
against the local Docker stack, and is **not** part of the PHPUnit gate — it
is the manual/CI-optional evidence script. The equivalent assertions also
exist as PHPUnit tests (§3).

### 2.7 What we still will not do

- Resume a mid-flight `DagExecutor` plan.
- `shell.exec` job type.
- Server-authored bash strings.
- Push-only delivery (Centrifugo may *hint*; check-in remains required).
- brogent / `browser.*` jobs (separate consumer, same table later).
- A polished daemon in `synaplan/`. The harness stays a test script.

---

## 3. Tests (`synaplan/`)

- Enqueue flag off → 404.
- Enqueue other user’s device → 404.
- Check-in leases one job; second device gets `[]`.
- Lease expiry requeues (unit, fake clock).
- Report without lease token → 400.
- Result larger than cap → rejected.
- Unknown `protocol` → empty jobs + far `next_call_at`.
- Extra `input` keys survive a round trip **without** being promoted to
  anything executable (assert the payload the device would receive).
- Reaper with the flag off does nothing.
- MCP `tools/list` snapshot is a **superset** with the flag on, and
  **unchanged** with the flag off (C2 + C8).
- Characterization **unchanged** (do not auto-plan `skill.run`).
- i18n for the composer action and the waiting/failed card ×4.
- Fixtures in `_devextras/testing/desktop/fixtures/` are asserted against the
  live OpenAPI / MCP schema, so a later server change that breaks the frozen
  contract fails the gate rather than the client (C9).

---

## 4. Exit criteria — and the Phase A gate

1. Web: queue a job; the harness leases, reports, and the chat shows a
   completion message with a file link.
2. Every refusal path in §2.6 asserted (harness **and** PHPUnit).
3. Revoked device cannot check in (401).
4. Flag off: enqueue 404, tools absent, reaper idle, no nav (C8).
5. `protocol: 1` documented, fixtures committed, `docs/DESKTOP.md` live (C9).
6. Full unfiltered Synaplan gate green; characterization diff empty.
7. Invariants C2, C3, C4, C5, C8, C9 named in the PRs.

**When 1–7 hold, Phase A is done and `synaplan-desktop` may be created
(`DC1`) — not before** (master plan decision 23).

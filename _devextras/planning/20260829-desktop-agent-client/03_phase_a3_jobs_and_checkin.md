# Sprint 6 — Check-in and web-queued skill jobs

**Goal:** Synaplan web (and later Saved Tasks) can queue “run skill X on
this computer”. The desktop polls, runs only that **named, installed,
enabled** skill, and reports a result. The web turn does **not** wait.
**Depends on:** Sprints 1 + 5. Checklist rows 9, 10, 14. July research
§1 and §4 (why pull; why not in-turn).
**Repos:** `synaplan/` (queue + MCP tools + UI) and `synaplan-desktop`
(poll loop + tray).

Do this **after** a real skill exists. A queue with nothing to run teaches
the wrong product.

---

## 0. Why this sprint exists

NAT and `SsrfGuard` make “Synaplan calls the laptop” a dead end. The
July paper and `07-AGENT-SCHEDULING.md` already specified the loop:

`check-in → jobs + next_call_at → work → report → sleep`.

This sprint implements that loop for **one** job type: `skill.run`.
It does not implement `file.read`, `email.send`, or `browser.scrape`
(those stay on the July companion-worker roadmap / brogent).

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `07-AGENT-SCHEDULING.md` §4 | Response shape, lease, idempotency |
| `backend/src/Service/Media/MediaJobStore.php` | Lease / heartbeat to copy |
| `backend/src/Mcp/McpServerFactory.php` | Add two tools |
| `frontend` task cards / media job UX | “Queued” pattern |
| Desktop Sprint 3 tool loop | Reuse; do not fork a second executor |

---

## 2. Developer steps

### 2.1 Migration — `BDESKTOPJOBS` (own PR)

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

Reaper: reuse the media-job idea (expired lease → queued, increment
attempt; max attempts → failed). Command `app:desktop:reap-jobs` +
Redis lock. Platform cron is a **later** `synaplan-platform` PR; in
dev, the worker or a minute tick is enough.

### 2.2 Enqueue from the web

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

Chat UX (small): a composer action **Run on this computer** (only if
the user has ≥1 active device) that enqueues and inserts a task-card
style line “Waiting for *Jan's laptop*”. Do **not** hook the planner
to emit `skill.run` automatically in v1 (prompt injection). A later
step can add a planner capability that only proposes, still requiring
the same enqueue endpoint and user-visible confirm.

### 2.3 MCP tools

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

### 2.4 Desktop poll loop

Background task (window open in v1; tray in a follow-up D-step):

1. Sleep until `next_call_at` (or 30s the first time).
2. Call `agent_checkin`.
3. For each job: if `type != skill.run` or skill not enabled →
   `report failed` (`unknown_skill` / `unknown_type`).
4. Else: run the **same** tool loop as interactive chat, with the
   job prompt as the user message and that skill forced into context.
5. Report success/failure. Never run `input.command` even if a
   future buggy server sends it — ignore unknown keys.

Local confirm: first unattended `skill.run` for a skill should
notify (OS notification) and optionally require click-through.
`allowUnattended` per skill in `skills.json` (default false).

### 2.5 What we still will not do

- Resume a mid-flight `DagExecutor` plan.
- `shell.exec` job type.
- Server-authored bash strings.
- Push-only delivery (Centrifugo may *hint*; check-in remains required).
- brogent / `browser.*` jobs (separate consumer, same table later).

---

## 3. Tests

### 3.1 Synaplan

- Enqueue flag off → 404.
- Enqueue other user’s device → 404.
- Check-in leases one job; second device gets `[]`.
- Lease expiry requeues (unit, fake clock).
- Report without lease token → 400.
- Result larger than cap → rejected.
- MCP `tools/list` snapshot is a **superset** (C2).
- Characterization **unchanged** (do not auto-plan `skill.run`).
- i18n for the composer action ×4.

### 3.2 Desktop

- Mock check-in with `skill.run` / `pptx` enabled → loop invoked with
  that prompt.
- `skill.run` / `not-installed` → report `unknown_skill`, no Bash.
- Payload `{ "type": "skill.run", "input": { "command": "rm -rf" } }` →
  ignore `command`, only `skill` + `prompt`.
- Unknown type → failed, no subprocess.

---

## 4. Exit criteria

1. Web: queue a pptx job; desktop (manual) produces a file and the
   chat shows a completion message with a file link.
2. Uninstalled skill name fails closed on the device.
3. Revoked device cannot check in (401).
4. Flag off: enqueue 404; desktop backs off.
5. Both repo gates green.
6. Invariants C2, C3, C4, C5 named in the PRs.

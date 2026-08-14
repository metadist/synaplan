# Sprint 3 — User-facing scheduler (cron)

**Goal:** A Saved Task can run on a **user-owned schedule**. The single new backend surface is one console command, `app:saved-tasks:tick`; production drives it from the **existing `synaplan-platform` host-cron family** (checklist row 11), dev/self-host from the Docker scheduler role. Both call the same self-locking command.

**Depends on:** Sprint 1 (tables incl. `next_run_at` / `consecutive_failures`, runner) and Sprint 2 (graph compile, run rows). **Unlocks:** unattended automations; Sprint 4 mutating actions must respect `allow_unattended`.

---

## 0. Non-goals

- Do not implement `07-AGENT-SCHEDULING.md` MCP pull-queue (`agent_checkin`). That is for external agents (brogent). This sprint is **server-side** execution inside Synaplan workers.
- Do not ask **users** to install crontab (operators install one cron script once, like the existing ones).
- Do not use widget `crawlInterval` as the schedule engine.
- Do not modify or replace `cron-gmail.sh` / `app:process-mail-handlers` / `app:process-emails` (compatibility invariant C4). Saved Tasks get a **sibling** script.

---

## 1. Where schedules actually run (production reality)

Production (`synaplan-platform`, Galera cluster, web1/2/3) does **not** use the Docker `SYNAPLAN_ROLE=scheduler` service. It uses **host crontab on web1** with wrapper scripts in the platform repo — this is the backbone we expand:

| Script (platform repo) | What it runs | Pattern |
| ---------------------- | ------------ | ------- |
| `cron-gmail.sh` | `app:process-mail-handlers` + `app:process-emails` (currently the only inbound mail pickup) | web1-only, `docker compose exec -T backend` |
| `cron-media-reaper.sh` | `app:media:reap-jobs`, every minute | web1-only **+ cross-node Redis lock** in the command (`media-job-reaper`, 120s TTL) — duplicate runs are safe no-ops |
| `synaplan-cron.logrotate` | rotates `/var/log/synaplan-*.log` | new logs are covered for free |

### 1.1 New: `cron-saved-tasks.sh` (lands in `synaplan-platform`, separate PR there)

Copy the media-reaper pattern exactly:

```bash
#!/bin/bash
# cron-saved-tasks.sh — fires due Saved Task schedules (Saved Task Workflows, Sprint 3).
# Run on web1 ONLY (same single-node pattern as cron-gmail.sh). The command
# self-locks via a cross-node Redis lock (`saved-tasks-tick`), so an
# overlapping/duplicate run — or a self-hoster's Docker scheduler role running
# in parallel — is a safe no-op.
#
# Crontab on web1:
#   * * * * * /netroot/synaplanCluster/synaplan-compose/cron-saved-tasks.sh >> /var/log/synaplan-saved-tasks.log 2>&1

export SYNDBHOST=10.0.0.2
cd /netroot/synaplanCluster/synaplan-compose
docker compose exec -T backend php bin/console app:saved-tasks:tick
```

Notes:

- **Separate script, separate log** — do not append the tick to `cron-gmail.sh` (different cadence: tick is every minute; mail pickup keeps its own rhythm). `cron-gmail.sh` stays byte-identical.
- Logrotate: `/var/log/synaplan-saved-tasks.log` matches the existing glob; no logrotate change needed.
- Install order: the cron can be installed **before** the feature flag is on — the tick exits immediately while `SAVEDTASKS.ENABLED` is globally off (see rollout §8.5 in the master plan).
- Real infra values (`SYNDBHOST`, paths) live only in the platform repo — never in this public repo.

### 1.2 Dev / self-host: Docker scheduler role (additive)

`_docker/backend/lib/container-runtime.sh` (`SYNAPLAN_ROLE=scheduler`) today runs `app:media:reap-jobs` (~60s), `app:files:reap-ephemeral` (hourly), `app:updates:check` (daily). **Additive:** also run `app:saved-tasks:tick` every ~60s.

### 1.3 The invariant that makes both safe

`app:saved-tasks:tick` **must acquire a cross-node Redis lock** (name `saved-tasks-tick`, TTL ≥ 2× expected tick duration, same mechanism as `app:media:reap-jobs`) before doing anything, and exit 0 quietly if the lock is held. This makes every combination safe: host cron + scheduler role, two operators, an overlapping slow tick. The DB compare-and-set claim (§3) is the second, per-task guard — belt and braces, both required.

---

## 2. Schedule model

Store on `saved_tasks.trigger_config` when `trigger_type = schedule` (or graph trigger type `schedule`):

```json
{
  "kind": "cron",
  "expr": "0 7 * * 1-5",
  "tz": "Europe/Berlin"
}
```

v1 supported kinds (implement only these):

| Kind | Config | Example |
| ---- | ------ | ------- |
| `interval` | `every_minutes` ∈ {15, 30, 60} | Mail poll every 15 min |
| `daily` | `at` `HH:MM`, `tz` | 07:00 local |
| `weekly` | `days[]`, `at`, `tz` | Mon–Fri 07:00 |

Do **not** accept arbitrary cron from the UI in v1 (injection / foot-guns). A hidden `expr` for admins is optional and must be validated by a cron parser with a bounded next-fire.

Columns `next_run_at` (UTC, indexed), `last_run_at`, `consecutive_failures` **already exist** — the Sprint 1 migration created them nullable. This sprint adds no schema.

On save: compute `next_run_at` in PHP (timezone-aware). Never trust the client’s next-run.

**`allow_unattended`:** schedules cannot be enabled unless this is true **and** the graph has no mutating action, **or** the user has confirmed a danger dialog listing the mutating nodes (`calendar_event` is **not** mutating in v1 — it only attaches `.ics`. `email_me` **is** mutating. Webhook POST **is** mutating. Future Graph write **is** mutating).

---

## 3. Claim loop (concurrency)

`app:saved-tasks:tick`:

1. Acquire the `saved-tasks-tick` Redis lock (§1.3); held elsewhere → exit 0 quietly.
2. If global flag off, exit 0.
3. Select due rows: `enabled = 1 AND trigger_type = 'schedule' AND next_run_at <= NOW()` `LIMIT N` (e.g. 20).
4. **Claim** with a compare-and-set (`UPDATE … SET next_run_at = <tentative> WHERE id = ? AND next_run_at = <old>`) so two runners cannot double-run a task. Galera-safe: single-row UPDATE, no Schema API.
5. Create `saved_task_runs` (`trigger = schedule`).
6. Run `SavedTaskRunner` (graph compile or planner) **as the owner id — no session, no OIDC** (master plan §3.4). Catch all expected failures; mark run failed with a user-readable reason; **always** compute the following `next_run_at` so a failed task does not hot-loop (backoff: at least `max(interval, 5 minutes)`).
7. Failure accounting: increment `consecutive_failures`; at **3**, set `enabled = 0` and notify the user (run row reason + in-app notice: *paused after repeated failures, here is why, resume here*). Success resets the counter.
8. Isolated failure per task: one user throwing must not abort the tick.

Idempotency: `idempotency_key = saved_task_id + scheduled_slot` (e.g. `2026-08-14T07:00:00+02:00`) unique on runs to survive retries.

Timeouts: reuse data-node timeouts; the tick must not block the media reaper. Prefer dispatching a **Messenger** message per claimed task (`SavedTaskRunMessage`) and returning quickly from the tick. **Lock:** Messenger dispatch is the correct shape (async media already uses the worker). Tick = claim + enqueue; worker = execute.

---

## 4. Mail pickup on a schedule

For the flagship story, schedule + `inbound_email` / `email_search`:

**Preferred v1:** schedule trigger fires the graph; first process node is `email_search` (read-only IMAP, already exists). No second IMAP daemon.

**Not in v1:** replacing `app:process-emails` (smart@) or department mail handlers. Those remain ops/commands.

UI copy: “Search my connected mailbox when this task runs” (email_search node), not “Synaplan deletes or files your mail” (IMAP stays `FT_PEEK` / read-only).

---

## 5. Frontend

1. “When to run” section: interval / daily / weekly + timezone (IANA, default from browser, stored explicitly).
2. Show next run in the user’s timezone.
3. Pause = `enabled = false` (does not delete graph).
4. Danger dialog for `allow_unattended` when `email_me` or webhook is in the graph.
5. Runs list: distinguish `schedule` vs `manual` vs `webhook`.
6. i18n four locales; no “cron” in primary copy — **Schedule**.

---

## 6. Testing

| Layer | Assert |
| ----- | ------ |
| Unit | Next-run computation: Europe/Berlin DST spring-forward / fall-back fixtures (fixed clock) |
| Unit | Tick exits 0 without work when the Redis lock is held (fake lock store) |
| Unit | Claim UPDATE: second claim with stale `next_run_at` affects 0 rows |
| Unit | Failed run still advances `next_run_at`; unique idempotency prevents double insert |
| Unit | 3rd consecutive failure pauses the task + records the notice; success resets the counter |
| Unit | Schedule rejected if `allow_unattended` false and graph contains `email_me` |
| Unit | Tick runner path never touches session/OIDC services (constructor has no `Security` dependency) |
| Integration | Tick with SQLite/test DB enqueues Messenger message; handler creates run |
| Messenger | Handler uses TestProvider; no sockets (`--disable-socket` not applicable in PHPUnit the same way — still: no real IMAP, no real LLM) |
| Vitest | Schedule form validation; tz displayed; paused-task banner with resume action |
| E2E | Optional: set interval 15 min is enough to save; do **not** wait for a real tick in E2E. Use a backend command in a feature test instead |
| Regression | Media reaper still registered; scheduler role still runs existing commands; `cron-gmail.sh` untouched (platform-repo PR review, invariant C4) |
| Gate | Unfiltered |

Determinism: inject a `Clock` / `now` into the calculator. Never `sleep` in tests or production tick.

---

## 7. Documentation

| Doc | Change |
| --- | ------ |
| `docs/FEATURES.md` | Scheduled Saved Tasks |
| `docs/CONFIGURATION.md` | `SAVEDTASKS` flags; how schedules fire (host cron **or** scheduler role, both safe via the Redis lock) |
| `docs/DEVELOPMENT.md` | How to run `app:saved-tasks:tick` locally |
| **`synaplan-platform` repo (separate PR)** | `cron-saved-tasks.sh` (§1.1) + crontab line in `_devextras/SYSADMIN-help.md` / `CLUSTER-DOC.md`. Never commit node IPs or paths to the public repo |
| `docs/` operator note (public repo) | Self-hosters: schedules need either the scheduler role or a one-line system cron calling `app:saved-tasks:tick`; duplicate installation is safe |
| `backend/docs/SMART_EMAIL_CRONJOB_SETUP.md` | Clarify relationship: `cron-gmail.sh` (smart@ + mail handlers) is **not** Saved Tasks and is unchanged; Saved Tasks search IMAP via `email_search` on their own schedule |
| i18n | four locales |

---

## 8. Release gate

- [ ] Two ticks cannot execute the same slot twice — Redis lock **and** DB claim tested.
- [ ] Host cron + Docker scheduler role running simultaneously is a proven no-op (lock test).
- [ ] Failed run does not tight-loop; 3 consecutive failures auto-pause with a visible, resumable notice.
- [ ] DST fixtures pass.
- [ ] `allow_unattended` enforced.
- [ ] Existing scheduler jobs still run; `cron-gmail.sh` and media reaper untouched (invariant C4).
- [ ] Flag off: tick no-ops (cron can be pre-installed).
- [ ] `cron-saved-tasks.sh` PR opened in `synaplan-platform` (script + crontab + ops doc).
- [ ] Unfiltered gate green.

---

## 9. Handoff to Sprint 4

Outbound webhook action and plugin nodes will run **inside the same worker**. Rate limits and confirmation: scheduled runs skip interactive confirm — that is why `allow_unattended` exists. Sprint 4 must not add a mutating node that can be scheduled without that flag.

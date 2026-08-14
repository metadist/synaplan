# Sprint 3 — User-facing scheduler (cron)

**Goal:** A Saved Task can run on a **user-owned schedule** without ops crontab. The platform scheduler role claims due work and executes the same `SavedTaskRunner` as Run now / graph compile.

**Depends on:** Sprint 1 (tables, runner) and Sprint 2 (graph compile, run rows). **Unlocks:** unattended automations; Sprint 4 mutating actions must respect `allow_unattended`.

---

## 0. Non-goals

- Do not implement `07-AGENT-SCHEDULING.md` MCP pull-queue (`agent_checkin`). That is for external agents (brogent). This sprint is **server-side** execution inside Synaplan workers.
- Do not ask users to install crontab. Optional ops docs may mention “if you self-host without the scheduler container, enable it”.
- Do not use widget `crawlInterval` as the schedule engine.

---

## 1. Current scheduler (do not break)

`_docker/backend/lib/container-runtime.sh` (`SYNAPLAN_ROLE=scheduler`) today:

- ~60s: `app:media:reap-jobs`
- hourly: `app:files:reap-ephemeral`
- daily: `app:updates:check`

**Additive:** every ~60s (or a dedicated 15–60s tick) also run `app:saved-tasks:tick`.

Self-hosters without that role: document running the command from system cron **or** enable the scheduler service. Cloud/`synaplan-platform`: one scheduler replica (avoid duplicate claims — see §3).

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

Columns (if not added in Sprint 1):

- `next_run_at` (UTC datetime, indexed)
- `last_run_at`
- `schedule_token` (optional, for detecting config changes mid-flight)

On save: compute `next_run_at` in PHP (timezone-aware). Never trust the client’s next-run.

**`allow_unattended`:** schedules cannot be enabled unless this is true **and** the graph has no mutating action, **or** the user has confirmed a danger dialog listing the mutating nodes (`calendar_event` is **not** mutating in v1 — it only attaches `.ics`. `email_me` **is** mutating. Webhook POST **is** mutating. Future Graph write **is** mutating).

---

## 3. Claim loop (concurrency)

`app:saved-tasks:tick`:

1. If global flag off, exit 0.
2. Select due rows: `enabled = 1 AND trigger_type = 'schedule' AND next_run_at <= NOW()` `LIMIT N` (e.g. 20).
3. **Claim** with a compare-and-set (`UPDATE … SET next_run_at = <tentative> WHERE id = ? AND next_run_at = <old>`) so two scheduler replicas cannot double-run. Galera-safe: single-row UPDATE, no Schema API.
4. Create `saved_task_runs` (`trigger = schedule`).
5. Run `SavedTaskRunner` (graph compile or planner). Catch all expected failures; mark run failed; **always** compute the following `next_run_at` so a failed task does not hot-loop (backoff: at least `max(interval, 5 minutes)`).
6. Isolated failure per task: one user throwing must not abort the tick.

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
| Unit | Claim UPDATE: second claim with stale `next_run_at` affects 0 rows |
| Unit | Failed run still advances `next_run_at`; unique idempotency prevents double insert |
| Unit | Schedule rejected if `allow_unattended` false and graph contains `email_me` |
| Integration | Tick with SQLite/test DB enqueues Messenger message; handler creates run |
| Messenger | Handler uses TestProvider; no sockets (`--disable-socket` not applicable in PHPUnit the same way — still: no real IMAP, no real LLM) |
| Vitest | Schedule form validation; tz displayed |
| E2E | Optional: set interval 15 min is enough to save; do **not** wait for a real tick in E2E. Use a backend command in a feature test instead |
| Regression | Media reaper still registered; scheduler script still runs existing commands |
| Gate | Unfiltered |

Determinism: inject a `Clock` / `now` into the calculator. Never `sleep` in tests or production tick.

---

## 7. Documentation

| Doc | Change |
| --- | ------ |
| `docs/FEATURES.md` | Scheduled Saved Tasks |
| `docs/CONFIGURATION.md` | `SAVEDTASKS` flags; scheduler role now also ticks Saved Tasks |
| `docs/DEVELOPMENT.md` | How to run `app:saved-tasks:tick` locally |
| `_docker` / platform help (`SYSADMIN-help.md` in platform repo — **do not edit here if private**; add a note in `docs/` for operators) | Scheduler container must run for schedules to fire |
| `backend/docs/SMART_EMAIL_CRONJOB_SETUP.md` | Clarify relationship: smart@ cron is **not** Saved Tasks; Saved Tasks search IMAP via `email_search` on their own schedule |
| i18n | four locales |

---

## 8. Release gate

- [ ] Two ticks cannot execute the same slot twice (unit + integration).
- [ ] Failed run does not tight-loop.
- [ ] DST fixtures pass.
- [ ] `allow_unattended` enforced.
- [ ] Existing scheduler jobs still run.
- [ ] Flag off: tick no-ops.
- [ ] Unfiltered gate green.
- [ ] Operator docs state that the scheduler role is required.

---

## 9. Handoff to Sprint 4

Outbound webhook action and plugin nodes will run **inside the same worker**. Rate limits and confirmation: scheduled runs skip interactive confirm — that is why `allow_unattended` exists. Sprint 4 must not add a mutating node that can be scheduled without that flag.

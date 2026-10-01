# One scheduler, one job list

**Status:** Plan drafted 2026-10-01, revised the same day after a critical
review (§9). **No code until §0 is ticked.**
**Goal:** every periodic job runs from one place — the `scheduler` role in
`_docker/backend/lib/container-runtime.sh` — on every install, including
production. Slow jobs never hold up fast ones, a restart never repeats a
daily job, and production alerts when jobs stop running.
**Binding contracts:** [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md)
(U1–U12) and AGENTS.md "Perfect UX & Stability".

Four changes, in this order:

| # | Change | Repo | Mobile class |
| - | ------ | ---- | ------------ |
| **P1** | Dev fix: the local `scheduler` service runs the scheduler role | `synaplan` | no-app-impact (`docker-compose*.yml`) |
| **P2** | Scheduler hardening: parallel lanes, timeouts, persisted slots, fixed daily time, opt-in prod jobs, job status command | `synaplan` | backend-only / no-app-impact |
| **P3** | Production: scheduler service on every node, delete the app host crons, stale alert | `synaplan-platform` (private) | n/a — **deploy only once the running image contains P2** |
| **P4** | Admin status card + sidebar hint (journey J-SCH-1) | `synaplan` | ota-candidate |

Platform details (paths, hosts, node scripts) live in the platform repo's
cluster doc, never here.

---

## 0. Decision checklist — tick every row before any code

| # | Decision | Recommendation | State |
| - | -------- | -------------- | ----- |
| 1 | Order | P1 now; P2; release; P3; P4. **Not** P3 first: with today's loop it delays Saved Tasks behind slow jobs and repeats user mails on every restart (§1.3) | open |
| 2 | Where the scheduler runs in production | **Every web node**, like `worker`. Safe after P2 (every unlocked job sits in a claimed slot, every tick job holds a lock). No compose profile, no per-node script edits, no single point of failure. Tick jobs then run up to once per node per minute; verify in P2 that the saved-tasks tick advances `nextRunAt` before it releases its lock | open |
| 3 | Schedule state | Slot claims in BCONFIG (owner 0, group `SCHEDULER`), atomic like `ModelDiscoveryStateStore::claimNotifyDay`. Job status (start, finish, exit code) in Redis, shared by all nodes | open |
| 4 | Daily time | Fixed UTC time `SYNAPLAN_SCHEDULER_DAILY_AT`, default `03:30`. A slot that never ran runs at once (fresh installs get the docs sync and update check on day one) | open |
| 5 | `app:process-mail-handlers` | Into the tick lane, ungated (no-op without active handlers, already locked). Fixes inbound handlers on self-host installs, which had no runner at all | open |
| 6 | `app:process-emails` (operator smart mailbox) | Tick lane behind `SYNAPLAN_SCHEDULER_SMART_MAILBOX=1`, default off; gets a lock so several nodes cannot fetch the same mail | open |
| 7 | `app:sync-model-prices` (writes prices) | Daily lane behind `SYNAPLAN_SCHEDULER_PRICE_SYNC=1`, default off. Production behaviour unchanged; the fingerprint bug in §8 is a separate issue | open |
| 8 | `app:digest:run` in production | **Boss decides.** It has never run in production. Per user with new messages: up to 4 chat calls/day with the memory model plus embeddings. Check where the per-user cursor starts before enabling — from 0 it works through the whole history at 100 messages/user/day. Until decided: `DIGEST/ENABLED=0` in production | open |
| 9 | Stale alert in production | The existing host disk watchdog (already posts to Discord, runs every 5 min) calls `app:scheduler:status`; one post when jobs are stale for two runs, one on recovery. No new cron | open |
| 10 | Symfony Scheduler | Not now — see §7 | open |

---

## 1. Verified baseline (2026-10-01, `main`, production 5.0.6)

### 1.1 Why production misses jobs

Production runs no scheduler container. It runs host crons on one web node,
one wrapper per job (`docker compose exec -T backend php bin/console …`).
Every feature that added a job to the loop promised a "separate platform
PR"; only some landed. Nothing detects the drift.

The local dev `scheduler` service (`docker-compose.yml` ~705, #1958) never
sets `SYNAPLAN_ROLE: scheduler` (the commit message says it does, the diff
never did), so it starts as a second web server and no job runs locally.
`deploy/compose.yaml:515` and the Umbrel compose set the role.

### 1.2 Job matrix

| Job | Loop today | Production today | After P2 + P3 (lane) |
| --- | ---------- | ---------------- | -------------------- |
| `app:media:reap-jobs` | tick | host cron, every minute | tick |
| `app:chat:reap-stuck` (#1913) | tick | **missing** | tick |
| `app:desktop:reap-jobs` | tick | **missing** | tick |
| `app:saved-tasks:tick` (runs due tasks inline; sweeps expired approvals) | tick | host cron, every minute | **own lane** `tasks` |
| `app:files:reap-ephemeral` | hourly | host cron, hourly | hourly |
| `app:approvals:expire` | hourly | covered by the saved-tasks tick | hourly |
| `app:updates:check` | daily | host cron, daily, `--force` | daily (manifest cache TTL is 6 h, `--force` not needed) |
| `app:models:check-availability --notify` | daily | host cron, daily | daily |
| `app:models:discover --notify` | daily | **missing** (`MODEL_DISCOVERY_ENABLED=1` is set) | daily |
| `app:digest:run` | daily | **missing** | daily (see §0 #8) |
| `app:selfaware:sync-docs` | daily | **missing** | daily |
| `app:approvals:digest` | daily | **missing** | daily |
| `app:model:health-check --jitter=120` | 900 s | host cron, every 15 min | own lane `health` |
| `app:process-mail-handlers` | — | host cron, every minute | tick |
| `app:process-emails` | — | host cron, every minute | tick, opt-in |
| `app:sync-model-prices` | — | host cron, daily | daily, opt-in |
| disk watchdog | — | host cron, every 5 min | **stays** (host-level, must run when the app is down); gains the stale check |

### 1.3 Facts the design depends on

| Fact | Where |
| ---- | ----- |
| The loop runs everything **one after another** in one process | `container-runtime.sh:359-469` |
| The health check's jitter is a blocking `sleep` of 0–120 s every 15 min | `ModelHealthCheckCommand.php:64`, `:133-148` |
| The saved-tasks tick runs due tasks inline (`SavedTaskRunner::run`), no wall clock, up to 20 runs | `SavedTaskTickService.php:84` |
| `app:digest:run` worst case: users × 4 memory-model calls (60–120 s each) | `MessageDigestService.php:147-165`, `MessageDigestConfig.php:35-37` |
| → a daily slot can hold up Saved Tasks and reapers for a long time, and the heartbeat (written once per tick, max age 180 s) goes stale | `container-healthcheck.sh:32-48` |
| Slot timers start at `0`: every restart runs all daily jobs again | `container-runtime.sh:346-348` |
| A rerun on the same day **mails the approval digest again** (no per-day dedupe) and **posts the availability drift again** (no throttle) | `ApprovalExpiryService.php:48-66`, `DigestApprovalsCommand.php:45-59`; `DiscordNotificationService.php:814-846` |
| Digest and docs sync reruns are cheap (cursor; per-page sha256) | `MessageDigestRunner.php:175-182`; `PlatformDocsSyncService.php:70-76` |
| No total timeout: Ollama client (Guzzle `timeout` 0), IMAP close | `OllamaClient.php:24-39`; `InboundEmailHandlerService.php:450-454` |
| `timeout` (GNU coreutils) is in the image | `/usr/bin/timeout` in the backend container |
| Locks (`LOCK_DSN`, Redis) on every tick/hourly job and on digest/approvals digest; **no lock** on updates check, availability, discover, docs sync, health check, `process-emails` | the commands' `LockFactory::createLock` calls |
| BCONFIG has a UNIQUE key on (`BOWNERID`, `BGROUP`, `BSETTING`); `ConfigRepository` does not cache | `Entity/Config.php:11`; `ConfigRepository.php:19-27` |
| Keyless health check records models as `Unconfigured`; nothing is disabled or hidden | `ModelHealthEvaluator.php:284-288`, `ModelAutoDisabler.php:75-93` |
| `wait_for_web_health` defaults to `http://backend/api/health` (matches the platform service name) | `container-runtime.sh:265-277` |
| Compose 2.40: `up --remove-orphans` without the profile keeps a profiled container, `down` without the profile **leaves it running** — profiles need every node script to export them | local experiment 2026-10-01 |
| `/api/v1/config/features` and `/admin/features` are admin-only | `ConfigController.php:2381-2391`; `router/index.ts:668-676` |

---

## 2. Design (P2)

### 2.1 Lanes

The loop starts each lane as a background child and moves on; a lane is
skipped while its previous run is still alive. Inside a lane, jobs run in
order.

| Lane | Due | Jobs | Cap per job (`timeout`) |
| ---- | --- | ---- | ----------------------- |
| `tick` | every tick | media, stuck chats, desktop, mail handlers, smart mailbox (opt-in) | 300 s |
| `tasks` | every tick | saved-tasks tick | none — it runs AI tasks inline and has no stale-run reaper; verify before choosing a cap |
| `hourly` | claimed slot | ephemeral files, approval expiry | 900 s |
| `daily` | claimed slot at `DAILY_AT` | updates, availability, discover, digest, docs sync, approval digest, price sync (opt-in) | 3600 s (digest: 3 h) |
| `health` | claimed slot every 900 s | model health check | 600 s |

The heartbeat file keeps its meaning (loop alive) because the loop itself
never blocks. `TERM` stops every live lane before the loop exits.

### 2.2 Slot claims

`app:scheduler:claim <slot>`: exit `0` claimed; exit `3` not due, prints the
seconds until it is due so the loop does not ask again every minute. Atomic
conditional `UPDATE`, `INSERT IGNORE` for the first row (pattern of
`claimNotifyDay`). A Galera certification conflict counts as "not due". Any
other error: skip the slot this tick, log it, ask again next tick — no
in-memory fallback, because a fallback would let two nodes run the same
slot.

### 2.3 Job status

A console event subscriber records start, finish and exit code for the
scheduled commands (one list in PHP) in Redis. A job killed by `timeout`
shows as "did not finish". `app:scheduler:status [--max-age=600]` prints the
state and exits non-zero when the loop is stale — used by the production
watchdog in P3 and read by the admin card in P4.

### 2.4 Dev fix (P1)

`SYNAPLAN_ROLE: scheduler` on the dev `scheduler` service. Checked:
keyless health check disables nothing; no @ci E2E creates scheduled tasks
the tick could fire; `DISCORD_WEBHOOK_URL` is empty locally. Still run
`make test-e2e` with the scheduler on. CI E2E uses `docker-compose.test.yml`
without a scheduler, so this is a local-only difference.

---

## 3. UX (P4, `ota-candidate`)

**Journey J-SCH-1 — An admin sees whether background jobs run.**

1. Admin opens **Admin → Feature status** (`/admin/features`).
2. A "Background jobs" card states the last run in one sentence and lists
   the lanes (every minute, Saved Tasks, hourly, daily, model health) with
   their last run.
3. A lane whose last run had failures names the failed jobs in plain words
   and points to the scheduler log.
4. When jobs stopped, the card says so, what is not happening (scheduled
   tasks, reminders, clean-ups) and how to recover; an admin-only sidebar
   hint (update-badge pattern, `SidebarV2.vue:886-894`) shows until jobs run
   again.

**Ten-second path (U2):** sidebar hint (only while stale) → `/admin/features`.

| State | Copy (en draft; all five locales in the same PR) |
| ----- | ---- |
| running | "Background jobs ran {time} ago." |
| failures | "Daily jobs ran {time} ago. 1 job failed: update check. The scheduler log has the details." |
| stale | "Background jobs stopped {time} ago. Scheduled tasks, reminders and clean-ups are not running. Restart the scheduler service." + docs link |
| never (U5) | "Background jobs have never run on this server." + primary action "How to start the scheduler" |
| error (U8) | "We could not load the background job status. Reload the page." |

**Five questions (U7):** runs on this server's scheduler service; the lanes
and jobs listed; stop or restart the scheduler service; started by the
install's compose file. Read-only surface, no undo control (U3 n/a).

**Exit bullets (§6 of the UX contract):**

1. J-SCH-1 walked: running → `docker compose stop scheduler` → stale card
   and sidebar hint → start → running (U10).
2. Stale state found in ten seconds via the sidebar hint (U2).
3. State-specific copy in all five locales (U3).
4. Never-ran, error and stale states present; nothing for non-admins (U5, U8, U11).
5. Light, dark, `.design-v2`, 320 px (U9).

---

## 4. Steps

| Step | Scope | Tests | State |
| ---- | ----- | ----- | ----- |
| **P1** | Dev fix (§2.4) | `make test-e2e` with the scheduler running | open |
| **P2** | §2.1–2.3; lock on `app:process-emails`; flags in `backend/.env.example` + `docs/ADMIN.md`; docs claims re-checked (`docs/PRICING_MAINTENANCE.md:139`, `docs/DEVELOPMENT.md:168`, `docs/ADMIN.md:695`, `docs/CONVERSATION_CONTINUITY.md:59`) | shell tests: every job present, lanes skip while alive, `TERM` stops lanes, claim exit codes; PHPUnit: claim atomicity, fixed-time due logic, status | open |
| **P3** | `scheduler` service on every node (image entrypoint kept, `SYNAPLAN_ROLE=scheduler`, env/volumes/extra_hosts like `worker`, `container-healthcheck`, `restart: unless-stopped`); the two flags; delete the 8 app cron wrappers + logrotate entries; watchdog calls `app:scheduler:status`; `DIGEST/ENABLED` per §0 #8; cluster doc: scheduler section, crontab cleanup, checks, rollback | `docker compose config -q`; first deploy watched | open |
| **P4** | Status API block (additive, admin-only, OpenAPI → `generate-schemas`) + card + sidebar hint (§3) | Vitest per state; one E2E on `/admin/features` | open |

---

## 5. Rollout (production, P3)

1. Confirm the running image contains P2 (`/api/v1/config/runtime`
   `build.version`). **An older image with schedulers on three nodes would
   run every daily job three times.**
2. Remove the eight app crontab lines.
3. Update the nodes one by one; check `docker compose ps` (scheduler
   healthy) and the scheduler log (first tick, claims).
4. Expect one daily run if the slot never ran: availability report, update
   check, the "✅ New-model check is active" post, docs sync.
5. Read-only checks: stale chats and stale desktop jobs drop to 0 within
   minutes.

Rollback: stop the scheduler services, restore the crontab lines from the
cluster doc.

---

## 6. Gates

- P1, P2, P4: `make ci-local`; `make test-e2e` (P1, P4); shell runtime tests;
  `make -C backend phpstan` unfiltered; mobile-impact check
  (`node scripts/mobile-impact.mjs --base origin/main --head HEAD`).
- P4: J-SCH-1 walked in the browser.
- P3: reviewed by the platform owner; no secret values in the diff.

## 7. Non-goals

- **Symfony Scheduler.** Considered: it adds a dependency, changes all four
  deploy targets, and its consumer handles messages one at a time, so it
  needs several consumer processes to solve the same blocking problem the
  lanes solve.
- Per-job history beyond the last run.
- Discord stale alert for self-host installs (they get the card and the
  container healthcheck).

## 8. Side finding (separate issue)

`app:sync-model-prices` writes BMODELS prices without refreshing
`__catalog_fingerprint`. `ModelSeeder` then treats the row as hand-edited and
preserves it (`ModelSeeder.php:168-170`), so later catalog price fixes for
synced rows never land, and a deliberate catalog override is overwritten by
LiteLLM the next day. The production cron runs this sync daily.

## 9. Review log (2026-10-01)

The first draft put production first on today's loop, one scheduler node
behind a compose profile, and a 24 h cadence. The review found: the loop is
sequential (blocking jitter, inline Saved Task runs, long digest); a restart
re-sends approval digest mails; `down` without the profile leaves the
container running; a wandering daily time is wrong for a user mail; IMAP and
Ollama calls can hang. Each finding changed a row in §0 or §2.

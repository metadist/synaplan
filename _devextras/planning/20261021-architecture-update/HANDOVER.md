# Handover — 2026-10-22

> **Update 2026-10-23:** AU2 ([#2626](https://github.com/metadist/synaplan/pull/2626))
> merged on 2026-10-22; AU5 is the next step in progress. The step log in
> [`STATUS.md`](./STATUS.md) is current; this file is the 2026-10-22 snapshot.

For the owner, so the track can continue from here. Step log:
[`STATUS.md`](./STATUS.md). Plan: [`00_master_plan.md`](./00_master_plan.md).

## Done

S0 has started. AU1 is on `main`. AU2 is an open pull request and is ready
to take over: review it and merge it. Nothing here was merged except AU1.

### AU1 — runtime benchmarks as a make target

Merged as [#2628](https://github.com/metadist/synaplan/pull/2628)
(`ad9a8ed79`).

- `make perf-runtime`. The scripts live in `_devextras/perf/runtime/`. The
  run starts worker-mode FrankenPHP on `:8090`, mints a temporary API key,
  prints a table, writes `backend/var/perf/runtime-<date>.json`, and exits
  non-zero when an in-force budget is missed.
- Cleanup always runs, including after a failure or an interrupt: the key
  is revoked, the admin session is logged out, the benchmark FrankenPHP
  stops, and `var/cache/prod` is removed only when this run warmed it. The
  target refuses to start unless the dev backend is `APP_ENV=dev` and no
  other run is active.
- No paid provider by default. `test-model` streams for about ten seconds
  through `SYNAPLAN_TEST_PROVIDER_CHUNK_MS` (default 50, cap 2000).
  `PerfTestProviderPass` keeps that mock out of a normal production
  container. `--with-providers` opts in.
- Two runs on the dev stack, about a minute apart:
  - Health p95, 1 client: 5.9 ms and 6.5 ms. The 15 ms budget is in force.
  - Health p95, 8 clients: about 36 ms. Recorded only.
  - API probe while 12 streams hold 8 workers: 10 s and 20 s. That wait is
    what AU3 removes. The 50 ms budget starts then.
  - Gateway time to first token, p50, `test-model`: 9.3 ms and 13.0 ms,
    about 40 % apart, outside the 20 % band.
- `app:perf:chat-stream` was skipped. The seeded admin's chat model is
  Anthropic.

### AU2 — the health check no longer starts a program

Open: [#2626](https://github.com/metadist/synaplan/pull/2626), branch
`fix/au2-health-ready`, class `backend-only`. Rebased onto `main` after
#2628. The only overlap was the step log and `services.yaml`; both sides
are kept.

- `WhisperService::isAvailable()` is cached: five minutes after a success,
  one minute after a failure. The cache key includes the host name, because
  the binary and the models live on each server. A cache outage still runs
  the probe.
- New `GET /api/health/ready`. It checks Redis only: no provider status, no
  process, no database query. The address is on the setup-lockdown
  allowlist, so a fresh install does not get `503 SETUP_REQUIRED` and a
  database outage does not turn this probe into a 500.
- The regression was red on the parent (the binary started twice) and green
  on the branch. The wall-clock assertion was removed because it was flaky.
- Local gate, not one `make ci-local`: PHPStan (`src/` and `tests/`),
  deptrac, php-cs-fixer, and the health tests. A full `docker compose run`
  of PHPUnit exits 139 around test 740 on this machine; that is the local
  stack. CI on the pull request is the proof. Required before merge: green
  checks and a review. Merge stays with the owner.

## Not done (S0)

- **AU3.** Two worker pools (`streams` and `api`) and an immediate "server
  busy" body. §0 #13 and #14 have been ticked since AU0b, so the step is
  free. Before rollout, check Galera `max_connections` (M2) and start with
  one node.
- **AU4.** Chat and the widget say the server is busy, retry, and put the
  text back in the composer. UX, all five locales.
- **AU5.** A waiting stream gives its database connection back. Depends only
  on AU1, so it is free.
- **AU6–AU8.** Watchdog, the capacity page, Kubernetes probes in
  `synaplan-charts`.
- **M1–M6** on production and `vultr-cluster` are still open. The numbers
  above are from the dev stack.
- At most two unmerged step branches. After #2626 merges, AU3 or AU5 is the
  next step.

## Second opinion

A separate review of the plan, checked against the code on 2026-10-22.
The plan is sound: the facts hold, the measurements are clean, and the
order is right. The main objection is that S5, the Go watchdog plus the
PHP stream pump, is probably more architecture than a mid-size customer
needs. Do S0, measure it in production, and decide on S5 after that.

Confirmed in the code:

- `AiFacade` is 1,747 lines, `GatewayToolLoop` 1,432, `MessagesGateway`
  1,051.
- `ChatToolLoop` and `McpFetchRunner` do not call `ToolExecutionGate`.
  Approvals and the audit log are skipped there.
- `/api/health` used to call `WhisperService::isAvailable()` on every
  request. AU2 caches that call; it is not on `main` until #2626 merges.
- `UsageRecorded` is dispatched and has no listener.
- The worker template is `num {$FRANKENPHP_WORKER_NUM:16}`. One PHP worker
  per stream is the limit.

What holds up:

- S0 is the real gain, cheap and immediate: separate pools (measured: an
  11 s wait against 30 ms for the API), a fast "server busy" answer, the
  health check, and Kubernetes probes that stop treating a full pod as
  ready.
- S3 is well evidenced. Adding a provider has touched 41 to 72 files.
- S4 closes real approval and audit gaps.
- The loop is sound: characterization first, default-off flags, then shadow
  mode. S5 has a rollback and a go/no-go at the AU28 spike.

Objections, and the recommendation that follows:

1. **S5 may be oversized.** The plan rejects "just more workers" because of
   database connections. AU5 releases the connection of a waiting stream.
   After that, one more worker costs about 3.3 MB. A hundred or more stream
   workers per server covers the plan's own peak of 40 to 55 turns, without
   a Go binary, a pump role, Redis streams, an HMAC attach token, a reaper,
   or drain logic. Count that option ("AU5 plus large stream pools") in the
   plan as an equal alternative, not only as the fallback after a no-go.
2. **The track is a year of work:** 36 steps and about 5,000 lines of plan.
   S1 and S2 are risky and show little to a person using the product, and
   they can crowd out the steps that do.
3. **The S4 gate gap is a security bug.** `ChatToolLoop` and
   `McpFetchRunner` skip the approval gate. Pull that out as a small fix
   now, instead of waiting through S0–S3.
4. **The numbers are from a laptop.** The design conclusions travel; the
   absolute figures do not. The plan already says M1–M6 are open. So: S0
   and AU6 first, then decide with production data.
5. **Overlap with the Notion work.** [#2625](https://github.com/metadist/synaplan/pull/2625)
   (`feat/notion-chat`, open) changes `McpFetchRunner`, `McpActionRunner`,
   `ChatRunner`, and the runner tests. AU24 and AU25 rebuild that same
   code. Merge #2625 before those steps, and before the early gate fix.

Reading: points 1 and 3 are fair. Suggested order: merge #2626, then AU5
and AU3, then the gate fix. Decide S5 when AU6 has production numbers.

## Taking over #2626

- `make perf-runtime` measures the code the running dev stack has mounted.
  `TestProviderChunkDelay`, `PerfTestProviderPass`, the kernel line, and
  the `TestProvider` definition are on `main` with AU1.
- The plan calls AU1 `no-app-impact`. The diff touches `backend/**`, so
  `mobile-impact` classifies it as `backend-only`. AU2 is `backend-only`.

# S0 — Protect and measure

Master plan §5 rows AU1–AU8. Contracts: [`03_contracts.md`](./03_contracts.md)
§5, §6, §9; capacity design: [`04_capacity_and_kubernetes.md`](./04_capacity_and_kubernetes.md).
Loop: [`01_vibe_coding_loop.md`](./01_vibe_coding_loop.md).

**Why first:** today one production web node serves 24 AI streams, and while
they run every other request waits (`02` §2: a 15 ms request waited 10.85 s).
S0 removes the stall without changing how streams work, makes the limit
visible, and builds the measuring tools every later sprint is judged by.

Each step has a **vibe brief**: paste it into the coding agent as the task
after pointing it at `00_master_plan.md`. The exit criteria are the
definition of done; the agent reports each one with the command it ran.

---

## AU1 — Runtime benchmarks as make targets (no-app-impact)

### Goal

- Do: move `spike/runtime/*.py` and `worker-mode.Caddyfile` to
  `_devextras/perf/runtime/`; `run.sh` that warms the prod cache in the dev
  backend, starts the worker-mode FrankenPHP on `:8090` (admin `:2020`),
  creates a temporary API key for the seeded admin, runs the cases below,
  revokes the key, stops the instance and removes `var/cache/prod` — also on
  failure (`trap`).
- Do: a deterministic slow stream without a provider:
  `SYNAPLAN_TEST_PROVIDER_CHUNK_MS` read by `TestProvider` (default 50, as
  today), so `test-model` streams for ~10 s at 400.
- Do: `budgets.json` with the master plan §6 rows; `make perf-runtime` prints
  a table, writes `var/perf/runtime-<date>.json`, exits non-zero on a breach.
- Cases: health p95 (1 and 8 clients), runtime-config throughput, API probe
  while 12 slow streams hold 8 workers, gateway TTFB overhead against
  `test-model` (no provider cost), `app:perf:chat-stream` phases.
- Do not: call a paid provider by default (`--with-providers` opts in and
  reads keys from `backend/.env`); run against anything but the dev stack.

### Files

Reads: `spike/README.md`, `02` §2–§4. Touches: `_devextras/perf/runtime/*`
(new), `Makefile` (one target), `backend/src/AI/Provider/TestProvider.php` (the
delay knob), `backend/.env.example`, `docs/DEVELOPMENT.md` (one paragraph).

### Vibe brief

> Read `02_measurements.md` and `spike/README.md`. Implement AU1: promote the
> spike scripts into `_devextras/perf/runtime/` and add `make perf-runtime`
> exactly as the Goal says. The harness must leave the stack as it found it,
> also when a case fails: no running spike FrankenPHP, no `var/cache/prod`, no
> API key. Add the `SYNAPLAN_TEST_PROVIDER_CHUNK_MS` knob to `TestProvider`
> with a unit test that the default stays 50 ms. Run it twice; enter both
> tables in STATUS as the track's baseline. `make ci-local`.

### Sprint test

- Proof: two runs within 20 % of each other on every row; a forced breach
  (budget set to 1 ms) exits non-zero.
- Regression: `TestProvider` unit tests and every snapshot unchanged with the
  knob unset.
- Performance: the baseline itself, in STATUS.

### Exit criteria

1. `make perf-runtime` green on the dev stack; table in STATUS.
2. After the run: `ls backend/var/cache` shows no `prod`, the admin has no
   API key, only PID 1 runs FrankenPHP in the backend container.
3. `make ci-local` green.

---

## AU2 — The health check no longer starts a program on every call (backend-only)

### Goal

- Do: `WhisperService::isAvailable()` caches its probe (the `--help` run and
  the model scan) for 5 minutes in the app cache; a failed probe for 60 s.
- Do: `GET /api/health/ready`: Redis ping and a constant answer, no provider
  status, no process, no database query — the readiness target for the
  watchdog (AU6) and the Kubernetes startup probe (AU8). Full OpenAPI
  annotations; `make -C frontend generate-schemas` (zero diff expected, no
  frontend consumer).
- Do not: change the body of `GET /api/health`.

### Vibe brief

> Read `02_measurements.md` §2.6. Implement AU2 as a `fix(health)`. Write
> the regression test first: two `isAvailable()` calls within the cache
> lifetime start the process once (mock `Process` factory or a counting
> binary path); run it on the parent, quote the failure in STATUS. Add
> `/api/health/ready` with a functional test (200, under 10 ms in the test
> kernel, no process started). Then `make perf-runtime`: health p95 with 8
> clients must drop from ≈ 115–140 ms to under 15 ms, and its throughput must
> grow with the worker count (today ≈ 80–110 req/s whatever the client count). `make ci-local`, `make test-e2e`.

### Exit criteria

1. Regression test red on the parent, green on the branch.
2. `make perf-runtime`: `/api/health` 8 clients p95 ≤ 15 ms; `/api/health/ready` p95 ≤ 5 ms.
3. `HealthControllerTest` unchanged and green.
4. `make ci-local` and `make test-e2e` green.

---

## AU3 — AI streams get their own PHP pool and a full server answers at once (no-app-impact)

**Ask first:** §0 #13 (Docker), #14.

### Goal

- Do: `_docker/backend/synaplan-worker-mode.Caddyfile` with two workers,
  `streams` and `api`, the `match` list of `03` §5, `num_threads`,
  `max_threads`, `max_wait_time` as env placeholders. Verify placement with
  `frankenphp validate` in the image.
- Do: `_docker/backend/Caddyfile`: global `metrics`; `handle_errors` for 503
  on the long routes with the three bodies of `03` §5 and `Retry-After: 5`.
- Do: the new variables in `docker-compose.yml`, `deploy/compose.yaml`, the
  quickstart / casaos / umbrel / elestio compose files that set
  `FRANKENPHP_WORKER_NUM`, `backend/.env.example`, `docs/CONFIGURATION.md`
  and a short "Two worker pools" section in `docs/ARCHITECTURE.md`.
- Do: a Playwright `@ci` spec `busy-server.spec.ts` against a dev profile with
  1 stream worker and `max_wait_time 1s`: two parallel slow `test-model`
  streams, the second gets 503 with the `server_busy` body; a parallel
  `/api/v1/config/runtime` answers.
- Do: `tests/Architecture/LongRouteListTest.php` (`03` §5): every route whose
  controller returns a `StreamedResponse` is covered by a `match` pattern or
  listed as short (the usage export); a new streaming route fails it.
- Do not: change the base image; change dev classic mode (dev keeps no
  worker mode); touch PHP code.

### Vibe brief

> Read `02` §4.1–§4.2, `03` §5 and `spike/runtime/split-pools.Caddyfile`.
> Implement AU3. First prove the Caddyfile in the production image: build it
> (`make test-e2e-full` builds the test image; or `docker build` the backend
> prod stage), run `frankenphp validate`, start it with 2 + 2 workers and run
> `_devextras/perf/runtime/run.sh --case pool-isolation` (8 slow streams, the
> API probe p95 must stay ≤ 50 ms; with `FRANKENPHP_MAX_WAIT_TIME=2s` the
> excess streams get the `server_busy` body within 3 s). Repeat the case with
> the stream pool filled by dictation session streams
> (`/v1/audio/transcriptions/sessions/{id}/stream`, a short `timeout`) and by
> `/api/v1/tts/stream`: the API probe must hold the same budget. Probes count
> only HTTP 200 (`saturate.py` aborts on an unhealthy baseline). Check whether
> nested env placeholders work; if not, default the stream pool in the
> entrypoint (`_docker/backend/docker-entrypoint.sh`) instead. Then the
> compose and docs changes. `make ci-local`, `make test-e2e`,
> `make test-e2e-full`.

### Sprint test

- Proof: the pool-isolation case (probe p95 ≤ 50 ms while the stream pool is
  full) and the 503 body per route family, both in STATUS.
- Regression: `make test-e2e-full` on the production test image; with
  `FRANKENPHP_STREAM_WORKER_NUM` unset the stream pool equals today's worker
  count.
- Performance: `make perf-runtime` before / after.

### Exit criteria

1. `frankenphp validate` passes in the image; metrics show
   `frankenphp_total_workers{worker="…streams"}` and `{…api}`.
2. Pool isolation and fast-busy cases green, numbers in STATUS.
3. `busy-server.spec.ts` green in CI.
4. Rollout note for `synaplan-platform`: Galera `max_connections` checked
   (M2) before the stream pool is added on the web nodes; one node first.

---

## AU4 — A busy server says so and retries instead of hanging (ota-candidate)

### User-flow

Journey **J-AU-1**. Ben sends a message while every stream slot of his
server is taken. Today he waits up to the full stream time, then reads
"Connection failed (HTTP 503)" (`frontend/src/services/api/chatApi.ts:551`, a
hardcoded English string with a status code — a U8 breach).

```text
┌ Ben's message ──────────────────────────────────────────────┐
│ Summarise the attached report                               │
└─────────────────────────────────────────────────────────────┘
  ◌ Synaplan is busy — your answer starts in a moment.         (status line, 1st–3rd try)
  … then the answer streams as usual.

  after three tries:
  ⚠ Synaplan is busy right now. Your message was not sent — it is back in
    the input box. [ Try again ]
```

The widget shows the same two sentences in its own bubble style.

A refused request never reached PHP, so nothing was stored
(`StreamTurnPreparer::createIncoming()` runs only after admission). The copy
therefore never claims the message is saved. Instead the text goes back into
the composer, where the existing per-chat draft persistence
(`useInputPersistence` / `useAutoPersist`, off in incognito) keeps it across a
reload; the unsent bubble is removed so the history shows only what the server
has.

### Goal

- Do: the chat stream transport (`chatApi.ts` POST SSE path) and the widget
  message call (`widgetsApi.ts:293`) recognise `503` + `server_busy`, retry
  after `Retry-After` (default 5 s) up to three times with jitter, report a
  `busy` status to the stream handler, then end with the honest sentence, put
  the text back into the composer (persisted as the chat's draft) and offer
  **Try again**, which sends it again (no duplicate user message).
- Do: every other non-OK status keeps today's path but loses the status code
  in copy: one sentence per class (auth, limit, server) in five locales.
- Do: unit tests in `frontend/tests/unit/services/` for the retry schedule
  and the copy keys; the `busy-server.spec.ts` of AU3 extended to see the
  status line and the final sentence.
- Do not: retry on any status but 503 `server_busy`; change SSE event names.

### Vibe brief

> Read master plan §3 J-AU-1 and this step. Walk the wireframe and the copy
> first (U1): enter the five locale strings and a screenshot of the copy in
> the pull request before the component code. Implement the retry in the
> transport, not in `ChatView.vue`. Tests first: the schedule (5 s, then
> `Retry-After`, max 3), no retry on 500, the terminal copy. Walk J-AU-1 in
> the browser against the AU3 dev profile (1 stream worker), light and dark,
> 320 px, web chat and widget; enter the walk in STATUS.
> `make ci-local`, `make test-e2e`, `make -C frontend test-e2e-layout`.

### Exit criteria (with the UX contract §6 bullets)

1. J-AU-1 walked in the browser for web chat and widget (U10).
2. Findability: the status line appears where the answer will appear, within
   one second of the 503 (U2).
3. Consequence copy says what did not happen ("your message was not sent")
   and where the text is, in all five locales; a reload after the final
   refusal still shows the text in the composer (not in incognito) (U3, U8).
4. Empty / error / flag-off: with one free slot nothing changes; on 500 the
   new server sentence; no status code anywhere (U5, U8, U11).
5. Dark + V2 + 320 px (U9).
6. `make ci-local`, `make test-e2e`, layout suite green.

---

## AU5 — A waiting stream gives its database connection back (backend-only)

### Goal

- Do: after the turn is prepared and before the provider stream starts, the
  stream path closes the Doctrine connection when no transaction is active
  (`Connection::close()`; DBAL reconnects on the next query), in
  `ChatStreamOrchestrator`, the widget stream and both gateway stream paths.
- Do: a counter of closes and reconnects in the request timeline later
  (AU13); now a debug log line.
- Do not: close inside a transaction; touch the buffered paths.

### Vibe brief

> Implement AU5 as a `fix(chat)`. Regression test first: a functional test
> that streams a `test-model` answer and asserts, from inside the provider
> callback, that the connection is closed (`isConnected()` false) and that
> the reply is stored afterwards. Run it on the parent (connected: true),
> quote it. Then measure with the worker-mode harness: 8 workers, 8 slow
> streams, `information_schema.PROCESSLIST` count from the backend address
> during the streams must drop from 8 to ≈ 0. `StreamTranscriptCharacterizationTest`
> unchanged. `make ci-local`, `make test-e2e`.

### Exit criteria

1. Regression test red on the parent, green on the branch.
2. Connections held during 8 slow streams: before / after in STATUS.
3. Every stream transcript snapshot unchanged; chat, widget and gateway E2E
   specs green.

---

## AU6 — A watchdog on every server reports its capacity (no-app-impact)

**Ask first:** §0 #15 (Go dependency `github.com/redis/go-redis/v9`, Docker).

### Goal

- Do: `sidecars/synaplan-watchdog/` (Go, Makefile, `go test -race`, a size
  budget check) with the agent loop and `:8098` endpoints of `03` §6 — no
  relay yet.
- Do: build it in the backend image (a Go builder stage like
  `sidecars/synaplan-compute`); the `web` role starts it next to FrankenPHP
  in `_docker/backend/lib/container-runtime.sh` and restarts it when it exits;
  `SYNAPLAN_WATCHDOG=0` skips it.
- Do: snapshots and aggregates in Redis exactly as `04` §2 and §7.
- Do: metrics passthrough and `synaplan_server_info`,
  `synaplan_stream_slots_*` (`03` §9).
- Do not: listen on a public interface for anything but `:8098`; read any
  PHP state except through FrankenPHP's admin API and Redis; parse AI formats.

### Vibe brief

> Read `03` §6, §9 and `04` §2, §5.2, §7. Implement AU6. Go tests first with
> a fake admin API (recorded `metrics` and `threads` responses from the
> 2026-10-21 spike) and an in-memory fake behind a small store interface (no
> extra test dependency): snapshot shape, TTLs, aggregates, `/ready` false
> when the PHP probe is late. One integration test against the dev stack's
> Redis, skipped when it is not reachable. Then the image and role changes. Start the dev stack, check
> `docker compose exec backend curl -s localhost:8098/metrics | grep synaplan_`
> and the Redis keys; stop the watchdog process and see the role restart it.
> `make -C sidecars/synaplan-watchdog test`, `make ci-local`,
> `make test-e2e-full`.

### Exit criteria

1. Watchdog tests green with `-race`; size budget respected.
2. In the dev stack and in the production test image: `:8098/live`,
   `/ready`, `/metrics` answer; Redis has `capacity:server:*`.
3. Killing the watchdog process brings it back within 5 s; FrankenPHP keeps
   serving meanwhile.
4. `SYNAPLAN_WATCHDOG=0`: no process, no keys.
5. Rollout: one web node, read its snapshot for a day (M1), then the others.

---

## AU7 — Capacity of every server on the system status page (ota-candidate)

### User-flow

Journey **J-AU-2**: Olga, administrator, opens Admin → System → Capacity,
reads how loaded each server is and whether one needs help. Wireframe, copy,
five questions and states: `04` §4.1.

### Goal

- Do: `GET /api/v1/admin/capacity/servers` (admin only; reads Redis only)
  with full OpenAPI annotations → Zod schemas; a `CapacityService` in
  `App\Service\Observability\` and a thin controller.
- Do: `views/CapacityView.vue` under `/admin/capacity`, nav child of
  Admin → System next to Features, absent and 404 when `SYNAPLAN_WATCHDOG=0`
  everywhere (U11). Components under 300 lines, house tokens, five locales.
- Do not: put the data into runtime config (store-required); poll faster than
  every 10 s; show anything a non-admin could see.

### Vibe brief

> Read `04` §4.1 and walk the wireframe and the copy with the owner first
> (U1): the five locale strings and the five-question answers in the pull
> request before the Vue file. Backend first with tests: the shape from
> recorded snapshots, the 403 for non-admins, the empty and Redis-down
> states. Then the view, its unit tests and a Playwright `@ci` spec that
> seeds two snapshots in Redis and finds both servers. Walk J-AU-2 in the
> browser, light / dark / 320 px, enter it in STATUS.
> `make ci-local`, `make test-e2e`, layout suite.

### Exit criteria (with the UX contract §6 bullets)

1. J-AU-2 walked (U10).
2. Findability: Capacity is one click from Admin → System, next to Features (U2, U6).
3. Consequence copy for the sizing hint and the "stopped reporting" state in five locales (U3).
4. Empty, Redis-down, stopped-reporting and flag-off states (U5, U8, U11).
5. Dark + V2 + 320 px; no banned words (U9, UX contract §8).
6. `make ci-local`, `make test-e2e`, layout suite green.

---

## AU8 — Probes follow PHP capacity, metrics are scraped (in `synaplan-charts`, no-app-impact)

### Goal

- Do: in `synaplan-charts/charts/synaplan`: probes, Downward API env,
  `metrics.serviceMonitor` / `podMonitor`, the Grafana dashboard JSON, the
  `FRANKENPHP_*` and `SYNAPLAN_WATCHDOG` values, documented resource starting
  values — the "After" column of `04` §5.1 except autoscaling and drain.
- Do: `README.md.gotmpl` updated, `make docs`.
- Do not: enable the HPA by default; change model deployments (that repo's
  AGENTS.md: model names are immutable).

### Vibe brief

> In `synaplan-charts`, read its `AGENTS.md`. Implement AU8 on a feature
> branch: values, templates, dashboard, docs. `make all` green. Install into
> the `vultr-cluster` load-test namespace (not production), run
> `saturate.py` against it (M6), and enter the pod tables and a screenshot of
> the dashboard in this track's STATUS. Pull request in `synaplan-charts`,
> Copilot, CI as `01` §9–§10.

### Exit criteria

1. `make all` green in `synaplan-charts`; CI green there.
2. On the load-test cluster: a pod whose stream pool is saturated stays
   live, turns not-ready only when its PHP probe misses for 2 s, and comes
   back when the streams end (observed, in STATUS).
3. M6 tables in STATUS.

---

## S0 exit

- AU1–AU8 merged; `make perf-runtime` baseline and after-values in STATUS.
- J-AU-1 and J-AU-2 walked.
- One production web node ran a week with two pools and the watchdog; its
  capacity page and `queue_depth` entered in STATUS; then all three.

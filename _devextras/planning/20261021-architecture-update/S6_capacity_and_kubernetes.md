# S6 — Capacity, Kubernetes, sizing

Master plan §5 rows AU33–AU35 (the first half of the capacity part shipped in
S0: AU6–AU8). Design: [`04_capacity_and_kubernetes.md`](./04_capacity_and_kubernetes.md).
Loop: [`01_vibe_coding_loop.md`](./01_vibe_coding_loop.md).

---

## AU33 — Requests, AI calls, tools and outbound traffic on the capacity page (ota-candidate)

### User-flow

Journeys **J-AU-2** (second half) and **J-AU-3**.

- J-AU-2: Olga sees that answers got slower since Tuesday, opens
  **Requests**, sorts by p95, opens the slowest chat stream and sees that its
  time went to the AI (first token 7 s from one provider); she opens **AI
  calls** and finds that provider's first-token p95 doubled.
- J-AU-3: Olga sets the company proxy (`HTTPS_PROXY`, `NO_PROXY`) and
  `EGRESS.MODE=report`, opens **Outbound** a week later, sees two destinations
  marked "would be refused" (a web-search plug and an MCP server), clicks
  **Allow** on both, switches to `enforce`, and sees no refusal the next day.

Wireframes and the tab content: `04` §4.2.

### Goal

- Do: the four tabs over the AU13 and AU15 endpoints (plus
  `GET /api/v1/admin/capacity/tools` and `…/outbound`, and
  `POST|DELETE /api/v1/admin/egress/allow` for the Allow / Remove buttons,
  with OpenAPI annotations and Zod schemas).
- Do: request detail panel (the parts bar, the AI and tool calls, the
  outbound calls; the request id copies to the clipboard for the admin logs
  API).
- Do: five locales; banned words checked; components under 300 lines.
- Do not: show content, prompts, arguments, or a user's name in a list.

### Vibe brief

> Walk both journeys on paper with the owner first: wireframes of `04` §4.2,
> copy in five locales, the five-question answers (U1, U7). Backend endpoints
> with tests (admin only, Redis only, bounded responses). Then the tabs, unit
> tests for the formatting (durations, percentiles, the parts bar), and a
> Playwright `@ci` spec that seeds timeline records and finds the slowest
> request and a would-be-refused destination; Allow then Remove. Walk J-AU-2
> and J-AU-3, light / dark / 320 px, enter both in STATUS.
> `make ci-local`, `make test-e2e`, layout suite.

### Exit criteria (with the UX contract §6 bullets)

1. J-AU-2 and J-AU-3 walked (U10).
2. Findability: the slowest request of the last hour in ten seconds from
   Admin → System → Capacity (U2).
3. Allow / Remove say what happens to outbound traffic, in five locales (U3);
   undo is the same row (UX bar 5).
4. Empty states per tab (one sentence, one action); Redis down; egress
   `off` ⇒ the Outbound tab says so and links to the setting (U5, U8, U11).
5. Dark + V2 + 320 px; no banned words (U9).

---

## AU34 — Scale on held streams and drain before a restart (in `synaplan-charts`, no-app-impact)

### Goal

- Do: the remaining "After" rows of `04` §5.1: HPA on
  `synaplan_stream_slots_busy_ratio` (KEDA `prometheus` trigger or
  prometheus-adapter, values for both, off by default), scale-down
  stabilisation, `minReplicas: 2`, the `pump` Deployment scaled on
  `synaplan_pump_streams`, `PodDisruptionBudget`s, `preStop` drain and the
  grace period.
- Do: `README.md.gotmpl` with a "Scaling Synaplan" section; `make docs`.

### Vibe brief

> In `synaplan-charts`: implement AU34, `make all`. On the `vultr-cluster`
> load-test namespace: drive `saturate.py` until the ratio passes the target
> and watch a second web pod start; roll the web Deployment during 200
> streams and count failed streams (target 0: clients re-attach). Tables and
> the Grafana screenshot in this track's STATUS. Pull request in
> `synaplan-charts`, Copilot, CI.

### Exit criteria

1. `make all` and CI green in `synaplan-charts`.
2. Scale-out observed on the load-test cluster; rolling restart with 0
   failed streams (STATUS).

---

## AU35 — Capacity and sizing guide with measured numbers (no-app-impact)

### Goal

- Do: `docs/CAPACITY.md`: what limits a server (before and after S5), the
  production figures from AU6 snapshots (M1) and the load-test cluster (M6),
  the sizing method of `02` §5 with a worked example, how to read the
  capacity page, the knobs (`FRANKENPHP_*`, `WATCHDOG_STREAM_SLOTS`, pump
  processes), Kubernetes recipes, and the answer to the architect's question
  in its first paragraph.
- Do: link it from `docs/ARCHITECTURE.md`, `docs/INSTALLATION.md`, the
  capacity page's "Open the sizing guide" and `synaplan-charts`' README.
- Do: archive this plan with a dated note when every step is merged (track
  exit, master plan §6).

### Exit criteria

1. `docs/CAPACITY.md` merged with production numbers.
2. Track exit of master plan §6 ticked in STATUS; plan moved to
   `2026-archive/`.

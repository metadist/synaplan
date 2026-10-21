# Capacity, the request timeline and Kubernetes

The owner's extra part: *every request, of every kind, measured from start to
end and shown in a clear view for DevOps decisions — how strained one server
is; on Kubernetes how big the load, the speed and the memory are.* This file
is the design; the steps are AU6, AU7, AU8 (S0), AU13 (S1) and AU33–AU35 (S6).

---

## 1. The questions the view answers

| Decision | Question | Answered by |
| -------- | -------- | ----------- |
| Add a server? | How many AI streams does each server hold, against how many it can? How often did someone get "busy"? | Servers tab: stream slots busy / total, peak today, busy answers today (AU7) |
| Is something slow? | Which kind of request got slower, and where did the time go: waiting for PHP, PHP itself, the AI, tools, outbound calls, the database? | Requests tab: p50 / p95 per route class, the slowest requests with their parts (AU33) |
| Is a provider the problem? | Time to first token and failures per provider and model, today against last week | AI calls tab (AU33) |
| Is memory or CPU the limit? | RSS and CPU per server against its limit (cgroup or host) | Servers tab (AU7) |
| What leaves the installation? | Which destinations, how often, what would be refused | Outbound tab (AU33, with AU15) |
| How big must a Kubernetes pod be? | p95 memory and CPU per pod over a week, against requests and limits | Servers tab, technical row; `docs/CAPACITY.md` (AU35) |
| Can it autoscale? | Busy stream slots ratio per pod | `synaplan_stream_slots_busy_ratio` (AU34) |

---

## 2. Where the data comes from

```text
each server                                              shared
-----------                                              ------
FrankenPHP ── :2019/metrics, /frankenphp/threads ─┐
cgroup / /proc / Kubernetes env ──────────────────┼─► watchdog agent (every 5 s) ─► Redis
PHP request timeline (kernel.terminate) ─► Redis ─┘        │                         capacity:server:{id}     (TTL 20 s)
                                                           │                         capacity:agg:{id}:{min}  (24 h)
                                                           │                         timeline ring            (10,000 / 24 h)
                                                           └─► :8098/metrics (Prometheus, optional)

Admin → System → Capacity ─► GET /api/v1/admin/capacity/* (PHP, reads Redis only, one short request)
```

- **No PHP request reads another server.** Each watchdog reports its own
  server; the page reads Redis. On three web nodes with a shared Redis, the
  page shows all three.
- **No Prometheus needed.** Operators who run Prometheus scrape `:8098/metrics`
  on each server; the in-app page works without it.
- **Measuring costs ≤ 0.3 ms per request** (one Redis pipeline in
  `kernel.terminate`, after the response is sent). `make perf-runtime` checks it
  (AU13).
- **Aggregates store distributions, not averages** (corrected in AU0b):
  a one-minute aggregate carries a fixed-bucket duration histogram per
  series (the bucket bounds are part of AU13's contract), so the p50 /
  p95 / p99 of §4.2 are computed from buckets when minutes are merged —
  percentiles cannot be derived from stored percentiles. The watchdog
  rolls one-minute aggregates into the fifteen-minute aggregates of §7
  before the one-minute keys expire (AU13 names the roll-up in its
  contract; AU33's tests seed buckets, not percentile values).

---

## 3. The request timeline — start to end

One record per request, written in `kernel.terminate` (and for streams when
the run ends), from an allow-list like the event ring (`docs/OBSERVABILITY.md`):

| Field | Meaning |
| ----- | ------- |
| `id` | `X-Request-Id` |
| `ts`, `server` | start time, server id |
| `route_class` | one of a fixed list: `chat_stream`, `widget_message`, `openai_api`, `messages_api`, `files`, `search`, `admin`, `auth`, `config`, `mcp`, `webhook`, `media`, `other` (from the route name, never the path) |
| `status_class` | `2xx`, `4xx`, `5xx`, `busy` |
| `total_ms` | first byte in to last byte out (streams: to the terminal frame) |
| `php_ms` | time a PHP worker spent on it (streams: summed over the short steps after S5) |
| `wait_ms` | time spent waiting for a worker (from the watchdog when it relays; else not known and left out) |
| `db_ms`, `db_queries` | Doctrine middleware totals |
| `ai[]` | per AI call: `provider`, `model` (catalog id), `capability`, `source`, `ttft_ms`, `duration_ms`, `tokens_in`, `tokens_out`, `cost`, `outcome`, `hooks_ms` |
| `tools[]` | per tool call: `source`, `kind`, `outcome`, `duration_ms` |
| `egress[]` | per outbound call: `destination` (`host:port` of a rule or `other`), `status_class`, `duration_ms` |
| `user_id` | pseudonymous, as in the event ring; never shown in a list, only in a single request's detail |

Never in the record: prompts, answers, tool arguments or results, file names,
URLs with ids, emails, keys. A test feeds a request with secrets and content
through the recorder and asserts none of it reaches Redis (the event-ring
test pattern).

The record's `id` is the same correlation id the event ring uses, so one id
finds both the timing and the warnings.

---

## 4. The capacity page

`/admin/capacity`, Admin → System, next to Features, administrators only
(§0 #20). Tabs: **Servers** (AU7), then **Requests**, **AI calls**, **Tools**,
**Outbound** (AU33).

### 4.1 Servers (AU7)

```text
Admin › System › Capacity              [ Servers ]  Requests   AI calls   Tools   Outbound

  3 servers · updated 4 s ago                                    [ Open the sizing guide ]

  ┌ web1 ───────────────────────────────────────────────────────────────────────┐
  │ AI answers      ██████████████░░░░░░  14 of 24 at once   peak today 22 (11:40) │
  │ Other requests  ██░░░░░░░░░░░░░░░░░░   3 of 24           waiting now 0         │
  │ Memory          █████████░░░░░░░░░░░  2.1 of 6.0 GB                           │
  │ Processor       ███░░░░░░░░░░░░░░░░░  14 % of 8 cores                         │
  │ "Busy" answers today: 0          Answer time p95, last hour: 9.8 s             │
  │ ▸ Technical details                                                          │
  │     Docker · FrankenPHP 1.12.6 · 24 stream + 24 API workers · wait limit 15 s  │
  │     Database connections held: 31 · Reported by the watchdog 0.1.0          │
  └──────────────────────────────────────────────────────────────────────────────┘
  ┌ web2 … ┐  ┌ web3 … ┐

  Last 24 hours   AI answers at once (all servers)   ▁▂▂▃▅▇█▇▅▃▂▁   peak 61 at 11:40
```

On Kubernetes the technical row reads: *Kubernetes pod synaplan-web-7d9f-x2
in namespace synaplan · memory limit 3 GiB, request 1 GiB · processor request
1 core, no limit*.

**Sizing hint**, one sentence under a server whose peak reached 80 % of its
slots in the last 24 h: *"At its peak today web1 answered 22 of the 24 AI
requests it can hold at once. Add a server or raise its AI answer slots — the
sizing guide shows how."* Nothing else on the page is advice.

**The five questions on the surface (U7):**

| Question | Answer on the page |
| -------- | ------------------ |
| Who owns this? | "Every server of this installation reports here." (header line) |
| Who else? | "Only administrators see this page." (footer) |
| What can they do? | Read; open the sizing guide; open one request's detail (AU33). Nothing on the page changes a server |
| How do I stop it? | "A server stops reporting when its watchdog is turned off (SYNAPLAN_WATCHDOG=0)." (technical row) |
| Where does it come from? | "Each server measures itself every 5 seconds and keeps 24 hours." (header tooltip) |

**States:**

| State | Shows |
| ----- | ----- |
| Watchdog off on every server (`SYNAPLAN_WATCHDOG=0`) | No nav entry; `/admin/capacity` 404s (U11) |
| On, no report yet | "No server has reported yet — servers report within a minute after they start." [ Check again ] |
| A server stopped reporting | Its card greys out with "Last report 3 minutes ago — the server may be down or restarting." |
| Redis down | "Capacity cannot be read right now because the shared cache does not answer. The servers keep working." [ Check again ] |

Copy: "server", "AI answers", "other requests", "memory", "processor".
Never "node", "container", "tenant", "sandbox" (UX contract §8). Five
locales in the same pull request.

### 4.2 Requests, AI calls, Tools, Outbound (AU33)

| Tab | Rows | Detail |
| --- | ---- | ------ |
| Requests | per route class: per minute, p50 / p95 / p99, errors, "busy" share, waited for PHP | "Slowest in the last hour": id, kind, total, a bar split into waiting · PHP · AI · tools · outbound · database; one click opens the request with its parts |
| AI calls | per provider and model: calls, first-token p50 / p95, duration p95, failures, tokens, cost today; per source (chat, widget, gateways, sorter, memories, search, media) | the hooks' share of the time |
| Tools | per source and kind: calls, done / refused / waiting for approval / failed, p95 | link to Approvals for "waiting" |
| Outbound | per destination: requests, failures, p95; in report mode "would be refused" with [ Allow ] | [ Allow ] adds the rule; the row shows [ Remove ] at once (U3, undo is a click) |

---

## 5. Kubernetes

`synaplan-charts/charts/synaplan`, two steps: AU8 (probes, metrics,
resources) and AU34 (autoscaling, drain). Each follows that repository's
`AGENTS.md` (`make docs`, `make lint`, `make template`, `make all`).

### 5.1 What the chart does today and what changes

| Today | After |
| ----- | ----- |
| liveness and readiness `GET /` (static file, never touches PHP) | liveness `:8098/live`, readiness `:8098/ready` (PHP answered within 2 s, Redis up, not draining), startup `GET /api/health/ready` with a long `failureThreshold` for migrations (AU8) |
| `resources: {}` | documented starting values, measured in M6 on `vultr-cluster` and replaced by production figures in AU35 (proposal: memory request 1 GiB, limit 3 GiB; processor request 1, no processor limit because throttling shows up as latency) |
| HPA on CPU 80 %, off | HPA on `synaplan_stream_slots_busy_ratio` (target 0.6) via KEDA `prometheus` trigger or prometheus-adapter, off by default; scale-down stabilisation 10 min; `minReplicas` 2 (AU34). CPU HPA kept as fallback, with a values comment that it does not see streams |
| no metrics | `metrics.serviceMonitor.enabled` / `podMonitor` for `:8098/metrics`; a Grafana dashboard JSON in `charts/synaplan/dashboards/` (AU8) |
| no Downward API | env `SYNAPLAN_SERVER_ID` (pod name), `SYNAPLAN_K8S_NAMESPACE`, `SYNAPLAN_K8S_NODE`, `SYNAPLAN_CPU_REQUEST`, `SYNAPLAN_MEMORY_LIMIT` from `fieldRef` / `resourceFieldRef`, read by the watchdog for the technical row (AU8) |
| a restart kills running streams | `preStop`: `POST :8098/drain`, wait for held streams up to `terminationGracePeriodSeconds`; with the pump (AU30) on its own Deployment, a web pod only drops client connections and clients re-attach, so the grace period can stay at 30 s (AU34) |
| web, worker, scheduler, tts Deployments | + `pump` Deployment (AU30), scaled on `synaplan_pump_streams`; `PodDisruptionBudget` `minAvailable: 1` for web and pump (AU34) |
| (pump, new) | its own shutdown contract (`03` §7): `SIGTERM` ⇒ stop claiming, finish active turns, at `PUMP_DRAIN_SECONDS` (240 s) end the rest like a Stop; `terminationGracePeriodSeconds` = drain + 30 s; rollout `maxSurge: 1`, `maxUnavailable: 0`; scale-down stabilisation long enough that a pump is not drained while it is still the busiest. A web pod's `/drain` does not cover pumps — they hold the provider sockets |

### 5.2 Detection in the watchdog

`KUBERNETES_SERVICE_HOST` set ⇒ runtime `kubernetes`; pod name from
`HOSTNAME` or `SYNAPLAN_SERVER_ID`; namespace from
`/var/run/secrets/kubernetes.io/serviceaccount/namespace`; limits from the
cgroup files (authoritative) and requests from the Downward API env (only
source). `/.dockerenv` ⇒ `compose`; else `host`.

### 5.3 Verification

`vultr-cluster/load-testing` already installs kube-prometheus and Grafana and
has a Synaplan chat scenario in Playwright. M6 runs `spike/runtime/saturate.py`
against a test deployment there, once before AU8 and once after AU34, and
enters the tables of `02` §2 for a pod in STATUS. Never against production.

---

## 6. Compose and self-hosted

Same page, same watchdog, no Prometheus. `deploy/compose.yaml` and the
platform compose pass `SYNAPLAN_SERVER_ID` (default: the container hostname)
and keep `:8098` unpublished; an operator who wants Prometheus publishes it
on an internal interface. The platform's `scripts/php-runtime-check.sh`
remains the shell view and gains the watchdog's numbers (ops, private repo).

---

## 7. Retention and privacy

| Data | Where | Kept |
| ---- | ----- | ---- |
| Server snapshot | Redis `capacity:server:{id}` | 20 s (overwritten every 5 s) |
| One-minute aggregates | Redis `capacity:agg:{id}:{minute}` | 24 h |
| Fifteen-minute aggregates | Redis `capacity:agg15:{id}:{slot}` (rolled up from the one-minute buckets by the watchdog, §2) | 14 days |
| Request timeline records | Redis ring | 10,000 records or 24 h |
| Prometheus | the operator's | the operator's |

Only administrators read any of it. The records carry a pseudonymous user id
and no content (§3). Like the event ring, this is a troubleshooting and
sizing feed, not an audit log; the audit log stays `BAUDITLOG`.

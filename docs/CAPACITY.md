> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Related:** [Runtime & Outbound Options](RUNTIME_OPTIONS.md) · [Backend Architecture](ARCHITECTURE.md)

# Capacity and Sizing

> **Draft — dev-stack numbers.** Every figure below was measured on a
> development stack (a laptop and a 4-core cloud VM), not on a production
> node. The numbers that follow from the design (one PHP worker per held
> stream, the cost of a stream in the pump) carry over; the absolute ones
> (requests per second, megabytes) do not. Production snapshots from the
> capacity watchdog and a load test on Kubernetes replace them before this
> page loses its draft mark.

**How many people can use one Synaplan server at the same time?** As many
as it can hold AI answers at once, and that is a design limit, not a
hardware one. Each chat answer holds one PHP worker of the `streams` pool
for its whole duration (16 by default, 24 on large nodes), while it uses
about 4 % of a processor. A server with 24 stream workers answers 24 chats
at once. The 25th waits up to 15 seconds and then gets "busy, try again".
Everything else (sign-in, files, settings) keeps its own `api` pool and
stays fast. With the stream pump on, the call to the provider moves to a
pump process, and adding pump processes is cheap (about 160 MB idle each
on the dev stack). The web request still copies the answer to the browser,
and it holds a `streams` worker while it does, unless the watchdog relay
(`SYNAPLAN_RELAY=1`) holds the connection instead. A mid-sized installation
with 1,000 people needs two web servers for availability, the relay, and
enough pump processes for its peak, worked out in [Sizing](#sizing).

---

## What limits a server

| | Without the pump (default) | Pump, no relay | Pump and relay (`SYNAPLAN_RELAY=1`) |
| --- | --- | --- | --- |
| Who calls the provider | A PHP worker of the `streams` pool | A pump process (`SYNAPLAN_ROLE=pump`), one turn each | A pump process, one turn each |
| Who holds the browser connection | The same `streams` worker | A `streams` worker, copying frames from Redis | The watchdog relay; PHP only admits the turn |
| Answers at once per server | `FRANKENPHP_STREAM_WORKER_NUM` (default: the same as `FRANKENPHP_WORKER_NUM`, 16) | The smaller of the `streams` workers and the pump processes | The smaller of `WATCHDOG_STREAM_SLOTS` (320) and the pump processes |
| Past the connection limit | Waits for a free worker up to `FRANKENPHP_MAX_WAIT_TIME` (15 s), then `503 server_busy` with `Retry-After: 5` | The same as without the pump | `503 server_busy` with `Retry-After: 5` at once, from the relay |
| Admitted, but every pump is busy | — | The turn queues in Redis; the answer starts later, and the `streams` worker waits with it | The turn queues in Redis; the answer starts later |
| What the rest of the API feels | Nothing: short requests have their own `api` pool | Nothing | Nothing |
| What adding capacity costs | One database connection per PHP worker, on every web node | The same, plus one per pump process | One database connection per pump process |

Memory is not the limit: an extra booted PHP worker costs about 3.3 MB, and
a held stream raises a worker by about 5 MB. Processor time is not the
limit either: a held stream spends at least 96 % of its time waiting for the
provider. The limit is the number of workers and, behind it, the number of
database connections the cluster accepts (check MariaDB / Galera
`max_connections` before raising worker counts).

Today the pump only takes signed-in web chat turns, with
`RUNTIME.STREAM_PUMP=all` and a percent above 0. Guests, the widget,
incognito turns and the OpenAI- and Anthropic-compatible gateways stay in
the web request and in the `streams` pool (`gateways` mode has no pumped
caller yet). Size that pool for them even with the pump on.

---

## Measured numbers (dev stack)

From the architecture-update measurements of 2026-10-21 (FrankenPHP 1.12,
PHP 8.5, Docker Desktop with 10 cores and 16 GB) unless marked otherwise.

| Question | Measured | Notes |
| --- | --- | --- |
| Answers at once, one shared pool, 8 workers | 8; a 15 ms request waited up to **11 s** behind them | Before the two pools |
| Answers at once, separate pools (4 stream + 4 api workers) | 4 per pool; the API stayed at **19–23 ms p95, ≤ 30 ms max** | Today's default shape |
| Past the stream pool with `max_wait_time 2s` | The excess streams got **503** after 2 s instead of waiting 12 s | Production waits 15 s |
| CPU of a held stream | ≈ **4 %** of its duration (upper bound) | Dev boots the kernel per request |
| Memory per extra PHP worker | ≈ **3.3 MB** (170 MB with 2 workers, 243 MB with 24) | Shared OPcache dominates |
| Database connections | **1 per PHP worker** | Doctrine keeps its connection |
| What the gateways add before the first token | **+10 to +50 ms** median, worker mode | Inside provider jitter |
| What the app's own chat adds before the provider is asked | ≈ **1.5 s** (sorter 1.0 s, embedding 0.23 s, memory search 0.24 s) | The worker is held for it |
| One PHP process holding provider streams (spike) | **1,000 streams**, ≈ 134 MB peak, 2.7 ms CPU each | The pump design; today's pump runs one turn per process |
| Idle memory per role (2026-11-22, 4-core VM, dev compose) | web 878 MB, worker 133 MB, scheduler 213 MB, pump 162 MB | `docker stats`; the web container runs `APP_ENV=dev` |
| Short requests on the same dev backend | `/api/v1/config/runtime`: 1 client p50 434 ms; 8 clients 8.5 req/s, p95 1.06 s | `APP_ENV=dev` rebuilds and checks its cache on every request |

The last row is why dev numbers are no sizing basis: with `APP_ENV=dev` a
short request costs hundreds of milliseconds that production does not pay
(the split-pool run above kept the API at 19–23 ms p95). Use the capacity
page of a production-shaped server.

---

## Sizing

Concurrent answers = answers per second × seconds per answer (Little's law).

**Worked example.** 1,000 people, 15 % active in the peak hour, one message
every 2 minutes each, 15 seconds per answer (1.5 s before the provider is
asked, then the answer):

- arrival rate = 150 people ÷ 120 s = **1.25 answers per second**
- concurrent answers = 1.25 × 15 s ≈ **19**; plan for peaks of 2–3× ≈ **40–55**

| Shape | Answers at once per server | Servers for the peak |
| --- | ---: | ---: |
| No pump, 24 stream workers per server | 24; the 25th waits, then "busy" | 2–3 |
| No pump, 16 stream workers (default) | 16 | 3–4 |
| Pump without the relay, 24 stream workers per server | 24: the `streams` pool still holds every answer | 2–3, plus a pump process per answer |
| Pump and relay (`SYNAPLAN_RELAY=1`), 2 web servers | up to 320 connections per server; one answer per pump process | 2 web servers for availability, **40–55 pump processes** at the peak, spread over the nodes |

Take the seconds per answer and the peak from your own installation, not
from this example: **Admin → Server load → Requests** shows the p95 of chat
answers, and **Servers** shows the day's peak of held answers per server.

---

## Reading the capacity page

**Admin → Server load** (`/admin/capacity`) has five tabs. Every tab reads
Redis; when Redis does not answer, the page says so and the servers keep
working.

| Tab | Answers | Look at |
| --- | --- | --- |
| **Servers** | Is a server full or silent? | "AI answers at once" against its total, today's peak and the sizing hint under it; a server that stopped reporting is marked with the time it was last seen |
| **Requests** | Which kind of request is slow, and where did the slowest one spend its time? | Sort by p95; open one of the slowest requests of the last hour to see the parts bar (waiting, PHP, AI, tools, database, outbound) and its calls; copy the request ID to search the logs. The legend shows what was measured for each part; the parts can overlap (a tool can call an AI model), so the bar is an estimate |
| **AI calls** | Which provider or model got slower or more expensive? | First word p50 / p95 per model, the whole call p95, and hooks: the time Synaplan adds around each call |
| **Tools** | Do tool calls fail or ask for approval? | "Asked for approval" counts the window's calls; the button counts what still waits for you and opens **Approvals** when approvals are on |
| **Outbound** | Where does Synaplan call out to, and what would the outbound filter refuse? | With `EGRESS.MODE=report`, rows marked "Would be refused"; **Allow** puts the host on `EGRESS.ALLOW`, **Remove** asks, then takes it off. In `off` mode nothing is recorded |

Percentiles come from fixed buckets (1, 5, 10, 25, 50, 100, 250, 500 ms,
1, 2.5, 5, 10, 30, 60 s), so a p95 of "500 ms" means "between 250 and 500
ms". The timeline keeps the last 1,000 requests across all servers, with
timings and counts only — never a prompt, an answer or a tool argument.

---

## The knobs

| Setting | Default | Where | Raise it when |
| --- | --- | --- | --- |
| `FRANKENPHP_STREAM_WORKER_NUM` | `FRANKENPHP_WORKER_NUM` | Environment | **Servers** shows today's peak at the total, and the database has connections to spare |
| `FRANKENPHP_WORKER_NUM` | `16` | Environment | Short requests show waiting on **Requests** (rare) |
| `FRANKENPHP_MAX_WAIT_TIME` | `15s` | Environment | Never for capacity; it only decides how long a full server makes people wait before "busy" |
| `FRANKENPHP_NUM_THREADS` | unset | Environment | Only when you must cap threads; it must exceed both pools together |
| `RUNTIME.STREAM_PUMP`, `RUNTIME.STREAM_PUMP_PERCENT` | `off`, `0` | `BCONFIG`, owner 0 | You want the provider call out of the web request: start with `all` and a small percent, and turn on the relay to free the `streams` pool too |
| Pump processes | 1 (compose service `pump`) | `docker compose up --scale pump=N`, or the chart's `pump.replicaCount` | Turns queue: answers start later while every pump process holds a turn |
| `SYNAPLAN_RELAY`, `WATCHDOG_STREAM_SLOTS` | off, `320` | Environment, web role | The relay holds client connections in the watchdog instead of PHP; slots cap them per server |

`RUNTIME_OPTIONS.md` lists every runtime switch;
[`EGRESS.md`](EGRESS.md) explains the outbound filter.

---

## Kubernetes

The [`synaplan` chart](https://github.com/metadist/synaplan-charts) carries
the watchdog probes, metrics and resources. Its README section "Scaling
Synaplan" lists the values; in short:

- **Scale web pods on held answers, not on processor.** A server whose
  stream pool is full uses a few percent of a processor, so a CPU target
  never fires. The watchdog exports `synaplan_stream_slots_busy_ratio`
  (held answers ÷ stream workers) on `:8098/metrics`; scale on it through
  KEDA's `prometheus` trigger or prometheus-adapter, with a target of 0.6,
  at least two replicas and a scale-down window of ten minutes, so a pod
  is not removed while it still holds answers.
- **Run the pump as its own Deployment** when `RUNTIME.STREAM_PUMP` is on.
  On `SIGTERM` a pump stops taking turns and finishes the one it holds, so
  its grace period must cover your longest answer (the chart's default is
  five minutes). Roll it with `maxUnavailable: 0`.
- **Disruption budgets** for the web and pump Deployments keep one pod of
  each through a node drain.
- **Starting resources** for a web pod: memory request 1 GiB, limit 3 GiB,
  processor request 1, no processor limit (throttling shows up as slow
  answers, not as a visible limit).

Not yet in place: a pump metric to scale pumps on, and a watchdog drain
endpoint for the web pod's `preStop`. Until then, scale pumps by hand from
the peak on **Servers** and keep the web pod's grace period long enough for
an answer to finish.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Related:** [Runtime & Outbound Options](RUNTIME_OPTIONS.md) · [Backend Architecture](ARCHITECTURE.md)

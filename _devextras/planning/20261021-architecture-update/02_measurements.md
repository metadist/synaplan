# Measurements — how far the PHP runtime scales today

Measured 2026-10-21 on the local dev stack (`main` at `4dd1b26da`, FrankenPHP
v1.12.6, PHP 8.5.9, Caddy 2.11.4, Docker Desktop with 10 cores and 16 GB).
Scripts and the clean-up are in [`spike/`](./spike/README.md). This file answers
the architect's question: *concurrent streaming sessions per node, memory per
worker, the latency the hooks add.*

A dev laptop is not a production node. The numbers that depend on the
hardware (requests per second, absolute memory) are indicative. The numbers
that depend on the design (one worker per stream, CPU share of a held stream,
cost per stream in Go and in a PHP pump) carry over.

---

## 1. The headline

| Question | Today | Evidence |
| -------- | ----- | -------- |
| Concurrent AI streams per server | **Equal to the PHP worker count**: 16 by default, **24 on the production web nodes** (`FRANKENPHP_WORKER_NUM`, `synaplan-platform/docs/PHP-RUNTIME-SIZING.md`). The stream after that waits for a free worker | §2.1, §2.2 |
| What everybody else feels when the pool is full | A 15 ms request waits **up to 11 s** | §2.1, §2.2 |
| CPU a held stream uses | **≈ 4 %** of its duration (upper bound; dev boots the kernel per request). The worker waits on the provider ≥ 96 % of the time | §2.3 |
| Memory per worker | **≈ 3.3 MB** per extra booted worker (170 MB with 2 workers, 243 MB with 24). Memory is not the limit | §2.4 |
| What does limit adding workers | **One database connection per worker** (8 workers → 8 connections). 3 nodes × N workers on one Galera cluster | §2.5 |
| What the gateways add before the first token | **≈ 25 ms** median in worker mode | §3.1 |
| What the app's own chat adds before the first token | **≈ 1.5 s**: AI sorter 1.0 s, shared embedding 0.23 s, memory search 0.24 s. A pre-call step that calls a model already exists: the sorter | §3.2 |
| Separate pool for streams | API stays at **22 ms p95** while streams saturate their pool (was 10.8 s) | §4.1 |
| Fast "busy" instead of a silent wait | `max_wait_time 2s` → the excess streams get **503 after 2 s** instead of waiting 12 s | §4.2 |
| One Go process holding streams | **2,000 streams, 142 MB peak RSS (~35 KB each), 6.3 s CPU** for 2,000 × 10 s | §4.3 |
| One PHP process holding provider streams | **1,000 streams, 26 MB peak, 2.5 ms CPU each** (Symfony HttpClient, curl_multi) | §4.4 |

**Answer for "start with mid-sized customers":** today's limit is a design
limit, not a hardware limit. One web node serves as many simultaneous AI turns
as it has PHP workers, and each extra worker costs a database connection. The
plan (S0) protects the rest of the API from that limit at once and makes the
limit visible; S5 removes it by keeping the long waits out of PHP. Sizing with
Little's law is in §5.

---

## 2. PHP holds one worker per stream

### 2.1 Classic mode, 20 threads (the dev backend as it runs)

`saturate.py`, model `claude-haiku-4-5-20251001`, a 700-word answer (≈ 12 s).
The probe is `GET /api/health`.

| Streams | Threads busy (max) | Stream TTFB p50 / p95 | Stream duration p95 | Probe p50 / p95 / max | Probes answered |
| ------: | -----------------: | --------------------- | ------------------: | --------------------- | --------------: |
| 10 (`test-model`) | 12 of 20 | 0.54 / 0.55 s | 1.8 s | 0.10 / 0.55 / 0.55 s | 11 |
| 20 | **20 of 20** | 1.68 / 1.79 s | 13.1 s | 0.10 / **11.06** / 11.06 s | 5 in 13 s |
| 30 | **20 of 20** | 1.90 / **12.87** s | 24.0 s | 0.11 / 0.16 / **11.51** s | 34 |

At 30 streams the ten that did not get a thread waited about one stream
duration (≈ 11 s) before their first token.

### 2.2 Production worker mode, 8 workers

`worker-mode.Caddyfile` (`APP_ENV=prod`, `num 8`, `num_threads 12`).

| Streams | Workers busy (max) | Stream TTFB p50 / p95 | Probe baseline p50 | Probe during p50 / max |
| ------: | -----------------: | --------------------- | -----------------: | ---------------------- |
| 12 | **8 of 8** | 1.23 / **12.06** s | 16 ms | 15 ms / **10.85 s** |

The four threads outside the worker pool stay idle: every request goes to
`public/index.php`, which is the worker. Worker count is the ceiling, as
`PHP-RUNTIME-SIZING.md` §4 predicted.

### 2.3 CPU of a held stream

Twenty Haiku streams of 12.4 s mean: the FrankenPHP process used **10.1 CPU
seconds** in 13.5 s wall (`process_cpu_seconds_total`), **0.50 s per stream,
4.05 % of the time it was held**. This includes the dev kernel boot per request
and the probes, so production is lower. The rest of the time the worker waits
on the provider.

### 2.4 Memory per worker

Resident memory of the worker-mode FrankenPHP after 400 requests (all workers
booted):

| Workers | RSS |
| ------: | --: |
| 2 | 170 MB |
| 8 | 187 MB |
| 16 | 209 MB |
| 24 | 243 MB |

≈ 3.3 MB per extra worker; the base (≈ 160 MB) is mostly the shared OPcache.
During 8 streams the RSS rose 182 → 202 MB (≈ 2.5 MB per streaming request); in
classic mode 20 streams raised the container 447 → 690 MB (≈ 12 MB each, dev
mode). The production limit stays `num_threads × memory_limit` (32 × 512 MB),
as `PHP-RUNTIME-SIZING.md` says, not the resident size.

### 2.5 One database connection per worker

With 8 workers serving requests, MariaDB shows **8 connections** from the
backend's address (`information_schema.PROCESSLIST`). A worker keeps its
Doctrine connection between requests. Raising the worker count to hundreds
would multiply connections on the shared Galera cluster (three web nodes plus
the messenger workers). That is why the plan does not answer the limit with
"more workers".

### 2.6 Short PHP requests

`microreq.py`, keep-alive, worker mode, 8 workers:

| Route | Clients | Requests/s | p50 | p95 |
| ----- | ------: | ---------: | --: | --: |
| `/api/health` | 1 | 82 | 11.7 ms | 14.3 ms |
| `/api/health` | 8 | **81** | 95 ms | 137 ms |
| `/api/health` | 16 | **80** | 192 ms | 259 ms |
| `/api/v1/config/runtime` | 8 | 291 | 18 ms | 24 ms |

`/api/health` does not scale with workers. `HealthController::health()` calls
`WhisperService::isAvailable()`, which starts `whisper --help` with
`Symfony\Component\Process` on every call. Forking a large multi-threaded
FrankenPHP process costs ≈ 12 ms and does not run in parallel. The same cost
applies to every `ffmpeg`, `whisper` or `pdftotext` a web request starts. AU2
caches the probe.

---

## 3. What the platform adds before the provider answers

### 3.1 The gateways

`overhead.py`, 10 runs, `max_tokens 60`, time to first token:

| Model | Direct p50 | `/v1/chat/completions` classic | worker mode |
| ----- | ---------: | -----------------------------: | ----------: |
| Groq `openai/gpt-oss-20b` | 0.13 s | +0.19 s | **+0.03 s** |
| Anthropic `claude-haiku-4-5-20251001` | 0.47 s | +0.13 s | **±0.01 s** (inside network jitter) |
| Anthropic via `/v1/messages` (classic) | 0.48 s | +0.15 s | — (gateway off locally) |

The classic-mode overhead is mostly the dev kernel boot (`/api/health` takes
0.10 s there, 0.012 s in worker mode). Production runs worker mode, so the
gateway path adds ≈ 25 ms today. This is the baseline for the hook budget in
[`03_contracts.md`](./03_contracts.md) §1.4.

Side finding: a two-word "Hi" through `/v1/messages` reached Anthropic as
**1,298 input tokens**: the gateway's injected context. Tokens a hook adds are
cost, and the call timeline reports them per hook (AU13).

### 3.2 The app's own chat

Five turns through `GET /api/v1/messages/stream`, the `perf` event of
`PerfTimer` (median, ms):

| Phase | Median | Min | Max |
| ----- | -----: | --: | --: |
| `classify` (AI sorter, a model call) | **1,024** | 901 | 1,097 |
| `shared_embedding` | 233 | 202 | 543 |
| `memories_search` | 240 | 209 | 549 |
| `history`, `summary`, `prompt`, `feedback`, `digests_search` | < 5 each | | |
| `provider_total` | 3,481 | 1,753 | 3,592 |
| first provider token (`provider_ttft` mark) | 4,303 | | |
| total | 4,765 | | |

The web worker is held for all of it, including the ≈ 1.5 s before the
provider is asked. This is the architect's concern, measured: "hooks that call
a model hold it longer". The sorter is such a hook today.

---

## 4. What the alternatives cost

### 4.1 A separate pool for streams

`split-pools.Caddyfile`: `worker { name streams; match /v1/chat/completions; num 4 }`
and `worker { name api; num 4 }`, accepted by FrankenPHP 1.12.6
(`frankenphp validate`, metrics `frankenphp_total_workers{worker="m#streams"} 4`).

| 8 Haiku streams on 4 stream workers | Value |
| ----------------------------------- | ----: |
| Streams busy (max) | 4 |
| Stream TTFB p50 / p95 | 12.0 / 12.6 s (the second four waited) |
| Probe baseline p50 | 13 ms |
| **Probe during p50 / p95 / max** | **14 / 22 / 29 ms** (one shared pool: 10.85 s max) |

### 4.2 A fast "busy" instead of a silent wait

Same pools, global `max_wait_time 2s`: 4 streams ran, **4 got HTTP 503 after
2 s**, the probe stayed at 26 ms p95. FrankenPHP answers with a bare 503; AU3
turns it into a machine-readable body with `Retry-After`, and AU4 into copy a
person understands.

### 4.3 A Go process holding client streams

`relay/` (stdlib only): a stub upstream streams 200 tokens 50 ms apart
(10 s); the relay copies and flushes every event; the load client opens N at
once.

| Streams | TTFB p95 | Duration p95 | Relay RSS idle → peak | Per stream | CPU for all |
| ------: | -------: | -----------: | --------------------- | ---------: | ----------: |
| 100 | 0.07 s | 10.41 s | 8 → 18 MB | 95 KB | 0.46 s |
| 500 | 0.11 s | 10.41 s | 18 → 45 MB | 55 KB | 1.80 s |
| 1,000 | 0.08 s | 10.40 s | 45 → 73 MB | 28 KB | 3.51 s |
| **2,000** | **0.18 s** | **10.47 s** | 73 → **142 MB** | **35 KB** | **6.30 s** |

### 4.4 One PHP process holding provider streams

`pump/pump.php` in the backend container: `HttpClient::create([], $n)`, N
`request()` calls, one `stream()` loop over all responses, against the same
stub.

| Streams | Events read | Wall | CPU | Per stream | Peak memory | First chunk p95 |
| ------: | ----------: | ---: | --: | ---------: | ----------: | --------------: |
| 100 | 20,100 | 10.5 s | 0.21 s | 2.1 ms | 6.4 MB | 0.09 s |
| 500 | 100,500 | 10.5 s | 1.15 s | 2.3 ms | 14.0 MB | 0.18 s |
| **1,000** | **201,000** | **10.5 s** | **2.49 s** | **2.5 ms** | **26.0 MB** | **0.21 s** |

The pump only counted events; decoding and translating them adds CPU per
event. Even at ten times this cost, one process holds hundreds of streams.
This is what lets the plan keep every wire format in PHP (S5).

---

## 5. Sizing with these numbers

Concurrent turns = turns per second × seconds per turn (Little's law).

Example, a mid-sized customer: 1,000 people, 15 % active in the peak hour,
one turn every 2 minutes each, 15 s per turn (pre-call 1.5 s + answer):

- arrival rate = 150 / 120 s = 1.25 turns/s
- concurrent turns = 1.25 × 15 s ≈ **19**, peaks at 2–3× ≈ **40–55**

| Shape | Concurrent turns per server | Servers for the peak |
| ----- | --------------------------: | -------------------: |
| Today (24 workers, shared pool) | 24, and the rest of the API stalls at 24 | 2–3, with stalls |
| After S0 (split pools, 24 stream + 24 API workers) | 24, the API stays responsive; the 25th gets "busy, retrying" | 2–3, no stalls |
| After S5 (watchdog + pump) | hundreds; bounded by provider rate limits and the pump's CPU | 1 (2 for availability) |

The capacity page (AU7) shows the measured concurrency per server, so this
estimate is replaced by real numbers within a day of AU6.

---

## 6. Open measurements

| # | What | Where it gets measured |
| - | ---- | ---------------------- |
| M1 | The same tables on one production web node: `queue_depth`, busy workers, RSS, DB connections at the daily peak | AU6 watchdog snapshots; read-only, no load test in production |
| M2 | Galera `max_connections` and connections in use per node | Ops, `synaplan-platform` (private) |
| M3 | Pump CPU per event with the real decoders (OpenAI, Anthropic, Gemini) | AU28 spike |
| M4 | Pipeline overhead with the default hooks (target ≤ 1 ms p95 per call) | AU10, `make perf-runtime` |
| M5 | The watchdog hop added to TTFB (target ≤ 5 ms p95) | AU28 spike |
| M6 | Kubernetes: the same tables in a pod with CPU / memory limits | AU8, `vultr-cluster/load-testing` |

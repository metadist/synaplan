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

**Method (after the review of [#2623](https://github.com/metadist/synaplan/pull/2623)).**
The first run of the scripts had four measuring defects that Copilot found;
every table below is from the corrected re-run. A stream counts as successful
only with HTTP 200, answer text and its end marker (`[DONE]`); failed and cut-off
streams are counted, never averaged in. Time to first token means the first
non-empty **text** delta (not a role, metadata or empty delta). The pump's
memory is the process's resident memory from `/proc/self/status`, which
includes libcurl's buffers, not only the PHP heap. The Go load client waits for
its memory sampler before reading the peak (`go build -race`: no report).
The design conclusions did not change; two numbers did, and both are corrected
here and in the plan: the PHP pump's memory (≈ 134 MB, not 26 MB, for 1,000
streams) and the gateway overhead (10–50 ms, not "≈ 25 ms"). A second review
round made three scripts stricter again — the pump parses complete lines
across chunk boundaries, and probes and micro-requests count only HTTP 200
(an unhealthy baseline aborts the run) — and the affected cases were re-run
with every probe and request answering 200; the results did not move.

---

## 1. The headline

| Question | Today | Evidence |
| -------- | ----- | -------- |
| Concurrent AI streams per server | **Equal to the PHP worker count**: 16 by default, **24 on the production web nodes** (`FRANKENPHP_WORKER_NUM`, `synaplan-platform/docs/PHP-RUNTIME-SIZING.md`). The stream after that waits for a free worker | §2.1, §2.2 |
| What everybody else feels when the pool is full | A 15 ms request waits **up to 11 s** | §2.1, §2.2 |
| CPU a held stream uses | **≈ 4 %** of its duration (upper bound; dev boots the kernel per request). The worker waits on the provider ≥ 96 % of the time | §2.3 |
| Memory per worker | **≈ 3.3 MB** per extra booted worker (170 MB with 2 workers, 243 MB with 24). Memory is not the limit | §2.4 |
| What does limit adding workers | **One database connection per worker** (8 workers → 8 connections). 3 nodes × N workers on one Galera cluster | §2.5 |
| What the gateways add before the first text token | **+10 to +50 ms** median in worker mode, with warm connections on both sides; inside the network jitter | §3.1 |
| What the app's own chat adds before the first token | **≈ 1.5 s**: AI sorter 1.0 s, shared embedding 0.23 s, memory search 0.24 s. A pre-call step that calls a model already exists: the sorter | §3.2 |
| Separate pool for streams | API stays at **19–23 ms p95, ≤ 30 ms max** (two runs, every probe HTTP 200) while streams saturate their pool (one shared pool: 11 s) | §4.1 |
| Fast "busy" instead of a silent wait | `max_wait_time 2s` → the excess streams get **503** instead of waiting 12 s | §4.2 |
| One Go process holding client streams | **2,000 streams, 147 MB peak RSS (≈ 33 KB each), 6.6 s CPU** for 2,000 × 10 s | §4.3 |
| One PHP process holding provider streams | **1,000 streams, ≈ 134 MB peak RSS (≈ 73 KB each, libcurl included; PHP heap 26 MB), 2.7 ms CPU each** (Symfony HttpClient, curl_multi) | §4.4 |

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
The probe is `GET /api/health` every 250 ms.

| Streams | Threads busy (max) | Streams ok | First text p50 / p95 | Duration p95 | Probe p50 / p95 / max | Probes answered |
| ------: | -----------------: | ---------: | -------------------- | -----------: | --------------------- | --------------: |
| 20 | **20 of 20** | 20 | 2.13 / 2.26 s | 13.4 s | 1.35 / **10.72** / 10.72 s | 4 in 13 s |
| 30 | **20 of 20** | 30 | 1.69 / **13.12** s | 24.2 s | 0.11 / 0.14 / **11.57** s | 34 |

At 30 streams the ten that did not get a thread waited about one stream
duration (≈ 11 s) before their first token. (A first run, with the looser
success rule, gave the same picture: probe max 11.06 s and 11.51 s.)

### 2.2 Production worker mode, 8 workers

`worker-mode.Caddyfile` (`APP_ENV=prod`, `num 8`, `num_threads 12`).

| Streams | Workers busy (max) | Streams ok | First text p50 / p95 | Probe baseline p50 | Probe during p50 / max | FrankenPHP RSS before → during |
| ------: | -----------------: | ---------: | -------------------- | -----------------: | ---------------------- | ------------------------------ |
| 12 | **8 of 8** | 12 | 1.29 / **12.01** s | 15 ms | 15 ms / **10.98 s** | 186 → 228 MB |

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
During 8 streams the RSS rose 186 → 228 MB (≈ 5 MB per streaming request); in
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

`microreq.py`, keep-alive, worker mode, 8 workers, only HTTP 200 timed
(re-run with status checks: every request answered 200; the first run is in
brackets):

| Route | Clients | Requests/s | p50 | p95 |
| ----- | ------: | ---------: | --: | --: |
| `/api/health` | 1 | 109 (82) | 7.5 ms | 15.9 ms |
| `/api/health` | 8 | **107** (81) | 64 ms | 116 ms |
| `/api/health` | 16 | **89** (80) | 184 ms | 234 ms |
| `/api/v1/config/runtime` | 8 | 242 (291) | 21 ms | 28 ms |

`/api/health` does not scale with workers: one client gets as much as sixteen. `HealthController::health()` calls
`WhisperService::isAvailable()`, which starts `whisper --help` with
`Symfony\Component\Process` on every call. Forking a large multi-threaded
FrankenPHP process costs ≈ 12 ms and does not run in parallel. The same cost
applies to every `ffmpeg`, `whisper` or `pdftotext` a web request starts. AU2
caches the probe.

---

## 3. What the platform adds before the provider answers

### 3.1 The gateways

`overhead.py`: time to the first **text** delta, `max_tokens 400` (so the
reasoning model `gpt-oss` always reaches answer text), failures counted and
left out. Every call reuses one keep-alive connection per destination after a
warm-up call, so the direct call and Synaplan's call to the provider are both
warm — Synaplan's long-lived workers keep their provider connections, and a
cold direct call would otherwise flatter the gateway.

**Worker mode, warm, 20 runs per path, no failure:**

| Model | Direct p50 / p95 | `/v1/chat/completions` | `/v1/messages` |
| ----- | ---------------- | ---------------------: | -------------: |
| Groq `openai/gpt-oss-20b` | 0.139 / 0.610 s | **+0.012 s** | **+0.030 s** |
| Anthropic `claude-haiku-4-5-20251001` | 0.411 / 0.459 s | **+0.038 s** | **+0.046 s** |

**Classic mode (dev), a new connection per call, 10 runs, no failure:**

| Model | Direct p50 | `/v1/chat/completions` | `/v1/messages` |
| ----- | ---------: | ---------------------: | -------------: |
| Groq `openai/gpt-oss-20b` | 0.205 s | +0.172 s | +0.089 s |
| Anthropic `claude-haiku-4-5-20251001` | 0.483 s | +0.123 s | +0.144 s |

The classic-mode overhead is mostly the dev kernel boot (`/api/health` takes
0.10 s there, 0.012 s in worker mode). Production runs worker mode, so the
gateway path adds **10–50 ms** today, inside the provider's own jitter (Groq's
direct p95 is 0.61 s). This is the baseline for the hook budget in
[`03_contracts.md`](./03_contracts.md) §1.4 and master plan §6.

Why the first run was wrong: it took the first SSE line that contained
"content" — Anthropic's `message_start` (`"content": []`) and OpenAI's role
delta (`"content": ""`) — so it timed metadata; with `max_tokens 60` five of
ten direct Groq runs produced no answer text at all and were timed anyway.

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
A later check put all four long routes on one `match` line and counted each on
`streams` (STATUS).

| 8 Haiku streams on 4 stream workers | First re-run | Second re-run (status-checked probes) |
| ----------------------------------- | ----------: | ------------------------------------: |
| Streams ok | 8 | 8 |
| First text p50 / p95 | 12.5 / 13.1 s | 11.8 / 13.2 s (the second four waited) |
| Probe baseline p50 | 16 ms | 14 ms |
| **Probe during p50 / p95 / max** | **14 / 19 / 30 ms** | **15 / 23 / 27 ms**, 0 probe errors |

One shared pool, for comparison: 10.98 s max.

### 4.2 A fast "busy" instead of a silent wait

Same pools, global `max_wait_time 2s`: 4 streams ran, **4 got HTTP 503**
instead of waiting ≈ 12 s, and the probe stayed at 20–25 ms p95 with every
probe answering 200 (second re-run: 0 probe errors). FrankenPHP answers
with a bare 503; AU3 turns it into a machine-readable body with `Retry-After`,
and AU4 into copy a person understands.

### 4.3 A Go process holding client streams

`relay/` (stdlib only): a stub upstream streams 200 tokens 50 ms apart
(10 s); the relay copies and flushes every event; the load client opens N at
once and counts a stream only with HTTP 200 and `[DONE]`.

| Streams | Ok / failed | TTFB p95 | Duration p95 | Relay RSS idle → peak | Per stream | CPU for all |
| ------: | ----------- | -------: | -----------: | --------------------- | ---------: | ----------: |
| 100 | 100 / 0 | 0.07 s | 10.45 s | 8 → 21 MB | ≈ 127 KB | 0.46 s |
| 500 | 500 / 0 | 0.10 s | 10.49 s | 21 → 45 MB | ≈ 50 KB | 1.92 s |
| 1,000 | 1,000 / 0 | 0.06 s | 10.48 s | 45 → 83 MB | ≈ 38 KB | 3.73 s |
| **2,000** | **2,000 / 0** | **0.14 s** | **10.53 s** | 83 → **147 MB** | **≈ 33 KB** | **6.56 s** |

The idle value of a run is the process after the previous one (Go keeps freed
memory), so the per-stream column is approximate. A `-race` build at 200
streams reported no data race.

### 4.4 One PHP process holding provider streams

`pump/pump.php` in the backend container: `HttpClient::create([], $n)`, N
`request()` calls, one `stream()` loop over all responses, against the same
stub. A stream counts only with HTTP 200 and `[DONE]`, read from complete
lines (chunks are buffered across boundaries). The line-buffered re-run
counted exactly 200 events per stream and the same memory (1,000 streams:
133.6 MB peak, 2.6 ms CPU each).

| Streams | Done / failed | CPU | Per stream | PHP heap peak | Process RSS before → peak | RSS per stream | First chunk p95 |
| ------: | ------------- | --: | ---------: | ------------: | ------------------------- | -------------: | --------------: |
| 100 | 100 / 0 | 0.30 s | 3.0 ms | 6.4 MB | 63 → 72 MB | ≈ 79 KB | 0.09 s |
| 500 | 500 / 0 | 1.25 s | 2.5 ms | 14.0 MB | 63 → 99 MB | ≈ 73 KB | 0.18 s |
| **1,000** | **1,000 / 0** | **2.70 s** | **2.7 ms** | 26.0 MB | 63 → **134 MB** | **≈ 73 KB** | **0.27 s** |

Per stream the PHP pump costs about twice the Go relay's memory (libcurl keeps
a buffer per transfer). The pump only counted events; decoding and translating
them adds CPU per event. Even at ten times this cost, one process holds
hundreds of streams. This is what lets the plan keep every wire format in PHP
(S5).

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

# Architecture update — one AI path, providers as units, central egress, one tool path, a runtime that does not wait

**Status:** S0 is in progress. AU0, AU0b, AU1, AU2, AU3, AU4 and AU5 are merged.
AU6 (the capacity watchdog) is the open step. The contract hardening tasks in
`03_contracts.md` (§1.7, §2.3, §7.1) must merge before AU10, AU19 and AU28
respectively.
**Priority:** Row 22 of [`../ROADMAP.md`](../ROADMAP.md). S0 is production
relief and continues before the owner orders the rest.
**Origin:** Four directions from an external system architect (quoted in
§1.1), plus the owner's remarks of the same day: long waits must not hold
PHP, a smart but simple watchdog directs the work, and every request is
measured from start to end and shown to whoever decides on servers, on
Docker as on Kubernetes.
**Goal:** Every AI call goes through one pipeline with hooks. A provider is
one class that describes itself. Every outbound HTTP request goes through one
client that honours the enterprise proxy and an allowlist. Every tool call
goes through one invoker that applies approvals and audit. A server holds
hundreds of AI streams instead of as many as it has PHP workers, and an
administrator sees how loaded each server is.
**Binding UX:** [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) (U1–U12) for
AU4, AU7, AU21, AU25b, AU31b, AU33. Everything else is invisible to people when it
works, and the plan measures that it stays invisible (§6).
**Class:** per step in §5. Most steps are `backend-only`; runtime and
infrastructure steps are `no-app-impact`; six steps are `ota-candidate`.
Nothing touches a `store-required` path.
**Prerequisite:** none on `main`. Ask-first rows: §0 #10, #13, #15, #18
(Docker / compose / CI), #15 and #10 (Go dependency), #25 (schema) — all
approved in the 2026-10-21 pre-flight session (Agree column of §0).

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Start here: decisions, facts, target shape, journeys, step table, gates, risks |
| [`01_vibe_coding_loop.md`](./01_vibe_coding_loop.md) | The loop every step runs: tests first, flags, sprint test with a performance layer, pull request, Copilot, CI, merge hand-off, stop rules |
| [`02_measurements.md`](./02_measurements.md) | The benchmarks the architect asked for, measured on 2026-10-21, and the sizing method |
| [`03_contracts.md`](./03_contracts.md) | The contracts: call pipeline and hooks, provider descriptor, egress policy, tool invoker, worker pools, watchdog, stream pump, run stream, metrics |
| [`04_capacity_and_kubernetes.md`](./04_capacity_and_kubernetes.md) | The extra part the owner asked for: the request timeline, the capacity page, the watchdog as server agent, Kubernetes probes, autoscaling and drain |
| [`S0_protect_and_measure.md`](./S0_protect_and_measure.md) … [`S6_capacity_and_kubernetes.md`](./S6_capacity_and_kubernetes.md) | One file per sprint: one vibe brief per step, sprint test, exit criteria |
| [`STATUS.md`](./STATUS.md) | Step log, measurements, review log |
| [`HANDOVER.md`](./HANDOVER.md) | 2026-10-22 handover, updated 2026-10-24: AU3, AU4 and AU5 merged, AU6 in progress, second opinion on S5 |
| [`spike/`](./spike/README.md) | The 2026-10-21 runtime spike: scripts that produced every number in `02` |

---

## Start here (60 seconds)

**What changes, in one picture:**

```text
today                                     after this track
-----                                     ----------------
chat / widget  ─┐                         chat / widget / /v1 / /v1/messages / voice / sorter / RAG
/v1 gateway    ─┼─ three ways to a        └─► AiFacade ─► AiCallPipeline (hooks) ─► provider
/v1/messages   ─┘  provider                                 rate limit · circuit · health · usage · metrics · audit

~20 name-keyed tables per provider        Provider::descriptor() ─► ProviderCatalog ─► every table asks it

76 HttpClient users, 9 raw cURL,          EgressHttpClient: HTTPS_PROXY / NO_PROXY · allowlist · metrics
6 SDK stacks, no proxy setting            (+ optional egress gateway for the sidecars)

3 agent loops + 22 runners, the gate      ToolInvoker: registry · gate · executor · clamp · audit · usage
skipped by two of them

one PHP worker per AI stream              watchdog (Go) holds the client · stream pump (PHP) holds the
(24 per node), API stalls when full       provider · PHP workers only for short steps · capacity page
```

**Where it lives today:**

| You want to change… | Go to… |
| ------------------- | ------ |
| The AI entry point every product path uses | `backend/src/AI/Service/AiFacade.php` (1,747 lines, in the size ledger), `executeWithHealth()` / `observing()` |
| Provider lookup by capability | `backend/src/AI/Service/ProviderRegistry.php` (tagged locators `app.ai.<capability>`), `backend/config/services.yaml` |
| The gateways | `backend/src/Controller/OpenAICompatibleController.php`; `backend/src/AI/Messages/MessagesGateway.php` (1,051) and `Translator/*` (bypass `AiFacade`) |
| Metering | `backend/src/Service/RateLimitService.php::recordUsage()`, called by each caller; `Service/Usage/UsageRecorded.php` (no listener) |
| Provider tables keyed by name | `AI/Credential/ProviderKeyStore.php`, `ProviderKeyCatalog.php`, `ProviderDefaultsService.php`, `AI/Messages/Translator/ChatCompletionsUpstreams.php`, `AI/StructuredOutput/StructuredOutputCapability.php`, `AI/ToolCalling/ToolCallingCapability.php`, `AI/Tool/CatalogToolUse.php`, `AI/Provider/ReasoningLevelCatalog.php`, `AI/Health/Probe/PlatformKeyModelListProbe.php`, `Command/SyncModelPricesCommand.php`, `Service/Voice/VoiceLiveConcurrency.php`, `Controller/AdminProviderKeysController.php`, `Service/Admin/SystemConfig/Tab/AiTab.php`; frontend `utils/providerIcons.ts`, `utils/providerHelp.ts` |
| Outbound HTTP | 76 classes inject `HttpClientInterface`; raw cURL in `MediaDownloader`, `MediaGenerationService`, `OpenAICurlTransport`, `CloudflareProvider`, `SystemConfigService` (×5); SDK stacks: openai-php, Ollama, Stripe, Google API client, Apple App Store library |
| SSRF guard and pinned connect | `Service/Security/SsrfGuard.php`, `Service/Tool/Custom/HttpToolExecutor.php` |
| The Secure Compute egress proxy | `sidecars/synaplan-compute/internal/egress/` (Go CONNECT proxy, PHP-pinned IPs) |
| Tool loops | `Service/Document/ChatToolLoop.php` (no gate), `AI/Messages/Tools/GatewayToolLoop.php` (1,432), `AI/OpenAI/OpenAiGatewayToolLoop.php` (626), runners in `Service/Multitask/Execution/Runner/` |
| Tool policy | `Service/Tool/ToolRegistry.php`, `ToolExecutionGate.php`, `Policy/ApprovalPolicy.php`, `Service/Multitask/Execution/StepApprovalGate.php`, audit `Service/Iam/AuditLogWriter.php` → `BAUDITLOG`, shown in `components/people/AuditTab.vue` |
| The PHP runtime | `_docker/backend/Caddyfile`, `_docker/backend/synaplan-worker-mode.Caddyfile` (`num {$FRANKENPHP_WORKER_NUM:16}`), base image `synaplan-base-php`; production values in `synaplan-platform/docs/PHP-RUNTIME-SIZING.md` |
| Streams and re-attach | `Controller/StreamController.php`, `Service/Chat/Stream/ChatStreamOrchestrator.php`, `Service/Chat/Run/ChatRunBuffer.php` (Redis ZSET), `Controller/StreamAttachController.php`, `Service/Realtime/SseWriter.php`, `Service/Chat/Stream/CancelledTurns.php` |
| Status for administrators | `/admin/features` → `views/FeatureStatusView.vue`; `Service/Config/FeatureStatusReporter.php`; the event ring `Observability/EventRingStore.php` (`docs/OBSERVABILITY.md`) |
| Kubernetes | `synaplan-charts/charts/synaplan` (probes on `/`, CPU HPA off by default, `resources: {}`) |

**The loop that keeps you safe** ([`01_vibe_coding_loop.md`](./01_vibe_coding_loop.md)):
characterization first → the change behind a default-off flag → shadow or
report mode where the change has one → the sprint test (proof, regression,
journey, performance) → one installation opts in → seeded default.

**Rules for the AI assistant driving this:**

- One step = one pull request. The title comes verbatim from §5; the type
  decides the release version (`feat` raises the minor).
- A claim about FrankenPHP, Caddy, Symfony HttpClient, Go or Kubernetes is
  proven by a script in `spike/` or a test, never "should work". Record what
  you ran in STATUS.
- Characterization snapshots are written on the untouched code first. A
  `refactor:` with a snapshot diff is wrong. A `fix:` quotes its diff.
- Never change an SSE event name or payload shape: web chat, widget, Desktop
  and the mobile apps read them (`docs/ARCHITECTURE.md`, chat stream).
- The watchdog never parses an AI wire format. If a step needs it to, the
  design is wrong; stop and ask.
- `AiFacade`, `GatewayToolLoop`, `MessagesGateway`, `McpServerFactory` only
  shrink. New code goes into new classes (size ratchet, deptrac).
- Keys, tokens, prompts and answers are never logged, never in a metric
  label, never in the request timeline. Allow-list fields only, as the event
  ring does.
- No hostnames, IPs or secrets of real installations: this repository is
  public. Production notes go to `synaplan-platform`.
- Docker / compose / CI, a new dependency (Composer, npm, Go module) and a
  schema change are ask-first rows in §0. Stop if a step needs one that is
  not ticked.

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | **Order.** S0 (protect and measure) first, because it relieves production at once and every later sprint is judged by its numbers. Then S1 one AI path → S2 central egress → S3 providers as units → S4 one tool path → S5 long waits leave PHP → S6 capacity, Kubernetes, sizing. S2 may start once AU10 has merged; S3 needs AU15; S5 needs AU12, AU17 and AU24. At most two sprint branches open at a time. | Proposed | Yes — 2026-10-21 |
| 2 | **The one AI path lives under `AiFacade`.** `AiFacade` stays the API callers use; inside it every capability call becomes an `AiCall` that runs through `AiCallPipeline`. The Messages gateway translators, live voice usage, Perplexity web answers, the LLM reranker and the model probes join the same pipeline; probes carry a `probe` context that metering ignores. No second facade, no new entry point. | Proposed | Yes — 2026-10-21 |
| 3 | **Hooks are tagged PHP services** (`app.ai.call_hook`, `#[AsAiCallHook(priority, blocking, budgetMs, onError, flag)]`; the phase is the method — `before()` or `after()` — not an attribute field, `03` §1.2). A non-blocking hook may touch only memory, APCu or Redis. A hook that calls a network service or a model is `blocking`: off by default, switched on per installation, with its budget shown on the capacity page. A budget is **measured, not preemptive**: PHP cannot interrupt a running hook, so a blocking hook must pass its own budget as the timeout of the call it makes; the pipeline times every hook and applies `onError` when a `before()` returns over budget (`03` §1.4). Work that can wait (memory extraction, summaries) stays a Messenger job. Out-of-process hooks come later through row 18's plugin services (§9). | Proposed | Yes — 2026-10-21 |
| 4 | **Hook budget.** Pipeline plus default hooks ≤ **1 ms p95** per call, measured by `make perf-runtime` (§6). A blocking hook declares its budget and enforces it cooperatively — its remaining budget becomes the total-duration deadline (`max_duration`) of the I/O it makes, one deadline shared by its retries, never reset (`03` §1.4): observability hooks fail open, policy hooks fail closed, declared per hook. Today the gateways add 10–50 ms before the first text token (worker mode, warm connections) and the app chat's pre-call work ≈ 1.5 s (`02` §3); the pipeline must not add to either measurably. | Proposed | Yes — 2026-10-21 |
| 5 | **Metering in the pipeline, exactly one writer per billable call at every step.** `UsageHook` writes the `BUSELOG` row and `RateLimitHook` checks before the call. `AI.USAGE_HOOK` = `legacy` (callers write) → `shadow` (callers write, the hook compares; seven days in production) → `on` (the hook writes for paths that joined the pipeline). Callers' writes go through `LegacyUsageWriter`, which stays until one release after `on`, so every rollback keeps one writer; the gateway and live voice keep their own writers until AU12 joins them (`03` §1.5). | Proposed (changed after review) | Yes — 2026-10-21 |
| 6 | **A provider describes itself in typed PHP.** `public static function descriptor(): ProviderDescriptor` on the provider class: name, display name, capabilities, endpoints, key requirements, dialects, defaults, icon, help URL (`03` §2). DI tags come from an attribute, not `services.yaml`. `ModelCatalog` rows stay data. JSON descriptors only for OpenAI-compatible providers a plugin declares, later (row 18). | Proposed | Yes — 2026-10-21 |
| 7 | **The frontend reads provider metadata from a new endpoint** (`GET /api/v1/ai/providers`), never from runtime config (`stores/config.ts` and `*RuntimeConfig*` are `store-required`). | Locked (mobile policy) | Yes — 2026-10-21 |
| 8 | **One outbound HTTP client.** Symfony HttpClient is the only HTTP stack; `EgressHttpClient` decorates the default `http_client` service. Modes `off` \| `report` \| `enforce`, default `off`; `report` for a week before `enforce`. Allowlist = endpoints the enabled providers declare + configured integrations + administrator entries. `HTTPS_PROXY` / `NO_PROXY` are honoured explicitly, `EGRESS_PROXY` overrides. URLs a person types keep `SsrfGuard` and the pinned connect; behind a proxy the pin is kept by tunnelling to the pinned IP, and through the egress gateway by a request-scoped grant (`03` §3.6). | Proposed (changed after review) | Yes — 2026-10-21 |
| 9 | **Non-HTTP traffic is listed, not wrapped:** MariaDB, Redis, SMTP, IMAP, gRPC to Triton, the voice sidecar's provider WebSockets. `docs/EGRESS.md` names each with its setting. The egress gateway (#10) covers WebSockets through CONNECT. | Proposed | Yes — 2026-10-21 |
| 10 | **Optional egress gateway** for every Synaplan service: the CONNECT proxy of `sidecars/synaplan-compute/internal/egress` as its own service; PHP, voice, transcriber and compute send through it; it enforces the allowlist and chains to the enterprise proxy. **Docker / compose — ask first.** | Ask | Yes — approved 2026-10-21 |
| 11 | **One tool invoker.** `ToolInvoker::invoke()` runs registry → gate → executor → clamp → audit → usage → metrics for every loop. The gate always runs. Document tools on the person's own files are `write` with policy `auto` (no new prompt) and are audited. `tool.executed` rows go to `BAUDITLOG` for write and destructive calls; reads become metrics only, to keep the audit log readable. | Proposed | Yes — 2026-10-21 |
| 12 | **Synaplan's own MCP server tools** (`memory_add`, `file_ingest`, …) go through the invoker for audit. Alternative: they keep their own path and only gain the audit row. | Proposed | Yes — 2026-10-21 |
| 13 | **Two PHP worker pools.** `streams` takes the long routes (`/api/v1/messages/stream`, `/api/v1/messages/stream/attach`, `/api/v1/widget/*/message`, `/v1/chat/completions`, `/v1/messages`, the dictation session stream, `/api/v1/tts/stream`, `/mcp`; `03` §5, guarded by `LongRouteListTest`); `api` takes the rest. `FRANKENPHP_STREAM_WORKER_NUM` defaults to `FRANKENPHP_WORKER_NUM`, so stream capacity stays as today and the API gets its own; `FRANKENPHP_NUM_THREADS` / `FRANKENPHP_MAX_THREADS` become settings. Up to twice the database connections until AU5 lands; ops checks Galera's `max_connections` first (`02` M2). **Docker — ask first.** | Ask | Yes — approved 2026-10-21 |
| 14 | **A full server answers at once.** `max_wait_time` 15 s; Caddy turns FrankenPHP's 503 into `{"error":"server_busy","retryAfter":5}` with `Retry-After: 5` (OpenAI- and Anthropic-shaped on `/v1/*`, so SDKs retry by themselves). Web chat and widget retry three times with backoff and then say so (AU4). | Proposed | Yes — 2026-10-21 |
| 15 | **The watchdog** is one Go binary, `sidecars/synaplan-watchdog`, built into the backend image and started by the `web` role next to FrankenPHP, restarted when it exits, off with `SYNAPLAN_WATCHDOG=0`. Relay on `127.0.0.1:8097`, probes and metrics on `:8098`. It never parses an AI wire format. Standard library plus `github.com/redis/go-redis/v9`. **New Go dependency and Docker — ask first.** | Ask | Yes — approved 2026-10-21 |
| 16 | **Not a Caddy module.** A panic in a Caddy module stops FrankenPHP and every request with it; a separate process fails alone. On Kubernetes the same binary can run as its own container in the pod. | Proposed | Yes — 2026-10-21 |
| 17 | **The stream pump is PHP.** One PHP process held 1,000 provider streams at ≈ 134 MB resident, ≈ 73 KB each with libcurl's buffers (`02` §4.4), so the decoders and translators stay in one language and one test suite. It runs as a new role `pump` of the backend image, like `worker`. Its queue carries opaque ids; job bodies expire one by one and hold no provider key; each start has its own incarnation id, and stopping a pump is a drain that ends every active turn honestly (`03` §7). | Proposed (changed after review) | Yes — 2026-10-21 |
| 18 | **Run events move to a Redis Stream** (`XADD chatrun:{id}:stream 0-{seq}`) so the watchdog block-reads many runs in one call. Entries say `frame`, `terminal` or `gap`, so the watchdog closes without reading a frame; no silent trimming; the attach cursor and SSE `id:` lines keep today's meaning (`03` §8). `StreamAttachController` reads both shapes during the change. New role in the compose files — **Docker — ask first.** | Ask (changed after review) | Yes — approved 2026-10-21 |
| 19 | **Attach token:** HMAC-SHA256 over run id, owner key, resume cursor and expiry (30 min) with an installation secret (encrypted in BCONFIG); the watchdog verifies it without asking PHP. | Proposed | Yes — 2026-10-21 |
| 20 | **Capacity page:** Admin → System → **Capacity** at `/admin/capacity`, sibling of Features, administrators only. Copy says "server", never "node" or "container" (UX contract §8); Kubernetes facts sit in a technical row. | Proposed | Yes — 2026-10-21 |
| 21 | **Metrics:** Prometheus text on the watchdog's `:8098/metrics` (FrankenPHP's own metrics merged with Synaplan's). The in-app page needs no Prometheus: the watchdog writes snapshots and one-minute aggregates to Redis. | Proposed | Yes — 2026-10-21 |
| 22 | **Request timeline:** one redacted record per request (route class, status, durations, AI calls, tool calls, outbound calls, database time) in a Redis ring (10,000 records, 24 h), built from an allow-list like the event ring. No table. | Proposed | Yes — 2026-10-21 |
| 23 | **Kubernetes:** liveness → watchdog `/live`, readiness → watchdog `/ready` (false while PHP misses its own probe for 2 s, Redis is down, or the server drains); HPA on `synaplan_stream_slots_busy_ratio` through KEDA or prometheus-adapter, off by default; `preStop` drain. In `synaplan-charts`, with that repo's gate. | Proposed | Yes — 2026-10-21 |
| 24 | **Flags.** Boot-time settings are env and therefore per server (pools, `max_wait_time`, watchdog, `SYNAPLAN_RELAY`). Runtime switches are global BCONFIG and therefore installation-wide (`RUNTIME.STREAM_PUMP`, `EGRESS.MODE`, `AI.USAGE_HOOK`, …): a per-server rollout or rollback uses an env switch, never a BCONFIG one. Every new behaviour has a kill switch and is off until its soak; then seeded on for new installs, and existing installs get it through an explicit migration `UPDATE` (AGENTS.md: BCONFIG defaults are bootstrap-only). | Proposed (changed after review) | Yes — 2026-10-21 |
| 25 | **No schema change.** Timelines and snapshots in Redis, audit in `BAUDITLOG`, settings in BCONFIG. A step that needs a table stops and asks. | Proposed | Yes — 2026-10-21 |
| 26 | **Cross-repo.** AU8 and AU34 land in `synaplan-charts`; rollout notes for the web nodes in `synaplan-platform` (private, no IPs here); no base-image change (the worker Caddyfile override lives in this repo). | Proposed | Yes — 2026-10-21 |
| 27 | **The numbers are published.** `02_measurements.md` answers the architect now; `docs/CAPACITY.md` (AU35) is the maintained version with the production figures of AU6. | Proposed | Yes — 2026-10-21 |

**Decision record — pre-flight session 2026-10-21 (AU0b).** All 27 rows
agreed as written (the Ask rows #10, #13, #15, #18 and the schema row #25
are approved). In addition the owner decided:

- **Prompt handling is out of scope** (§8). Prompt assembly, the sorter
  prompt, context and token budgets, `BPROMPT` topics and memory injection
  do not change in this track.
- **Plugin development is AU22 only.** A provider in one file is the plugin
  surface of this track; manifests, out-of-process hook and tool services
  and provider-as-plugin JSON descriptors stay with row 18 (§9 #3–4).
- **Autonomy is "merge-ready, then pause".** The agent runs a step
  uninterrupted to a green, merge-ready pull request and then pauses; the
  owner merges, and soaks (AU11, AU15/AU18, AU31) and production rollouts
  are planned pauses, not waits inside a session (`01` §1, §11).
- **Copilot review is capped at three rounds**; a fourth means the step is
  unclear and the owner decides (`01` §9).
- **Remote final test** means: GitHub CI green, the load cases on the
  `vultr-cluster` load-test namespace, and a read-only one-node production
  soak by ops — no load test against production (§6, §8).
- **Model routing:** one lead model executes every step (continuity of
  style and context); a second model reviews read-only at the exits of
  S1, S4 and S5; a fast model may take AU1 and AU14 only. One checkout is
  written by one session at a time (`01` §2).

---

## 1. Why — facts this plan rests on

### 1.1 The architect's four directions (2026-10-21, abridged)

1. **One path for all AI traffic, with pre- and post-call hooks.** The app's
   chat (`AiFacade`) and the `/v1` and Anthropic-compatible gateways reach the
   providers on separate paths. One path makes observability, cost tracking,
   logging, cheaper routing, redaction, policy and audit pluggable. Open
   question: how far it scales when each streaming request holds a PHP worker
   and hooks that call a model hold it longer; benchmarks wanted.
2. **Providers as self-contained units.** Adding a provider touches about 20
   files keyed by its name; a provider should declare capabilities,
   endpoints and key requirements once, and everything else asks the
   registry. Providers then become a natural plugin type.
3. **Central egress.** One HTTP client everywhere, no raw cURL or SDK
   stacks, `HTTPS_PROXY` / `NO_PROXY`, a per-destination allowlist; extend
   the Secure Compute egress proxy to the whole app.
4. **One tool execution path.** Chat, gateways and multitask runners run
   tools in several loops; one path guarantees approvals and audit for every
   call, and fits the out-of-process plugin plan.

The owner added: long waits must leave PHP idle, a watchdog directs requests
to the logic — enterprise-like, simple, smart — and every request is measured
from start to end and shown for DevOps decisions, including Kubernetes.

### 1.2 Verified against `main` at `4dd1b26da`

| # | Fact | Evidence |
| - | ---- | -------- |
| F1 | **Three AI paths.** Product paths use `AiFacade` → `ProviderRegistry`. `MessagesGateway` reaches providers through its own translators (Anthropic passthrough, OpenAI and Gemini translation over `HttpClient`). Live voice opens provider sockets in the Node sidecar. Perplexity web answers call `PerplexityProvider::chat()` directly. Probes call providers without the facade. | Code map in STATUS 2026-10-21 |
| F2 | **Metering is the caller's job.** `AiFacade` records model health and the circuit breaker (`executeWithHealth()`), not usage. Each caller calls `RateLimitService::recordUsage()`; a new call site that forgets it is unmetered. `UsageRecorded` is dispatched but has no listener. There is no `*Listener`, `*Middleware` or `*Interceptor` under `backend/src/AI/`. | `AiFacade.php` 1343–1379 |
| F3 | **Providers are name-keyed in ~33 places.** A Cerebras-class provider needs 15–21 spine files; the Cerebras pull request touched 41, Meta 54, Higgsfield 50, Perplexity 72. `ProviderRegistry` already answers who exists and the display name; keys, endpoints, dialects, defaults and icons are separate tables. | `git show --stat 78f55bcf6`, `c1aa202a4`, `d90f85ae5`, `752f178e2` |
| F4 | **Outbound HTTP has several stacks.** 76 classes inject Symfony `HttpClientInterface` (honours proxy env, no `default_options`, no scoped client); 9 raw `curl_init`; 1 `file_get_contents` fallback; 1 `HttpClient::create()`; openai-php, the Ollama SDK, Stripe, the Google API client and the Apple library carry their own. No proxy setting anywhere in the compose files. A per-destination allowlist exists only for compute runs. | Egress map in STATUS |
| F5 | **Tools run in three agent loops and 22 runners.** `ChatToolLoop` never calls `ToolExecutionGate`; `McpFetchRunner` neither; both gateway loops call it only when approvals are on. Audit rows exist only for approval events, not for executed tools. Caps (8 iterations, 240 s, 16 tools, 12,000 characters) are copied three times. | Tool map in STATUS |
| F6 | **One PHP worker per stream.** Production web nodes run 24 workers; the 25th stream waits, and so does every other request (`02` §2). | `02` §1 |
| F7 | **Waits that are not streams.** Video renders poll with `sleep()` in `GoogleVideoGeneration`, `HuggingFaceProvider`, `HiggsfieldProvider`; the async media job is the default, but `force_inline_media` (a multitask node another node depends on) and `MediaGenerationService::generateVideo()` still poll inside a request. | `MediaJobDetach.php` 34–41 |
| F8 | **Re-attach exists.** `ChatRunBuffer` keeps every SSE frame of a run in Redis (30 min while running), and `GET /api/v1/messages/stream/attach` replays it after a reload. This is the base of the watchdog design: frames already live outside the request. | `ChatRunBuffer.php` |
| F9 | **FrankenPHP 1.12.6 already has what S0 needs:** worker `name` and `match`, global `max_wait_time`, Prometheus metrics (`frankenphp_busy_workers`, `queue_depth`, `worker_request_time`, …) behind the Caddy `metrics` option, and the per-thread admin endpoint `/frankenphp/threads`. None is switched on today. | `02` §4.1, §4.2 |
| F10 | **Kubernetes cannot see the limit.** The chart's probes request `/`, which Caddy serves without PHP, so a pod with every worker held stays ready. The optional HPA scales on CPU at 80 %; a server saturated with streams uses about 4 % (`02` §2.3). | `synaplan-charts/charts/synaplan/values.yaml` |
| F11 | **The health check spawns a program.** `GET /api/health` starts `whisper --help` on every call and caps at ≈ 80 requests/s whatever the worker count. | `02` §2.6 |

---

## 2. Target shape

```text
Browser · widget · SDK · Desktop · apps
  │
Caddy (FrankenPHP) ──── /connection/* ─► Centrifugo (unchanged)
  │ long routes                                   │ everything else
  ▼                                               ▼
watchdog (Go, one per server)               PHP pool "api"   (short requests)
  · admission: free stream slots? else "busy, retrying" (AU31)
  · forwards the start request ─► PHP pool "streams": prepare the turn
  │                                   (auth, limits, routing, pre-call hooks)
  │                                   └─► enqueue the provider stream ─┐
  · tails the run stream in Redis ◄────────────────────────────────────┤
  · keep-alive, cancel on disconnect, a terminal frame guaranteed      │
  · capacity agent: snapshots, /metrics, /ready, /live (AU6)           │
                                                                       ▼
                                   stream pump (PHP, few processes per server)
                                     · AiCallPipeline → EgressHttpClient → provider
                                     · incremental decoders → client frames → run stream
                                     · tool call → ToolInvoker job (worker) → next round
                                     · end → finish job (store reply, usage, memory)

Every AI call ─► AiCallPipeline: rate limit · circuit · health · usage · metrics · audit · later: redaction, routing
Every outbound HTTP request ─► EgressHttpClient: proxy · allowlist · per-destination metrics
Every tool call ─► ToolInvoker: registry · gate · executor · clamp · audit · usage · metrics
Every provider ─► descriptor() ─► ProviderCatalog ─► keys · endpoints · dialects · defaults · icons · allowlist
```

With the watchdog off (`SYNAPLAN_WATCHDOG=0`) or the pump off
(`RUNTIME.STREAM_PUMP=0`), streams run in the PHP `streams` pool exactly as
today. That is the fallback at every step of S5.

---

## 3. Journeys

Details, wireframes and copy in the sprint files and in
[`04_capacity_and_kubernetes.md`](./04_capacity_and_kubernetes.md) §4.

| Journey | Who | Walked in |
| ------- | --- | --------- |
| **J-AU-1** — Ben chats while the server is full: he sees "busy, starting in a moment", then the answer; after three tries an honest sentence that nothing was sent yet | Ben | AU4, again in AU31 |
| **J-AU-2** — Olga opens Capacity, sees how loaded each server is, and decides whether to add one | Olga (operator, administrator) | AU7, AU33 |
| **J-AU-3** — Olga sends all outbound traffic through the company proxy: report mode, the Outbound tab, missing entries added, enforce | Olga | AU15, AU33 |
| **J-AU-4** — A developer adds an OpenAI-compatible provider with one class and model rows; it appears under AI keys, in the model list and in the allowlist | Dev | AU22 |
| **J-AU-5** — Ada finds every tool call in the audit log: a document edit in chat, an MCP write from a gateway, a Saved Task step | Ada (administrator) | AU25b |
| **J-AU-6** — Ben's answer keeps streaming in the open page while the server it started on restarts | Ben | AU31b, again with AU34 |

---

## 4. What we do not rebuild

| Already here | Use it |
| ------------ | ------ |
| One facade for product AI calls, circuit breaker and model health | `AiFacade`, `CircuitBreaker`, `ModelHealthRecorder` — they become the first hooks |
| Capability locators | `ProviderRegistry` |
| Recorded provider requests for characterization | `tests/Characterization/Support/ProviderHttpRecorder.php`, `OpenAISdkRecorder`, `OfflineHttpGuard.php` |
| Stream transcripts, message turn and routing snapshots | `tests/Characterization/` (`StreamTranscriptCharacterizationTest`, `MessageTurnCharacterizationTest`, `RoutingCharacterizationTest`) |
| A Messages translator seam | `MessagesTranslatorInterface`, tag `app.messages.translator` |
| SSRF guard with operator mode and pinning | `SsrfGuard::pinnedIps()`, `HttpToolExecutor` (`resolve`, no redirects) |
| A Go CONNECT proxy that dials only pinned IPs | `sidecars/synaplan-compute/internal/egress/` |
| Tool registry, sources, gate, policy, approvals inbox | `ToolRegistry`, 7 `ToolSourceInterface` sources, `ToolExecutionGate`, `ApprovalPolicy`, `ApprovalService` |
| Audit log with an admin view | `AuditLogWriter` → `BAUDITLOG`, `GET /api/v1/admin/audit`, `AuditTab.vue` |
| Redacted operational events | `EventRingStore`, `EventScrubber`, `X-Request-Id` (`docs/OBSERVABILITY.md`) |
| Per-phase timings of a chat turn | `PerfTimer`, the `perf` SSE event, `app:perf:chat-stream` |
| Re-attachable runs, cancel | `ChatRunBuffer`, `StreamAttachController`, `CancelledTurns` |
| Async media renders | `MediaJobDetach`, `AdvanceMediaJobCommand` |
| Background roles in one image | `_docker/backend/lib/container-runtime.sh` (`web`, `worker`, `scheduler`) |
| Status page for administrators | `/admin/features`, `FeatureStatusReporter`, module probes |
| A Go sidecar with its own Makefile, tests and image | `sidecars/synaplan-compute` |
| Load tests with Prometheus and Grafana on Kubernetes | `vultr-cluster/load-testing` |

---

## 5. Step table

Ids are stable; other plans may cite them.

| Step | Commit title (= pull-request title) | Class | Depends |
| ---- | ----------------------------------- | ----- | ------- |
| AU0 | `docs(planning): architecture update — one AI path, providers as units, central egress, one tool path, a runtime that does not wait` | no-app-impact | — |
| AU0b | `docs(planning): close the architecture-update pre-flight — §0 ticked, scope and loop amended` | no-app-impact | AU0 |
| **S0 — Protect and measure** ([file](./S0_protect_and_measure.md)) | | | |
| AU1 | `chore(perf): runtime benchmarks as make targets` | no-app-impact | AU0 |
| AU2 | `fix(health): the health check no longer starts a program on every call` | backend-only | AU0 |
| AU3 | `fix(runtime): AI streams get their own PHP pool and a full server answers at once` | no-app-impact | AU1, §0 #13 #14 |
| AU4 | `fix(chat): a busy server says so and retries instead of hanging` | ota-candidate | AU3 |
| AU5 | `fix(chat): a waiting stream gives its database connection back` | backend-only | AU1 |
| AU6 | `feat(runtime): a watchdog on every server reports its capacity` | no-app-impact | AU3, §0 #15 #21 |
| AU7 | `feat(admin): capacity of every server on the system status page` | ota-candidate | AU6, §0 #20 |
| AU8 | `fix(kubernetes): probes follow PHP capacity, metrics are scraped` (in `synaplan-charts`) | no-app-impact | AU6, §0 #23 #26 |
| **S1 — One AI path** ([file](./S1_one_ai_path.md)) | | | |
| AU9 | `test(ai): characterization of every AI call path` | backend-only | AU0 |
| AU10 | `refactor(ai): one call pipeline with ordered hooks under AiFacade` | backend-only | AU9, §0 #2 #3 #4, hardening `03` §1.7 merged |
| AU11 | `fix(ai): every AI call is metered once, in the pipeline` | backend-only | AU10, §0 #5 |
| AU12 | `refactor(ai): the Messages gateway, live voice and direct provider calls join the pipeline` | backend-only | AU11 |
| AU13 | `feat(observability): every request and AI call timed from start to end` | backend-only | AU10, §0 #22 |
| **S2 — Central egress** ([file](./S2_central_egress.md)) | | | |
| AU14 | `test(egress): gate new outbound HTTP stacks` | backend-only | AU0 |
| AU15 | `feat(egress): one outbound HTTP client with proxy settings and a destination allowlist` | backend-only | AU14, AU10, §0 #8 |
| AU16 | `refactor(egress): raw cURL calls use the egress client` | backend-only | AU15 |
| AU17 | `refactor(egress): SDK clients send through the egress client` | backend-only | AU15 |
| AU18 | `feat(egress): an optional egress gateway for every Synaplan service` | no-app-impact | AU15, §0 #9 #10 |
| **S3 — Providers as units** ([file](./S3_providers_as_units.md)) | | | |
| AU19 | `refactor(ai): every provider declares its capabilities, endpoints and keys` | backend-only | AU10, §0 #6, hardening `03` §2.3 merged |
| AU20 | `refactor(ai): provider tables ask the provider catalog` | backend-only | AU19 |
| AU21 | `refactor(frontend): provider names, icons and help come from the provider catalog` | ota-candidate | AU20, §0 #7 |
| AU22 | `feat(ai): add an OpenAI-compatible provider in one file` | backend-only | AU20, AU15 |
| **S4 — One tool path** ([file](./S4_one_tool_path.md)) | | | |
| AU23 | `test(tools): characterization of every tool loop` | backend-only | AU0 |
| AU24 | `refactor(tools): one tool invoker behind every loop` | backend-only | AU23, §0 #11 |
| AU25 | `fix(tools): every tool call passes the approval gate and is audited` | backend-only | AU24, §0 #11 #12 |
| AU25b | `feat(people): tool calls in the audit log` | ota-candidate | AU25 (stacked, merged right after) |
| AU26 | `refactor(tools): one agent loop for document chat and both gateways` | backend-only | AU25 |
| **S5 — Long waits leave PHP** ([file](./S5_long_waits_leave_php.md)) | | | |
| AU27 | `fix(media): no web request waits for a render` | backend-only | AU0 |
| AU28 | spike: watchdog relay and stream pump end to end (STATUS entry; branch never merged) | no-app-impact | AU6, AU12, hardening `03` §7.1 merged |
| AU29 | `refactor(ai): provider streams as a request plus an incremental decoder` | backend-only | AU17, AU28 |
| AU30 | `feat(runtime): a stream pump reads provider streams outside the web workers` | backend-only | AU29, AU24, §0 #17 #18 |
| AU31 | `feat(runtime): the watchdog holds AI streams so PHP never waits` | backend-only | AU30, §0 #15 #19 |
| AU31b | `feat(chat): an answer that loses its connection continues in the open page` | ota-candidate | AU30 (can land before AU31) |
| AU32 | `feat(runtime): chat, widget and both gateways stream through the watchdog by default` | backend-only | AU31, AU31b, J-AU-1 and J-AU-6 walked |
| **S6 — Capacity, Kubernetes, sizing** ([file](./S6_capacity_and_kubernetes.md)) | | | |
| AU33 | `feat(admin): requests, AI calls, tools and outbound traffic on the capacity page` | ota-candidate | AU7, AU13, AU15, AU24 |
| AU34 | `feat(kubernetes): scale on held streams and drain before a restart` (in `synaplan-charts`) | no-app-impact | AU8, AU31 |
| AU35 | `docs(ops): capacity and sizing guide with measured numbers` | no-app-impact | AU6, AU32 |

S0 is useful on its own: after AU3 the API never stalls behind streams, and
after AU7 an administrator sees the limit. S1–S4 change no screen except the
provider helpers (AU21) and the audit filter (AU25b). S5 changes where
streams wait, behind a flag, with the PHP pool as the fallback at every step.

---

## 6. Gates

Per commit, unfiltered:

```bash
make ci-local            # lint, phpstan (src + tests), deptrac, PHPUnit, Vitest, vue-tsc
```

Per pull request, in addition:

```bash
make test-e2e                                                   # every step that changes an HTTP contract, a stream or a screen
make -C frontend test-e2e-layout                                # AU4, AU7, AU21, AU25b, AU31b, AU33
make -C frontend generate-schemas && git diff --exit-code frontend/src/generated/   # zero diff unless the step adds an endpoint
docker compose exec -T backend php bin/console lint:container   # every step that adds services, tags or attributes
node scripts/mobile-impact.mjs --base <parent> --head HEAD      # equals the class in §5
make perf-runtime                                               # AU1 onward, for every step in S0, S1, S2, S5 (budgets below)
make -C sidecars/synaplan-watchdog test                         # AU6 onward, for watchdog changes
(cd ../synaplan-charts && make all)                             # AU8, AU34
```

**Performance budgets** (`make perf-runtime`, compared with the baseline in
STATUS; a breach fails the step, a drift of more than 20 % is explained in the
pull request):

| Measure | Budget |
| ------- | ------ |
| `GET /api/health` p95, idle, worker mode | ≤ 15 ms (after AU2: ≤ 5 ms) |
| API probe p95 while the stream pool is saturated | ≤ 50 ms (after AU3) |
| Pipeline overhead per AI call with the default hooks | ≤ 1 ms p95 (AU10 onward) |
| Gateway overhead to the first text token vs. direct, worker mode, warm connections | ≤ the AU1 baseline + 15 ms median (baseline 10–50 ms on 2026-10-21) |
| Watchdog hop added to TTFB | ≤ 5 ms p95 (AU31) |
| Streams held per server with the watchdog and pump | ≥ 500 at 10 s each without a failed stream (AU31) |

### Track exit

- J-AU-1 … J-AU-6 walked and entered in STATUS.
- `AiFacade` is the only way to a provider; `AiPathCharacterizationTest`
  shows one metered row per billable call on every path.
- A new OpenAI-compatible provider is one file plus model rows (J-AU-4).
- `EgressInventoryTest` lists only the non-HTTP protocols of §0 #9; with
  `EGRESS.MODE=enforce` and an empty administrator list the dev stack still
  answers chat, search and the gateways.
- `ToolLoopCharacterizationTest` shows the gate, an audit row for every write
  and a usage row for every billable tool call, on every loop.
- One production web node held its daily peak through the watchdog for a
  week with `queue_depth` at 0 (measured, entered in STATUS).
- `docs/CAPACITY.md` carries the production numbers; plan archived.

**Final acceptance, local and remote** (decided 2026-10-21; testing happens
at every step — this is the last gate, not the only one): locally the full
gate of `01` §6 and the named journey walks; remotely (1) GitHub
**All Checks Passed** on the merged head, (2) the load cases of AU8 and
AU34 on the `vultr-cluster` load-test namespace, and (3) a read-only
one-node production soak by ops with the capacity page read before and
after. There is no load test against production (§8).

---

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| A refactor of the AI path changes what is sent to a provider or billed | Characterization of every path first (AU9), recorded on the untouched code; double entry for metering (§0 #5); flags off ⇒ byte-identical snapshots |
| A hook slows every call or fails every call | Budget per hook, measured on every call (`03` §1.4 — a budget is not a preemptive interrupt, so a blocking hook passes its budget as its own I/O timeout); fail-open for observability, fail-closed only for policy hooks that say so; `make perf-runtime` gate; kill switch per hook |
| Two pools double database connections | AU5 releases the connection of a waiting stream; ops checks Galera `max_connections` before raising pool sizes (M2); pool sizes are settings, rolled out one node at a time |
| A `max_wait_time` that is too short refuses work that would have succeeded | 15 s default (longer than today's p95 wait), the 503 carries `Retry-After`, clients retry; the capacity page counts refusals so the value is tuned on data |
| Enforcing an allowlist breaks a provider or an integration | `report` mode for a week first; the allowlist is built from the enabled providers' descriptors and configured integrations, not typed by hand; the Outbound tab lists every blocked destination with one click to allow |
| A proxy breaks streaming (buffering, timeouts) | Proxy settings are tested with a streaming request in AU15 and AU18; `NO_PROXY` for the internal services; the egress gateway is optional |
| Routing every tool call through the gate prompts people who never had to approve | Document tools on own files are `auto` (§0 #11); the characterization diff names every changed decision; the owner reviews it |
| Audit volume | Write and destructive calls only (§0 #11); reads as metrics |
| The watchdog becomes a second application | It never parses AI formats (rule); it copies frames PHP wrote; a size budget of 2,000 lines of Go outside tests, checked in its own `make test`; Linux only, shipped inside the backend image |
| The pump stalls every stream it holds when one turn blocks | The pump does only non-blocking I/O; tools, persistence and memory go to Messenger jobs; one decoder per turn with a CPU guard; several pump processes per server |
| Streams lost during a deploy | Today they are; after AU30 the run lives in Redis and the pump on its own role, so a web restart drops the client connection only. Today the open page then reloads the turn from history (`memberError.ts`, `recoverInterruptedTurn()`); AU31b makes it re-attach from its last frame instead (J-AU-6); AU34 drains before stopping |
| A user-typed URL loses its DNS pin behind a proxy | `03` §3.6: tunnel to the pinned IP through an enterprise proxy, a request-scoped grant through the egress gateway; rebinding tests in AU15 and AU18 |
| SSE contract drift | `StreamTranscriptCharacterizationTest` runs in both modes (in-request and pump) and must produce identical frames (AU30) |
| Kubernetes autoscaling on a custom metric is not available everywhere | Off by default; KEDA and prometheus-adapter documented; the CPU HPA stays as a fallback with a warning that it does not see streams |
| Three web nodes in production | Everything shared lives in Redis or the database; rollout one node at a time (`updatewebN.sh`); flags global, not per node |
| App release | No `store-required` path; UI steps are `ota-candidate` |

---

## 8. Non-goals

- Rewriting the PHP application in Go, or letting the watchdog understand AI
  formats, tools, prompts or billing.
- Replacing Centrifugo, Messenger or the scheduler.
- A new SSE event, a renamed event, or a changed payload.
- Removing `AiFacade` or the per-person PHP plugins.
- Running hooks or tools inside the watchdog.
- A Kubernetes-only feature: every step works on the compose deployments.
- Load tests against production. Production is measured read-only (AU6).
- A new database table (§0 #25).
- **Prompt handling** (decided 2026-10-21): prompt assembly, the sorter
  prompt, context and token budgets, `BPROMPT` topics and memory injection
  do not change; `AiCallPayload` carries them unchanged. A leaner prompt
  path is a separate plan.
- **Plugin development beyond AU22** (decided 2026-10-21): manifests,
  lifecycles and out-of-process hook, tool and provider services stay with
  row 18. This track's plugin surface is a provider in one file (AU22) and
  the seams it leaves.
- **Non-AI ingress channels:** email and WhatsApp ingestion, file upload
  and Tika, webhooks and Centrifugo keep their adapters; only their AI
  calls join the pipeline.

---

## 9. Later (in this order)

| # | What | Why it waits |
| - | ---- | ------------ |
| 1 | Turn preparation (sorter, embeddings, memory search, ≈ 1.5 s) moves from the `streams` pool into Messenger workers | Measure after AU32 whether it still matters; the sorter is the largest part and decision routing (row 11) may remove it |
| 2 | Hook catalog: redaction before an external provider, routing to a cheaper model, a policy hook per group | Needs AU10 and the owner's choice of the first two |
| 3 | Out-of-process hooks and tools through plugin services (row 18) | Row 18 Phase 3 |
| 4 | Providers as a plugin type: a plugin manifest declares OpenAI-compatible providers as JSON descriptors | AU22 and row 18's manifest v2 |
| 5 | Fair admission per organisation in the watchdog | After the production figures of AU6 show whether one organisation can crowd out the others |
| 6 | OpenTelemetry export of the request timeline | When an operator asks for it; Prometheus first |
| 7 | Desktop and the mobile apps handle `server_busy` with their own copy | Separate repositories; their clients already retry on 503 |
| 8 | Following a run in several tabs through Centrifugo instead of the attach endpoint | After AU31 |
| 9 | The dictation session stream (a PHP poll loop up to 600 s) served by the watchdog from the session store | After AU31; until then it is isolated in the `streams` pool (AU3) |

---

## 10. Change log

| Date | Change |
| ---- | ------ |
| 2026-10-21 | Draft: research, code maps of the four directions, runtime spike (`spike/`), measurements (`02`), contracts, capacity and Kubernetes part, seven sprint files. |
| 2026-10-21 | Copilot review of [#2623](https://github.com/metadist/synaplan/pull/2623), 14 findings, all accepted: the spike scripts count only complete streams, time the first text token over warm connections and report process RSS; every table re-measured (`02`: pump ≈ 134 MB, not 26 MB, for 1,000 streams; gateway overhead 10–50 ms). Contracts: hook phases in one order, one usage writer through every migration step and rollback, pinned requests behind a proxy, a job queue without secrets and with per-job expiry, run-stream entry kinds, no silent trimming, the attach cursor kept. AU31b added (re-attach in the open page); per-server vs. installation-wide switches separated (§0 #24). |
| 2026-10-21 | Copilot review round 2, 11 findings, all accepted: usage stays one row per turn for per-turn actions (`TurnUsageLedger`); egress checks every redirect hop; the pump uses the credential decision of admission (`TurnCredentialResolver`); dead-pump cleanup moved to the scheduler with an atomic transition; the provider enum is dropped instead of hand-kept; the stream pool also takes the dictation stream, TTS stream and `/mcp` (`LongRouteListTest`); AU4 says the message was not sent and puts it back in the input box; AU31b rebuilds the bubble where no cursor exists. Three spike scripts made stricter (line-buffered pump, status-checked probes and micro-requests) and the affected cases re-run: results unchanged. |
| 2026-10-21 | Copilot review round 3 (the last the loop allows), 3 findings, all accepted: the turn usage ledger is shared Redis state with idempotent contributions and close; every pump start gets an incarnation id; pumps have a drain-on-`SIGTERM` contract with rollout and scale-down drills (`03` §7, `04` §5.1, AU30, AU34). |
| 2026-10-21 | Pre-flight (AU0b): all 27 §0 rows ticked in the owner's decision session; scope decided (prompt handling out, plugins = AU22 only, non-AI ingress unchanged); autonomy fixed as "merge-ready, then pause" with a three-round Copilot cap and the owner deciding beyond it; remote final test defined (CI, `vultr-cluster`, one-node soak); the hook budget corrected to measured-not-preemptive (§0 #3, `03` §1.4); contract hardening scheduled before AU10 (`03` §1.7), AU19 (`03` §2.3) and AU28 (`03` §7.1); docs, Go-format and conditional-E2E gate lines added in `01` §6; timeline aggregates store histogram buckets (`04` §2, §7). |

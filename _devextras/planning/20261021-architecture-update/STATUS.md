# Architecture update — status

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk, a
measurement and a rollout are entries here, not chat messages.

## Steps

| Date | Step | Pull request | State | Notes |
| ---- | ---- | ------------ | ----- | ----- |
| 2026-10-21 | AU0 | [#2623](https://github.com/metadist/synaplan/pull/2623) | merged (`ce89e32a4`) | research, spike, plan; three Copilot rounds, all findings accepted |
| 2026-10-21 | AU0b | [#2627](https://github.com/metadist/synaplan/pull/2627) | merged (`5af450f6e`) | Pre-flight: §0 ticked, scope and autonomy decided, hook budget corrected, hardening scheduled before AU10 / AU19 / AU28 |
| 2026-10-21 | AU1 | [#2628](https://github.com/metadist/synaplan/pull/2628) | merged (`ad9a8ed79`) | `make perf-runtime` |
| 2026-10-21 | AU2 | [#2626](https://github.com/metadist/synaplan/pull/2626) | merged 2026-10-22 (`3d4aeddd5`) | probe cache and `/api/health/ready` |
| 2026-10-23 | AU5 | [#2661](https://github.com/metadist/synaplan/pull/2661) | merged (`e6b511122`) | a waiting chat stream and an idle stream worker hold no database connection; 8 streams: 8 held while streaming → 0 |
| 2026-10-24 | AU3 | [#2687](https://github.com/metadist/synaplan/pull/2687) | merged (`87d37ca38`) | two worker pools; a full stream pool answers with `server_busy` while the api pool stays fast. Platform pools set in `synaplan-platform` [#29](https://github.com/metadist/synaplan-platform/pull/29): 16 streams + 8 api |
| 2026-10-24 | AU4 | [#2696](https://github.com/metadist/synaplan/pull/2696) | merged (`121da85ad`) | the chat and the widget say busy, retry three times, then give the text back with a Try again |
| 2026-10-24 | AU6 | [#2724](https://github.com/metadist/synaplan/pull/2724) | merged (`5e7a4ebdd`) | watchdog on `:8098`; `capacity:server:{id}` every 5 s with place and log source. No relay. Not rolled out |
| 2026-10-24 | AU6 (every role) | [#2727](https://github.com/metadist/synaplan/pull/2727) | in review | worker and scheduler report too; ids per role on compose, with [synaplan-platform#33](https://github.com/metadist/synaplan-platform/pull/33); seen-list, day peak, the app's Redis prefix |
| 2026-10-24 | AU7 | [#2729](https://github.com/metadist/synaplan/pull/2729), stacked on #2727 | in review | Admin › Capacity: one card per server with place, logs and "You are connected to"; `X-Synaplan-Server` on every response; `capacity` feature module |

## Baselines

| Date | What | Value | Source |
| ---- | ---- | ----- | ------ |
| 2026-10-21 | Size ledger (`make -C backend metrics`) | not re-counted; `TestProvider.php` stayed at 1044 lines | AU1 |
| 2026-10-21 | Runtime measurements | `02_measurements.md` §1–§4 | `spike/` |
| 2026-10-21 | Health p95, 1 client, worker mode | 5.9 ms and 6.5 ms | `make perf-runtime`, two runs |
| 2026-10-21 | Health p95, 8 clients, worker mode | 35.9 ms and 35.7 ms | recorded; the 15 ms budget is the idle row |
| 2026-10-21 | Runtime-config throughput | 315 and 318 req/s | 4 clients |
| 2026-10-21 | Gateway time to first text token, test-model | 9.3 ms and 13.0 ms p50 | no paid provider |
| 2026-10-21 | Probe p95 while 12 streams hold 8 workers | 10.3 s and 19.9 s | after AU3; not enforced |

---

## 2026-10-24 — AU7: capacity of every server on the system status page

**Where it is.** Admin › Capacity, one click from System status in the
Operate list (U2, U6). `GET /api/v1/admin/capacity/servers` reads the
seen-list and every snapshot from Redis in one request; `served_by` names
the answering server, the same value every response now carries in the
`X-Synaplan-Server` header beside `X-Request-Id`. The `capacity` feature
module is configured while `SYNAPLAN_WATCHDOG` is not `0`: off ⇒ no nav
entry, the route is the not-found page, the API answers the module gate's
404 (U11). The minimal lane sets it to `0`.

**The card.** AI answers and other requests as "n of m at once" with
today's peak and its time (from the watchdog's day peak), memory and
processor against their limits, a sizing sentence once the peak reached
80 % of the slots, and under Technical details: where the server runs
(Docker on synweb100 · pod · deployment · namespace · declared resources),
the PHP pools, the version, and the log row with the one command built from
the watchdog's facts (`docker logs <hostname>`, `kubectl logs -n <ns> <pod>
-c <container>`) plus **Open recent events**, which opens the event ring
filtered by the server's `event_ring_host` and read through the authenticated client, so the native app's Bearer session works like a cookie; the ring's own page stays with AU33. Workers and
the scheduler attach to the web server of their machine (same `place.host`,
or the `-worker` / `-bulk` / `-scheduler` id the platform compose gives
them) as compact rows; one with no web server of its own is a card with a
role chip and no AI bars. A silent server keeps its card, greyed, with
"Last report n min ago — the server may be down or restarting". An id whose
last report has expired (a week) is not a card: there is nothing to show.
The owner's first look on 2026-10-25 found two of my seeded walk entries
drawn as zeroed web cards; the seeds are removed and the rule is the fix.

**Walked (J-AU-2)** on the dev stack as `admin@synaplan.com`, with two
seeded reports beside the real one (a silent `web2`, a `web1-worker`, a
day peak of 14 of 16): the Operate entry appeared next to System status;
the real server's card said "You are connected to this server." and
"Reporting"; the seeded peak produced the sizing sentence; Technical
details read "Docker" and `docker logs 965a483f0450`; the worker rendered
as a role card without AI bars; `web2` greyed with its last report. Light
and dark read; at 320 px the page has no horizontal overflow
(`scrollWidth` 320) and every reading wraps. The light-mode walk found the
peak note overflowing its column and a worker rendered as a web card; both
fixed before the spec was written.

**Gate.** PHPStan, deptrac, php-cs-fixer clean; module tests, the
capacity service, the identity and the event-ring host filter are unit
tested; the frontend ratchet made the route and the nav entry modules of
their own (`router/capacityRoute.ts`, `composables/useCapacityNav.ts`);
`capacityFacts.spec.ts` covers the log command, grouping and the hint;
`capacity.spec.ts` (@ci) walks the real endpoint and the header, a mocked
three-server cluster, and the 503 state; `minimal-stack.spec.ts` asserts
the absent state. Five locales (`capacity.*`, `modules.capacity.label`,
`nav.adminCapacity`, `pageTitles.adminCapacity`).

**Carried.** "Busy answers today" (Caddy's 503 count) and the 24-hour
sparkline stay with AU33's request timeline, which also gives the event ring its page.

---

## 2026-10-24 — AU6: a watchdog on every server reports its capacity

`sidecars/synaplan-watchdog` is one static binary in the backend image. The
web role starts it beside FrankenPHP and starts it again when it exits.
`SYNAPLAN_WATCHDOG=0` does not start it. `:8098` is not published.

Every 5 s it reads `http://127.0.0.1:2019/metrics` and `/frankenphp/threads`,
the cgroup (`memory.max`, `memory.current`, `cpu.max`, `cpu.stat`) and
`/proc` when the cgroup has no usage. Thread request paths are counted and
not stored. It writes `capacity:server:{id}` (TTL 20 s) and
`capacity:agg:{id}:{minute}` (TTL 24 h). Redis is `REDIS_URL`, or `REDIS_DSN`
when that is unset.

`GET :8098/live` is 200 while the process runs. `GET :8098/ready` is 200
when PHP's `/api/health/ready` answered within 2 s in the last 10 s and
Redis answers. `GET :8098/metrics` passes FrankenPHP's metrics through and
adds `synaplan_server_info`, `synaplan_stream_slots_total`,
`synaplan_stream_slots_busy` and `synaplan_stream_slots_busy_ratio`.

There is no relay and no drain endpoint. Kubernetes pod facts are read when
`KUBERNETES_SERVICE_HOST` is set; they are not acted on until AU8.

**Place and logs (added after the owner's check on 2026-10-24).** The first
cut named a server only by `server_id` and `runtime`; a card could not say
which machine or pod it was, or where its log lines go. The snapshot now
carries `hostname`, `role`, `place` (`host`, `pod`, `namespace`,
`deployment`, `container`, declared resources) and `logs` (`collector`,
`event_ring_host`), and `synaplan_server_info` gains a `role` label. The
identity rule shared with PHP, the Downward API variables for the chart,
and the "You are connected to" header for AU7 are written in `03` §6.1 and
`04` §4.1, §5.2, §6. On the dev stack the snapshot read
`"hostname":"<container id>","role":"web","place":{},"logs":{"collector":"docker","event_ring_host":"<container id>"}`.
**Redis namespace (same day).** PHP's `RedisService` prefixes every key with `synaplan:<APP_ENV>:`; the watchdog now writes under that prefix (`APP_ENV` from the container, `prod` when unset), so the capacity page reads the snapshots and a dev stack next to a test stack does not see the other's servers. The seen-list `capacity:seen` (hash, 7 days) keeps a silent server on the page with its last report time.

**Every role (same day).** The worker and scheduler roles start the same
watchdog. Without FrankenPHP it skips the admin API and the PHP probe: its
report is place, memory and processor, `/ready` is Redis alone, `/metrics`
answers 200 with `synaplan_server_info{role="worker"}` and no slot gauges.
On compose, roles on one machine need distinct ids; the platform compose
derives `web1-worker`, `web1-bulk` and `web1-scheduler` from the exported
`SYNAPLAN_SERVER_ID`, and `updatewebN.sh` exports it with
`SYNAPLAN_HOST_NAME` next to `SYNDBHOST` ([synaplan-platform#33](https://github.com/metadist/synaplan-platform/pull/33)). Kubernetes pod names are distinct on their own. The
Downward API variables are AU8's, in `synaplan-charts`.

**Dev stack, 2026-10-24.** Rebuilt `backend` (classic mode, no worker pools).
`:8098` is not published. `/live` 200. `/ready` 200 once
`/api/health/ready` answered. `/metrics` served `synaplan_server_info` with
`runtime="compose"`. Redis `capacity:server:{hostname}` held the snapshot:
`admin_ok` true, 20 threads, memory about 491 MB, 10 cores, CPU ratio about
0.006. Stream and API slot gauges stayed 0 because classic mode does not
publish `frankenphp_total_workers{worker="streams"|"api"}`; the pool parser
is covered by the spike fixture. Killing the process brought a new one back
within 2 s and `/live` stayed 200.

**Gate.** `make -C sidecars/synaplan-watchdog test` (vet, gofmt, 1,138 lines
against the 2,000-line budget, `go test -race`). The container-runtime bash
test covers off, a missing binary, and a restart after exit. The scheduler
assertions that fail in that script on this Mac also fail on `main`.

---

## 2026-10-24 — AU4: a busy server says so and retries

Copy (all five locales, `chatError.busy*`, `processing.busy`, `widget.busy*`):
"Synaplan is busy — your answer starts in a moment." while the transport
retries; then "Synaplan is busy right now. Your message was not sent — it is
back in the input box." with **Try again**. The widget shows the same two
sentences in its bubble and composer.

**Walked (J-AU-1)** on the busy test stack (1 stream worker, 1 s wait,
`docker-compose.busy.yml`): `busy-server.spec.ts` holds the worker with
chained API streams, sends in the chat UI, sees the busy line in the answer
area, the not-sent sentence, the prompt back in the composer, and Try again
resending it (composer clears, prompt bubble back, no duplicate stored).
2 of 2 busy tests pass locally; the same job runs in CI.

**How it works.** `busyRetry.ts` is the one retry place: only 503 +
`server_busy` (all three body shapes), `Retry-After` else 5 s, plus jitter,
max 3 retries. The chat transport reports `busy` while waiting and a
`server_busy` code at the end; `memberError` then removes both bubbles,
restores the composer (ChatInput keeps it as the chat draft, not in
incognito) and offers Try again. Other refusals carry one code per class
(auth, limit, server) and the handler shows one localized sentence — no
status code anywhere. The widget retries through `postJsonWithBusyRetry`,
paints the busy sentence in the placeholder bubble, and on a final refusal
drops both bubbles and restores its composer.

**Tests.** `busyRetry.spec.ts` (schedule: `Retry-After`, then 5 s, max 3;
no retry on 500 or a non-busy 503), `memberErrorHandler.spec.ts` (busy
ending, one sentence per class), locale parity, size ratchet (the busy code
lives in `busyRetry.ts`, `widgetBusy.ts` and `useBusyRetryOffer.ts` because
`chatApi.ts`, `widgetsApi.ts`, `ChatView.vue` and `ChatWidget.vue` are
frozen), 3,154 Vitest tests, vue-tsc, ESLint, Prettier.

**Gate.** Frontend lint, vue-tsc, 3,154 Vitest tests, busy job 2 of 2 on
the busy stack, layout 16 of 17 (the login overflow is the documented
local-only demo-card failure). `make test-e2e` on the dev stack: the
remaining failures are the known-local set (guest 429 limit, subscription,
Telegram, WhatsApp, workspace tab, the two local-only chat/voice specs).
The widget/memory/task-prompt failures in the first run were a stale
`dist-widget` bind mount after a failed stack build on this machine; after
a backend restart all 15 pass.

**Review round 1 (Copilot, 9 findings).** Fixed: the Try again resend is
bound to its own chat and mode (the toast outlives a chat switch); a newer
draft typed during the retries is never overwritten (kept-draft sentence);
a stored prompt (Again) keeps its row and gets no resend; a send with
files or a tool keeps its row and files and is never resent as plain text;
widget history recovery re-reads the refreshed transcript and never counts
the busy sentence as an answer; a widget send with files or a newer draft
keeps its bubble. Not changed: the widget's Try again is its restored
composer with the Send button — the widget has no toast system, and
`ChatWidget.vue` is frozen in the size ratchet; the sentence sits in the
bubble directly above the composer.

**Review round 2 (Copilot, 2 findings).** Fixed: the resend guard now
binds to the incognito session itself (new `sessionId` in the incognito
store; a later session rejects), and a draft typed during the toast's
lifetime goes back into the composer after the resend, so Try again never
eats it.

**As delivered, different from the brief.** The guest stream also retries
and shows the busy line, but a final guest refusal keeps today's ending
(finish the empty bubble); the composer restore and Try again are the
signed-in turn's, as in J-AU-1. The 401 sentence the brief mentions never
reached the bubble — the handler already replaced transport strings with a
localized sentence; AU4 gives each refusal class its own sentence instead.

---

## 2026-10-24 — AU3: AI streams get their own PHP pool

`frankenphp validate` accepts the site Caddyfile and the two-pool snippet.
`match` is legal only inside `php_server`, and two global workers cannot
share a filename, so the pools are imported there. FrankenPHP writes its
own 503 (`maximum request handling time exceeded`) and does not return a
Caddy error, so `handle_errors` never sees it. Worker mode proxies PHP to
`127.0.0.1:9080` and replaces only status 503. Dev stays on `php_server`.

Measured with `_devextras/perf/runtime/pool-isolation.sh` on the dev stack:
2 stream workers, 2 api workers, `max_wait_time` 2s, `test-model` at 400 ms
per chunk, 8 parallel chat streams.

| | Result |
| - | --- |
| Streams admitted / refused | 2 / 6 |
| Refused body | `{"error":"server_busy","retryAfter":5}`, `Retry-After: 5` |
| `/v1/chat/completions` while the pool was full | 503, OpenAI-shaped `server_busy` |
| `/api/health` during the streams, 20 requests | 20 of 20, p95 5.6 ms (budget 50 ms) |
| Metrics | `frankenphp_total_workers{worker="streams"}` and `{worker="api"}` |

A dictation session create with the demo cookie answered 401, so that route
was not held. TTS answered 200 in 0.01 s and did not occupy a worker. The
OpenAI-shaped body, which is the dictation stream's body, was measured on
`/v1/chat/completions`.

**As delivered, different from the brief.** Nested Caddy defaults do not
parse, and an empty `num_threads` is a parse error. The entrypoint exports
the stream pool from the api pool when it is unset, and inserts
`num_threads` / `max_threads` only when those variables are set. The pools
sit inside `php_server`, not in the global `frankenphp` block the brief
drew.

---

## 2026-10-23 — AU5: a waiting stream gives its database connection back

State at the start (`main` at `ac9b97c6f`): AU0, AU0b, AU1 and AU2 merged;
no open pull request touches S0. The 2026-10-22 handover still lists AU2 as
open; it merged the same day.

**Measured** with `_devextras/perf/runtime/db_connections.sh 8` on the dev
stack: worker-mode FrankenPHP with 8 workers, 8 parallel in-app chat turns
as the demo user on `test-model` (400 ms per chunk), MariaDB connections from
the backend's address counted once a second. "Before" is the same branch with
`main`'s backend code.

| | Before (`main`) | After (this branch) |
| - | --------------: | ------------------: |
| Turns done | 8 of 8, 11.5 s | 8 of 8, 11.6 s |
| Connections after the chats were opened | 1 | 1 |
| Connections per second while the turns ran | 1, then 8 for 11 s | 1, 8, then **0** for 10 s |
| Connections 3 s after the streams | **8** | **0** |
| Calls to a provider other than the test provider | none | none |

The single 8 on the branch is the second after the turns started: all eight
classify and load their history at once, before the provider is called.

A first version of the script (Copilot review, round 1) checked only the chat
model and measured with Qdrant on: every turn then also embedded the question
for memory search through the `VECTORIZE` model (Cloudflare `bge-m3` on this
install), 17 small paid calls in total, and the chats were deleted before the
idle sample. Both are fixed; the numbers above are from the fixed script.

**Proof.** `ChatReplyGeneratorConnectionReleaseTest`: the provider stream
starts only after the connection was closed; a connection that provider setup
reopens is closed by the first chunk, and a later chunk leaves it alone; a
buffered answer keeps its connection. Mutation: with the release call
removed, the first test fails ("the connection is still open when the
provider stream starts"); with the first-chunk wrapper removed, the second
fails ("the connection reopened by provider setup is still open after the
first chunk"); both restored.
`DatabaseConnectionReleaseTest` (open and outside a transaction ⇒ closed; in a
transaction or already closed ⇒ left alone; every connection of the registry,
`default` and `read`) and `StreamedResponseConnectionReleaseTest` (after a
`StreamedResponse` ⇒ released; after a short response ⇒ kept; last on
`terminate`).

**As delivered, different from the brief (S0 AU5).**

- The brief's functional test ("`isConnected()` false inside the provider
  callback") cannot run: the test suite wraps every test in a DAMA
  transaction, and the release must never close a connection inside a
  transaction. The proof is the unit tests above plus the live measurement.
- The brief puts the before-wait release into the orchestrator, the widget
  stream and both gateways. The orchestrator runs the pre-call work
  (classification, history, knowledge) that needs the database, so the
  release sits right before the provider stream instead, in
  `ChatReplyGenerator`, and once more on the stream's first chunk, because
  provider setup (endpoint and credential lookups) can reconnect in between —
  that covers web chat and widget answers. The
  gateways reach the provider through `AiFacade`, `OpenAICompatibleController`,
  `OpenAiGatewayToolLoop`, `GatewayToolLoop` and `MessagesGateway`, all frozen
  in the size ledger (file length or constructor), so they cannot take the
  call without growing; the same holds for file analysis through
  `AnalysisReply`. Their before-call release becomes a before-hook of the AI
  call pipeline (AU10, AU12).
- Added: `StreamedResponseConnectionRelease` closes the connections after
  every `StreamedResponse`. Without it a stream worker keeps its connection
  while idle (Symfony's idle-connection listener only acts on that worker's
  next request, after 600 s), which is what AU3's second pool would double.
  It also covers the gateway, file-analysis, TTS, dictation and MCP streams
  once they end.

**Gate** (rebased on `a9f75713b`): php-cs-fixer, PHPStan, deptrac, PHPUnit
9,911 tests, Prettier, ESLint, `lint:container`, mobile-impact `backend-only`.
The local test and dev databases first needed this week's migrations
(`BCLOUDPICKUPS`, `BREADSCOPE`). `make test-e2e`: 149 passed, 14 failed, all
of them outside this change. 11 are on the known-local list (guest chat,
subscription, Telegram, WhatsApp, workspace tab). The other three fail the
same way with `main` checked out at the same base: `chat.spec.ts:20` (the
reply is stored; the page stays empty after the reload) and
`live-voice-activity-filter.spec.ts:9` fail every time, and
`multitask.spec.ts:27` failed 2 of 8 repeats on both `main` and this branch
(the sorter sometimes skips the planner). `main`'s CI is green, so all three
are local only.

---

## 2026-10-21 — Runtime benchmark (AU1)

`make perf-runtime` on the dev stack, worker mode, `test-model` at 400 ms
per chunk. Two runs, about a minute apart. The idle health row (1 client)
stays under 15 ms and the two runs are within 20% of each other, as are the
8-client health row and runtime-config throughput. Gateway time to first
token moved from 9.3 ms to 13.0 ms. The saturated probe is still seconds,
which is the single pool; that budget starts after AU3.

`app:perf:chat-stream` was not run: the seeded admin's chat model is
Anthropic, and the target does not call a paid provider unless
`--with-providers` is set. A budget forced to 1 ms makes the checker exit
non-zero. After each run the prod cache is gone, the temporary API key is
gone, and only the dev server's FrankenPHP is left.

---

## 2026-10-21 — Research and spike (AU0)

Against `main` at `4dd1b26da`, dev stack on Docker Desktop (10 cores, 16 GB),
FrankenPHP v1.12.6 / PHP 8.5.9 / Caddy 2.11.4. Plan written in a worktree
(`../synaplan-arch-plan`, branch `docs/architecture-update-plan`) so the dev
stack's checkout was not switched.

### Code maps (condensed; four read-only explorations of `backend/src` and `frontend/src`)

**AI paths.** `AiFacade` (1,747 lines) → `ProviderRegistry` (tagged locators
`app.ai.<capability>`) serves chat, widget, email / WhatsApp / Telegram turns,
`/v1/chat/completions`, `/v1/embeddings`, `/v1/images/generations`, the sorter,
memory extraction, vectorization, the embedding router, the LLM reranker,
media, STT and TTS. Bypassing it: `MessagesGateway` (1,051) through
`AnthropicPassthroughTranslator` (324), `OpenAiMessagesTranslator` (1,499),
`GeminiMessagesTranslator` (658) over `HttpClient`; live voice (the Node
sidecar opens the provider socket, `VoiceLiveUsageService` meters);
`PerplexityAdapter::maybeAnswer()` → `PerplexityProvider::chat()`; probes
(`ModelAvailabilityChecker`, `CapabilityProbe`, admin embed preflight).
Hook-like seams today: `AiFacade::executeWithHealth()` / `observing()`
(`CircuitBreaker` + `ModelHealthRecorder`), `UsageRecorded` (dispatched, no
listener), the Messages translator tag. No `*Listener` / `*Middleware` /
`*Interceptor` under `backend/src/AI/`. Metering is the caller's
`RateLimitService::recordUsage()`; the facade records only STT usage itself.
Streams are generated inside the web request (`ignore_user_abort(true)` in
both gateways); `ChatRunBuffer` keeps frames in Redis for re-attach.

**Providers.** Add commits: Cerebras `78f55bcf6` 41 files, Meta `c1aa202a4`
54, Higgsfield `d90f85ae5` 50, Perplexity `752f178e2` 72, Mistral `6700aff6c`
20 (older, smaller key stack). Spine for a Cerebras-class provider: 15–21
files. About 33 name-keyed sites: `ProviderKeyStore::SUPPORTED_PROVIDERS`,
`ProviderKeyCatalog`, `ProviderDefaultsService` (bindings,
`PREFERENCE_ORDER`), `ChatCompletionsUpstreams`, `OpenAiMessagesTranslator::INLINE_PNG_JPEG_ONLY`,
`MessagesGateway::VISION_REWRITE_PROVIDERS`, `MessagesGatewayController` BYOK
list, `StructuredOutputCapability`, `ToolCallingCapability`, `CatalogToolUse`,
`ReasoningLevelCatalog`, `PlatformKeyModelListProbe::NO_LISTING`,
`ProviderException` spellings, `SyncModelPricesCommand` (`PREFIX_MAP`),
`VoiceLiveConcurrency`, `AdminProviderKeysController` OpenAPI enum, `AiTab`,
`services.yaml`; frontend `providerIcons.ts`, `providerHelp.ts`,
`ServiceIcon.vue`, `WebSearchPlugTab.vue` and a few more. Already
registry-driven: `ProviderDisplayNames`, `AiProviderDisclosure`. Key stores:
`ProviderKeyStore` (platform, BCONFIG `provider_keys`), `UserProviderKeyResolver`
(BYOK), `HiggsfieldCredentialResolver`, `OpenAiCompatibleEndpointRegistry`,
`PlugKeyStore` (search / rerank plugs).

**Egress.** 76 classes inject `HttpClientInterface` (no `http_client`
config, no scoped client, no default options; honours proxy env). Raw cURL:
`MediaDownloader` (+ `file_get_contents` fallback), `MediaGenerationService`,
`OpenAICurlTransport`, `CloudflareProvider`, `SystemConfigService` ×5.
`HttpClient::create()` in `MetaProvider`. Own stacks: openai-php (PSR-18
discovery), Ollama SDK (Guzzle), Stripe (`CurlClient`), Google API client and
Apple App Store library (Guzzle), reCAPTCHA. Non-HTTP: Predis, IMAP
(`imap_open`), SMTP (Mailer), Triton gRPC. No `HTTP(S)_PROXY` / `NO_PROXY` in
any compose file. `SsrfGuard` in ~20 classes; pinned connect only in
`HttpToolExecutor`. Secure Compute egress: `sidecars/synaplan-compute/internal/egress/`
(CONNECT proxy dialling PHP-pinned IPs), behind `COMPUTE_EGRESS_ENABLED`,
default off.

**Tools.** Agent loops: `ChatToolLoop` (162, no gate, no audit),
`GatewayToolLoop` (1,432, gate only when approvals are on, `TOOLS` usage),
`OpenAiGatewayToolLoop` (626, gate only when approvals are on, no tool
usage, no custom HTTP). 22 runners; `ToolCallRunner`, `McpActionRunner`,
`CodeRunRunner` use the gate, `McpFetchRunner` does not; skill writes use
`StepApprovalGate` for unattended runs. Approval resume runs tools outside
the loops. Synaplan's MCP server (`McpServerFactory`, 1,426) has no gate.
`ToolSourceInterface`: Custom, Document, GatewayBuiltin, McpClient,
PluginCommand, Skill, Compute. Audit: only `approval.*` events. Caps 12,000 /
16 / 240 s and the iteration loop copied three times
(`ChatToolLoop.php:26–28`, `GatewayToolLoop.php:58–60`,
`OpenAiGatewayToolLoop.php:47–49`).

**Runtime.** Production web nodes: `FRANKENPHP_WORKER_NUM=24`, PHP
`memory_limit` 512M, two Messenger consumers (`synaplan-platform/docs/PHP-RUNTIME-SIZING.md`).
`_docker/backend/synaplan-worker-mode.Caddyfile` exposes only `num`.
Caddyfile: no `metrics`; long routes excluded from compression. Helm chart:
probes on `/`, `resources: {}`, CPU HPA off. Video providers poll with
`sleep()`; `MediaJobDetach` is the default, `force_inline_media` and
`MediaGenerationService::generateVideo()` still poll in a request. The web
chat turns a 503 into "Connection failed (HTTP 503)"
(`frontend/src/services/api/chatApi.ts:551`).

### Measurements

All tables in `02_measurements.md`; scripts and the exact runs in `spike/`.
Providers used: `test-model` (free), Groq `openai/gpt-oss-20b`, Anthropic
`claude-haiku-4-5-20251001`, OpenAI `gpt-5.4-mini` (one timing run); about
150 short turns in total.

### Verified technical dependencies

| Dependency | Result |
| ---------- | ------ |
| FrankenPHP 1.12.6 worker `name` + `match`, global `max_wait_time` | `frankenphp validate`: "Valid configuration"; two pools `m#streams` / `m#api` in the metrics; 503 after `max_wait_time` |
| One `match` line with several patterns | `match /api/v1/messages/stream* /api/v1/widget/*/message /v1/chat/completions /v1/messages`: each of `/api/v1/messages/stream/attach`, `/api/v1/widget/abc/message`, `/v1/messages`, `/v1/chat/completions` counted on `streams`; `/api/health`, `/api/v1/config/runtime` on `api` |
| FrankenPHP Prometheus metrics behind Caddy `metrics` | `frankenphp_busy_threads`, `busy_workers`, `queue_depth`, `ready_workers`, `total_threads`, `total_workers`, `worker_request_count`, `worker_request_time` |
| Caddy admin `/frankenphp/threads` | per thread: state, busy, current URI and method, request start, request count, memory |
| Symfony HttpClient multiplexing in one PHP process | 1,000 streams, all ending in `[DONE]`; process RSS 63 → ≈ 134 MB (≈ 73 KB per stream with libcurl's buffers; the PHP heap alone is 26 MB, which the first run reported by mistake), 2.7 ms CPU per stream (`spike/pump`, re-run after review) |
| Go relay, stdlib only | 2,000 streams, all with HTTP 200 and `[DONE]`: 147 MB peak, ≈ 33 KB per stream, 6.6 s CPU, TTFB p95 0.14 s (re-run after review; the first two runs gave 142 and 148 MB); a `-race` build at 200 streams reported no data race |
| Node 22.23.3 (`node:22-alpine` pinned in both Node sidecars) with `NODE_USE_ENV_PROXY=1` | `fetch()` and `https.get()` go to `HTTPS_PROXY` (ECONNREFUSED at an unreachable proxy); without the flag `fetch()` goes direct (200) |
| One database connection per worker | 8 workers → 8 connections in `information_schema.PROCESSLIST` |
| `/api/health` spawns a process | `WhisperService::isAvailable()` runs `whisper --help` on every call; ≈ 80–110 req/s whether 1, 8 or 16 clients ask (two runs, every response 200) |

### Gate for AU0

The diff touches only `_devextras/`, so the local gate ran on the dev
checkout at the same base (`4dd1b26da`, CI green on `main`):

- `make lint` (php-cs-fixer 0 of 3,276 files, Prettier, ESLint), PHPStan
  (no errors), deptrac (0 violations): green.
- PHPUnit, first run: 5 failures, all from a drifted local `synaplan_test`
  database ("Summary service not configured. Please load fixtures.",
  "Enhancement prompt not found", default summary provider `a2agent`
  instead of `test`). Reset the way CI prepares it (`doctrine:migrations:migrate`,
  `doctrine:fixtures:load`, `app:seed`, all `--env=test`).
- PHPUnit, second run: 9,666 tests, 1 failure,
  `TelegramChannelAccessTest::testChannelIsOpenForARegularUser`: the dev
  backend container sets `TELEGRAM_ENABLED=false` and an empty
  `TELEGRAM_WEBHOOK_BASE_URL`, which override `.env.test`. With `.env.test`'s
  values passed in, the test passes (1 test, 5 assertions). Local-only.
- Vitest 438 of 438 files, `vue-tsc` clean.
- Spike files: `py_compile`, `php -l`, `go vet`, `gofmt` clean; every
  relative link in the changed Markdown files resolves.

### Clean-up after the spike

Spike FrankenPHP instances stopped (only PID 1 left), `var/cache/prod`
removed, `spike-upstream` container removed, all three temporary API keys
revoked (`BAPIKEYS` empty), the test chat deleted, `MESSAGES_GATEWAY.ENABLED`
and `ALLOW_OPERATOR_KEY` back to 0, no `*prod*` keys in Redis. Usage rows of
the runs stay in the dev database.

---

## 2026-10-21 — Copilot review, round 1, and the re-run

Copilot reviewed [#2623](https://github.com/metadist/synaplan/pull/2623)
("Changes recommended", 14 threads: 5 medium on the benchmarks, 9 low on
contracts and plan). All 14 were accepted. The four scripts were fixed, then
every number they produce was measured again on the same stack (all tables in
`02` are from this re-run; the clean-up was repeated: only PID 1 running, no
`var/cache/prod`, gateway flags back to 0, no API key, no spike container):

| Re-run | Result |
| ------ | ------ |
| Classic, 20 / 30 Haiku streams | all complete with text and `[DONE]`; probe max 10.72 s / 11.57 s; queued streams' first text p95 13.12 s |
| Worker mode, 12 streams on 8 workers | 12 complete; first text p95 12.01 s; probe max 10.98 s; RSS 186 → 228 MB |
| Split pools, 8 streams on 4 stream workers | probe p95 19 ms, max 30 ms |
| `max_wait_time 2s` | 4 complete, 4 × 503; probe p95 20 ms |
| Gateway overhead, worker mode, warm, 20 runs | Groq +12 / +30 ms, Anthropic +38 / +46 ms (`/v1/chat` / `/v1/messages`); the cold, per-call-connection variant made Synaplan look faster than the provider, hence warm connections on both sides |
| Gateway overhead, classic, 10 runs | Groq +172 / +89 ms, Anthropic +123 / +144 ms (dev kernel boot); with `max_tokens 60` five of ten direct Groq runs had no answer text (reasoning model), hence `max_tokens 400` |
| Go relay, 100–2,000 streams | all complete; 2,000 at 147 MB, 6.6 s CPU; `-race` clean |
| PHP pump, 100–1,000 streams | all complete; 1,000 at ≈ 134 MB RSS (≈ 73 KB per stream) |

The temporary admin key and the Messages gateway flags were set for these
runs and removed / restored afterwards.

---

## 2026-10-21 — Copilot review, round 2

Requested after `2f26e52ba`; "Changes recommended", 11 new threads (8 on the
contracts and plan, 3 on the spike scripts). All accepted. A second writer
edited `pump.php` and `saturate.py` in this worktree at the same time; the
owner asked this session to continue alone, the duplicates were removed and
the merged code kept.

Re-run after the script fixes (same stack, same clean-up, all verified):

| Re-run | Result |
| ------ | ------ |
| Pump, line-buffered, 100 / 500 / 1,000 streams | all done; exactly 200 events per stream; 1,000 at 133.6 MB peak, 2.6 ms CPU each |
| `microreq.py`, worker mode, status-checked | every request 200; `/api/health` 109 / 107 / 89 req/s at 1 / 8 / 16 clients; runtime config 242 req/s at 8 |
| Split pools, 8 streams on 4, status-checked probes | 0 probe errors; probe p95 23 ms, max 27 ms |
| `max_wait_time 2s` | 4 × 503; 0 probe errors; probe p95 25 ms |

The temporary admin key was revoked; `BAPIKEYS` empty, gateway flags 0, no
spike process, no `var/cache/prod`, no `*prod*` Redis keys.

---

## 2026-10-21 — Pre-flight (AU0b)

A professional review of the plan found it strong on architecture, the
execution loop and regression protection, and thin in five places. The
owner decided all of them the same day; this pull request writes the
decisions into the plan and nothing else changes:

| # | Finding | Decision / fix |
| - | ------- | -------------- |
| B1 | Prompt handling (assembly, sorter prompt, context budget) was only mentioned as "never log it" | Out of scope for this track (master §8); a separate plan owns it |
| B2 | §0 unticked; this file still listed AU0 as open | All 27 rows ticked in the owner's decision session, Ask rows approved; AU0 corrected to merged #2623 (`ce89e32a4`) |
| B3 | Master §0 #3 gave the hook attribute a `phase` the contract does not have; "cut off at its budget" is not implementable in PHP | Attribute aligned with `03` §1.2; the budget is measured, not preemptive, and a blocking hook passes its budget as its own I/O timeout (`03` §1.4) |
| B4–B6, B8 | `AiCallPayload` and friends, the descriptor sub-types, the pump/watchdog state and trust questions, and percentile aggregation were named, not defined | Hardening tasks scheduled and merged before the step that needs them: `03` §1.7 before AU10, §2.3 before AU19, §7.1 before AU28; aggregates store histogram buckets (`04` §2, §7); AU10, AU19 and AU28 carry the precondition in their sprint files |
| B7 | Docs-only steps, Go formatting and the conditional E2E matrix were not gate lines | Added in `01` §6 (house lint plus the relative-link check, `gofmt -l` inside the watchdog test gate, firefox / OIDC / Ollama conditionals, mobile-impact allowlist updates) |

Also decided and written down: plugin development is AU22 only (real
plugins stay row 18), non-AI ingress channels are unchanged, autonomy is
"merge-ready, then pause" (`01` §1, §11), Copilot is capped at three
rounds with the owner deciding beyond it (`01` §9), remote final testing
is CI + `vultr-cluster` + a one-node production soak (master §6, `01`
§10), and one lead model executes the track with a second model reviewing
read-only at the S1, S4 and S5 exits (`01` §1).

S0 may start on the owner's word after this pull request merges. The
`synaplan-charts` checkout AU8 needs was added to the workspace the same
evening (2026-10-21), as was already the case for `vultr-cluster` with
its `load-testing/` namespace, so no cross-repo blocker remains for
AU8 or AU34.

### Gate for AU0b

The diff touches only `_devextras/`, so the gate is the docs-only line of
`01` §6 plus the full unit/static gate, run on this branch:

- `make lint` (php-cs-fixer 0 of 3,276 files, Prettier, ESLint): green.
- The relative-link check over every changed Markdown file: no broken
  link.
- `make -C backend phpstan`: no errors. `make -C backend deptrac`:
  0 violations.
- PHPUnit, first run: 5 failures, all from the drifted local
  `synaplan_test` database — the same first run AU0 recorded. After the
  CI-style reset (`doctrine:migrations:migrate`,
  `doctrine:fixtures:load`, `app:seed`, all `--env=test`): 9,666 tests,
  1 failure, `TelegramChannelAccessTest::testChannelIsOpenForARegularUser`
  — the known local-only case (the dev container's
  `TELEGRAM_ENABLED=false` overrides `.env.test`; with `.env.test`'s
  values the test passes, 1 test, 5 assertions).
- Vitest 439 of 439 files (3,115 tests), `vue-tsc` clean.
- `node scripts/mobile-impact.mjs --base origin/main --head HEAD`:
  exit 0, class `no-app-impact`.

---

## 2026-10-21 — AU2 started

Started from `ce89e32a4` (#2623). Rebased onto `main` after AU0b
([#2627](https://github.com/metadist/synaplan/pull/2627)) ticked §0, including
the ask-first rows. AU2 itself does not change Docker, compose or the schema.

Regression, run on the parent (the dev container's `WhisperService` before
this change) with the new tests mounted:

```text
Failed asserting that 2 is identical to 1.
WhisperServiceTest::testAvailabilityProbeStartsTheBinaryOnceWithinTheCacheLifetime
WhisperServiceTest::testAFailedProbeStartsTheBinaryOnce
```

Two `isAvailable()` calls started `whisper` twice. On the branch the same
tests start it once. A successful probe is cached for 300 seconds, a failed
probe for 60. A missing binary does not use the cache. A cache outage still
runs the probe.

`GET /api/health` body is unchanged. `GET /api/health/ready` returns `status`
and the Redis result only. In the test kernel a warmed request took 13.5 ms,
so the functional test allows 25 ms. The 5 ms budget is `make perf-runtime`
(AU1), on `main` as [#2628](https://github.com/metadist/synaplan/pull/2628).

`HealthControllerTest` was not edited. PHPStan (full `src/` and `tests/`) and
deptrac reported no errors. php-cs-fixer reported no changes on the four PHP
files.

Not done in this session: `make ci-local` as one command, `make test-e2e`,
and `make perf-runtime`. A one-off `docker compose run` of the full PHPUnit
suite exited 139 (segfault) twice at about test 740 of 9675, in a stretch
that does not touch Whisper. The long-running dev container ran the health,
Whisper, size-ratchet and thin-controller tests; the only failure was
`ChatRunner::run` 129 → 132, which is on the other checkout, not in this diff.

---

## 2026-10-22 — Handover

AU1 is on `main` ([#2628](https://github.com/metadist/synaplan/pull/2628)).
AU2 stays open as [#2626](https://github.com/metadist/synaplan/pull/2626) for
the owner to review and merge. What is done, what is not, and a second
opinion on S5 and the approval-gate gap are in
[`HANDOVER.md`](./HANDOVER.md).

---

## Review log

| Date | Pull request | Round | Finding | Answer |
| ---- | ------------ | ----- | ------- | ------ |
| 2026-10-21 | #2623 | 1 | Pump reports PHP heap, not process RSS | Defect. `pump.php` reads `VmRSS` / `VmHWM`; re-run: ≈ 134 MB for 1,000 streams; `02` §1, §4.4, master §0 #17, ROADMAP corrected |
| 2026-10-21 | #2623 | 1 | Relay counts 502/503 and cut-off streams as ok | Defect. Ok = HTTP 200 and `[DONE]`; re-run: 0 failed at every size |
| 2026-10-21 | #2623 | 1 | Relay sampler races with the final peak read | Defect. Sampler done-channel waited on; `-race` build clean |
| 2026-10-21 | #2623 | 1 | `overhead.py` times metadata, keeps failed and textless runs | Defect. First non-empty text delta, non-200 / error / textless counted as failures; warm connections; `max_tokens 400`; re-run: 10–50 ms; `02` §3.1, master §0 #4, §6 |
| 2026-10-21 | #2623 | 1 | `saturate.py` counts partial and error streams as ok | Defect. Ok = 200 + text + `[DONE]`, SSE errors recorded; re-run of every case |
| 2026-10-21 | #2623 | 1 | After-hooks in reverse order run metrics before usage | Defect in the contract. Both phases descending; `HookContractTest` pins usage before metrics (`03` §1.2–§1.3) |
| 2026-10-21 | #2623 | 1 | Proxy routing loses the pin and the per-request exception | Gap. `03` §3.6: tunnel to the pinned IP via an enterprise proxy, request-scoped grants via the gateway, rebinding tests (AU15, AU18) |
| 2026-10-21 | #2623 | 1 | `stream:jobs` has no per-job expiry; payloads keep provider keys | Gap. Opaque ids in the stream, bodies in keys with their own TTL, `GETDEL` claim, `XACK` + `XDEL`, `XAUTOCLAIM`, claim deadline; no provider key in Redis — the pump builds the request (`03` §7) |
| 2026-10-21 | #2623 | 1 | The watchdog cannot see completion without parsing | Gap. Entry kinds `frame` / `terminal` / `gap`; close on `terminal` with a healthy pump; test (`03` §8) |
| 2026-10-21 | #2623 | 1 | `MAXLEN` hides lost prefixes | Gap. No trimming; byte cap with a `gap` entry and the existing truncated flag, ending in today's recoverable path; test (`03` §8) |
| 2026-10-21 | #2623 | 1 | Attach from `0` loses the `from` cursor | Gap. `fromSeq` in the directive and the token, entry ids `0-{seq}`, `id:` lines kept for web chat and widget (`03` §8) |
| 2026-10-21 | #2623 | 1 | AU11 could leave the gateway unmetered; retained modes stop metering | Gap. One writer per billable call at every step and rollback: `legacy` / `shadow` / `on`, `LegacyUsageWriter`, gateway and voice join in AU12 with their writers (`03` §1.5, S1 AU11–AU12, master §0 #5) |
| 2026-10-21 | #2623 | 1 | No live re-attach in the open page after a transport drop | Gap, confirmed in `memberError.ts:46–50`. New step AU31b (`ota-candidate`), J-AU-6 walked there |
| 2026-10-21 | #2623 | 1 | A global pump flag cannot roll back one node | Gap. §0 #24 separates per-server env switches from installation-wide BCONFIG ones; AU31 rollout and AU32 drills split accordingly |
| 2026-10-21 | #2623 | 2 | One usage row per provider call breaks the per-turn `MESSAGES` unit and quotas | Defect in the contract. `TurnUsageLedger`, a unit per action (per turn / per call), rows exactly as today; multi-round tests in AU11 (`03` §1.5) |
| 2026-10-21 | #2623 | 2 | Redirects bypass the egress policy | Gap. The decorator follows redirects itself and checks every hop; allowed → refused regression in AU15, `MediaDownloader` in AU16 (`03` §3.2) |
| 2026-10-21 | #2623 | 2 | The pump reading `ProviderKeyStore` loses BYOK, endpoints and operator-fallback rules | Gap, confirmed in `MessagesGateway::resolveCredential()`. Non-secret credential decision in the job, one `TurnCredentialResolver` (AU29), parity cases in AU30 (`03` §7) |
| 2026-10-21 | #2623 | 2 | No reaper when the last pump dies | Gap. `app:runs:reap` in the scheduler's tick lane and at pump start, atomic transition; AU28 G4 and AU30 stop every pump (`03` §7) |
| 2026-10-21 | #2623 | 2 | A hand-kept provider enum defeats one-file providers | Agreed, solved differently: the enum is dropped (string validated against the catalog) rather than generated, because a generated enum would make each provider a frontend schema change; in AU21 (`03` §2.1) |
| 2026-10-21 | #2623 | 2 | Dictation and TTS streams stay in the API pool | Gap, confirmed (300 s / 600 s poll loop, synchronous TTS); `/mcp` added too; `LongRouteListTest` and audio saturation in AU3 (`03` §5) |
| 2026-10-21 | #2623 | 2 | "Your message is saved" is not true for a refused request | Defect, confirmed (`createIncoming()` runs after admission). Copy says "not sent", the text goes back into the composer and its per-chat draft (AU4) |
| 2026-10-21 | #2623 | 2 | No cursor on the live stream, merged chunks | Gap, confirmed (`SseWriter::send()`, `ChatRunRecorder`). AU31b rebuilds the bubble from a full replay where no `id:` exists, resumes by cursor only behind the watchdog; tests in both modes |
| 2026-10-21 | #2623 | 2 | Pump misses `[DONE]` split across chunks | Defect. Per-response line buffer; re-run: 200 events per stream, all done |
| 2026-10-21 | #2623 | 2 | `microreq.py` times non-200s and ignores thread failures | Defect. Only 200 timed, failures counted, non-zero exit; re-run: every request 200 |
| 2026-10-21 | #2623 | 2 | `saturate.py` probe non-200s enter the latency | Defect. Baseline must be 200 or the run aborts; later non-200s are errors; re-run: 0 probe errors |
| 2026-10-21 | #2623 | 3 | A request-scoped usage ledger cannot span pumps and the finish job | Gap. `turnusage:{turnId}` in Redis, `HSETNX` per call, `SET … NX` close; distributed parity test in AU30 (`03` §1.5) |
| 2026-10-21 | #2623 | 3 | A restart with the same server id and PID renews a dead pump's heartbeat | Gap. Incarnation id per start in the heartbeat and run ownership; same-PID restart test (`03` §7) |
| 2026-10-21 | #2623 | 3 | Pumps have no graceful shutdown, so scaling interrupts answers | Gap. Drain on `SIGTERM`, `PUMP_DRAIN_SECONDS`, grace period = drain + 30 s, `maxSurge 1 / maxUnavailable 0`, rollout and scale-down drills (`03` §7, `04` §5.1, AU30, AU34) |
| 2026-10-21 | #2627 | 1 | A hook budget as an I/O `timeout` only bounds inactivity; a streaming provider runs past it | Defect. `03` §1.4 now requires a total-duration deadline (`max_duration`, one deadline shared by retries, never reset); §1.7 gains the deadline shape as a hardening item |
| 2026-10-21 | #2627 | 1 | Master §0 #4 and AU10's brief still said a hook is "cut off" at its budget | Defect (same class in two more places, found by auditing the diff's wording). Row 4 and the AU10 vibe brief now say: cooperative deadline, `onError` applied when an over-budget `before()` returns, overruns in `after()` only logged |
| 2026-10-21 | #2627 | 1 | The conditional E2E commands cannot run in sequence after the first `cd frontend` | Defect. Each command now runs in a subshell from the repository root (`01` §6) |
| 2026-10-21 | #2627 | 1 | The attach-token and relay-header drills were assigned to AU30, which introduces neither | Defect. Grant-reuse stays AU30; token tamper/expiry and the external `X-Synaplan-Relay` drill move to AU31 (`03` §7.1 #5, S5 AU31 goal and exit criteria) |
| 2026-10-21 | #2627 | 3 (overview) | The documented local OIDC gate omits CI's `--grep @oidc`, so it also runs specs needing an external installation | Defect (overview finding, verified against `ci.yml` grep_filter and `frontend/package.json`). `01` §6 now documents `npm run test:e2e:oidc -- --grep @oidc`. Round 3 otherwise approved with no open issues; the three-round cap is reached, so the re-review of this fix is the owner's decision (`01` §9) |
| 2026-10-23 | #2661 | 1 | Provider setup can reconnect after the release, so the connection is held for the rest of the answer | Defect, confirmed (`OpenAICompatibleProvider::clientForCall()` reads the endpoint from `BCONFIG`). `DatabaseConnectionRelease::releaseOnFirstCall()` wraps the stream callback: the first chunk releases once more, later chunks never; regression test with a reconnecting provider, mutation-checked |
| 2026-10-23 | #2661 | 1 | The benchmark's guard checks only the chat model | Defect, confirmed and worse: every turn also embedded the question for memory search through Cloudflare (17 calls in `BUSELOG`). Guard now covers chat, sorting and memory models; the measured FrankenPHP runs without Qdrant; the run fails on any ledger call to another provider. Re-run: none |
| 2026-10-23 | #2661 | 1 | The chats were deleted before the idle sample | Defect. `stream_turns.py` has `prepare`, `stream`, `cleanup` phases; the idle sample is taken with no request in between, the chats are deleted after it. Re-run: 0 connections after the streams |
| 2026-10-23 | #2661 | 2 | The guard reads stored bindings, not what the app resolves (group policies, inactive bindings, fallbacks) | Defect. The SQL guard is gone: before it opens a chat, `stream_turns.py prepare` reads `/api/v1/config/models/defaults` from the measured FrankenPHP (`reportedDefault()`, the resolution generation uses) and refuses unless chat, sorting and memory resolve to a test-provider model. Checked: real defaults accepted; a paid sorting model refused before any chat |
| 2026-10-23 | #2661 | 2 | A half-way `prepare` leaves chats nobody can delete | Defect. The state file is written before the first chat and after every one, so the exit trap deletes whatever exists. Checked with the second creation failing: the first id is in the state file |
| 2026-10-23 | #2661 | 3 | A topic prompt's own model (`aiModel`) answers before the CHAT default, and the turns send no `modelId` | Defect, fixed differently: a `modelId` pin cannot select a test model, because the stream turns it into `override_model_id`, which `ChatModelSelector` honours only above 0, and test models have negative ids. `prepare` now also reads `/api/v1/prompts` (the user-facing prompts the sorter routes to; every `aiModel` use reads the routed prompt) and refuses when one names a model outside the test provider. Checked: test defaults with a paid model on `general` are refused before any chat; full run green. Three-round cap reached; a re-review is the owner's decision (`01` §9) |

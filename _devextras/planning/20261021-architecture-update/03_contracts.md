# Contracts

The shapes every step builds against. Names are proposals until the step that
introduces them merges; after that this file is updated to what shipped
("As delivered"). Section numbers are cited by the sprint files.

---

## 1. The AI call pipeline (S1)

### 1.1 Values

```php
namespace App\AI\Pipeline;

final readonly class AiCall
{
    public function __construct(
        public AiCapability $capability,      // chat, chat_stream, embed, embed_batch, vision, image, video_start,
                                              // video_poll, transcribe, synthesize, synthesize_stream, passthrough
        public string $provider,              // catalog name, e.g. "anthropic"
        public ?string $model,                // provider model id; null when the provider picks
        public AiCallContext $context,
        public AiCallPayload $payload,        // the capability's input (messages, input texts, image ref, …)
        public AiCallOptions $options,        // temperature, max tokens, tools, reasoning level, stream flag, …
    ) {}
}

final readonly class AiCallContext
{
    public function __construct(
        public ?int $userId,                  // who pays; null only for probes and system calls
        public AiCallSource $source,          // chat_web, chat_stream, widget, email, whatsapp, telegram, openai_api,
                                              // messages_api, saved_task, sorter, memory, summary, vectorize, rerank,
                                              // media, voice_live, probe, system
        public ?string $requestId,            // X-Request-Id
        public ?string $runId,                // ChatRun id when streamed
        public ?int $messageId,
        public bool $billable = true,         // false for probes and health checks
    ) {}

    public static function probe(string $requestId = null): self { /* source probe, billable false */ }
}

final readonly class AiCallResult
{
    public function __construct(
        public AiCallOutcome $outcome,        // ok, refused, failed, cancelled
        public mixed $value,                  // what the facade returns today, unchanged
        public ?AiUsage $usage,               // input/output/cached tokens, units (images, seconds, characters)
        public AiCallTimings $timings,        // queued_ms, hooks_before_ms, ttft_ms, provider_ms, hooks_after_ms
        public ?string $failureClass = null,  // exception class, never its message
    ) {}
}
```

`AiFacade` keeps every public method and return shape. Inside, each method
builds an `AiCall`, calls `AiCallPipeline::run()` (or `stream()`), and returns
`$result->value`. Callers do not change in AU10.

### 1.2 Hooks

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsAiCallHook
{
    public function __construct(
        public int $priority,                 // higher runs earlier before the call, later after it
        public bool $blocking = false,        // true: may do network I/O or call a model
        public int $budgetMs = 1,             // cut-off; enforced for blocking hooks
        public HookFailure $onError = HookFailure::Open,   // Open: log and continue · Closed: refuse the call
        public ?string $flag = null,          // BCONFIG key that switches the hook; null = always on
    ) {}
}

interface AiCallHookInterface
{
    /** Return the call (possibly changed: model, payload redacted) or a refusal. */
    public function before(AiCall $call): AiCall|AiCallRefusal;

    public function after(AiCall $call, AiCallResult $result): void;
}

interface StreamingAiCallHookInterface extends AiCallHookInterface
{
    /** Sees each normalized chunk; may not block. */
    public function onChunk(AiCall $call, StreamChunk $chunk): void;
}
```

Tag `app.ai.call_hook`, collected with `#[AutowireIterator]` in priority
order, pinned by a test (the tagged-list rule of `docs/ARCHITECTURE.md`).

### 1.3 Default hooks, in order

| Priority | Hook | Before | After | Replaces | Step |
| -------: | ---- | ------ | ----- | -------- | ---- |
| 900 | `RequestContextHook` | stamps request id, run id, start time | — | — | AU10 |
| 800 | `RateLimitHook` | refuses when the caller's limit is exhausted (billable calls) | — | caller-side `checkLimit()` | AU11 |
| 700 | `CircuitBreakerHook` | refuses while the provider's breaker is open | records success / failure | `executeWithHealth()` | AU10 |
| 600 | `ModelHealthHook` | — | `ModelHealthRecorder` success / failure | `observing()` | AU10 |
| 300 | `UsageHook` | — | writes the `BUSELOG` row (shadow first) | caller-side `recordUsage()` | AU11 |
| 200 | `AiCallMetricsHook` | — | counters, histograms, the call record of the request timeline | — | AU13 |
| 100 | `AiCallAuditHook` | — | `ai.call` audit row only when an installation turns on "audit AI calls" (off by default) | — | AU13 |

The order is a contract: limits before the breaker (a refused call does not
count as a provider failure), usage before metrics (metrics read the cost).

### 1.4 Budget and failure

- The pipeline measures every hook (`hrtime`) and puts the sums into
  `AiCallTimings`. `make perf-runtime` runs 1,000 calls through the pipeline
  with the default hooks and a no-op provider: **≤ 1 ms p95** total overhead.
- A non-blocking hook that does network I/O is a review defect. A test fails
  when a hook without `blocking: true` injects `HttpClientInterface`,
  `AiFacade`, `EntityManagerInterface` or a repository (checked by reflection
  over the tagged services, like `ThinControllerTest`).
- A blocking hook runs under its `budgetMs`: past it, `onError` decides.
  `Open` logs `ai.hook_timeout` to the event ring and continues; `Closed`
  refuses the call with `AiCallRefusal::policy()`, which callers already map to
  one sentence.
- A hook exception never escapes the pipeline: `Open` hooks log it, `Closed`
  hooks refuse.

### 1.5 Metering, double entry

1. AU11 adds `UsageHook` with `AI.USAGE_HOOK = shadow`: it builds the
   `BUSELOG` row it would write and compares it with the row the caller wrote
   in the same request (same request id, action, provider, model). A mismatch
   logs `ai.usage_mismatch` (action, provider, model, token delta — no
   content) to the event ring.
2. `AiPathCharacterizationTest` asserts equality per path; the paths without
   a caller row today (F1: Perplexity web answer, LLM rerank, …) are listed as
   expected additions.
3. After seven days without a mismatch in production, `AI.USAGE_HOOK = on`:
   the hook writes, callers' `recordUsage()` calls are removed in the same
   pull request, and the snapshot shows one row per billable call.

### 1.6 Paths that join (AU12)

| Path today | Joins as |
| ---------- | -------- |
| `MessagesGateway` translators (Anthropic passthrough, OpenAI, Gemini) | `AiCall(capability: passthrough \| chat_stream)`; the translator becomes the provider transport, the wire format is untouched |
| Live voice (`VoiceLiveUsageService`) | one `AiCall(capability: voice_session)` opened at session start, closed with usage at the end; hooks see start and end, not audio |
| Perplexity web answer (`PerplexityAdapter::maybeAnswer`) | through `AiFacade::chat()` |
| `ModelAvailabilityChecker`, capability probes, admin embed preflight | `AiCallContext::probe()`: hooks run, metering skips, metrics label `source=probe` |

---

## 2. The provider descriptor (S3)

```php
namespace App\AI\Catalog;

final readonly class ProviderDescriptor
{
    /**
     * @param list<AiCapability>   $capabilities
     * @param list<ProviderEndpoint> $endpoints
     */
    public function __construct(
        public string $name,                         // lowercase, = tag key = BSERVICE
        public string $displayName,
        public array $capabilities,
        public array $endpoints,                     // base URLs the provider calls; feed the egress allowlist
        public ?ProviderKeySpec $key,                // env var, optional secret env var, console URL, validation request,
                                                     // store kind: platform | platform_and_user | endpoint_registry | none
        public ProviderDialects $dialects,           // structured output, tool calling, reasoning ladder, inline image rules
        public ?ProviderDefaults $defaults,          // default bindings and preference rank
        public bool $listsModels,                    // the model-list probe works
        public ?string $pricingPrefix,               // LiteLLM prefix for app:models:sync-prices
        public ?int $liveVoiceSessions,              // concurrency cap, when the provider offers live voice
        public ProviderBranding $branding,           // iconify id, help URL key, docs URL
    ) {}
}
```

```php
final class CerebrasProvider extends AbstractChatCompletionsCloudProvider
{
    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: 'cerebras',
            displayName: 'Cerebras',
            capabilities: [AiCapability::Chat, AiCapability::Vision],
            endpoints: [new ProviderEndpoint('https://api.cerebras.ai/v1', purpose: 'chat_completions')],
            key: ProviderKeySpec::platform(env: 'CEREBRAS_API_KEY', consoleUrl: 'https://cloud.cerebras.ai',
                validation: ProviderKeyValidation::get('https://api.cerebras.ai/v1/models')),
            dialects: new ProviderDialects(structuredOutput: 'json_schema', toolCalling: ToolCallingDialect::OpenAi,
                inlineImages: InlineImageRule::PngJpegOnly),
            defaults: null,
            listsModels: true,
            pricingPrefix: 'cerebras/',
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'simple-icons:cerebras', helpKey: 'cerebras'),
        );
    }
}
```

(The values above illustrate the shape; AU19 copies the real ones from
today's tables, which the parity test enforces.)

### 2.1 Who asks the catalog (AU20)

| Table today | Becomes |
| ----------- | ------- |
| `ProviderKeyStore::SUPPORTED_PROVIDERS` | `ProviderCatalog::withKey()` |
| `ProviderKeyCatalog` entries | `descriptor()->key` |
| `ProviderDefaultsService` bindings and `PREFERENCE_ORDER` | `descriptor()->defaults` |
| `ChatCompletionsUpstreams` URLs and local hosts | `endpoints` with purpose `chat_completions` |
| `StructuredOutputCapability`, `ToolCallingCapability`, `CatalogToolUse`, `OpenAiMessagesTranslator::INLINE_PNG_JPEG_ONLY`, `MessagesGateway::VISION_REWRITE_PROVIDERS` | `dialects` |
| `ReasoningLevelCatalog` per-service ladders | `dialects->reasoning` |
| `PlatformKeyModelListProbe::NO_LISTING` | `listsModels` |
| `SyncModelPricesCommand` `PREFIX_MAP` | `pricingPrefix` |
| `VoiceLiveConcurrency` caps | `liveVoiceSessions` |
| `AdminProviderKeysController` OpenAPI enum | kept as a constant; a test asserts it equals the catalog (OpenAPI attributes must be constant) |
| `AiTab` password fields | generated from `key` |
| `services.yaml` `app.ai.<capability>` tags and `$envKeys` | `#[AsAiProvider]` + `registerAttributeForAutoconfiguration`, tags from the implemented interfaces, keys from the descriptor |
| frontend `providerIcons.ts`, `providerHelp.ts`, `ServiceIcon.vue` | `GET /api/v1/ai/providers` (AU21); help text stays in the five locale files under `admin.providerHelp.<helpKey>` |

### 2.2 Parity test

`ProviderCatalogParityTest` (AU19) builds every table of §2.1 from the
descriptors and compares it with today's constant, entry by entry. It is the
regression proof for AU20: each table switch keeps it green, then the
constant is deleted and the test asserts the consumer's output instead.

---

## 3. Central egress (S2)

### 3.1 The client

`EgressHttpClient` decorates the `http_client` service (`#[AsDecorator('http_client')]`),
so every one of the 76 injectors gets it without a change. Framework
defaults in `config/packages/framework.yaml`:

```yaml
framework:
  http_client:
    default_options:
      proxy: '%env(default::EGRESS_PROXY)%'      # empty ⇒ HTTPS_PROXY / HTTP_PROXY from the environment
      no_proxy: '%env(default::EGRESS_NO_PROXY)%'
      timeout: 30
      max_duration: 0                           # streams set their own
      headers: { 'User-Agent': 'Synaplan/%app.version%' }
```

### 3.2 The policy

```php
final readonly class EgressDestination { public function __construct(public string $scheme, public string $host, public int $port) {} }

enum EgressMode: string { case Off = 'off'; case Report = 'report'; case Enforce = 'enforce'; }

final readonly class EgressDecision
{
    public function __construct(public bool $allowed, public EgressRule|null $rule, public string $reason) {}
}
```

| Source of an allow rule | Example | Step |
| ----------------------- | ------- | ---- |
| Built-in Synaplan services on the internal network | `qdrant:6333`, `tika:9998`, `centrifugo:8000`, `compute`, `voice`, `ollama` | AU15 |
| The endpoints of every enabled provider's descriptor | `api.anthropic.com:443` | AU22 (AU15 uses a static list until then) |
| Configured integrations | the OpenAI-compatible endpoints, MCP servers, web-search plugs, WhatsApp Graph, Telegram, Stripe, OAuth providers, federation peers | AU15 |
| Administrator entries | `*.example.org:443` (one wildcard level) | AU15 |
| Requests whose URL a person typed (URL reader, custom tools, MCP servers of a person, URL watch) | allowed per request by `SsrfGuard` + pinned connect, never added to the list | unchanged |

Settings: `EGRESS.MODE` (BCONFIG global, default `off`), `EGRESS.ALLOW`
(JSON list), env `EGRESS_PROXY`, `EGRESS_NO_PROXY`. Admin → System settings →
a new "Outbound traffic" section.

In `report` mode a request that `enforce` would refuse goes out and logs
`egress.would_block` (destination, caller class, request id). In `enforce`
mode it throws `EgressRefusedException` before connecting, which callers map
to their existing "service not reachable" sentence.

### 3.3 Per-destination metrics

`synaplan_egress_requests_total{destination, outcome}`,
`synaplan_egress_duration_seconds{destination}`; `destination` is
`host:port` of an allowed rule or `other`, so label cardinality is bounded.

### 3.4 SDK adapters (AU17)

| SDK | Adapter |
| --- | ------- |
| openai-php | `->withHttpClient(new Psr18Client($egressClient))` in `OpenAiCompatibleClients`, `OpenAIApiClient`, `GroqProvider`, `XaiProvider`, `PerplexityProvider`, `AbstractChatCompletionsCloudProvider` |
| Ollama SDK | its PSR-18 hook, else move the remaining calls to the Symfony path the class already uses for streaming |
| Stripe | `\Stripe\ApiRequestor::setHttpClient()` with an adapter over the egress client |
| Google API client, Apple App Store library | a Guzzle handler stack with an `EgressPolicy` middleware and the proxy option |

### 3.5 The inventory gate (AU14)

`tests/Architecture/EgressInventoryTest.php` scans `src/` and `plugins/` for
`curl_init(`, `file_get_contents('http`, `fopen('http`, `HttpClient::create(`,
`new \GuzzleHttp\Client`, `Psr18ClientDiscovery`, and SDK factory calls without
an injected client. `egressInventoryBaseline.json` freezes today's sites; it may
only shrink. After AU17 it lists only the allow-listed transports (Triton
gRPC, Redis, SMTP, IMAP).

---

## 4. The tool invoker (S4)

```php
namespace App\Service\Tool\Execution;

final readonly class ToolInvocation
{
    public function __construct(
        public string $toolName,             // registry name, e.g. "mcp__12__create_issue", "doc_replace_text", "code_run"
        public array $arguments,             // decoded JSON arguments
        public ToolCaller $caller,           // user id, channel (chat, messages_api, openai_api, saved_task, mcp_server),
                                             // assistant id, node id, unattended flag, request id, run id
    ) {}
}

final readonly class ToolOutcome
{
    public function __construct(
        public ToolOutcomeKind $kind,        // done, refused, needs_approval, failed, timed_out
        public string $resultText,           // clamped to ToolLimits::RESULT_CHARS, ellipsis marked
        public ?int $approvalId,
        public ?ToolUsage $usage,
        public int $durationMs,
    ) {}
}

interface ToolExecutorInterface            // tag app.tool.executor, one per source
{
    public function supports(ToolDefinition $tool): bool;
    public function execute(ToolDefinition $tool, ToolInvocation $invocation): ToolExecution;
}
```

`ToolInvoker::invoke()` = registry lookup → `ToolExecutionGate::inspect()`
(**always**) → executor → clamp → `tool.executed` audit row for write and
destructive kinds → usage row (`TOOLS`, `MCP_TOOL`, `COMPUTE_RUNS`, `WEB_SEARCH`)
→ metrics. Executors: Document, Mcp, CustomHttp, GatewayBuiltin (web search,
image analysis), Skill, Compute.

```php
final readonly class ToolLimits                 // one place, replaces the three copies
{
    public const MAX_ITERATIONS_DEFAULT = 8;
    public const DEADLINE_SECONDS = 240;
    public const MAX_TOOLS_PER_TURN = 16;
    public const RESULT_CHARS = 12000;
}
```

Audit row (`BAUDITLOG`, action `tool.executed`): actor user id, tool name,
source, kind (write / destructive), outcome, approval id, request id,
duration. **Never the arguments or the result.**

`AgentToolLoop` (AU26) owns iterations, deadline, partition of
Synaplan-owned and client-owned tool calls, and error-as-result; the three
loops keep only their wire format (`DocumentToolLoopAdapter`,
`MessagesToolLoopAdapter`, `OpenAiToolLoopAdapter`).

---

## 5. Worker pools and a fast "busy" (S0)

`_docker/backend/synaplan-worker-mode.Caddyfile` after AU3:

```caddyfile
frankenphp {
	num_threads {$FRANKENPHP_NUM_THREADS:}
	max_threads {$FRANKENPHP_MAX_THREADS:}
	max_wait_time {$FRANKENPHP_MAX_WAIT_TIME:15s}
	worker {
		name streams
		file "/var/www/backend/public/index.php"
		num {$FRANKENPHP_STREAM_WORKER_NUM:{$FRANKENPHP_WORKER_NUM:16}}
		match /api/v1/messages/stream* /api/v1/widget/*/message /v1/chat/completions /v1/messages
		env APP_RUNTIME App\Runtime\FrankenPhpRuntime
	}
	worker {
		name api
		file "/var/www/backend/public/index.php"
		num {$FRANKENPHP_WORKER_NUM:16}
		env APP_RUNTIME App\Runtime\FrankenPhpRuntime
	}
}
```

Verified on 2026-10-21 with FrankenPHP 1.12.6, workers inside `php_server`:
`name`, global `max_wait_time`, and one `match` line with all four patterns.
A request to each long route was counted on `streams`
(`frankenphp_worker_request_count{worker="m#streams"}`), `/api/health` and
`/api/v1/config/runtime` on `api` (STATUS). Still to verify in AU3: the
global-`frankenphp`-block placement shown above, the nested default
placeholder, and an empty `num_threads`.

`_docker/backend/Caddyfile` gains the global `metrics` option and a
`handle_errors 503` block for the long routes:

| Route family | Body | Headers |
| ------------ | ---- | ------- |
| `/api/v1/messages/stream*`, `/api/v1/widget/*/message` | `{"error":"server_busy","retryAfter":5}` | `Retry-After: 5` |
| `/v1/chat/completions` | `{"error":{"message":"Synaplan is busy. Retry in a few seconds.","type":"server_error","code":"server_busy"}}` | `Retry-After: 5` |
| `/v1/messages` | `{"type":"error","error":{"type":"overloaded_error","message":"Synaplan is busy. Retry in a few seconds."}}` (status 529 is Anthropic's; we keep 503) | `Retry-After: 5` |

The API copy is English on purpose: it is a machine contract that SDKs
retry on. People see AU4's localized sentence.

---

## 6. The watchdog (S0 agent, S5 relay)

One Go binary, `sidecars/synaplan-watchdog`, in the backend image.

| Mode / endpoint | Since | Does |
| --------------- | ----- | ---- |
| agent loop | AU6 | every 5 s: read `http://localhost:2019/metrics` and `/frankenphp/threads`, cgroup limits (`/sys/fs/cgroup/memory.max`, `cpu.max`), `/proc` RSS, Kubernetes facts; write `capacity:server:{serverId}` (TTL 20 s) and the one-minute aggregate `capacity:agg:{serverId}:{minute}` (TTL 24 h) to Redis |
| `GET :8098/live` | AU6 | 200 while the watchdog runs |
| `GET :8098/ready` | AU6 | 200 when PHP answered its own probe (`GET /api/health/ready`, AU2) within 2 s in the last 10 s, Redis answers, and the server is not draining; else 503 |
| `GET :8098/metrics` | AU6 | FrankenPHP's metrics passed through plus `synaplan_*` (§9) |
| `POST :8098/drain` (local only) | AU34 | stop admitting new streams, wait for held ones up to the grace period |
| relay `127.0.0.1:8097` | AU31 | the long routes when `SYNAPLAN_RELAY=1` (§8) |

Settings (env, boot time): `SYNAPLAN_WATCHDOG` (default `1` after AU6),
`SYNAPLAN_RELAY` (default `0` until AU32), `SYNAPLAN_SERVER_ID` (default the
hostname), `WATCHDOG_STREAM_SLOTS` (admission limit, default from the pool
size × 20 when the pump is on), `REDIS_URL`.

`serverId` in copy is the display name; Kubernetes pod name or the compose
hostname.

---

## 7. The stream pump (S5)

- Role `pump` of the backend image (`SYNAPLAN_ROLE=pump`), started by
  `_docker/backend/lib/container-runtime.sh` like `worker`; in compose a
  `pump` service; on the platform one per web node to start.
- Reads jobs from the Redis Stream `stream:jobs` (consumer group `pump`):

```json
{ "runId": "…", "callId": "…", "channel": "chat_stream|widget|openai_api|messages_api",
  "provider": "anthropic", "request": { "method": "POST", "url": "…", "headers": { … }, "body": "…" },
  "decoder": "anthropic_messages", "encoder": "synaplan_sse|openai_chunks|anthropic_events",
  "deadlineSec": 240, "turn": { "chatId": 1, "messageId": 2, "userId": 3 } }
```

  The request headers carry the provider key; the job lives only in Redis,
  with a TTL of 60 s until claimed, and is never logged.
- One event loop: `HttpClient::stream()` over all active responses with a
  50 ms tick; per chunk `StreamDecoderInterface::feed()` → `StreamChunk`s →
  `AiCallPipeline` streaming hooks → encoder → `XADD chatrun:{runId}:stream`.
- Tool call in the stream → `ToolStepCommand` (Messenger, `async_ai_high`)
  runs `ToolInvoker`, writes the result, re-queues the next upstream request
  for the same run.
- End → `FinishTurnCommand` (Messenger): store the reply, usage through the
  pipeline's after-hooks, memory extraction, `complete` frame.
- Heartbeat `pump:{serverId}:{pid}` every 2 s; a run whose pump heartbeat is
  gone for 10 s is failed with its terminal frame by any pump (no stuck
  "running", UX bar 6).
- Cancel: `CancelledTurns` flag checked on every tick; the upstream request
  is cancelled, the partial answer stored as today.

```php
interface StreamDecoderInterface
{
    /** @return list<StreamChunk> */
    public function feed(string $bytes): array;

    /** @return list<StreamChunk> the rest at end of stream */
    public function finish(): array;
}
```

Each streaming provider exposes `buildStreamRequest(AiCall): HttpRequestSpec`
and `streamDecoder(AiCall): StreamDecoderInterface` (AU29). Its existing
blocking loop becomes `foreach ($http->stream($response) as $chunk) { $decoder->feed(…) }`,
so in-request mode and pump mode share the same decoder.

---

## 8. Run stream, attach directive, attach token (S5)

- `chatrun:{runId}:stream` — Redis Stream of frames; each entry
  `{seq, frame}` where `frame` is the exact bytes for the client's wire
  (`event: …\ndata: …\n\n` or `data: {…}\n\n`). `MAXLEN ~ 5000`, TTL as
  `ChatRunBuffer` today (30 min running, 5 min terminal). The ZSET is written
  as well until AU32 ships; `StreamAttachController` reads either.
- With `SYNAPLAN_RELAY=1`, Caddy sends the long routes to the watchdog, which
  forwards the request to PHP with `X-Synaplan-Relay: 1`. PHP either answers
  normally (the watchdog passes the response through, byte for byte) or
  answers `200` with `Content-Type: application/vnd.synaplan.attach+json`:

```json
{ "runId": "…", "token": "…", "contentType": "text/event-stream",
  "headers": { "X-Request-Id": "…" },
  "terminal": "data: {\"status\":\"error\",\"message\":\"…\"}\n\n",
  "idleTimeoutSec": 60 }
```

- `token` = base64url(runId · ownerKey · exp) + "." + HMAC-SHA256 with
  `RUNTIME.ATTACH_SECRET`. The watchdog checks signature and expiry, then
  `XREAD BLOCK` on the run stream from `0`, writes each frame, flushes, and
  sends an SSE comment `: keep-alive` every 15 s of silence.
- If the run stream stays silent for `idleTimeoutSec` and the pump heartbeat
  is gone, the watchdog writes `terminal` and closes. A stream always ends in
  a terminal frame.
- Client disconnect → the watchdog sets the cancel flag (`CancelledTurns`
  key) for runs whose channel cancels on disconnect (gateways), and leaves
  web-chat runs running so a reload re-attaches (today's behaviour).

---

## 9. Metrics catalog

Prometheus names on the watchdog's `:8098/metrics`. Labels are bounded: no
user ids, no URLs with ids, no model names from free text (models come from
the catalog).

| Metric | Type | Labels | Source |
| ------ | ---- | ------ | ------ |
| `frankenphp_*` | as FrankenPHP | as FrankenPHP | passed through (AU6) |
| `synaplan_server_info` | gauge | `server`, `runtime` (`compose` \| `kubernetes`), `version` | watchdog |
| `synaplan_stream_slots_total` / `_busy` | gauge | `pool` | watchdog (from `frankenphp_total_workers` / `busy_workers` of `streams`; after AU31 its own slots) |
| `synaplan_stream_slots_busy_ratio` | gauge | — | watchdog; the HPA metric (AU34) |
| `synaplan_requests_total` | counter | `route_class`, `status_class` | PHP request timeline (AU13), aggregated by the watchdog |
| `synaplan_request_duration_seconds` | histogram | `route_class` | same |
| `synaplan_ai_calls_total` | counter | `provider`, `capability`, `source`, `outcome` | `AiCallMetricsHook` (AU13) |
| `synaplan_ai_ttft_seconds` / `_duration_seconds` | histogram | `provider`, `capability` | same |
| `synaplan_ai_tokens_total` | counter | `provider`, `direction` | same |
| `synaplan_tool_calls_total` | counter | `source`, `kind`, `outcome` | `ToolInvoker` (AU24) |
| `synaplan_egress_requests_total` | counter | `destination`, `outcome` | `EgressHttpClient` (AU15) |
| `synaplan_busy_refusals_total` | counter | `route_class` | Caddy's `caddy_http_requests_total` with code 503 on the long routes (AU3 checks the label exists with the `metrics` option), later the watchdog's own admission refusals (AU31) |
| `synaplan_pump_streams` | gauge | `server` | pump heartbeat (AU30) |

PHP writes its counters to Redis (`metrics:{serverId}:…`, `INCRBY` / small
hashes, one pipeline per request in `kernel.terminate`); the watchdog renders
them. No PHP request serves `/metrics`.

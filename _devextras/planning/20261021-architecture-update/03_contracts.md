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
        public ?string $turnId,               // one answer: all rounds of a tool loop share it (§1.5)
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
        public int $priority,                 // higher runs earlier, in both phases (before and after)
        public bool $blocking = false,        // true: may do network I/O or call a model
        public int $budgetMs = 1,             // measured budget (see §1.4 — not a preemptive cut-off)
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
The attribute carries **no phase field** (corrected in AU0b, which removed
`phase` from master plan §0 #3): the phase is the method the hook
implements — `before()` or `after()` above.

### 1.3 Default hooks, in order

| Priority | Hook | Before | After | Replaces | Step |
| -------: | ---- | ------ | ----- | -------- | ---- |
| 900 | `RequestContextHook` | stamps request id, run id, start time | — | — | AU10 |
| 800 | `RateLimitHook` | refuses when the caller's limit is exhausted (billable calls) | — | caller-side `checkLimit()` | AU11 |
| 700 | `CircuitBreakerHook` | refuses while the provider's breaker is open | records success / failure | `executeWithHealth()` | AU10 |
| 600 | `ModelHealthHook` | — | `ModelHealthRecorder` success / failure | `observing()` | AU10 |
| 300 | `UsageHook` | — | adds the call's usage to the turn ledger; writes per-call rows, the turn's owner closes per-turn rows (§1.5) | caller-side `recordUsage()` | AU11 |
| 200 | `AiCallMetricsHook` | — | counters, histograms, the call record of the request timeline | — | AU13 |
| 100 | `AiCallAuditHook` | — | `ai.call` audit row only when an installation turns on "audit AI calls" (off by default) | — | AU13 |

Both phases run in **descending priority** (900 → 100), before and after the
call alike. The order is a contract: limits before the breaker (a refused call
does not count as a provider failure), usage before metrics (metrics read the
cost `UsageHook` computed), metrics before audit. `HookContractTest` pins the
whole order and asserts explicitly that `UsageHook::after()` runs before
`AiCallMetricsHook::after()`.

### 1.4 Budget and failure

- The pipeline measures every hook (`hrtime`) and puts the sums into
  `AiCallTimings`. `make perf-runtime` runs 1,000 calls through the pipeline
  with the default hooks and a no-op provider: **≤ 1 ms p95** total overhead.
- A non-blocking hook that does network I/O is a review defect. A test fails
  when a hook without `blocking: true` injects `HttpClientInterface`,
  `AiFacade`, `EntityManagerInterface` or a repository (checked by reflection
  over the tagged services, like `ThinControllerTest`).
- **A budget is measured, not preemptive** (corrected in AU0b; the earlier
  "cut off at its budget" wording was not implementable). PHP cannot
  interrupt a running hook, so enforcement is cooperative and measured:
  (a) a `blocking` hook **must** turn its remaining `budgetMs` into a
  total-duration deadline for every network or model call it makes —
  Symfony HttpClient's `max_duration` (with `timeout` as the inactivity
  limit beside it), never `timeout` alone: a provider that keeps sending
  chunks would otherwise run past the budget without ever tripping an
  inactivity timeout. A hook that calls a model, or retries, carries the
  **same** deadline through every attempt (start time plus `budgetMs`,
  computed once) — a retry never resets it. A blocking hook whose
  constructor injects an HTTP client or the facade without reading its
  declared budget is a review defect; (b) the pipeline times every hook
  with `hrtime` and, when a `before()` returns over budget, applies
  `onError` to its result: `Open` logs `ai.hook_timeout` (hook, budget,
  measured — no content) to the event ring and keeps the returned call;
  `Closed` discards the result and refuses with
  `AiCallRefusal::policy()`, which callers already map to one sentence;
  (c) an `after()` that returns over budget can only be logged the same
  way — its work is already done, so `Closed` has no effect there and a
  hook that must be able to refuse declares that work in `before()`;
  (d) `HookContractTest` covers a slow hook in both phases with an
  injected clock: the overrun is logged once, `Closed` refuses in
  `before()` and does not refuse in `after()`.
- A hook exception never escapes the pipeline: `Open` hooks log it, `Closed`
  hooks refuse.

### 1.5 Metering: exactly one writer per billable call, at every step

The rule that holds through the whole migration and every rollback: **each
billable call has exactly one writer of its `BUSELOG` row.**

| `AI.USAGE_HOOK` | Who writes | `UsageHook` |
| --------------- | ---------- | ----------- |
| `legacy` (default until AU11 part 2) | the caller, as today | silent |
| `shadow` | the caller | computes the row it would write, compares, logs `ai.usage_mismatch` (action, provider, model, token delta — no content) to the event ring |
| `on` | `UsageHook`, for paths that run through the pipeline | writes |

1. AU11 part 1 adds `UsageHook` and `LegacyUsageWriter`. Every caller's
   `recordUsage()` call for an AI action moves into `LegacyUsageWriter`, which
   writes only when the mode is not `on` **or** the path has not joined the
   pipeline yet. A path is "joined" by a constant on its `AiCallSource`, set
   in the step that moves it.
2. `AiPathCharacterizationTest` asserts, per path and per mode, one row per
   billable call. The paths without a row today (F1: Perplexity web answer,
   LLM rerank, …) get theirs when they join (`on` only).
3. After seven days of `shadow` in production without a mismatch, `on`. The
   `/v1/messages` gateway and live voice keep their own writers until AU12
   joins them; AU12 flips their constant in the same pull request, so there is
   no release in which they are unmetered.
4. Rollback at any time: `legacy` or `shadow` restores the callers' writers
   for every path, because `LegacyUsageWriter` is still there.
5. After one release with `on` everywhere, a separate pull request removes
   `LegacyUsageWriter` and the setting. `UsageHook` then carries no flag
   (`flag: null`): metering cannot be switched off, and the way back from
   that point is a release revert, not a setting.

**The accounting unit stays what it is today.** A turn of the gateways or the
chat can make several provider calls (every round of a tool loop is one), but
today it writes **one** `MESSAGES` row with the usage summed over its rounds
(`GatewayToolLoop` aggregates, `MessagesGateway` writes once), and
`RateLimitService` counts those rows against message quotas. One row per
provider call would spend several message allowances on one answer. So:

- `UsageHook` does not write per call. It adds each call's usage to the
  `TurnUsageLedger` under the call's `turnId`. The ledger is **shared state,
  not process memory**, because from S5 on one turn's rounds run on different
  pumps and Messenger workers and are finished by `FinishTurnCommand` in yet
  another process: a Redis hash `turnusage:{turnId}` (TTL 1 h) with one field
  per provider call, written with `HSETNX` on the call's `callId`, so a
  retried or duplicated contribution counts once. Closing is idempotent too:
  `SET turnusage:{turnId}:closed NX` decides the single writer of the
  per-turn row, which sums every field present at that moment. In-request
  turns use the same store, so both modes share one implementation.
- Each `AiAction` declares its unit: **per turn** (`MESSAGES` on every
  channel) or **per call** (`SORTING`, `EMBEDDINGS`, `MEMORY_EXTRACTION`,
  `IMAGES`, `VIDEOS`, `TRANSCRIPTION`, `SPEECH`, …, as today).
- A per-call action's row is written in the call's after-hook; a per-turn
  action's row is written once, when the turn's owner closes the ledger
  (`TurnUsageLedger::close($turnId)`, called where today's single row is
  written). `key_source`, model and provider of the row follow today's rule
  for that path.
- Cost per provider call, when someone needs it, is in the request timeline
  and the metrics (AU13), never as extra `BUSELOG` rows.
- Tests: a gateway turn with three MCP rounds, a document tool loop with two
  rounds and a buffered single call each write exactly today's rows (one
  `MESSAGES` row with summed tokens for the turns) under `legacy`, `shadow`
  and `on`; the shadow comparison is per row, not per call; a duplicated
  contribution and a second close each change nothing. AU30 adds the
  distributed case: three rounds on two different pumps, the finish on a
  separate worker — one `MESSAGES` row with the full total.

Non-AI usage rows (`TOOLS`, `MCP_TOOL`, `COMPUTE_RUNS`, `WEB_SEARCH` from tool
loops, storage, messages-per-hour counters) are not part of this switch; they
move with the tool invoker (S4).

### 1.6 Paths that join (AU12)

| Path today | Joins as |
| ---------- | -------- |
| `MessagesGateway` translators (Anthropic passthrough, OpenAI, Gemini) | `AiCall(capability: passthrough \| chat_stream)`; the translator becomes the provider transport, the wire format is untouched |
| Live voice (`VoiceLiveUsageService`) | one `AiCall(capability: voice_session)` opened at session start, closed with usage at the end; hooks see start and end, not audio |
| Perplexity web answer (`PerplexityAdapter::maybeAnswer`) | through `AiFacade::chat()` |
| `ModelAvailabilityChecker`, capability probes, admin embed preflight | `AiCallContext::probe()`: hooks run, metering skips, metrics label `source=probe` |

### 1.7 Pre-sprint hardening — must merge before AU10 (AU0b)

§1.1 names `AiCallPayload`, `AiCallOptions`, `AiUsage`, `AiCallTimings`
and §1.2 names `StreamChunk`, but no section gives their fields; and no
table maps the facade's methods to the pipeline. AU10 must not start
until a `docs(architecture)` pull request has added, in this section:

1. **The method map:** every public `AiFacade` method (and the AU12 paths
   of §1.6) with its `AiCapability`, its `AiCallSource`, its billable
   flag and its accounting unit (§1.5) — one row per method, checked
   against `AiPathCharacterizationTest`'s case list from AU9.
2. **The value shapes:** the fields of `AiCallPayload` (one variant per
   capability, or one class with capability-checked fields — the pull
   request decides and says why), `AiCallOptions`, `AiUsage`,
   `AiCallTimings` and `StreamChunk`, each with types; no `mixed` outside
   `AiCallResult::$value`, whose per-capability type the method map names.
3. **The streaming shape:** the signature of `AiCallPipeline::stream()`,
   who cancels it, and what a hook sees per chunk — enough that AU29's
   decoders can be written against it without reopening this section.
4. **The deadline shape:** how a blocking hook's one deadline (§1.4) is
   represented and handed to the transport and to a model call — a field
   on `AiCallOptions` or a value the pipeline passes — so no hook or
   provider adapter computes (or resets) its own.

The hardening pull request changes no production code. AU10's vibe brief
re-checks its capability commits against the merged map.

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
| `AdminProviderKeysController` OpenAPI enum | **dropped** (AU21): the field becomes a string validated against the catalog (unknown name ⇒ 400 with the existing error shape). A hand-kept enum would be one more list to edit, and a generated one would make every new provider a change of the frontend's generated schemas; the UI already gets the names from `GET /api/v1/ai/providers`. Test: the exported spec (`/api/doc.json`) contains no provider-name list, and the AU22 fixture provider is accepted |
| `AiTab` password fields | generated from `key` |
| `services.yaml` `app.ai.<capability>` tags and `$envKeys` | `#[AsAiProvider]` + `registerAttributeForAutoconfiguration`, tags from the implemented interfaces, keys from the descriptor |
| frontend `providerIcons.ts`, `providerHelp.ts`, `ServiceIcon.vue` | `GET /api/v1/ai/providers` (AU21); help text stays in the five locale files under `admin.providerHelp.<helpKey>` |

### 2.2 Parity test

`ProviderCatalogParityTest` (AU19) builds every table of §2.1 from the
descriptors and compares it with today's constant, entry by entry. It is the
regression proof for AU20: each table switch keeps it green, then the
constant is deleted and the test asserts the consumer's output instead.

### 2.3 Pre-sprint hardening — must merge before AU19 (AU0b)

§2 names `ProviderEndpoint`, `ProviderKeySpec`, `ProviderDialects`,
`ProviderDefaults` and `ProviderBranding` without their fields, and §2.2's
parity test compares against tables that today exist only as the condensed
list in STATUS. AU19 must not start until a `docs(architecture)` pull
request has added, in this section:

1. **The frozen inventory:** every row of §2.1 with its file and line on
   `main` at the time of writing, and the constant or method the parity
   test will read — the same discipline as AU23's tool-loop list.
2. **The sub-type shapes:** the fields of `ProviderEndpoint`,
   `ProviderKeySpec` (including how BYOK user keys and the
   OpenAI-compatible endpoint registry are represented, since neither is a
   platform key), `ProviderDialects`, `ProviderDefaults` and
   `ProviderBranding`.
3. **Discovery:** how `ProviderCatalog` finds the descriptors (the
   `#[AsAiProvider]` attribute of §2.1, a tagged iterator, or a static
   list) and what happens for a provider class that carries no descriptor
   (the parity test must fail, not skip).

The hardening pull request changes no production code.

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
| Requests whose URL a person typed (URL reader, custom tools, MCP servers of a person, URL watch) | allowed per request by `SsrfGuard` + pinned connect, never added to the list; behind a proxy as §3.6 | AU15 (direct proxy), AU18 (gateway) |

Settings: `EGRESS.MODE` (BCONFIG global, default `off`), `EGRESS.ALLOW`
(JSON list), env `EGRESS_PROXY`, `EGRESS_NO_PROXY`. Admin → System settings →
a new "Outbound traffic" section.

In `report` mode a request that `enforce` would refuse goes out and logs
`egress.would_block` (destination, caller class, request id). In `enforce`
mode it throws `EgressRefusedException` before connecting, which callers map
to their existing "service not reachable" sentence.

**Every hop is checked, not only the first URL.** Symfony HttpClient follows
redirects inside the transport (`max_redirects`, default 20), where a
decorator never sees them. `EgressHttpClient` therefore sends every request
with `max_redirects: 0` and follows redirects itself, up to the caller's own
`max_redirects`: before each hop it applies the policy to the `Location`
destination (and, for a URL a person typed, `SsrfGuard` and the pin of §3.6),
keeps 307 / 308 method and body, and drops `Authorization` and cookies on a
host change, as Symfony does. A caller that asked for no redirects
(`HttpToolExecutor`) still gets none. Tests (AU15): allowed host → 302 →
refused host is refused in `enforce` and logged in `report`; allowed → allowed
follows; the hop limit holds; `MediaDownloader`, whose cURL transport follows
redirects today, keeps following them through this path (AU16).

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

### 3.6 Pinned requests behind a proxy

Today a URL a person typed is checked by `SsrfGuard`, and `HttpToolExecutor`
connects to the checked address through HttpClient's `resolve` option. Behind
any HTTP proxy that pin is lost: the client sends `CONNECT host:port`, and the
proxy resolves the name again — the DNS-rebinding window `resolve` closes.
And these destinations are deliberately not on the shared allowlist, so a
gateway that enforces it would refuse them.

| Route | Rule |
| ----- | ---- |
| No proxy | unchanged: `resolve` pin, no redirects |
| An enterprise proxy set for PHP (`EGRESS_PROXY` / `HTTPS_PROXY`) | PHP tunnels to the **pinned IP**: `CONNECT <ip>:<port>` while TLS keeps the original name for SNI and certificate checks (curl `CURLOPT_CONNECT_TO` through HttpClient's `extra.curl`). AU15 verifies this with a test proxy that logs the `CONNECT` target and with a rebinding resolver (first answer public, second private) |
| The Synaplan egress gateway (AU18) | PHP writes a **request-scoped grant** to Redis before the request: `egress:grant:{id}` = destination host and port, the pinned IPs, the request id, TTL 60 s, deleted after use. The request carries `Proxy-Authorization: Bearer <grant id>`. The gateway dials only the granted IPs for that one tunnel; without a valid grant the destination must be on the shared list |
| The gateway chained to an enterprise proxy (`UPSTREAM_PROXY`) | The gateway sends `CONNECT <pinned ip>:<port>` upstream. If the enterprise proxy refuses IP literals, the installation must choose: allow IP literals for granted tunnels there, or turn off typed-URL features (`docs/EGRESS.md` says so; a test covers the refusal copy) |

Tests (AU15, AU18): a custom HTTP tool and the URL reader through each route;
a rebinding name never reaches the private address; an expired or reused
grant is refused; the grant carries no secret and is never logged.

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
		match /api/v1/messages/stream* /api/v1/widget/*/message /v1/chat/completions /v1/messages /v1/audio/transcriptions/sessions/*/stream /api/v1/tts/stream /mcp
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

Which routes are long (checked 2026-10-21 by scanning the controllers for
`StreamedResponse` and event streams): the chat stream and its attach, the
widget message, both gateways, the dictation session stream
(`AudioTranscriptionController::streamSession()`, a PHP poll loop held 300 s by
default, up to 600 s), the TTS stream (`TtsController`, synchronous), and
`/mcp` (its `synaplan_chat` tool runs a whole chat turn inside one POST). The
usage CSV export streams but is short and stays in `api`. A new streaming
route goes on this line in the same pull request; `LongRouteListTest` (AU3)
fails when a controller returns a `StreamedResponse` from a route that no
`match` pattern covers, unless the route is on its short-stream allow-list.

Verified on 2026-10-21 with FrankenPHP 1.12.6, workers inside `php_server`:
`name`, global `max_wait_time`, and one `match` line with the first four patterns.
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
| `/v1/audio/transcriptions/sessions/*/stream` | the `/v1/chat/completions` body (OpenAI shape) | `Retry-After: 5` |
| `/api/v1/tts/stream` | `{"error":"server_busy","retryAfter":5}` | `Retry-After: 5` |
| `/mcp` | `{"jsonrpc":"2.0","error":{"code":-32000,"message":"Synaplan is busy. Retry in a few seconds."},"id":null}` | `Retry-After: 5` |

The API copy is English on purpose: it is a machine contract that SDKs
retry on. People see AU4's localized sentence.

---

## 6. The watchdog (S0 agent, S5 relay)

One Go binary, `sidecars/synaplan-watchdog`, in the backend image.

| Mode / endpoint | Since | Does |
| --------------- | ----- | ---- |
| agent loop | AU6 | every 5 s: read `http://localhost:2019/metrics` and `/frankenphp/threads`, cgroup limits (`/sys/fs/cgroup/memory.max`, `cpu.max`), `/proc` RSS, Kubernetes facts; write `capacity:server:{serverId}` (TTL 20 s), the one-minute aggregate `capacity:agg:{serverId}:{minute}` (TTL 24 h), the field `{serverId}` = last report time in the hash `capacity:seen` (TTL 7 d, refreshed), the same body once more as `capacity:last:{serverId}` (TTL 7 d, so a silent server keeps its role, machine and log source on the page), and the day's high-water mark `capacity:peak:{serverId}:{day}` (held AI answers, when; TTL 48 h) to Redis, every key under the application prefix `synaplan:<APP_ENV>:` that `RedisService` puts on its own keys, so PHP reads them and a dev and a test stack on one Redis stay apart. The hash is how the page lists a server whose snapshot has expired ("last report 3 minutes ago") |
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

### 6.1 One server identity, in PHP and in the watchdog (AU6, AU7)

Every record that names a server uses the same rule, so a capacity card, a
log line and a response header all point at one thing:

| Value | Rule | Who writes it |
| ----- | ---- | ------------- |
| `server_id` | `SYNAPLAN_SERVER_ID`, else the hostname. On Kubernetes the hostname is the pod name, so nothing has to be set. On compose the hostname is a container id (`79e4f90bc8c6`) unless the compose file sets `SYNAPLAN_SERVER_ID`; the platform cluster sets `web1` … `web3` from `updatewebN.sh`, a single-server install leaves it unset and sees its hostname | watchdog (snapshot); PHP (`X-Synaplan-Server` response header and the capacity API's `served_by`, AU7) |
| `hostname` | the container hostname, unchanged | watchdog (snapshot); PHP's event ring (`host`) already writes this |
| `role` | `SYNAPLAN_ROLE`, else `web` | watchdog. Every role runs it: the web role reports its pools and PHP readiness; a worker or the scheduler reports its place, memory and processor, is ready when Redis answers, and publishes no slot gauges. On compose, several roles on one machine need distinct ids (`web1`, `web1-worker`, `web1-bulk`, `web1-scheduler`), which the watchdog derives itself: a worker or the scheduler appends its role to an explicit id that carries none (`web1` → `web1-worker`), so one env file shared by several containers cannot make two roles overwrite one snapshot; the platform compose spells the suffixes out and the watchdog keeps them. On Kubernetes every pod name is distinct by itself |

The snapshot `capacity:server:{id}` (AU6) carries, next to the pools and the
limits:

| Field | Compose | Kubernetes | Source |
| ----- | ------- | ---------- | ------ |
| `place.host` | `SYNAPLAN_HOST_NAME` (the machine; optional) | the node, `SYNAPLAN_K8S_NODE` | env, Downward API `spec.nodeName` (AU8) |
| `place.address` | `SYNAPLAN_NODE_ADDRESS` (the machine's address; optional). Loopback is dropped, and the watchdog never scans interfaces, so `127.0.0.1` and a container bridge address do not appear | the same variable, when the chart sets it | env. Not the `X-Synaplan-Server` header |
| `place.pod` | — | the pod name | hostname |
| `place.namespace` | — | the namespace | the service-account file, else `SYNAPLAN_K8S_NAMESPACE` |
| `place.deployment` | — | `SYNAPLAN_K8S_DEPLOYMENT`, else the pod name without its ReplicaSet and pod suffixes | env (AU8), derived |
| `place.container` | — | `SYNAPLAN_K8S_CONTAINER` | env (AU8) |
| `place.cpu_request`, `place.memory_limit` | — | the declared resources | Downward API `resourceFieldRef` (AU8) |
| `logs.collector` | `docker` | `kubernetes` | runtime (`process` on a bare host) |
| `logs.event_ring_host` | the hostname | the pod name | what PHP stamps on `GET /api/v1/admin/logs` records |

PHP and the watchdog write to stderr; the collector is what gathers it. The
page (AU7) turns these facts into the one command an operator needs —
`docker logs <hostname>` on `<host>`, or `kubectl logs -n <namespace>
<pod> -c <container>` — and a link to the event ring filtered by
`event_ring_host`. The watchdog stores facts, never commands.

**Served by.** The browser learns which server answered from the
`X-Synaplan-Server` response header (AU7, next to today's `X-Request-Id`).
The capacity page marks that card "You are connected to this server"; a
support case can then quote both headers. The header is the `server_id`,
nothing else — no pod IP, no node address.

---

## 7. The stream pump (S5)

- Role `pump` of the backend image (`SYNAPLAN_ROLE=pump`), started by
  `_docker/backend/lib/container-runtime.sh` like `worker`; in compose a
  `pump` service; on the platform one per web node to start.
- **Jobs carry no secret and expire one by one.** The queue `stream:jobs`
  (consumer group `pump`) holds only opaque ids: `XADD stream:jobs * job <jobId>`.
  The job itself is a separate key with its own TTL:

```json
// stream:job:{jobId}   TTL 120 s, deleted on claim
{ "runId": "…", "callId": "…", "channel": "chat_stream|widget|openai_api|messages_api",
  "provider": "anthropic", "model": "claude-…", "call": { "payload": { … }, "options": { … } },
  "credential": { "source": "user|operator|endpoint", "userId": 3, "endpointId": null,
                  "operatorFallback": false, "keySource": "user" },
  "decoder": "anthropic_messages", "encoder": "synaplan_sse|openai_chunks|anthropic_events",
  "deadlineSec": 240, "turn": { "chatId": 1, "messageId": 2, "userId": 3 } }
```

  No provider key and no auth header are in Redis: the pump is PHP and builds
  the request itself (`buildStreamRequest(AiCall)`, §7 below). Prompts in
  `call.payload` live only for the 120 s until claim.
- **The pump uses the credential the turn was admitted with.** Admission
  already decided whose key pays: `MessagesGateway::resolveCredential()`
  picks a person's own key (BYOK, metered at zero cost), an endpoint of the
  OpenAI-compatible registry, or the operator's key only where
  `ALLOW_OPERATOR_KEY` permits it; the app chat has its own rule. AU29 moves
  these rules into one `TurnCredentialResolver`; admission stores its
  **non-secret** decision in `credential`, and the pump asks the same resolver
  for exactly that source — never "whatever `ProviderKeyStore` has". A source
  that is gone by then (key deleted) ends the run with the existing "key
  missing" sentence instead of falling back to another account. `keySource`
  flows into the usage row, so BYOK calls stay at zero cost. Parity tests
  (AU30): user-key-only, mixed (user key for one provider, operator for
  another), operator fallback disabled, endpoint registry — in-request and
  pump mode write the same row and use the same key.
- **Lifecycle.** `XREADGROUP` gives a pump the id; `GETDEL stream:job:{jobId}`
  claims the body atomically; then `XACK` and `XDEL` remove the entry, so the
  stream holds only unclaimed ids. A body that already expired ⇒ the pump
  writes the run's terminal entry ("could not start", §8) and removes the id.
  An id read by a pump that died before `GETDEL` is taken over with
  `XAUTOCLAIM` after 10 s. A run stays in state `queued` with a claim deadline
  of 30 s; the reaper (below) fails a queued run past it. A half-streamed
  answer is never retried.
- One event loop: `HttpClient::stream()` over all active responses with a
  50 ms tick; per chunk `StreamDecoderInterface::feed()` → `StreamChunk`s →
  `AiCallPipeline` streaming hooks → encoder → `XADD chatrun:{runId}:stream`.
- Tool call in the stream → `ToolStepCommand` (Messenger, `async_ai_high`)
  runs `ToolInvoker`, writes the result, re-queues the next upstream request
  for the same run.
- End → `FinishTurnCommand` (Messenger): store the reply, usage through the
  pipeline's after-hooks, memory extraction, `complete` frame.
- Every pump start gets a fresh **incarnation id** (a random UUID, created
  before the first claim), because a quick container restart can reuse both
  the server id and the PID. Heartbeat `pump:{incarnationId}` (value: server
  id and PID, for people) every 2 s with a 10 s TTL, and each run records the
  incarnation that owns it. A restarted pump never renews its predecessor's
  heartbeat, so that predecessor's runs are reaped. Test: kill a pump and
  start a new one with the same server id and PID inside the heartbeat TTL;
  the old runs are failed, the new pump's runs are not. **Cleaning up after a dead pump is not a pump's job**, because
  the dead one may have been the last: `app:runs:reap` runs in the scheduler's
  tick lane (every minute, `ScheduledJobs` and `container-runtime.sh`), and
  each pump also runs it once at start. A run that is `queued` past its claim
  deadline, or `running` while its owner's heartbeat is gone for 10 s, is
  failed in **one atomic step** (a Redis script: only if still in that state,
  set the run snapshot to failed and append its `terminal` entry), then the
  stored message gets the same failure as an in-request turn gets today. Pumps
  and the scheduler can both run it; the state check makes the second a
  no-op. While nobody has reaped yet, the watchdog closes the client after
  `idleTimeoutSec` with the directive's terminal frame (§8), so no client
  waits forever and no run stays "running" for more than about a minute
  after the last pump died (UX bar 6).
- Cancel: `CancelledTurns` flag checked on every tick; the upstream request
  is cancelled, the partial answer stored as today.
- **Graceful shutdown.** A pump owns provider sockets that cannot be moved
  and answers that are never retried half-way, so stopping one is a drain:
  on `SIGTERM` it (1) stops claiming — no new `XREADGROUP`, its heartbeat
  says `draining` so the capacity page and the reaper know; (2) keeps
  streaming its active turns to their end; (3) at `PUMP_DRAIN_SECONDS`
  (default = the turn deadline, 240 s) ends whatever is still running the way
  a person's Stop does today: the partial answer is stored, the run gets a
  `terminal` entry with the existing cut-off sentence, usage is closed.
  Nothing is left "running" and nothing is retried. The orchestrator's grace
  period is longer than the drain (`terminationGracePeriodSeconds` =
  `PUMP_DRAIN_SECONDS` + 30 s; in compose `stop_grace_period`). Rollouts
  replace pumps one at a time with a new one started first (Kubernetes
  `maxSurge: 1`, `maxUnavailable: 0`); scale-down picks a pump through the
  same drain. Drills (AU30 on compose, AU34 on Kubernetes): roll the pumps and
  scale them down by one while 200 turns run — every turn ends complete, or,
  past the drain, with its stored partial and the cut-off sentence; none ends
  in an error or stays running.

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

### 7.1 Pre-sprint hardening — must merge before AU28 (AU0b)

The spike of AU28 is where design gaps turn into load-test failures, so
the following are written down — in this section or in §8 — and merged
before the spike branch opens; AU28 verifies them, it does not invent
them:

1. **The turn state machine:** the states a run passes through (`queued`,
   `running`, the terminal states) with the one writer allowed to make
   each transition, including the atomic reaper transition of §7 and the
   `gap` path of §8. One table, cited by AU30's tests.
2. **Sequence numbers:** the first `seq` of a run (the explicit stream id
   `0-{seq}` of §8 rejects `0-0`, so the base is verified, not assumed),
   who allocates it across the pump rounds of one turn, and why two pumps
   can never allocate the same `seq` for one run.
3. **The attach token:** what `ownerKey` in §8 is (today's attach
   authorisation, named by class), how `RUNTIME.ATTACH_SECRET` is created
   on an existing installation (seeder / migration `UPDATE`, §0 #24) and
   what happens to in-flight tokens when it rotates.
4. **The relay trust boundary:** PHP answers an attach directive only to a
   request the watchdog actually made. How the watchdog proves itself
   (the header rides on a loopback-only route in Caddy, plus a shared
   secret or mTLS — the hardening pull request decides) and the test
   that an external request carrying `X-Synaplan-Relay: 1` gains nothing.
5. **Security tests written as exit criteria, not prose, each in the
   step that introduces what it exercises:** a reused egress grant (§3.6)
   is refused — AU30, with the gateway work; a tampered or expired attach
   token is refused and an external request carrying
   `X-Synaplan-Relay: 1` gains nothing — AU31, which introduces the token
   check and the relay route (AU30 has neither, so it cannot drill them).
6. **The canary rule:** `RUNTIME.STREAM_PUMP_PERCENT` (AU30) hashes a
   named stable key (run id, chat id or user id — the pull request picks
   and says why), so one turn never changes mode between its rounds.
7. **Prompts in Redis:** job bodies carry `call.payload` for up to 120 s
   (§7). The hardening states what the production Redis settings imply
   (persistence, encryption at rest, who may read the keyspace) and what
   `docs/EGRESS.md`'s sister note in `docs/CAPACITY.md` must tell an
   operator — or it shortens the TTL and says why that is safe.

---

## 8. Run stream, attach directive, attach token (S5)

- `chatrun:{runId}:stream` — Redis Stream, one entry per frame, written with
  the **explicit id `0-{seq}`** (`XADD key 0-{seq} …`), where `seq` is today's
  SSE sequence number (`id:` of `StreamAttachController`). Fields:

  | Field | Meaning |
  | ----- | ------- |
  | `kind` | `frame` (copy and flush), `terminal` (copy, flush, close — the run ended, successfully or not), `gap` (the log was cut, see below) |
  | `frame` | the exact bytes for the client's wire (`event: …\ndata: …\n\n` or `data: {…}\n\n`), without the `id:` line |

  The watchdog closes on `terminal` while the pump is healthy; it never has to
  read a frame to know the run ended. TTL as `ChatRunBuffer` today (30 min
  running, 5 min terminal). The ZSET is written as well until AU32 ships;
  `StreamAttachController` reads either.
- **No silent trimming.** No `MAXLEN`. The log is complete for its lifetime up
  to `RUNTIME.RUN_STREAM_MAX_BYTES` (default 16 MiB; today's replay buffer
  stops at 1 MiB, `ChatRunRecorder::MAX_BUFFERED_BYTES`, but there the live
  client did not read from it). At the cap the pump appends one `gap` entry
  and marks the run truncated, exactly as `ChatRun::markTruncated()` does
  today; a reader that meets `gap` writes the directive's `terminal` frame,
  which the clients already treat as a recoverable error and answer with the
  history recovery they use today. The pump keeps generating; the stored
  reply is complete.
- With `SYNAPLAN_RELAY=1`, Caddy sends the long routes to the watchdog, which
  forwards the request to PHP with `X-Synaplan-Relay: 1`. PHP either answers
  normally (the watchdog passes the response through, byte for byte) or
  answers `200` with `Content-Type: application/vnd.synaplan.attach+json`:

```json
{ "runId": "…", "token": "…", "fromSeq": 0, "sseIds": true,
  "contentType": "text/event-stream", "headers": { "X-Request-Id": "…" },
  "terminal": "data: {\"status\":\"error\",\"message\":\"…\"}\n\n",
  "idleTimeoutSec": 60 }
```

- `token` = base64url(runId · ownerKey · fromSeq · exp) + "." + HMAC-SHA256
  with `RUNTIME.ATTACH_SECRET`; the cursor is inside the signature, so a client
  cannot widen it.
- **The resume cursor survives.** A new stream gets `fromSeq: 0` (full replay).
  An attach (`GET /api/v1/messages/stream/attach?from=N`) is authorized by PHP
  exactly as today and answered with `fromSeq: N`. The watchdog reads
  `XREAD BLOCK … STREAMS chatrun:{runId}:stream 0-{fromSeq}`, which returns only
  entries after the cursor, so a resumed attach never repeats rendered text.
  `sseIds: true` (web chat, widget) makes the watchdog prefix each frame with
  `id: {seq}` as `StreamAttachController::emit()` does; `false` for the
  gateways, whose wire has no ids today.
- Each frame is written and flushed; an SSE comment `: keep-alive` goes out
  after 15 s of silence. `terminal` or `gap` ⇒ write, flush, close.
- If the run stream stays silent for `idleTimeoutSec` and the pump heartbeat
  is gone, the watchdog writes the directive's `terminal` frame and closes. A
  stream always ends in a terminal frame.
- Tests (AU30, AU31): completion while the pump stays healthy closes the
  client; a resumed attach from `N` gets exactly the frames after `N`; a run
  past the byte cap (cap set small in the test) ends with the recoverable
  terminal and a complete stored reply; an expired job body and a dead
  pre-claim consumer both end their runs in a terminal frame.
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
| `synaplan_server_info` | gauge | `server`, `role`, `runtime` (`compose` \| `kubernetes` \| `host`), `version` | watchdog |
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

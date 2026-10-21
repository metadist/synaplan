# Architecture update — status

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk, a
measurement and a rollout are entries here, not chat messages.

## Steps

| Date | Step | Pull request | State | Notes |
| ---- | ---- | ------------ | ----- | ----- |
| 2026-10-21 | AU0 | this plan | open | research, spike, plan |

## Baselines

| Date | What | Value | Source |
| ---- | ---- | ----- | ------ |
| 2026-10-21 | Size ledger (`make -C backend metrics`) | to be recorded by AU1 | — |
| 2026-10-21 | Runtime measurements | `02_measurements.md` §1–§4 | `spike/` |

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
| Symfony HttpClient multiplexing in one PHP process | 1,000 streams, 26 MB, 2.5 ms CPU per stream (`spike/pump`) |
| Go relay, stdlib only | 2,000 streams, 142 MB (`spike/relay`); a second run reproduced it: 147.7 MB peak, 38 KB per stream, 6.0 s CPU, TTFB p95 0.15 s |
| Node 22.23.3 (`node:22-alpine` pinned in both Node sidecars) with `NODE_USE_ENV_PROXY=1` | `fetch()` and `https.get()` go to `HTTPS_PROXY` (ECONNREFUSED at an unreachable proxy); without the flag `fetch()` goes direct (200) |
| One database connection per worker | 8 workers → 8 connections in `information_schema.PROCESSLIST` |
| `/api/health` spawns a process | `WhisperService::isAvailable()` runs `whisper --help` on every call; ≈ 80 req/s at 1, 8 and 16 clients |

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

## Review log

| Date | Pull request | Round | Finding | Answer |
| ---- | ------------ | ----- | ------- | ------ |

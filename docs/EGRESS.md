# Outbound traffic

Every byte Synaplan sends to another host, as of the inventory frozen in
`backend/tests/Architecture/egressInventoryBaseline.json` (S2 AU14). The
policy that will stand in front of these calls is
[`03_contracts.md`](../_devextras/planning/20261021-architecture-update/03_contracts.md)
§3. It is not switched on yet: `EGRESS.MODE` does not exist until AU15, and
its default will be `off`.

The 2026-10-21 map in `STATUS.md` counted 9 `curl_init` calls, one
`file_get_contents` fallback, and one `HttpClient::create()`. The scan of
`backend/src` and the plugins committed in this repository finds those
backend cURL sites, three `file_get_contents` downloads of an image URL in
OpenAI image generation, and `OpenAI::client()` in `OpenAIApiClient`.
Plugins named in `.gitignore` (Synaform, Synamail, and the other external
checkouts) are absent from a clean checkout and stay out of this inventory.

## HTTP stacks that bypass Symfony HttpClient

76 classes inject `HttpClientInterface`. They already share one client.
These sites do not.

| Kind | Where | What it calls |
| ---- | ----- | ------------- |
| `curl_init` | `MediaDownloader` | The URL a provider returned for a generated file. Falls back to `file_get_contents($url)` when the cURL extension is missing. |
| `curl_init` | `MediaGenerationService` | Saves generated media. |
| `curl_init` | `OpenAICurlTransport` | Multipart upload for `gpt-image`. |
| `curl_init` | `CloudflareProvider` | Embeddings. |
| `curl_init` ×5 | `SystemConfigService` | Health probes for Ollama, Tika, Docling, Qdrant, and Piper. |
| `file_get_contents` ×3 | `OpenAIImageGeneration` | Downloads an image URL (and a mask URL) before an edit or a variation. |
| `HttpClient::create()` | `MetaProvider` | Its own Symfony client when none is injected. |
| OpenAI PHP SDK, no injected client | `GroqProvider`, `XaiProvider`, `PerplexityProvider`, `AbstractChatCompletionsCloudProvider`, `OpenAiCompatibleClients`, `OpenAIApiClient` | `OpenAI::factory()->make()` and `OpenAI::client()` discover a PSR-18 client. |
| Ollama PHP SDK | `OllamaProvider` | `Ollama::client()`, which uses Guzzle. |
| Stripe PHP SDK | `SubscriptionController` (6), `StripeWebhookController` (2), `StripeCheckoutSessionGateway` (3) | `Stripe::setApiKey()` leaves the SDK on its default cURL client. |
| Google API client | `GooglePlayVerifier` | `new GoogleClient()` for the Play Developer API. The client uses Guzzle. |
| Apple App Store library | `AppleStoreKitVerifier` | `new SignedDataVerifier(...)`. Online checks, when enabled, use the library's own HTTP client. |
| reCAPTCHA | `RecaptchaService` | `new ReCaptcha($secret)`, which calls Google's siteverify endpoint. |

`EgressInventoryTest` fails when any of these patterns appears at a new
site. The baseline may only shrink (`UPDATE_EGRESS_INVENTORY=1` drops sites
that are gone and refuses to add one).

## Not HTTP, and not wrapped

These stay on their own protocols. The inventory does not list them. The
optional egress gateway (AU18) can carry the WebSocket through CONNECT.
Nothing else in this table moves onto the HTTP client.

| Protocol | Where | Setting |
| -------- | ----- | ------- |
| MariaDB | Doctrine DBAL | `DATABASE_WRITE_URL`, `DATABASE_READ_URL` |
| Redis | `RedisService` (Predis) | `REDIS_DSN` |
| SMTP | Symfony Mailer | `MAILER_DSN` |
| IMAP | `InboundEmailHandlerService`, `InboundEmailService`, `ImapMailboxSearcher` | The mailbox settings of the email handler |
| gRPC | Triton, from `LocalProviderAvailabilityProbe` and the model catalog | The Triton endpoint on the AI settings tab |
| WebSocket | The voice sidecar's provider socket | The voice service URL. PHP does not open it. |

No compose file sets `HTTP_PROXY`, `HTTPS_PROXY`, or `NO_PROXY`. Symfony
HttpClient honours those environment variables on its own. Raw cURL and the
SDKs above do not, until they move onto the shared client (AU16, AU17).

## What is deliberately not a shared allowlist entry

A URL a person types (the URL reader, a custom HTTP tool, a person's MCP
server, a URL watch) is checked by `SsrfGuard`. `HttpToolExecutor` is the
only place that pins the connection to the address that check resolved.
That pin is lost behind an HTTP proxy, because the proxy resolves the name
again. AU15 keeps the pin by tunnelling to the pinned IP. AU18 does the
same with a one-request grant. Neither adds the host to the installation
allowlist.

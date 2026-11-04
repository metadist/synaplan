> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 2: Administration, Security & Billing (4/9)**  
> **← Previous:** [2.3 Observability & Redacted Error Ring](OBSERVABILITY.md) · **Next:** [2.5 Self-Hosted Billing (Stripe) →](BILLING_SELFHOST.md)

# Outbound Traffic & Egress Control

> **In this guide:** Every outbound network connection Synaplan makes, how the outbound HTTP allowlist (`EGRESS.MODE`) is derived automatically from `ProviderCatalog`, how user-supplied URLs are guarded against SSRF with IP pinning, and how to run the optional egress gateway (`docker-compose.egress.yml`).

---

## 1. Outbound Policy Modes (`EGRESS.MODE`)

Every outbound HTTP call from the PHP backend (76 services injecting Symfony's `HttpClientInterface`, plus the OpenAI-compatible SDKs, Ollama, Stripe, Google Play, and reCAPTCHA) passes through a single policy layer (`EgressPolicy`, contract in [`03_contracts.md`](../_devextras/planning/20261021-architecture-update/03_contracts.md) §3).

Operators configure the mode on **Operate → System configuration → Outbound traffic** (guide: [Runtime and outbound traffic](https://docs.synaplan.com/runtime-setup); settings summary: [RUNTIME_OPTIONS.md](RUNTIME_OPTIONS.md)):

| Mode / Setting | Default | Behavior |
| -------------- | ------- | -------- |
| `EGRESS.MODE = off` | **Default** (seeded `off`) | All outbound HTTP calls proceed normally (subject to `SsrfGuard` on user-supplied URLs). |
| `EGRESS.MODE = report` | Opt-in | Lets unallowlisted calls proceed and records an `egress.would_block` event containing the target **host and port only** (never paths or secrets). Use this to audit custom endpoints before enforcing. |
| `EGRESS.MODE = enforce` | Opt-in | Refuses any unallowlisted outbound host **before** opening a socket. |
| `EGRESS.ALLOW` | *empty* | Additional hostnames allowed in `report` and `enforce` modes (for example, an admin-configured custom OpenAI-compatible gateway). |
| `EGRESS_PROXY` | *empty* | Environment variable. Overrides `HTTPS_PROXY` when set; leaving it empty preserves standard `HTTP_PROXY` / `HTTPS_PROXY` / `NO_PROXY` behavior. |
| `EGRESS_NO_PROXY` | `NO_PROXY` | Environment variable. Hosts that bypass the proxy and are never refused in `enforce` mode. |

---

## 2. Where the Allowlist Comes From

You do not maintain a static list of AI provider domains by hand. `EgressPolicy` assembles the allowlist from four sources:

1. **Internal installation services (`EgressBuiltinDestinations::INTERNAL`)** — The Docker Compose and Kubernetes service names of this installation (`db`, `redis`, `qdrant`, `tika`, `docling`, `searxng`, `collabora`, `tts`, `compute`, `centrifugo`, `voice`, `transcriber`).
2. **AI provider endpoints (`ProviderCatalog::endpoints()`)** — Every inference host and key-validation host declared in each provider's `ProviderDescriptor`. Adding a provider as one PHP class ([PROVIDERS.md](PROVIDERS.md)) automatically adds its hosts to the allowlist in `enforce` mode.
3. **Non-AI integrations (`EgressBuiltinDestinations::INTEGRATIONS`)** — Fixed upstream hosts for Stripe, Telegram, Meta Graph (WhatsApp), Brave Search, Google OAuth & Play Billing, GitHub OAuth, Apple Sign-In, reCAPTCHA, and Cloudflare Turnstile.
4. **Operator additions (`EGRESS.ALLOW` and `EGRESS_NO_PROXY`)** — Custom OpenAI-compatible base URLs entered by an administrator are not auto-added; in `report` mode they appear as `egress.would_block` until listed in `EGRESS.ALLOW`.

---

## 3. User-Supplied URLs & SSRF IP Pinning

A URL typed by a user—in the URL reader (`url_fetch`), a watched page, a custom HTTP tool (`HttpToolExecutor`), or a personal MCP server—is **never** added to the installation allowlist.

Instead, every user-supplied URL is validated by `SsrfGuard`:
- Private, loopback, link-local (`169.254.0.0/16`), and non-globally-routable IP ranges are rejected.
- To prevent DNS rebinding between the check and the connection—even behind an outbound HTTP proxy that would normally re-resolve the hostname—Synaplan pins the connection to the validated IP address (`CURLOPT_CONNECT_TO`, or a one-request IP grant when the optional egress gateway is active).

---

## 4. The Optional Egress Gateway (`docker-compose.egress.yml`)

For strict network-level enforcement across both PHP and the sidecars, load the optional egress overlay:

```bash
docker compose -f docker-compose.yml -f docker-compose.egress.yml --profile egress up -d
```

How the gateway works:
- Points `backend`, `worker`, `scheduler`, `voice`, and `transcriber` at `http://egress:3128` and sets `NODE_USE_ENV_PROXY=1` on the two Node sidecars.
- Accepts `CONNECT` tunnels only (`CURLOPT_HTTPPROXYTUNNEL` for plain HTTP).
- When `EGRESS_GATEWAY=1` is set, PHP attaches a single-use grant header (`CURLOPT_PROXYHEADER`) for user-typed URLs; the gateway dials the pinned IP and deletes the grant immediately without logging the grant ID.
- Publishes the allowlist to Redis on container boot and refreshes it on each command and request (24-hour TTL). `REDIS_KEY_PREFIX` must match `RedisService` (`synaplan:{APP_ENV}:`; `synaplan:dev:` in the dev overlay).
- **Secure compute runs** do not inherit `HTTP_PROXY`: their internal pin-proxy dials approved addresses directly, or forwards `CONNECT <pinned ip>` to the egress gateway using `EGRESS_UPSTREAM` and `EGRESS_UPSTREAM_TOKEN` (`EGRESS_COMPUTE_TOKEN` on the gateway).
- **Enterprise upstream proxy chaining:** When chained to a corporate proxy via `UPSTREAM_PROXY`, the gateway sends `CONNECT <pinned ip>:<port>`. If the corporate proxy refuses IP-literal `CONNECT` requests, user-typed URL features will fail with `reason=upstream_refused` (logging host and port only); allow IP literals on that tunnel or disable typed-URL features.

---

## 5. Non-HTTP Traffic & Inventory Baseline

### Protocols Not Wrapped by `HttpClientInterface`

These services speak binary or non-HTTP protocols directly (the optional egress gateway can carry the voice sidecar's WebSocket via `CONNECT`):

| Protocol | Used By | Configuration Setting |
| -------- | ------- | --------------------- |
| **MariaDB** | Doctrine DBAL | `DATABASE_WRITE_URL`, `DATABASE_READ_URL` |
| **Redis** | `RedisService` (Predis) & `ext-redis` | `REDIS_DSN`, `LOCK_DSN` |
| **SMTP** | Symfony Mailer | `MAILER_DSN` |
| **IMAP** | `InboundEmailHandlerService`, `InboundEmailService`, `ImapMailboxSearcher` | Per-mailbox handler settings |
| **gRPC** | Triton Inference Server (`LocalProviderAvailabilityProbe`) | Triton endpoint in AI settings |
| **WebSocket** | Live voice sidecar (`sidecars/synaplan-voice`) | `LIVE_VOICE_GATEWAY_URL` (opened by the sidecar, never by PHP) |

### Architecture Gate (`egressInventoryBaseline.json`)

`EgressInventoryTest` scans `backend/src` and committed plugins to ensure no new raw `curl_init`, `file_get_contents(http…)`, or uninjected `HttpClient::create()` calls are introduced. The frozen baseline (`backend/tests/Architecture/egressInventoryBaseline.json`) may only shrink via `UPDATE_EGRESS_INVENTORY=1`.

The sole third-party library without an injectable HTTP client is `AppleStoreKitVerifier` (`new SignedDataVerifier(...)`), whose online OCSP check is **off by default**.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 2: Administration, Security & Billing (4/9)**  
> **← Previous:** [2.3 Observability & Redacted Error Ring](OBSERVABILITY.md) · **Next:** [2.5 Self-Hosted Billing (Stripe) →](BILLING_SELFHOST.md)

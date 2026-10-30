# Outbound traffic

Every byte Synaplan sends to another host, as of the inventory frozen in
`backend/tests/Architecture/egressInventoryBaseline.json` (S2 AU14). The
policy that will stand in front of these calls is
[`03_contracts.md`](../_devextras/planning/20261021-architecture-update/03_contracts.md)
§3. `EGRESS.MODE` is seeded `off`. `report` lets a call out and records
`egress.would_block` with the host and port only. `enforce` refuses before
a socket opens. The extra allowlist is `EGRESS.ALLOW`. `EGRESS_PROXY`
overrides `HTTPS_PROXY` when it is set; an empty value leaves the
environment proxy alone. A URL a person typed is not added to the list:
`SsrfGuard` decides, and behind a proxy the tunnel goes to the pinned
address (`CURLOPT_CONNECT_TO`). Raw cURL moved onto this client in AU16.
The OpenAI-compatible SDKs, Ollama, Stripe, Google Play and reCAPTCHA
moved on in AU17. Apple's verifier has no HTTP hook: online checks are
off unless the operator turns them on, and then OCSP stays inside the
library.

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
| Apple App Store library | `AppleStoreKitVerifier` | `new SignedDataVerifier(...)`. Online checks, when enabled, use the library's own HTTP client. The constructor has no client argument, so OCSP cannot be moved onto the shared client. The default is off. |

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

The default compose file sets none of `HTTP_PROXY`, `HTTPS_PROXY`, or
`NO_PROXY`. Symfony HttpClient honours those environment variables on its
own. The SDKs that accept a client now use that client. Apple OCSP does
not, and only when online checks are on.

## The optional gateway

`docker-compose.egress.yml` is off unless you load it:

```bash
docker compose -f docker-compose.yml -f docker-compose.egress.yml --profile egress up -d
```

That file points PHP, the worker, the scheduler, voice, and the
transcriber at `http://egress:3128`, and sets `NODE_USE_ENV_PROXY=1` on the
two Node sidecars so they actually use the proxy. Plain HTTP is sent as a
CONNECT tunnel (`CURLOPT_HTTPPROXYTUNNEL`), because the gateway accepts
CONNECT only. `EGRESS_GATEWAY=1` tells PHP to send a one-request grant on
`CURLOPT_PROXYHEADER`, which is the header curl puts on CONNECT. The
gateway dials the pinned address and deletes the grant. An allowlisted
name is resolved by the gateway. The allowlist is written when the
process starts (the entrypoint runs a console command) and again on each
command and request; the key lasts a day. Without this file, nothing in
the default stack changes.

A compute run does not use that proxy environment. Its pin proxy dials
the approved address itself. When `EGRESS_UPSTREAM` and
`EGRESS_UPSTREAM_TOKEN` are set, that proxy sends `CONNECT <pinned ip>`
to the gateway with the token. The gateway dials that public address and
does not log the token. `docling` and `searxng` are in `NO_PROXY`. The dev overlay also bypasses
Packagist and GitHub: Composer runs before PHP has published the allowlist,
and without that bypass the backend and the worker never become healthy.

`REDIS_KEY_PREFIX` must be the prefix `RedisService` writes
(`synaplan:` plus `APP_ENV` plus `:`). The dev overlay uses `synaplan:dev:`
because this compose file pins `APP_ENV=dev`. Production sets
`EGRESS_COMPUTE_TOKEN` to the same value on the gateway and the compute
service. The dev overlay has a local default.

An enterprise proxy still uses `EGRESS_PROXY` and `CURLOPT_CONNECT_TO`.
The gateway, when it is chained with `UPSTREAM_PROXY`, sends
`CONNECT <pinned ip>:<port>` to that proxy. If the enterprise proxy
refuses a CONNECT to an IP address, typed-URL features stop: allow IP
literals for those tunnels, or turn the typed-URL features off. The
gateway log line is `reason=upstream_refused` and it names the host and
port only. A grant id is not written to a log.

## What is deliberately not a shared allowlist entry

A URL a person types (the URL reader, a custom HTTP tool, a person's MCP
server, a URL watch) is checked by `SsrfGuard`. `HttpToolExecutor` is the
only place that pins the connection to the address that check resolved.
That pin is lost behind an HTTP proxy, because the proxy resolves the name
again. AU15 keeps the pin by tunnelling to the pinned IP. AU18 does the
same with a one-request grant. Neither adds the host to the installation
allowlist.

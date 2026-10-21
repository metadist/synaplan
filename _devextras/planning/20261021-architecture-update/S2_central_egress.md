# S2 — Central egress

Master plan §5 rows AU14–AU18; architect direction 3. Contract:
[`03_contracts.md`](./03_contracts.md) §3. Loop:
[`01_vibe_coding_loop.md`](./01_vibe_coding_loop.md).

**Shape of the sprint:** freeze the stacks that exist (AU14), put one
policy in front of the Symfony client every class already uses (AU15), move
the raw cURL sites (AU16) and the SDKs (AU17) onto it, and offer one door for
the sidecars too (AU18). Enforcement is an operator's choice after a week in
report mode.

---

## AU14 — Gate new outbound HTTP stacks (backend-only)

### Goal

- Do: `tests/Architecture/EgressInventoryTest.php` and
  `egressInventoryBaseline.json` (`03` §3.5): today's 9 `curl_init`, 1
  `file_get_contents` fallback, 1 `HttpClient::create()`, the SDK
  constructions. Shrink-only, like the size ratchet; `UPDATE_EGRESS_INVENTORY=1`
  regenerates with removals only.
- Do: `docs/EGRESS.md` draft: every outbound path today, including the
  non-HTTP protocols of §0 #9.

### Vibe brief

> Implement AU14 as a `test(egress)`. The scan must catch each pattern in a
> scratch file under `tests/` (a fixture the scanner is pointed at), and the
> baseline must equal today's sites exactly (compare with the STATUS egress
> map of 2026-10-21). `make ci-local`.

### Exit criteria

1. A new `curl_init` in `src/` fails the test (shown with a scratch fixture).
2. Baseline equals the STATUS map; `docs/EGRESS.md` lists every path.

---

## AU15 — One outbound HTTP client with proxy settings and a destination allowlist (backend-only)

### Goal

- Do: `framework.http_client.default_options` (`03` §3.1);
  `EgressHttpClient` (`#[AsDecorator('http_client')]`), `EgressPolicy`,
  `EgressMode`, the allow sources of `03` §3.2 (static provider list until
  AU22), `EgressRefusedException`.
- Do: settings `EGRESS.MODE`, `EGRESS.ALLOW`, env `EGRESS_PROXY`,
  `EGRESS_NO_PROXY`; the "Outbound traffic" section in Admin → System
  settings as a `SystemConfigTab` (backend schema only; the generic settings
  page renders it).
- Do: metrics `synaplan_egress_*`, `egress.would_block` to the event ring,
  `egress[]` in the request timeline (AU13).
- Do: a streaming request through a proxy in a test: a small CONNECT proxy
  in the test (the compute sidecar's proxy image, or a PHP stub on a socket)
  and a stub SSE upstream — chunks arrive unbuffered.
- Do: keep the pin for URLs a person typed when a proxy is set (`03` §3.6):
  the tunnel goes to the pinned IP (`CURLOPT_CONNECT_TO` through
  `extra.curl`), TLS keeps the original name. Tested with a test proxy that
  logs its `CONNECT` target and a resolver that answers public first and
  private second (DNS rebinding), for a custom HTTP tool and the URL reader.
- Do not: change `SsrfGuard`'s rules or the no-proxy path of
  `HttpToolExecutor`; default to anything but `off`.

### Vibe brief

> Read `03` §3. Implement AU15 as a `feat(egress)`. Tests first: off ⇒ the
> decorator is a pass-through (provider request snapshots unchanged); report
> ⇒ request goes out and `egress.would_block` is logged with destination and
> caller class only; enforce ⇒ `EgressRefusedException` before any socket
> opens; proxy env and `EGRESS_PROXY` precedence; `NO_PROXY` for the internal
> services; a stream through the proxy arrives chunk by chunk. Then run the
> dev stack with `EGRESS.MODE=report` through `make test-e2e` and list every
> `would_block` destination in STATUS — that list is the gap AU16, AU17 and
> AU22 must close. `make ci-local`, `make test-e2e`, `make perf-runtime`
> (gateway overhead ≤ 40 ms median).

### Exit criteria

1. The three modes and the proxy precedence covered by tests; snapshots
   unchanged in `off`; behind the test proxy a rebinding name never reaches
   the private address.
2. The `report` run's destination list in STATUS.
3. `docs/EGRESS.md` explains modes, proxy, allowlist, what is not covered.
4. Budgets met.

---

## AU16 — Raw cURL calls use the egress client (backend-only)

### Goal

- Do: `MediaDownloader` (cURL and the `file_get_contents` fallback),
  `MediaGenerationService` (save generated media), `OpenAICurlTransport`
  (gpt-image), `CloudflareProvider` (embeddings), `SystemConfigService`
  (five health probes) on `HttpClientInterface`. Each first gets a recorded
  request characterization (the transport moved verbatim behind a seam if it
  is not recordable — V4 decision #7 exception), then the switch.
- Do: `EgressInventoryTest` baseline shrinks by these sites.
- Do not: change timeouts or retries except where cURL had none (then the
  explicit value of today's cURL default, noted in the pull request).

### Vibe brief

> Implement AU16 as a `refactor(egress)`, one site per commit. For each:
> record the request (method, URL, headers masked, body hash, timeouts) on
> the untouched code, switch, the recording must match. `OpenAICurlTransport`
> streams multipart image uploads — test a large upload. `make ci-local`,
> `make test-e2e` (media specs), baseline regenerated with removals only.

### Exit criteria

1. No `curl_init` and no HTTP `file_get_contents` in `src/`.
2. Recordings unchanged per site; media E2E specs green.

---

## AU17 — SDK clients send through the egress client (backend-only)

### Goal

- Do: the adapters of `03` §3.4 for openai-php, the Ollama SDK, Stripe, the
  Google API client and the Apple App Store library; `HttpClient::create()` in
  `MetaProvider` replaced by injection.
- Do: `OpenAIProviderRequestCharacterizationTest` and the other provider
  snapshots unchanged.
- Do: after this step every streaming provider uses Symfony HttpClient —
  the prerequisite for the stream pump (S5); STATUS names any exception.

### Vibe brief

> Implement AU17 as a `refactor(egress)`, one SDK per commit. Stripe and the
> store verifiers have no provider snapshot: record their requests first with
> a Guzzle mock handler or Stripe's client interface. Then run the dev stack
> with `EGRESS.MODE=enforce` and an administrator list containing only the
> providers you have keys for: chat, search, gateways, subscriptions in test
> mode answer (`make test-e2e`). `make ci-local`.

### Exit criteria

1. `EgressInventoryTest` lists only Triton gRPC, Redis, SMTP and IMAP.
2. Every provider snapshot unchanged.
3. `enforce` run green on the E2E suite (minus the specs whose providers had
   no key, named).

---

## AU18 — An optional egress gateway for every Synaplan service (no-app-impact)

**Ask first:** §0 #10 (Docker / compose).

### Goal

- Do: `sidecars/synaplan-egress` from the compute sidecar's
  `internal/egress` (CONNECT proxy, IPs pinned by PHP's policy): it reads the
  allowlist from Redis (written by PHP from `EgressPolicy`), chains to
  `UPSTREAM_PROXY`, logs per destination, exports metrics.
- Do: an opt-in compose profile `egress`; PHP, worker, scheduler, voice,
  transcriber, compute get `HTTPS_PROXY=http://egress:3128` and `NO_PROXY`
  for the internal names when the profile is on.
- Do: request-scoped grants for URLs a person typed (`03` §3.6): PHP writes
  `egress:grant:{id}` (host, port, pinned IPs, request id, TTL 60 s), sends
  `Proxy-Authorization: Bearer <grant id>`, and the gateway dials only the
  granted IPs for that tunnel, then deletes the grant; when chained to
  `UPSTREAM_PROXY`, it sends `CONNECT <pinned ip>:<port>` upstream.
- Do: the voice and transcriber sidecars honour the proxy with
  `NODE_USE_ENV_PROXY=1`. Verified on 2026-10-21 on their pinned image
  (`node:22-alpine`, Node 22.23.3): with an unreachable `HTTPS_PROXY`, both
  `fetch()` and `https.get()` fail with `ECONNREFUSED` at the proxy, and go
  direct without the flag (STATUS). The provider WebSockets (`ws` 8) open
  through `https.request`; prove one live voice session through the gateway
  before adding an agent package (**dependency — ask first**, only if that
  proof fails).
- Do not: make the gateway required; terminate TLS (CONNECT only).

### Vibe brief

> Implement AU18. Go tests first, reusing the compute proxy's tests. Then the
> profile on the dev stack: chat with two providers, web search, a live voice
> session and a compute run all pass through the gateway (its log shows each
> destination); a destination not on the list is refused with the PHP
> sentence. `make ci-local`, `make test-e2e`, `make test-e2e-full`.

### Exit criteria

1. Every service's outbound traffic visible in the gateway's log on the dev
   stack with the profile on (STATUS list).
2. Grant tests: a custom tool and the URL reader pass with a grant; an
   expired, reused or foreign grant is refused; a rebinding name never
   reaches the private address; no grant id or secret in any log.
3. With the profile off, nothing changes (E2E green).
4. `docs/EGRESS.md` has the enterprise recipe: company proxy, allowlist,
   report week, enforce, and what an enterprise proxy that refuses IP
   literals means for typed-URL features.

---

## S2 exit

- One HTTP stack, one policy, one proxy setting; J-AU-3 ready to walk once
  the Outbound tab exists (AU33).
- The architect's third direction answered: the compute egress idea now
  covers the whole installation, optional and off by default.

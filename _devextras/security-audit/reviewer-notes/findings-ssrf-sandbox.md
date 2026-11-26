# Findings: ssrf-sandbox

Commit a2e57b08dc79. Tested as seeded `demo@synaplan.com` (PRO, non-admin) on the running stack. No extra users were needed.
All test artefacts (URL watches 1-8, widget `audit-ssrf-widget`, one compute run, a temporary redirector in the backend container) were removed.
Paths are relative to `/Users/cristiangrxs/Workspace/metadist/synaplan/`.

Runtime facts that frame everything below:
- `EGRESS.MODE = off` (BCONFIG, seeded default) and no `EGRESS_GATEWAY` / proxy env on backend. `EgressHttpClient::request()` is then a pure pass-through (`backend/src/Service/Egress/EgressHttpClient.php:50-52`): no per-hop check, no pin, `EgressCall::typed()` has no effect. SSRF protection is whatever each call site does itself.
- The egress gateway sidecar is opt-in (`docker-compose.egress.yml`, profile `egress`); it is not running in the default stack, and `docs/EGRESS.md` §4 states it does not block direct egress.
- Qdrant (`qdrant:6333`) and Tika answer unauthenticated on the compose network.

## 1. Entry points enumerated

User/model/visitor-influenced outbound requests:
- `UrlContentService` (`fetch`, `fetchMultiple`, `fetchForReading`/`startReading`, `fetchForCrawling`, `fetchApi`, robots.txt) — used by chat link reading (`LinkedContentReader`), `WebFetchTool`, URL watches, widget crawler (`CrawlWidgetUrlMessageHandler`, `WidgetSetupService`), widget external API (`WidgetController::testApi`, `WidgetApiContext`), `UrlFetchRunner`.
- `AnalyzeImageTool`, `GuardedDocumentDownload`, `InboundEmailAttachmentStore`, `HttpToolExecutor`, `OpenApiImporter`, `OutboundWebhookRunner`, `WebDavClient`, `WordPressIntegrationService`, MCP (`McpClient`, `McpOAuthDiscovery`, `McpOAuthRegistration`, `mcp.oauth_client`), federation (`FederationPeerClient` + `FederationUrlGuard`), platform links (`DeliveryProbe`, `SignedNotificationClient`, `DeliveryOriginPolicy`), `MarketingNewsFeedService` (public `/api/v1/news/image`), inbound-email IMAP (`InboundEmailHandlerService`, `ImapMailboxSearcher`), Slack webhook, plugins (`castingdata`, `synaform`, `synafastbill`, `synascriber`).
- Admin/operator-configured: `OpenAiCompatibleEndpointRegistry`, `AI/Import/*`, `ProviderModelInventory`, `WopiDiscovery`, OIDC discovery, Tika/Docling clients, `ComputeClient`.
- Gateways: `/v1` `image_url` and Anthropic-style URL sources are forwarded to the upstream provider, not fetched by Synaplan (`OpenAiMessagesTranslator.php:613-626`, `GeminiMessagesTranslator.php:393-394`).
- Sandbox: `CodeExecutionTool` → `CodeExecutionInvoker` → `CodeRunRunner` → `ComputeClient` → `sidecars/synaplan-compute`; `/api/v1/compute/workspace*`.
- Egress sidecar: `sidecars/synaplan-egress/internal/proxy/{server,policy}.go`, `internal/store/redis.go`.

---

## 2. Findings

### F1. SsrfGuard bypass with non-canonical IPv4 hosts (hex / Unicode digits) — full-read SSRF to internal services

Severity: **High** · Status: **Confirmed** (reproduced end to end)

Where
- `backend/src/Service/Security/SsrfGuard.php:74-100` (`isBlockedHost`), `:250-268` (`resolveIps`), `:154-163` (`pinnedResolveOption`)
- Reached through `backend/src/Service/UrlWatch/UrlWatchService.php:53,116`, `backend/src/Service/Research/UrlContentService.php:227,349,475,686,847`, `backend/src/Controller/WidgetController.php:1313`, `backend/src/Service/Tool/Custom/OpenApiImporter.php:29-37`

What
`isBlockedHost()` only classifies a host as an IP when `filter_var(FILTER_VALIDATE_IP)` accepts it; anything else is resolved with `gethostbynamel()` and an empty answer means "not blocked" (fail open). `0xac.0x12.0x0.0xb` is not a valid IP for PHP and `gethostbynamel()` returns false for it, but libcurl 7.88 normalises it to `172.18.0.11` and connects. Symfony HttpClient also IDN-normalises the host before curl, so `①⑦②.18.0.11` and `127。0。0。1` become dotted quads after the guard has already passed them. `pinnedResolveOption()` returns `[]` for these hosts, so "pinned" callers are bypassed as well.
Done right elsewhere: `AnalyzeImageTool.php:232-238`, `HttpToolExecutor.php:186-199`, `FederationUrlGuard.php:51`, `OpenAiCompatibleEndpointRegistry.php:427-432` refuse when no public address was resolved (fail closed); `mcp.http_client` (`config/services.yaml:913-915`, `NoPrivateNetworkHttpClient`) blocked both forms in a direct test.

Preconditions: any authenticated user (PRO demo account used). Default configuration.

Reproduction (qdrant is 172.18.0.11, tika 172.18.0.2 here)
```
POST /api/v1/url-watches            {"url":"http://qdrant:6333/"}
 -> {"error":"blocked_url", ...}                                  (guard works for the name)
POST /api/v1/url-watches            {"url":"http://0xac.0x12.0x0.0xb:6333/collections"}  -> created, id N
POST /api/v1/url-watches/N/refresh
 -> body: {"result":{"collections":[{"name":"user_documents"},{"name":"user_memories"},{"name":"user_message_digests"}]},"status":"ok",...}
same with  http://①⑦②.18.0.11:6333/           -> {"title":"qdrant - vector search engine","version":"1.20.2",...}
same with  http://0xac.0x12.0x0.0x2:9998/version -> "Apache Tika 3.3.3"
same with  http://0xa9.0xfe.0xa9.0xfe/latest/meta-data/ -> "Failed to connect to 169.254.169.254 port 80" (request was made)

POST /api/v1/widgets/{own widget}/test-api {"apiUrl":"http://0xac.0x12.0x0.0xb:6333/collections","apiToken":"x"}
 -> {"reachable":true,"responsePreview":"{\"result\":{\"collections\":[...
POST /api/v1/widgets/{own widget}/test-api {"apiUrl":"http://0xac.0x12.0x0.0x14:8080/v1/runs/<id>","apiToken":"<wrong>"}
 -> "HTTP 401"   (attacker-chosen `Authorization: Bearer` reached the compute sidecar)

POST /api/v1/tools/custom/import-openapi/preview {"url":"http://0xac.0x12.0x0.0xb:6333/"}
 -> "Only OpenAPI 3.0 and 3.1 are supported"   (fetched and parsed)
 ... {"url":"http://0xac.0x12.0x0.0x5:6379/"} -> "Empty reply from server for \"http://172.18.0.5:6379/\""
```
Guard-level check inside the backend container: `isBlockedUrl()` returned false for `http://0x7f.1/`, `http://0x7f.0.0.1/`, `http://①②⑦.0.0.1/`, `http://127。0。0。1/`, and a plain `HttpClient` GET to each returned the local `/api/health` body.

Impact
Any signed-in user can issue GET requests (and, via `fetchApi`, GET with a chosen bearer token) to any address reachable from backend/worker and read the response: unauthenticated Qdrant holding every tenant's document, memory and digest vectors (`GET /collections/{c}/points/{id}` is a plain GET — not exercised), Tika, Centrifugo/Collabora admin surfaces, cloud metadata (169.254.169.254) on a cloud host, and the compute sidecar (which holds the Docker socket) if its token is known — the dev compose ships a fixed, documented token. Also reachable by a model through `web_fetch` / chat link reading (prompt-injection driven), not validated live because it needs a provider call.

CVSS 3.1: AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:L/A:N — 8.5. CWE-918 (with CWE-20 / CWE-436 interpretation conflict).

Fix
- In `SsrfGuard::isBlockedHost()`: canonicalise first (IDN/UTS46 to ASCII, strip trailing dot), then refuse any host made only of `[0-9a-fx.]` that is not a canonical dotted quad (or run it through `inet_aton`-style parsing and classify the result), and treat "no A/AAAA answer" as blocked.
- Make `pinnedResolveOption()` throw (or return a sentinel the caller must honour) when nothing can be pinned, instead of returning `[]`.
- Better: one guarded client (wrap `http_client` in `NoPrivateNetworkHttpClient` for every typed URL, as MCP already does) so the check happens on the address actually dialled.
- Tests: `SsrfGuardTest` data provider with `0x7f.1`, `0x7f.0.0.1`, `0177.0.0.1`, `2130706433`, `①②⑦.0.0.1`, `127。0。0。1`, `[::ffff:127.0.0.1]`, unresolvable name; an integration test asserting `UrlWatchService::register()` refuses them.

Detection
Today: nothing distinctive. A successful fetch logs `URL content fetched successfully` at info with the hex host; no warning, no `egress.would_block` (mode off). Proposed: log and count any typed URL whose host is not a canonical name/IP, and alert on backend→qdrant/tika/compute requests whose User-Agent is `SynaplanBot`/`Synaplan/<version>` from the URL-reading paths (Qdrant access log).

Related occurrences (same root cause, guard-only or fail-open pin)
- `UrlContentService::fetch` (:227), `fetchForReading`/`startReading` (:349, :475-495) → `WebFetchTool.php:86`, `LinkedContentReader.php:77`; `fetchForCrawling` (:686) → `UrlWatchService.php:116`, `CrawlWidgetUrlMessageHandler.php:54`, `WidgetSetupService.php:976`, `UrlFetchRunner.php:113`; `fetchApi` (:847) → `WidgetController.php:1313`, `WidgetApiContext.php:121` (runs on widget visitor traffic with the owner's URL).
- `GuardedDocumentDownload.php:50,87`; `InboundEmailAttachmentStore.php:272,290-294` (pin skipped when empty); `OpenApiImporter.php:29`; `OutboundWebhookRunner.php:60` (https only, blind POST); `WebDavClient.php:263` (https only); `WordPressIntegrationService.php:358,376`; `McpServerConfigController.php:347` (save-time only; runtime is covered by NoPrivateNetworkHttpClient).
- Also not blocked by the guard: NAT64 `64:ff9b::/96` literals (only matters on NAT64 networks).

### F2. Redirects are followed without a per-hop check in UrlContentService (fetch / fetchForCrawling / fetchApi / robots.txt)

Severity: **High** · Status: **Confirmed** (static path complete; second hop reproduced live — first hop used F1 because no public host is available offline)

Where
- `backend/src/Service/Research/UrlContentService.php:239-241` (`fetch`, `max_redirects: 3`), `:711-713` (`fetchForCrawling`, 5), `:859-861` (`fetchApi`, 3), `:1166-1168` (robots.txt, 2) — none pins DNS
- `backend/src/Service/Egress/EgressHttpClient.php:50-52` (pass-through in the default mode, so curl follows redirects itself)

What
These four requests validate only the first URL, then let the client follow up to 5 redirects. A public page answering `302 Location: http://qdrant:6333/...` is followed to the internal host and the body is returned. They also do not pin the validated address (DNS rebinding window between `gethostbynamel()` and curl's own lookup).
Done right in the same class: `fetchForReading()` (:342-380) and `startReading()` (:475-495) use `max_redirects: 0`, re-check every hop and pin; `GuardedDocumentDownload.php:44-70` and `InboundEmailAttachmentStore.php:272-312` do the same. In `report`/`enforce` mode `EgressHttpClient::follow()` (:119-142) re-checks hops for typed calls, but that mode is off by default.

Preconditions: any authenticated user; attacker controls any public web server. Default configuration.

Reproduction
Temporary redirector in the backend container on 127.0.0.1:18999 answering `/r` with `302 Location: http://qdrant:6333/collections` (hostname form, which the guard blocks when checked):
```
POST /api/v1/url-watches {"url":"http://0x7f.0.0.1:18999/r"} ; POST /api/v1/url-watches/N/refresh
 -> body: {"result":{"collections":[{"name":"user_documents"},{"name":"user_memories"},{"name":"user_message_digests"}]},...}
POST /api/v1/widgets/{id}/test-api {"apiUrl":"http://0x7f.0.0.1:18999/r"}
 -> {"reachable":true,"responsePreview":"{\"result\":{\"collections\":[...
```
The redirect target `http://qdrant:6333/collections` is refused when submitted directly (`blocked_url`), so the second hop was not checked.

Impact: same as F1, and it survives a fix of F1 — a normal public URL is enough. DNS-rebinding variant: Suspected (needs a rebinding resolver, not possible offline).

CVSS 3.1: AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:L/A:N — 8.5. CWE-918, CWE-601 (redirect), CWE-367 for the rebinding variant.

Fix: route all four through the hop loop already written for `fetchForReading()` (`max_redirects: 0`, `isBlockedUrl()` per hop, `pinnedResolveOption()` per hop, fail closed when no pin). Guard test: a `MockHttpClient` returning 302 to `http://127.0.0.1/` must yield `blocked_address` for `fetch`, `fetchForCrawling`, `fetchApi` and the robots probe; an architecture test forbidding `max_redirects > 0` next to `EgressCall::typed`.

Detection: none today (single info log for the original URL). Proposed: log final URL/hop count for typed fetches and alert when a hop's host is private.

Related occurrences (redirects followed after a first-URL-only check)
- `plugins/synaform/backend/Controller/SynaformController.php:6480-6492` (`fetchUrlText`, `max_redirects: 5`, **no SsrfGuard at all**, body stored and returned; route `POST /api/v1/user/{userId}/plugins/synaform/candidates/{candidateId}/urls`, :2242-2275). Status Suspected: plugin is not enabled for the demo user (`canAccessPlugin` :5432-5443 → 401), so not reproduced; for a user with the plugin enabled this is direct full-read SSRF to named internal hosts.
- `plugins/castingdata/backend/Service/CastingApiClient.php:99-110,149-160`: per-user `api_url`, validated only at save time by its own check (`CastingDataController.php:241-273`), default 20 redirects, no pin; any user may configure it (`canAccessPlugin` :278-285). Blind/boolean.
- `WordPressIntegrationService.php:381-385` (POST, default redirects, guard on first URL only; authenticated).
- `MarketingNewsFeedService.php:92-98` (public endpoint, default redirects; first hop limited to operator-configured feed hosts and the response must be `image/*`).
- `SlackNotificationService.php:296` (first hop pinned by regex to `hooks.slack.com`, redirects on).

### F3. Inbound-email "test connection" opens raw TCP connections to arbitrary internal hosts/ports

Severity: **Medium** · Status: **Confirmed**

Where
- `backend/src/Controller/InboundEmailHandlerController.php:196-262` (`POST /api/v1/inbound-email-handlers/test-connection`)
- `backend/src/Service/Email/InboundEmailHandlerService.php:89-135` (error text returned), `:443-488` (`imap_open('{%s:%d/%s/%s}INBOX')`, host/port unchecked and unescaped); same pattern `backend/src/Service/Email/ImapMailboxSearcher.php:34`

What: mail server host and port come from the request, no SsrfGuard, and the c-client error string is returned to the caller.

Preconditions: any authenticated user.

Reproduction
```
POST /api/v1/inbound-email-handlers/test-connection
{"mailServer":"qdrant","port":9,"protocol":"IMAP","security":"None","username":"a","password":"x"}
 -> 200 {"success":false,"message":"IMAP connection failed: Connection failed to qdrant,9: Connection refused"}  (0.15 s)
{"mailServer":"169.254.169.254","port":80,...} -> "...Connection failed to 169.254.169.254,80: Connection refused"
{"mailServer":"mailhog","port":1025,...}       -> request hangs > 45 s (open port, no IMAP greeting)
```
Impact: internal host/port mapping (closed = immediate "refused", open = hang or protocol error), reachability of metadata addresses, and a cheap way to pin PHP workers for the c-client timeout per request. No response body read. `mailServer` is also interpolated into the mailbox spec, so c-client flags can be injected (not explored).

CVSS 3.1: AV:N/AC:L/PR:L/UI:N/S:C/C:L/I:N/A:L — 6.4. CWE-918.

Fix: validate `mailServer` as a host name/IP (`^[A-Za-z0-9.-]+$` or bracketed IPv6), run it through `SsrfGuard::isBlockedHost()` (with the operator-network switch only behind an explicit env flag), restrict ports to 110/143/993/995 unless an operator allows others, set `imap_timeout()` to a few seconds, and return a fixed sentence instead of `imap_errors()`. Test: controller test asserting `qdrant`, `127.0.0.1`, `169.254.169.254` and `host}/x` are refused before `imap_open`.

Detection: `IMAP connection test failed` is logged at error with the message; nobody would notice a scan. Proposed: rate limit per user and alert on private/non-mail ports.

Related: saved handlers (`POST/PUT /api/v1/inbound-email-handlers`, `/{id}/test`) and the scheduled poller use the same `connectMailbox()`.

### F4. Compute sidecar: host-root-equivalent service behind a fixed default token on the shared network

Severity: **Low** (defence in depth) · Status: **Confirmed** as configuration; no escape found

Where: `docker-compose.yml:1446-1480` (`user: "0:0"`, `/var/run/docker.sock`, `COMPUTE_AUTH_TOKEN` default), `:391-393` (same default in backend/worker/scheduler env); `sidecars/synaplan-compute/internal/auth/bearer.go:36-49`; `docker-compose.egress.yml:21,89` (second fixed default token).

What: the sidecar runs as root with the Docker socket and listens on the compose network for every container; authentication is one static bearer token whose dev default is fixed and documented (`docs/COMPUTE.md` "do not change it"). Its API does not expose raw Docker and every run goes through `Hardened()`/`ValidateHardened()`, so the token yields sandboxed runs plus read access to any owner's workspace/artefacts by id (the sidecar does not check `owner` on `GET /v1/workspaces/{id}/files*` or `/v1/runs/{id}*`; PHP is the only owner check). Combined with F1/F2 `fetchApi` (attacker-chosen bearer header, GET only) this is cross-tenant read on installs that kept the default token.

Fix: refuse to start outside `APP_ENV=dev` with the well-known default; put compute on its own network shared only with backend/worker; require `owner` on workspace/run reads in the sidecar; consider a socket proxy that only allows container create/start/inspect/remove.

CVSS 3.1: AV:A/AC:H/PR:L/UI:N/S:C/C:L/I:L/A:N — 4.6. CWE-1188, CWE-250.

---

## 3. Ruled out

- **AnalyzeImageTool (GHSA-26f3-684h-hpjh)**: https only, guard, `max_redirects: 0`, pinned to a guard-accepted address and refuses when nothing can be pinned, body capped — `backend/src/AI/Messages/Tools/AnalyzeImageTool.php:205-238`. Hex/Unicode hosts fail closed here.
- **Custom HTTP tools**: `HttpToolExecutor.php:47-70,176-199` — guard, own resolution, fail closed, pinned, no redirects.
- **MCP client / OAuth discovery / registration**: `NoPrivateNetworkHttpClient` on the dialled address (`config/services.yaml:913-933`) plus `SsrfGuard` (`McpClient.php:155`, `McpOAuthDiscovery.php:39-83,217,284`); hex and Unicode hosts were blocked in a direct test of that client.
- **Federation peers**: https + guard + non-empty pin required (`FederationUrlGuard.php:43-53`), `max_redirects: 0` (`FederationPeerClient.php:57,79`); `FEDERATION_ALLOW_LOCAL` defaults false (`config/services.yaml:201`).
- **Platform links**: registered-host match, pinned address, no redirects (`DeliveryOriginPolicy.php:48,58-71`, `DeliveryProbe.php:34-47`, `SignedNotificationClient.php:38-57`).
- **Admin OpenAI-compatible endpoints / model import**: admin only; private ranges allowed by design for LAN gateways, link-local/metadata still refused, pinned, no redirects (`OpenAiCompatibleEndpointRegistry.php:203-210,411-438`; `SsrfGuard.php:186-222`).
- **News image proxy** (`/api/v1/news/image`, public): host must equal a configured feed host (`MarketingNewsConfig.php:113-127`), `image/*` only, size capped; returned 404 for `http://qdrant:6333/` live. Redirect following listed under F2 related/hardening.
- **Gateway `image_url` / URL document sources**: passed through to the provider, never fetched server-side (`OpenAiMessagesTranslator.php:613-626`, `GeminiMessagesTranslator.php:393-394`); provider-returned image URLs are https + no redirects (`OpenAiImageGenerationService.php:122-131`).
- **Slack webhook**: URL must match `^https://hooks\.slack\.com/services/…$` (`SlackNotificationService.php:48-56`).
- **ReadDocumentTool**: works on stored files only, no URL input.
- **Non-http schemes**: refused by `SsrfGuard.php:55-58`; decimal, octal, short-form, IPv4-mapped and 6to4 literals, `host.docker.internal`, compose names and `*.nip.io` to loopback were all blocked in the guard test.
- **Sandbox isolation** (probe run through the sidecar): uid/gid 65534, only `lo`, connects to qdrant/compute/1.1.1.1 → "Network is unreachable", no Docker socket, all capability sets zero, `NoNewPrivs: 1`, seccomp filter on, read-only rootfs, `/tmp` noexec tmpfs, env holds no secrets, only `/work` and `/out` writable — `sidecars/synaplan-compute/internal/runner/hostconfig.go:70-160`, enforced again by `ValidateHardened` `:176-240`.
- **Artefact / workspace path handling**: symlinks planted in `/out` (`link.txt → /etc/passwd`, `rootlink → /`) were not listed and returned 404; traversal name 404 — `internal/safepath/safepath.go:55-110` (openat + O_NOFOLLOW per component), `internal/names/names.go:20-50`, `internal/artefact/artefact.go:46-110`; workspace ids are ULID-only (`internal/workspace/store.go:38`), bind sources confined (`hostconfig.go:343-357`).
- **Cross-user workspace access via PHP**: the workspace id is never client-supplied; it is the caller's own row (`ComputeWorkspaceService.php:39-47,209-235`), paths normalised (`:237-255`). Input files for runs are owner-checked (`CodeRunRunner.php:899-901`).
- **Shell injection into the sandbox**: no shell string in the contract; program is an allow-list key and args are `[scriptName]` (`CodeRunRunner.php:340-397`, `pkg/contract/run.go:1-8`, `internal/images/map.go:34-40`); language limited to python/node (`CodeExecutionInvoker.php:73-76`).
- **Sandbox egress**: flag off, sidecar reports `features.egress: false`, containers are `NetworkMode=none`.
- **Egress gateway logic** (static): CONNECT only; IP-literal CONNECT refused without the compute token; grants are single-use (`GETDEL`), must match host and port, and private pins are dropped; allowlisted names are resolved by the gateway and the resolved IP is dialled; built-in internal names resolve to private addresses and are refused — `sidecars/synaplan-egress/internal/proxy/server.go:76-160`, `policy.go:70-84`, `internal/store/redis.go:39-52`. With a proxy/gateway active, typed URLs with unpinnable hosts (F1 forms) are refused (`EgressHttpClient.php:233-242`).

## 4. Hardening ideas

- One guarded HTTP client for every user/model-supplied URL (check the dialled address, per hop), instead of ~20 call sites each re-implementing guard + pin + redirect loop; add an architecture test that `SsrfGuard::isBlockedUrl` may not be used without it.
- Default `max_redirects: 0` in `framework.http_client.default_options`; opt in per fixed-host integration.
- `EGRESS.MODE=enforce` does not stop F1 on its own (typed URLs are allowed when the guard accepts them); the gateway is optional and not network-enforced. Put backend/worker on an internal-only network with the gateway as the only route when it is enabled.
- Give Qdrant an API key and Tika basic auth even on the private network; separate data services from the network the URL-fetching workers sit on.
- Stop returning transport error strings to users (`OpenApiImporter.php:53`, `UrlWatch` failure reason, IMAP errors, `WidgetController::testApi`): they expose resolved internal IPs and port state.
- Egress gateway `Private()` misses 240/4, documentation ranges, NAT64 and 6to4; align it with `FILTER_FLAG_GLOBAL_RANGE`.
- Compute: no gVisor at T1 (shared kernel) — keep `REQUIRE_TIER=gvisor` for any multi-tenant install as the docs already say.

## 5. Coverage gaps

- `web_fetch`, chat link reading, `analyze_image`, `code_execution` through the model loop were traced statically only (they need a provider call); the sandbox was exercised directly against the sidecar with the backend's own token.
- DNS rebinding against unpinned callers and a real public first hop for F2 could not be exercised offline.
- Egress gateway and compute egress proxy (`internal/egress`, `runner/egressnet.go`) were reviewed statically (gateway only); the overlay is not running and compute egress is off.
- Persistent-workspace runs (quota rollback, `workspace_busy`) and the `/api/v1/compute/workspace/push` WebDAV path were not exercised; demo has no workspace.
- Not reviewed in depth: Telegram/WhatsApp media download, Dropbox/M365/Notion clients (fixed hosts), `synascriber`/`synafastbill` plugins, WOPI and OIDC discovery (operator-configured URLs), voice/transcriber sidecars, IMAP flag injection via `mailServer`.

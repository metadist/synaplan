# Out-of-process plugins — steps

Roadmap: [`00_master_plan.md`](./00_master_plan.md) §5. Contracts:
[`02_contracts.md`](./02_contracts.md). Journeys and copy:
[`03_journeys.md`](./03_journeys.md). Review: [`05_review.md`](./05_review.md).

Each step has a **vibe brief**: paste it into the coding agent as the task,
after pointing it at `00_master_plan.md`. The exit criteria are the definition
of done; the agent reports each one with the command it ran. A step starts
only when the §0 rows it names are ticked. Steps are listed in the order of
the step table; ids OP1–OP14 keep their 2026-10-16 meaning.

The `ota-candidate` steps (OP5, OP11, OP12, OP13, OP14) carry a **User-flow**
block and the five exit bullets of [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) §6.

---

## Before OP1 — local setup

```bash
make up                                                                  # dev stack
_devextras/planning/20261016-out-of-process-plugins/spike/run.sh         # OP0: three SDK servers on the Synaplan network
_devextras/planning/20261016-out-of-process-plugins/spike-2/run.sh eras  # current SDK lines, both eras, the stdio bridge
```

`spike/run.sh` starts the Python, Node.js and Go MCP servers and a FastAPI
service on the Synaplan network and prints today's behaviour.
`spike-2/run.sh eras` starts six servers on their own network and shows which
of them today's `McpClient` reaches. Keep them running while you build Phase 1;
they are the real-world counterpart of the OP4 stub. Remove them with
`--down`.

---

# Phase 1 — tools for everyone

## OP15 — Speak MCP 2026-07-28 and fall back to 2025-11-25 (backend-only)

### Goal

- Do: `Service/Mcp/McpEraResolver` — per origin, a `server/discover` probe,
  the table of `02` §10, the era cached for an hour in the app cache, one
  re-probe when a cached modern era answers a non-modern 4xx.
- Do: `McpClient` sends a modern operation as one POST with the headers and
  `_meta` of `02` §10, keeps today's session for the legacy era, retries a
  `-32022` with a listed version, reads `resultType` (`input_required` ⇒
  `McpClientException` with the `03` §5 sentence).
- Do: `MCP.PROTOCOL_MODE` (`auto` | `legacy`, global, code default `auto`) in
  `McpClientConfig`, an admin field in `SystemConfigService` (`channels` →
  `mcp`), a row in `docs/FEATURE_FLAGS.md`.
- Do not: Multi Round-Trip Requests, `subscriptions/listen`, the tasks
  extension; change the network rule (OP2) or the auth (OP3); drop the legacy
  session.

### Files

Reads: `00` §0 #1 #18, `02` §10, `spike-2/results.md`;
`Service/Mcp/McpClient.php`, `McpClientConfig.php`,
`tests/Unit/Service/Mcp/McpClientTest.php`.
Touches: those, `Service/Mcp/McpEraResolver.php` (new),
`Service/Admin/SystemConfigService.php`, `docs/FEATURE_FLAGS.md`, tests.

### Vibe brief

> Read `00_master_plan.md` §0 #1 and `02_contracts.md` §10. Implement OP15.
> Write the era matrix first as MockHttpClient tests, one case per row of the
> §10 table, with the exact status codes and error bodies measured in
> `spike-2/results.md`: Python, Go and TypeScript v2 answer `server/discover`;
> TypeScript v1 answers 400 `-32000`; the bridge answers 404 `-32601`; a
> modern-only server answers `initialize` with 400 `-32022`. Assert the
> per-origin cache, the single re-probe, `Mcp-Method` / `Mcp-Name` and `_meta`
> on every modern request, no `DELETE` in the modern era, and
> `input_required`. With `MCP.PROTOCOL_MODE=legacy` the requests must equal
> today's. Then run `spike-2/run.sh eras` and paste the McpClient part into
> STATUS. `make ci-local`.

### Exit criteria

1. The era matrix is green; the existing `McpClientTest` cases pass unchanged.
2. `spike-2/run.sh eras`: all six servers list and call their tools through
   `McpClient`, the modern-only TypeScript server included. Output in STATUS.
3. `MCP.PROTOCOL_MODE=legacy` reproduces today's request sequence (asserted).
4. `make ci-local` green.

---

## OP16 — A slow or broken MCP server no longer slows every turn (backend-only)

### Goal

- Do: in `McpToolRegistry`, cache a failed discovery for 60 s (a failure
  marker; `refresh()` bypasses it); serve a shared server's catalog that is
  older than 4 minutes and dispatch `RefreshMcpToolCatalog(serverId)` to the
  worker; honour `ttlMs` within 60 s – 1 h (`02` §11).
- Do: `Service/Mcp/McpCircuitBreaker` in the app cache, per server: three
  consecutive failures within 120 s open it for 60 s, then one trial call.
  While it is open, `McpClient` fails at once with a reason code the UI maps
  to `mcpServers.notAnswering` (OP5).
- Do: `max_duration` = `MCP.NODE_TIMEOUT` on every request.
- Do not: change what a person sees in chat (#2377 stays); add a flag — this
  fixes every server.

### Files

Reads: `00` §0 #26, `02` §11; `Service/Mcp/McpToolRegistry.php`,
`McpClient.php`, `config/packages/cache.yaml`, `config/packages/messenger.yaml`.
Touches: those, `Service/Mcp/McpCircuitBreaker.php`,
`Message/RefreshMcpToolCatalog.php` and its handler (new), tests.

### Vibe brief

> Read `02_contracts.md` §11 and `McpToolRegistry`. Implement OP16 as a
> `fix(mcp)` commit. Tests first, with a clock double and a MockHttpClient
> whose server fails: a second `toolsFor()` within 60 s makes no request;
> three failures open the breaker and the fourth call makes none; after 60 s
> one trial request goes out; a stale shared catalog is returned at once and a
> `RefreshMcpToolCatalog` message is dispatched; `max_duration` is set on
> every request. `make ci-local`.

### Exit criteria

1. The four behaviours above are covered by tests.
2. With the OP4 stub answering 503 or hanging, a person's planned turn costs at
   most one failed request per server per minute (asserted; observed once on
   the dev stack and entered in STATUS).
3. `make ci-local` green.

---

## OP1 — Administrators add MCP servers for everyone (backend-only)

### Goal

- Do: `McpClientConfig::isInstanceServersEnabled()` (BCONFIG `MCP` /
  `INSTANCE_SERVERS_ENABLED`, global only, code default off) plus
  `offForUser(int)` / `setOffForUser(int, int, bool)` (`MCP` / `OFF_FOR_ME`).
  Admin field `MCP_INSTANCE_SERVERS_ENABLED` in `SystemConfigService`
  (`channels` → `mcp`), row in `docs/FEATURE_FLAGS.md`.
- Do: `McpServerConfig::INSTANCE_OWNER = 0`, `isInstanceWide()`; repository
  `findVisibleToUser()`, `findUsableForUser()`, `findInstanceWide()` exactly as
  `02` §1.2.
- Do: switch every "use" call site of `02` §1.3 and leave the "own" ones,
  `AtlassianToolArguments` and `AccountRecordsEraser` included.
- Do: `AdminMcpServersController` + `Service/Mcp/InstanceMcpServerService`
  (`02` §1.4 with `auth_mode` `none` | `bearer`; `synaplan`, `share_email` and
  `trust` come with OP3); the accepted tools of `02` §1.5 (`McpToolRegistry`
  offers accepted tools of shared rows; `POST …/accept`); the `AuditLogWriter`
  events of `02` §1.1.
- Do: person API additions (`instance_servers_enabled`, `everyone` with
  `managed_by`, `plugin` and accepted `tools`, `PATCH /api/v1/mcp-servers/{id}/for-me`)
  with full OpenAPI annotations; `make -C frontend generate-schemas`.
- Do not: change the network rule (OP2) — shared rows still pass
  `isBlockedUrl()` in OP1; mint tokens (OP3); touch Vue files; add a column;
  seed the flag on.

### Files

Reads: `00` §0 #3 #4 #18 #24, `02` §1; `Controller/McpServerConfigController.php`,
`Service/Mcp/McpClientConfig.php`, `Repository/McpServerConfigRepository.php`,
every file in `02` §1.3, `Service/Iam/AuditLogWriter.php`,
`Security/ApiKeyScope.php`.
Touches: the above plus `Controller/AdminMcpServersController.php` (new),
`Service/Mcp/InstanceMcpServerService.php` (new),
`Service/Admin/SystemConfigService.php`, `docs/FEATURE_FLAGS.md`,
`frontend/src/generated/api-schemas.ts` (generated), tests under
`backend/tests/Unit/Service/Mcp/`, `backend/tests/Controller/`, and the runner
tests.

### Vibe brief

> Read `_devextras/planning/20261016-out-of-process-plugins/00_master_plan.md`
> §0 and `02_contracts.md` §1. Implement step OP1 from `04_sprints.md`.
> Shared MCP servers are `BMCPSERVERS` rows with `BUSERID = 0`; a person sees
> them when `MCP.INSTANCE_SERVERS_ENABLED` is on and they have not switched
> the server off for themselves. Add the three repository methods, then switch
> only the call sites listed as "use" in §1.3 — keep the "own" ones untouched.
> A shared row offers only accepted tools (§1.5). Write the admin controller
> thin (validation + one service call), `ROLE_ADMIN` only, with full OpenAPI
> annotations, and record every change through `AuditLogWriter`. With the flag
> off, every existing MCP test and `frontend/tests/e2e/tests/mcp-config.spec.ts`
> must pass unchanged. Then `make -C frontend generate-schemas`, `make ci-local`.

### Exit criteria

1. Flag off: all MCP unit tests and `mcp-config.spec.ts` unchanged and green;
   `GET /api/v1/mcp-servers` returns `everyone: []`; the admin routes and
   `…/for-me` answer 404.
2. Flag on: a row created through `POST /api/v1/admin/mcp-servers` appears in
   a person's `GET /api/v1/tools?source=mcp`, in `catalogForUser()`, and is
   resolved by `McpFetchRunner`, `McpActionRunner`, `ToolCallRunner` and both
   gateway loops (unit tests with a mocked client).
3. `PATCH …/for-me {"enabled": false}` removes it for that person only.
4. A tool the server adds after **Test** is not offered until `POST …/accept`.
5. Non-admin session → 403; a restricted API key → 403.
6. Audit rows for created, updated, deleted, tested and tools accepted.
7. `make ci-local` green; generated schemas committed; `vue-tsc` green.

---

## OP2 — Administrator-added servers may use the organisation network (backend-only)

### Goal

- Do: `SsrfGuard::operatorTargetIps(string $host)` (public or organisation
  network, never loopback; `[]` when any answer is refused) — `02` §2.
- Do: `Service/Mcp/McpTargetResolver` applying the `02` §2 table by row scope
  and `MCP_PUBLIC_SERVERS_ONLY`; `McpClient` uses it for every request of
  either era with `resolve` and `max_redirects: 0`; both controllers validate
  with it and answer with the `03` §5 copy.
- Do: `MCP_PUBLIC_SERVERS_ONLY` container parameter (default `false`),
  documented in `backend/.env.example` and `docs/CONFIGURATION.md` next to
  `OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY`.
- Do not: change custom HTTP tools; allow loopback for anything; allow an
  organisation address for a person's own server.

### Files

Reads: `00` §0 #5 #6, `02` §2; `Service/Security/SsrfGuard.php`,
`AI/Credential/OpenAiCompatibleEndpointRegistry.php::resolveUpstream()`,
`Service/Tool/Custom/HttpToolExecutor.php::assertSafe()`,
`Service/Mcp/McpClient.php`, `tests/Unit/Service/Mcp/McpClientTest.php`.
Touches: `SsrfGuard.php`, `Service/Mcp/McpTargetResolver.php` (new),
`McpClient.php`, both MCP controllers, `config/services.yaml`,
`backend/.env.example`, `docs/CONFIGURATION.md`, tests.

### Vibe brief

> Read `00_master_plan.md` §0 #5 #6 and `02_contracts.md` §2. Implement OP2.
> Write the test matrix first: every row of the §2 table × {person,
> administrator, administrator with `MCP_PUBLIC_SERVERS_ONLY=1`}, using literal
> IPs like `McpClientTest` does, plus a name whose answers mix allowed and
> refused addresses (resolver double) and an assertion that MockHttpClient
> receives `resolve => [host => pinned ip]` on every request, in both eras.
> Then add `SsrfGuard::operatorTargetIps()` and `McpTargetResolver`, and make
> `McpClient` connect only to the pinned address. Loopback stays refused for
> every scope: from the backend it is the container itself. `make ci-local`.

### Exit criteria

1. The matrix is green; loopback, link-local and mixed answers are refused for
   every scope.
2. The three spike servers, registered through `POST /api/v1/admin/mcp-servers`,
   answer **Test** with their tools; the same addresses through
   `POST /api/v1/mcp-servers` are refused with the `03` §5 sentence. Output in
   STATUS.
3. With `MCP_PUBLIC_SERVERS_ONLY=1` the administrator's save is refused with
   the kill-switch sentence.
4. Person rows: the existing `McpClientTest` cases pass unchanged except the
   added pinning assertion.
5. `make ci-local` green.

---

## OP3 — Synaplan signs each call to a shared server with a short-lived token (backend-only)

### Goal

- Do: `Service/Mcp/CallKeyStore` (the P-256 key pair of `02` §3.2 in BCONFIG
  `MCP` / `CALL_KEYS`, encrypted, generated on first use race-safe, rotation),
  `Service/Mcp/CallTokenIssuer` (the claims of `02` §3.1 with
  `firebase/php-jwt`), `Service/Mcp/CallerPseudonym` (`02` §3.3, key `MCP` /
  `PSEUDONYM_KEY`), `Controller/SynaplanJwksController`
  (`GET /.well-known/synaplan-jwks.json`, public, cache headers, OpenAPI),
  `app:mcp:rotate-call-key`.
- Do: auth mode `synaplan` on shared rows (refused on a person's row),
  `share_email`, and `trust` in the admin responses (`02` §1.4).
- Do: `McpClient` encodes the JSON once, sends those bytes as `body`, and
  mints one token per HTTP request over exactly those bytes for `synaplan`
  rows; nothing for a person's rows.
- Do: thread the acting person through `callTool(…, ?int $actingUserId)`:
  runners, gateway loops, approval resume (the approval's owner), Saved Tasks
  (the task's owner), `AtlassianToolArguments`; discovery and **Test** as
  `system`.
- Do: log `jti`, server id, tool, duration and outcome per call; never the
  token.
- Do not: send a token to a person's own servers; return the private key or
  the pseudonym key from any endpoint; add a dependency.

### Files

Reads: `00` §0 #7 #8, `02` §1.4 and §3, `spike-2/identity/`;
`Service/Auth/AppleClientSecretGenerator.php`,
`Service/Federation/FederationSigner.php`,
`Controller/FederationWellKnownController.php`,
`Service/Security/EncryptionService.php`, `Service/Mcp/McpClient.php`.
Touches: the new classes above, `McpClient.php`,
`InstanceMcpServerService.php`, `AdminMcpServersController.php`, the callers of
`McpClient::callTool()`, tests, generated schemas.

### Vibe brief

> Read `00_master_plan.md` §0 #7 #8 and `02_contracts.md` §3. Implement OP3.
> Start with a unit test that verifies the published token of §3.5 with its
> public key at the pinned time, recomputes the pseudonym from the test key,
> and refuses the five tampered cases; then mint a token with a fresh key and
> verify it the same way. Make `McpClient` send the exact bytes it hashes.
> Assert that `synaplan` rows carry `Authorization: Bearer` with valid claims
> on every request of a legacy session and on the single modern request, that
> a person's rows carry no Synaplan token, that **Test** signs as `system`, and
> that the key set lists a retired key for 15 minutes. Then verify a token
> minted by the running Synaplan with the four verifiers of
> `spike-2/identity/`. Regenerate schemas, `make ci-local`.

### Exit criteria

1. The vector test is green; a token captured from MockHttpClient verifies
   with `spike-2/identity/verify_token.py` (run once, paste into STATUS).
2. `email` only with `share_email` on; no Synaplan token on a person's rows.
3. After a rotation the old key stays listed for 15 minutes and then goes; a
   token from a key retired longer ago fails verification.
4. No endpoint returns the private key or the pseudonym key (tested on every
   admin response).
5. `make ci-local` green; schemas regenerated.

---

## OP4 — MCP stub server (no-app-impact; §0 #15 ask first)

### Goal

- Do: `frontend/tests/e2e/stub-servers/mcp/mcp-stub-server.ts`, dependency-free
  (Node `http` and WebCrypto, like the Telegram stub): both eras — the legacy
  handshake (`initialize` echoes `2025-11-25`, `notifications/initialized` →
  202) and modern single requests (`server/discover` lists `2026-07-28`);
  `tools/list` → two tools (`stub_word_count` with `readOnlyHint: true`,
  `stub_create_note` with `readOnlyHint: false`); `tools/call` → deterministic
  text. With `STUB_REQUIRE_TOKEN=1` it verifies the call token against
  `SYNAPLAN_JWKS_URL` and answers 401 otherwise. It records method, headers,
  decoded token claims and body; `GET /__requests`, `POST /__reset`,
  `POST /__down?on=1` (503), `POST /__down?hang=1` (no answer, for OP16),
  `POST /__tools` (change the tool list, for J-OP-8).
- Do: service `mcp-stub` in `docker-compose.test.yml` (default) and profile
  `mcp-stub` in `docker-compose.yml`; on the `plugins` network once OP17 has
  landed; helper `frontend/tests/e2e/helpers/mcpStub.ts`; a line in
  `docs/E2E_TESTING.md`.
- Do not: add an npm dependency; use the real SDKs in the stub (they live in
  the spikes).

### Vibe brief

> Read `00_master_plan.md` §0 #15 (must be ticked) and the Telegram stub
> (`frontend/tests/e2e/stub-servers/telegram/telegram-stub-server.ts`) plus its
> compose entries. Implement OP4: a single-file MCP stub with the behaviour in
> `04_sprints.md` OP4, wired like the Telegram stub. Reuse the WebCrypto
> verifier of `spike-2/identity/verify_token.mjs`. Prove it with
> `spike-2/eras/era_probe.mjs` (both eras) and with
> `spike/mcp_client_probe.php` pointed at `http://mcp-stub:<port>/mcp`.

### Exit criteria

1. `docker compose --profile mcp-stub up -d mcp-stub` starts it; the era probe
   sees both eras and the McpClient probe lists both tools.
2. `GET /__requests` returns the recorded headers and claims; `/__down` makes
   the next call fail; `?hang=1` makes it time out; `/__tools` changes the list.
3. `node scripts/mobile-impact.mjs --base main --head HEAD` → no-app-impact.

---

## OP5 — MCP servers for everyone, with a switch per person (ota-candidate)

**User-flow:** J-OP-1, J-OP-2, J-OP-3, J-OP-8 in [`03_journeys.md`](./03_journeys.md);
wireframes `03` §2, copy `03` §4, states `03` §5. Placement per §0 #27.

### Goal

- Do: first split `frontend/src/components/config/McpServersConfiguration.vue`
  (789 lines) into components under 300 lines with no behaviour change (its
  own commit inside the PR).
- Do: Admin → Plugins with the MCP servers tab — **Add server** with the
  sign-in choice and the email switch, **Test**, **Edit**, **Trust** (the
  trust card), **Turn off for everyone**, **Delete**, and the review of
  pending tool changes (J-OP-8) — plus the Admin child entry and its count.
- Do: Apps → MCP "For everyone" for every person, with **Turn off for me** /
  **Turn on for me** and, for administrators, **Manage**; the
  `mcpServers.notAnswering` copy for OP16's reason code.
- Do: read the flag from `GET /api/v1/mcp-servers` (`instance_servers_enabled`),
  never from runtime config; admin controls only for `authStore.isAdmin`.
- Do: confirmations through `useDialog()`, toasts through `useNotification()`,
  house buttons and form controls; five locales.
- Do: `frontend/tests/e2e/tests/mcp-everyone.spec.ts` (`@ci`) on the OP4 stub:
  the admin adds and tests a server and sees the trust card; a second user sees
  the row, turns it off, the tool leaves `GET /api/v1/tools?source=mcp`, turns
  it on again; the stub adds a tool, the count appears, the admin accepts it.
- Do: seed `MCP.INSTANCE_SERVERS_ENABLED = 1` for new installs only
  (`BConfigSeeder::insertIfMissing` in `McpConfigSeeder`); existing installs
  keep their value (AGENTS.md: BCONFIG defaults are bootstrap-only).
- Do not: touch `frontend/src/stores/config.ts` or any `*RuntimeConfig*` file;
  add a rail item; add a second icon for an existing action.

### Vibe brief

> Read `00_master_plan.md` (rules for the AI assistant, §0 #27),
> `03_journeys.md` §1–§5 and AGENTS.md "Buttons", "Form controls", "Icons",
> "i18n". Implement OP5. Commit 1 splits `McpServersConfiguration.vue` without
> changing behaviour (`mcp-config.spec.ts` stays green). Commit 2 adds Admin →
> Plugins and the "For everyone" section exactly as wireframed, with the copy
> from §4 in all five locales and the states from §5; read the flag from the
> servers response, not runtime config. Write `mcp-everyone.spec.ts` on the MCP
> stub. Walk J-OP-1, J-OP-2, J-OP-3 and J-OP-8 in the browser in light and dark
> at 320 px and enter the walks in STATUS. Gates: `make ci-local`,
> `make test-e2e`, `make -C frontend test-e2e-layout`, mobile-impact.

### Exit criteria

1. Named journeys J-OP-1, J-OP-2, J-OP-3 and J-OP-8 walked in the browser
   (U10), entered in STATUS.
2. Later-self findability: a person finds the server under Apps → MCP → For
   everyone, Apps → Connected counts it, and an administrator finds pending
   tool changes from the Admin count, each in ten seconds (U2).
3. Kind-specific consequence copy for "Turn off for me", "Turn off for
   everyone", **Delete** and **Accept** in all five locales (U3).
4. Empty, error and flag-off states of `03` §5 present (U5, U8, U11).
5. Light, dark, V2 and 320 px checked (U9).
6. `mcp-everyone.spec.ts` and `mcp-config.spec.ts` green; locale parity green.

---

## OP6 — Build a Synaplan tool in any language (no-app-impact)

### Goal

- Do: `docs/PLUGINS.md` (new): what a tool is; the language table of `00` §1;
  minimal servers in Python (`mcp` 2.x, `MCPServer`, stateless), TypeScript
  (`@modelcontextprotocol/server` 2.x, `createMcpHandler` in its default
  posture) and Go (`go-sdk` ≥ v1.8, `Stateless: true`), each with the token
  check of `02` §3.4 in front; the allowed-hosts note (`02` §9); honest
  `readOnlyHint` / `destructiveHint`; a compose snippet on the `plugins`
  network (on the Synaplan network, with a note, until OP17 lands); adding the
  server for everyone (J-OP-1); using it in chat with "MCP data sources" on;
  approvals; troubleshooting from `03` §5.
- Do: runnable examples under `docs/examples/mcp-tools/{python,node,go}/`
  (server, Dockerfile, compose snippet) with pinned SDK versions; link from
  `plugins/README.md` and `docs/CONNECTIONS.md`.
- Do not: put examples under `plugins/` (the kernel and `app:plugin:sync` would
  pick them up).

### Vibe brief

> Read `01_research.md` §5, `02_contracts.md` §3 and §9, `spike-2/` and the
> spike servers. Write `docs/PLUGINS.md` and the three examples. Every command
> in the page must run as written on a fresh dev stack; run each one and paste
> the outputs into the PR. Then ask someone who has not read this plan to
> follow the page (J-OP-6, Python) and enter their walk in STATUS.

### Exit criteria

1. Each example starts with its compose snippet, verifies a token and answers
   `tools/call` through Synaplan's **Test**.
2. J-OP-6 walked by a person new to the plan; gaps fixed in the page.

---

# Phase 2 — start together

## OP17 — A plugins network and an operator plugins folder (no-app-impact; §0 #22 ask first)

### Goal

- Do: in `docker-compose.yml`, `deploy/compose.yaml` and
  `docker-compose.test.yml`, the `plugins` network of `02` §12.1: backend
  (alias `synaplan`), worker and scheduler join it; no other service does.
- Do: in `deploy/compose.yaml`, `./plugins:/plugins-local:ro` on backend,
  worker and scheduler, and `PLUGINS_LOCAL_DIR=/plugins-local` in
  `x-app-environment`.
- Do: `.gitignore` gains `docker-compose.override.yml` and
  `deploy/compose.override.yaml`.
- Do: `deploy/README.md` "Start a plugin with Synaplan" (the three places of
  `02` §12.3, and that a plain `docker compose up` skips the compose check);
  one paragraph in `docs/DEVELOPMENT.md`.
- Do not: change any service beyond network membership and the read-only
  mount; publish ports; touch `deploy/quickstart/compose.yaml`.

### Vibe brief

> Read `02_contracts.md` §12.1–§12.3 and `spike-2/compose/semantics.sh`.
> Implement OP17. Prove with `docker compose config --format json` that the
> `plugins` network holds exactly the PHP services, in all three files. Then
> include `spike-2/compose/plugins/word_tools/compose.yaml` from an override
> file, `make up`, and show that the backend reaches `word-tools`, that the
> stand-in reaches `http://synaplan/`, and that `db` does not resolve from it.
> Do the same for `deploy/compose.yaml` (with `deploy/compose.docker-desktop.yaml`
> where bind mounts need it). Gates: `make ci-local`, `make test-e2e`,
> `deploy/scripts/tests/test-lifecycle.sh`.

### Exit criteria

1. `docker compose config` shows the membership above for all three files.
2. The live checks pass in dev and in the self-hosted layout; output in STATUS.
3. `make test-e2e` and the deploy script tests green.
4. `node scripts/mobile-impact.mjs --base main --head HEAD` → no-app-impact.

---

## OP18 — A plugin folder registers its MCP servers for everyone (backend-only; §0 #10 ask first)

### Goal

- Do: `PluginManifest` parses and validates `provides.mcpServers`, `label` and
  `minSynaplanVersion` (`02` §4.2) with errors that name the field.
- Do: `PluginManager` reads both roots (`plugins_dir`, `PLUGINS_LOCAL_DIR`) and
  refuses a folder with `backend/` or `frontend/` in `/plugins-local`.
- Do: migration `ALTER TABLE BMCPSERVERS ADD COLUMN IF NOT EXISTS BSOURCE
  VARCHAR(128) NULL` and `CREATE UNIQUE INDEX IF NOT EXISTS` on it — raw,
  idempotent, no Schema API (Galera rule); the entity field.
- Do: `app:plugin:sync` as `02` §12.4: upsert, disable on disappearance, accept
  by name (`02` §1.5), audit; called after the migrations in
  `_docker/backend/docker-entrypoint.sh` and in the tick lane
  (`ScheduledJobs::LANES` and `run_scheduler_tick_lane()`).
- Do: plugin rows in `findVisibleToUser()` (`02` §1.2); `PATCH` `enabled` as
  the administrator's "turn off for everyone"; the `DELETE` rule of `02` §1.4;
  enabled out-of-process plugins in the runtime-config plugin list
  (`ModulesRuntimeConfig`, backend) for people who have not turned them off.
- Do: `app:plugin:list` shows each plugin's state of `02` §12.4.
- Do: fixture plugin `backend/tests/Fixtures/plugins/declared/fixture_service/`
  (manifest only).
- Do not: load PHP for these plugins; add per-person install; touch Vue (OP5
  already renders `managed_by: plugin`).

### Vibe brief

> Read `00_master_plan.md` §0 #9 #10 #21 #23 (ticked), `02_contracts.md` §1.2,
> §1.5, §4 and §12.4, `docs/MIGRATIONS.md` and AGENTS.md "Production Platform
> Specifics (Galera)". Implement OP18: parse and validate the new manifest keys
> (one test per invalid field asserting the field name in the error), add the
> `BSOURCE` migration as raw idempotent SQL, implement `app:plugin:sync`, wire
> it into the entrypoint and the tick lane, and include plugin rows in
> `findVisibleToUser()`. Use the fixture plugin for the integration test. Then
> walk J-OP-7 on the dev stack with the OP0 Python server as
> `plugins/word_tools/` and enter it in STATUS. `make ci-local`,
> `make -C backend migrate` on a fresh and on an existing dev database,
> `lint:container`, `make test-e2e`.

### Exit criteria

1. The valid fixture parses; each invalid field fails with its name.
2. Sync twice → one row per declared server; folder removed → row disabled,
   id kept; folder back → the same id enabled again.
3. With the flag on, a person sees the plugin row and can turn it off; an
   administrator can turn it off for everyone.
4. Migration idempotent (run twice); `doctrine:schema:validate` green.
5. J-OP-7 walked on the dev stack, entered in STATUS.
6. `lint:container`, `make ci-local` and `make test-e2e` green.

---

## OP19 — Check a plugin's compose file and its service before they run (backend-only; §0 #22 #25)

### Goal

- Do: `app:plugin:compose-check` (`02` §13.1): reads the rendered JSON of each
  included plugin file and Synaplan's service names, applies the rules, prints
  one sentence with the fix per failure, exits non-zero.
- Do: run it from `scripts/compose-up.sh` (before the first
  `docker compose up`) and `deploy/scripts/validate-release.sh`: collect the
  `include:` entries of the override file, render each with
  `docker compose -f <file> config --format json`, and run the command in the
  backend image (`docker run --rm --entrypoint php <image> bin/console …`).
- Do: `app:plugin:check <id|url>` (`02` §13.2).
- Do: the six cases of `spike-2/compose/semantics.sh` as compose-check
  fixtures, plus `privileged`, the Docker socket, `network_mode: host` and a
  bind mount outside the folder.
- Do not: run Docker from PHP; pretend a plain `docker compose up` is covered.

### Vibe brief

> Read `02_contracts.md` §13 and `spike-2/results.md` part 3. Implement OP19.
> Tests first: every refusal rule on a fixture, and an accepted fixture shaped
> like `02` §12.2. Wire the check into `scripts/compose-up.sh` and
> `validate-release.sh` so that a refused plugin file stops the start before
> any container starts. Then run `app:plugin:check` against the OP4 stub with
> and without `STUB_REQUIRE_TOKEN`, and against the six `spike-2` era servers,
> and paste the output into STATUS. `make ci-local`, the deploy script tests.

### Exit criteria

1. Every refusal rule is covered by a test.
2. `make up` with case F of `spike-2` included stops with the sentence before
   any container starts (entered in STATUS).
3. `app:plugin:check`: the stub with the token required passes; without it,
   the token check fails; the six era servers report their era.
4. `make ci-local` and the deploy script tests green.

---

## OP12 — Plugins and shared MCP servers on the system status page (ota-candidate)

**User-flow:** J-OP-7 (Ada's step) and J-OP-3 for shared servers.

### Goal

- Do: `FeatureStatusReporter::build()` (`backend/src/Service/Config/`) gains a
  `plugins` group next to `modules`: each plugin with its state of `02` §12.4,
  its declared servers' last discovery and breaker state, its service health
  (`HttpSidecarHealthProbe` on `provides.service.health`, Phase 3), and the
  administrator-added shared servers; `FeatureStatusView.vue` renders it with
  the module row component.
- Do not: create a `FeatureModule` class per plugin (they are dynamic) or a
  hand-written status block.

### Exit criteria

1. J-OP-7's "Running" and "Not answering" walked (U10).
2. An administrator finds a down plugin from Admin → System status in ten
   seconds (U2).
3. Row copy in five locales (U3); not configured, refused, not answering and
   flag-off states (U5, U8, U11); light, dark, V2 and 320 px (U9).

---

## OP20 — Start a tool together with Synaplan (no-app-impact)

### Goal

- Do: `docs/PLUGINS.md` part 2: the folder (`02` §4.1), the compose file and
  its rules (`02` §12.2), the `include:` line in its three places (§12.3), the
  compose check and its sentences, `app:plugin:check`, troubleshooting.
- Do: examples under `docs/examples/plugins/{python,node,go,stdio-bridge}/`,
  each with `manifest.json`, `compose.yaml`, the server and a `Dockerfile`; the
  stdio-bridge example wraps an existing stdio tool with a pinned bridge and
  the small token-checking proxy of `02` §9.
- Do: link from `deploy/README.md` and `plugins/README.md`.

### Vibe brief

> Read `02_contracts.md` §4, §9, §12 and §13. Write part 2 of
> `docs/PLUGINS.md` and the four examples. Run every command on a fresh dev
> stack and in the self-hosted layout (`deploy/compose.yaml` plus an override
> file). Then ask someone new to the plan to walk J-OP-7, and walk J-OP-6 a
> second time with the stdio-bridge example; enter both in STATUS.

### Exit criteria

1. Each example passes `app:plugin:compose-check` and `app:plugin:check`, and
   its tool answers **Test**.
2. J-OP-6 (stdio bridge) and J-OP-7 walked and entered in STATUS; a tool answer
   in chat needs a cloud model key in the sandbox.

---

# Phase 3 — a plugin with its own service

## OP7 — Plugins declare a service, settings and chat context in the manifest (backend-only)

### Goal

- Do: `PluginManifest` parses and validates `provides.service`,
  `provides.settings`, `provides.context`, `provides.plugs[].transport = "http"`
  and `locales/` (`02` §4.2) with errors that name the field.
- Do: publish `docs/plugins/manifest.schema.json` and
  `docs/plugins/service.openapi.yaml` (§0 #28); a test runs `PluginManifest`
  and the schema over the same valid and invalid fixtures.
- Do: `app:plugin:list` shows the declared service, configured or not.
- Do not: load PHP for manifest-only plugins; send email to plugin services;
  add a secret (services trust the call token).

### Vibe brief

> Read `00_master_plan.md` §0 #9 #28, `02_contracts.md` §4 and §6. Implement
> OP7: one test per invalid field asserting the field name, and the
> schema-versus-validator test over the shared fixtures (extend the OP18
> fixture plugin). `make ci-local`.

### Exit criteria

1. The valid fixture parses; each invalid field fails with its name.
2. Schema and `PluginManifest` agree on every fixture.
3. `make ci-local` green.

---

## OP8 — One checked route from a plugin to its own service (backend-only)

### Goal

- Do: `Controller/Plugin/PluginServiceProxyController` +
  `Service/Plugin/PluginServiceClient` implementing `02` §5 in that order of
  checks; target through `McpTargetResolver`-style rules; the call token with
  `aud` = the service URL and `sub` scoped `plugin:<id>`.
- Do: flag `PLUGINS.SERVICES_ENABLED` (code default off, admin field, row in
  `docs/FEATURE_FLAGS.md`).
- Do not: change `ChatView.vue` — `"endpoint": "/svc/chat"` already works.

### Vibe brief

> Read `02_contracts.md` §2, §3 and §5. Implement OP8. Test every check of §5
> with MockHttpClient: wrong person, plugin switched off (for everyone and for
> the person), no service declared, env unset, refused target, header
> stripping (`Cookie`, `Authorization`, incoming `X-Synaplan-*`), the added
> token and its claims, caps, content-type allow-list, 5xx → 502 with copy,
> and the added `nosniff` / `CSP: sandbox` headers. Extend the MCP stub with
> `/svc/*` echo routes for an E2E of a `/svc/chat` slash command.
> `make ci-local`, `make test-e2e`.

### Exit criteria

1. All §5 checks covered by tests; a person can never reach another person's
   route.
2. The fixture plugin's slash command reaches the stub through `/svc/chat`
   and renders its reply (E2E); the stub verified the token.
3. `make ci-local` and `make test-e2e` green.

---

## OP9 — Web search, extraction, rerank and chat context from a plugin service (backend-only)

### Goal

- Do: `Plug/WebSearch/Adapter/RemoteWebSearchAdapter`,
  `Plug/Extraction/Adapter/RemoteExtractor`, `Plug/Rerank/Adapter/RemoteRerankAdapter`,
  `Service/Plugin/RemoteContextProvider`, each speaking `02` §6 with the call
  token.
- Do: the kernel registers one service per declaration
  (`provides.plugs[]` with `transport: "http"`, `provides.context`) with the
  port's tag; `PlugDeclarationCheckPass` accepts them; restart required.
- Do: `health()` config-only (URL set ⇒ ready); a 5xx or timeout throws so the
  registry falls back; an empty answer is "nothing found".
- Do not: do live I/O in `health()`; inject context longer than 4000
  characters.

### Vibe brief

> Read `plugins/README.md` (contributing a plug adapter), `02_contracts.md` §6
> and `backend/src/Plug/`. Implement OP9. Contract tests on recorded fixtures
> under `backend/tests/Fixtures/plugins/declared/fixture_service/` — no live
> network. Prove fallback: the remote web search answers 503 and the next
> provider answers. Prove the context is wrapped, capped and skipped on
> failure. `docker compose exec -T backend php bin/console lint:container`,
> `make ci-local`.

### Exit criteria

1. Each port's contract test green; fallback test green.
2. Admin → Plugs lists the remote adapters "from plugin fixture_service".
3. Flag off or URL unset ⇒ adapters report unavailable, routing unchanged
   (`RoutingCharacterizationTest` green).
4. `lint:container` and `make ci-local` green.

---

## OP10 — Stop offering plugin slash commands as runnable tools (backend-only)

### Goal

- Do: remove `Service/Tool/Source/PluginCommandToolSource.php`; keep the
  `ToolSource::Plugin` enum case and the OpenAPI enum (no schema drift); parse
  `chatCommands[].tool` as before but ignore it with one log line per plugin.
- Do not: change slash commands themselves.

### Vibe brief

> Read `00_master_plan.md` §0 #13 and `01_research.md` G5. Implement OP10 as a
> `fix(tools)` commit: `GET /api/v1/tools` must list only tools that can run.
> Update the tests that expected plugin tools in the registry; keep the
> composer behaviour (`chat-input.spec.ts` green). `make ci-local`.

### Exit criteria

1. `GET /api/v1/tools` no longer lists `plugin:*` tools; slash commands work.
2. `make -C frontend generate-schemas` produces no diff.
3. `make ci-local` green.

---

## OP11 — Settings pages from a schema, in five languages (ota-candidate)

**User-flow:** J-OP-4 in [`03_journeys.md`](./03_journeys.md); contract `02` §7.

### Goal

- Do: `GET|PUT /api/v1/user/{userId}/plugins/{name}/settings`, optional
  `POST …/settings/test` (forwarded through OP8); storage in `plugin_data`,
  secrets encrypted and write-only; labels from the plugin's `locales/`.
- Do: `PluginView.vue` renders `PluginSettingsForm.vue` when the plugin
  declares `settings` and ships no `frontend/index.js`; house form controls
  and buttons; an empty state when the schema has no fields.
- Do not: render arbitrary HTML from the plugin; add a JSON Schema dependency.

### Vibe brief

> Read `02_contracts.md` §7, `03_journeys.md` J-OP-4 and AGENTS.md "Form
> controls". Implement OP11 with the fixture plugin (add a `settings.schema.json`
> and five locale files to it). The form supports exactly the §7 subset; any
> other schema keyword is a manifest error reported by `app:plugin:sync`. Walk
> J-OP-4 up to "Word tools answered." in light, dark, 320 px; enter it in
> STATUS. Gates: `make ci-local`, `make test-e2e`, `test-e2e-layout`.

### Exit criteria

1. Named journey J-OP-4 walked in the browser (U10).
2. Later-self findability: the plugin is under Apps → Plugins and its
   settings open in one click (U2).
3. Consequence copy for **Save** and **Test** in all five locales; plugin
   labels in the person's language with English fallback (U3).
4. Empty, error (service down) and flag-off states (U5, U8, U11).
5. Light, dark, V2 and 320 px (U9).

---

# Phase 4 — isolated views

## OP-S1 — Spike: MCP Apps host bridge and its sandbox origin (no-app-impact)

Branch `spike/mcp-apps-host`, never merged. Answer each question with yes /
no / partly, the versions tested and a code pointer, as a dated STATUS entry:

0. Can Synaplan serve the sandbox proxy that the stable MCP Apps specification
   (2026-01-26) requires — a **second origin**, `allow-scripts
   allow-same-origin` — in dev, self-hosted (`deploy/compose.yaml`), the
   platform and the mobile app's WebView, and what does each need (host name,
   TLS, `frame-ancestors`, Caddy site)? This decides §0 #14.
1. Which revision of `io.modelcontextprotocol/ui` do the Python (in core) and
   TypeScript (package) SDKs ship — the stable 2026-01-26 one or the draft?
2. Which reference MCP Apps run in the same-origin fallback
   (`ArtifactPanel`-style frame, `sandbox="allow-scripts"`, `srcdoc`, CSP from
   `chatArtifacts.ts`) with only message passing?
3. Which host messages does a minimal app need (initialize, tool input and
   result, size, theme), and can each be served without the session?
4. How do "send a message" and "call a tool" from the view map onto the
   composer and `ToolExecutionGate`?
5. Does it work in the mobile app's WebView (Capacitor) and in Safari?

A "no" on 0 means the fallback frame, documented as a deviation, provided 2
says the apps that matter still work; a "no" on 2 or 5 moves OP13 to its
fallback: show the tool's text result with an **Open in browser** link to a
page the service serves, and record it.

---

## OP13 — Show a tool's own view in the chat, isolated (ota-candidate)

**User-flow:** J-OP-5. Contract `02` §8. Starts after OP-S1 is answered and
§0 #14 is ticked.

Exit: J-OP-5 walked (U10); the view is found on the chat card and in the side
panel (U2); copy in five locales (U3); "could not load" and flag-off states
(U5, U8, U11); light, dark, 320 px, mobile WebView (U9); a test proves the
frame never receives the session.

---

## OP14 — Plugin pages served by the plugin's service, isolated (ota-candidate)

**User-flow:** J-OP-4 with a service-served page instead of the schema form.
Contract `02` §8. Starts after OP-S1, OP8 and OP11.

Exit: the five bullets as in OP13, plus: a plugin that ships `frontend/index.js`
keeps today's behaviour (trusted PHP plugins), and a manifest-only plugin can
only use the isolated frame.

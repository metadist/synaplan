# Out-of-process plugins — tools and plugins in any language, started together with Synaplan

**Status:** Draft 2026-10-16; reviewed and updated 2026-10-18
([`05_review.md`](./05_review.md), evidence in [`spike-2/`](./spike-2/README.md)).
The draft answered *"Could a Python developer add a tool to the web interface,
written in Python, Node.js or Go?"*. The review widened the goal at the
owner's request: *start external tools together with Synaplan and extend the
platform with them, in any language.* Tick §0 before the first product PR.
**Priority:** Row 18 of [`../ROADMAP.md`](../ROADMAP.md), not started. Its
place among the queued rows is the owner's call.
**Goal:** A developer who does not write PHP gives Synaplan's AI a tool, and
later ships a whole plugin, as a service in any language that runs next to
Synaplan — never inside the PHP worker. An operator starts it together with
Synaplan by adding one folder and one line:

1. **Phase 1 — tools for everyone (OP15, OP16, OP1–OP6).** An administrator
   adds an MCP server once for everyone, also on the organisation's own
   network. Synaplan speaks both MCP revisions, every call carries a
   short-lived token that says, signed, who is asking, and a broken server
   never slows anyone else down.
2. **Phase 2 — start together (OP17–OP20, OP12).** A plugin folder holds a
   manifest and a compose file. One `include:` line starts it with Synaplan,
   on a network that reaches Synaplan and nothing else. Its tools appear for
   everyone within a minute, and a check refuses a compose file that could
   touch Synaplan itself.
3. **Phase 3 — a plugin with its own service (OP7–OP11).** The manifest also
   declares a service, settings, chat context and plug ports over HTTP; core
   reaches the service through one checked route.
4. **Phase 4 — the service's own view, isolated (OP-S1, OP13, OP14).** MCP
   Apps and service pages render in a sandbox that never sees the session.

**Why now:** The protocol part is measured. Synaplan's real `McpClient`
listed and called tools on servers built with the official Python,
TypeScript (v1 and v2) and Go SDKs and on a stdio server behind a bridge,
without a change; only a server that refuses the 2025 handshake fails, which
OP15 fixes (§1). What blocks a developer is policy, packaging and starting. The SSRF guard refuses every internal address, MCP connections
exist only per person, a manifest cannot declare a service, and a
self-hosted install has no way to receive a plugin or start it. The archived
open-plugin-platform plan chose in-process PHP plus curation; for other
languages, out of process is both safer and smaller.
**Binding UX:** [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) (U1–U12) —
surfaces in OP5, OP11, OP12, OP13, OP14.
**Class:** OP1–OP3, OP7–OP10, OP15, OP16, OP18, OP19 `backend-only`; OP5,
OP11–OP14 `ota-candidate`; OP0, OP4, OP6, OP17, OP20, OP-S1 `no-app-impact`.
**Prerequisite:** none on `main`. Ask-first rows: §0 #10 (schema), #14
(MCP Apps origin), #15 (E2E stub, Docker / CI), #22 (compose files).

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Start here, decisions, target shape, step table, gates, risks |
| [`01_research.md`](./01_research.md) | The 2026-10-16 write-up: how plugins work today, every extension point, the gaps, the measured evidence, five options compared; §10 adds what changed by 2026-10-18 |
| [`02_contracts.md`](./02_contracts.md) | Data, API and wire contracts: shared servers, network rule, call token (with a test vector), manifest v2, service proxy, HTTP contracts, settings, isolated views, protocol eras, stability, starting together, checks |
| [`03_journeys.md`](./03_journeys.md) | Journeys J-OP-1…8, wireframes, copy, the five questions, empty / error / flag-off states |
| [`04_sprints.md`](./04_sprints.md) | One vibe brief per step: goal, files, brief to paste, exit criteria |
| [`05_review.md`](./05_review.md) | The 2026-10-18 review: what stands, thirteen findings with evidence, the decisions it puts to the owner |
| [`spike/`](./spike/README.md) | The 2026-10-16 spike: Python, Node.js and Go MCP servers, a FastAPI service, the `McpClient` probe |
| [`spike-2/`](./spike-2/README.md) | The 2026-10-18 review spike: protocol eras on current SDKs and a stdio bridge, the call token in four languages, Compose `include:` semantics, a live `plugins` network |
| `STATUS.md` | Step log and measured results |

---

## Start here (60 seconds)

**What we build:** Synaplan already speaks MCP to servers a person connects
under Apps → MCP, and the AI uses their tools in chat (planner `mcp_fetch` /
`mcp_action`), in Saved Tasks, in assistants and on the `/v1` gateways. We let
an **administrator** connect a server **for everyone** and let that server
live **on the organisation's network**. Synaplan **signs every call** with a
token that names the person it runs for. Then we let an **operator** drop a
**plugin folder** next to Synaplan that starts its own service with
`docker compose up` and registers its tools for everyone. Later the folder
can declare a service for pages, settings, search, extraction, rerank and
chat context, still without PHP.

**In any language** means a developer implements three small things:

1. **MCP over Streamable HTTP.** Official SDKs exist in ten languages (Tier 1
   TypeScript, Python, C#, Go, Rust, Ruby; Tier 2 Java; Tier 3 Swift, PHP,
   Kotlin). A program that speaks MCP only on stdin/stdout, in any language,
   runs behind a bridge container (`02` §9).
2. **One token check.** A standard JWT library, or a few dozen lines of the
   standard library (Node.js and Go in `spike-2`), with no secret to copy.
3. **Phase 3 only: plain JSON over HTTP** for the service endpoints, published
   as an OpenAPI 3.1 document, so any language can generate a server stub.

`app:plugin:check` (OP19) turns "this language works" into a test result.

**Start together, in practice** (`02` §12):

```text
plugins/word_tools/                 dev: the repository's plugins/ · self-hosted: ./plugins next to compose.yaml
  manifest.json                     provides.mcpServers → shared rows, registered on start
  compose.yaml                      its services, on the `plugins` network only
  service/                          any language, built here — or an image: reference instead

docker-compose.override.yml         self-hosted: compose.override.yaml
  include:
    - plugins/word_tools/compose.yaml

make up                             self-hosted: docker compose up -d
```

**Where it lives:**

| You want to change… | Go to… |
| ------------------- | ------ |
| The MCP handshake, calls, size cap, SSRF check, protocol eras | `backend/src/Service/Mcp/McpClient.php` (`withSession()`, `runSession()`, `post()`) |
| Server rows and who sees them | `backend/src/Entity/McpServerConfig.php`, `backend/src/Repository/McpServerConfigRepository.php` |
| Cached tool discovery (5 min, failures not cached today) | `backend/src/Service/Mcp/McpToolRegistry.php` |
| MCP flags (`MCP.CLIENT_ENABLED`, `NODE_TIMEOUT`, …) | `backend/src/Service/Mcp/McpClientConfig.php`; admin fields in `backend/src/Service/Admin/SystemConfigService.php` (`channels` → `mcp`) |
| The user API for servers | `backend/src/Controller/McpServerConfigController.php` |
| MCP tools in the tool registry | `backend/src/Service/Tool/Source/McpClientToolSource.php`, `backend/src/Service/Tool/ToolRegistry.php` |
| Chat planner use | `backend/src/Service/Multitask/Execution/Runner/McpFetchRunner.php`, `McpActionRunner.php`; `backend/src/Service/Message/ConnectedSystemsHint.php` |
| Saved Task / planner tool step | `backend/src/Service/Multitask/Execution/Runner/ToolCallRunner.php` |
| Approval rules (read auto, write approve, destructive block, `allowWrite`) | `backend/src/Service/Tool/Policy/ApprovalPolicy.php` (`hardBlock()`), `backend/src/Service/Tool/ToolExecutionGate.php` |
| The SSRF guard and its operator-network mode | `backend/src/Service/Security/SsrfGuard.php` (`pinnedIps($host, allowOperatorNetwork: true)`) |
| The precedent: admin targets on the LAN, kill switch | `backend/src/AI/Credential/OpenAiCompatibleEndpointRegistry.php::resolveUpstream()`, `OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY` |
| Connecting to the checked address | `backend/src/Service/Tool/Custom/HttpToolExecutor.php::assertSafe()` + the `resolve` option |
| Tokens and keys to reuse | `firebase/php-jwt` and `web-token/jwt-library` (`backend/composer.json`); ES256 minting `backend/src/Service/Auth/AppleClientSecretGenerator.php`; key-set validation `backend/src/Service/Auth/JwtValidator.php`; an installation key published `backend/src/Service/Federation/FederationSigner.php`, `backend/src/Controller/FederationWellKnownController.php` |
| Gateway tool names | `backend/src/AI/Messages/Mcp/McpToolCatalogAdapter.php` (`mcp__{serverId}__{tool}`) |
| Audit log | `backend/src/Service/Iam/AuditLogWriter.php` |
| Account erasure (must keep leaving shared rows alone) | `backend/src/Service/Iam/Erasure/AccountRecordsEraser.php` |
| Apps → MCP page | `frontend/src/components/config/McpServersConfiguration.vue` (789 lines), `McpTemplatePicker.vue`, `frontend/src/config/mcpServerTemplates.ts`, `frontend/src/services/api/mcpServersApi.ts` |
| Plugin discovery, manifest, install | `backend/src/Kernel.php::getPlugins()`, `backend/src/Service/Plugin/PluginManifest.php`, `PluginManager.php` (`listAvailablePlugins()`), `PluginAgentInstaller.php`, `backend/src/Command/InstallPluginCommand.php` |
| Compose files and starting | `docker-compose.yml` (`synaplan-network`, `./plugins:/plugins:ro`), `deploy/compose.yaml` (network `internal`, no plugins mount), `docker-compose.test.yml`, `scripts/compose-up.sh` (`make up`), `deploy/scripts/lib.sh` (`compose()`, `SYNAPLAN_COMPOSE_OVERRIDE`), `deploy/scripts/validate-release.sh`, `_docker/backend/docker-entrypoint.sh`, `_docker/backend/Dockerfile` (`COPY plugins/ /plugins/`) |
| Periodic jobs (tick lane every 60 s) | `backend/src/Service/Scheduler/ScheduledJobs.php` and `_docker/backend/lib/container-runtime.sh::run_scheduler_tick_lane()` — a new command goes into both |
| Plugin page and slash commands | `frontend/src/views/PluginView.vue`, `frontend/src/stores/commands.ts`, `frontend/src/views/ChatView.vue::runPluginChatCommand()` |
| Plug ports and their declaration check | `backend/src/Plug/PlugPorts.php`, `backend/src/Plug/*/…Interface.php`, `backend/src/Plug/DependencyInjection/PlugDeclarationCheckPass.php` |
| Module status and health probes | `backend/src/Module/`, `backend/src/Module/Probe/HttpSidecarHealthProbe.php` (401 / 403 count as down), `backend/src/Service/Config/FeatureStatusReporter.php` |
| The isolated-frame precedent | `frontend/src/components/chat/ArtifactPanel.vue`, `frontend/src/utils/chatArtifacts.ts` (CSP) |
| E2E stub servers | `frontend/tests/e2e/stub-servers/*`, `docker-compose.test.yml`, the `telegram-stub` profile in `docker-compose.yml` |
| Tests to extend | `backend/tests/Unit/Service/Mcp/McpClientTest.php`, `McpToolRegistryTest.php`, runner tests under `backend/tests/Unit/Service/Multitask/Execution/Runner/`, `frontend/tests/e2e/tests/mcp-config.spec.ts` |

**The loop that keeps you safe:** default-off flag → the SSRF matrix, the era
matrix and the token vector written as failing tests first → stub E2E → one
installation opts in → seeded default. The network allowance is only ever
widened for what an administrator added or an operator's plugin folder
declared, never for a URL a person typed.

**Rules for the AI assistant driving this:**

- One step = one PR. The commit title comes verbatim from §5: the type decides
  the release version (`feat` raises the minor).
- A claim about how an SDK, a bridge or Docker Compose behaves is proven by
  [`spike/run.sh`](./spike/run.sh), [`spike-2/run.sh`](./spike-2/run.sh) or a
  test, never "should work". Record what you ran in STATUS.
- The SSRF matrix (`02` §2), the era matrix (`02` §10) and the token vector
  (`02` §3) are failing tests before they are code.
- Keys, tokens and auth headers are never logged or returned. Log the token's
  `jti`, the server id, the tool, the duration and the outcome — never the
  arguments.
- Keep the MCP client dual-era. Never drop the 2025-11-25 fallback while a
  measured SDK line or bridge still needs it (`spike-2/results.md`).
- Do not touch `frontend/src/stores/config.ts` or any `*RuntimeConfig*` file
  under `frontend/src`: they are `store-required` in
  `.github/mobile-impact-policy.json`. New data reaches the UI through its own
  endpoint (§0 #17).
- Copy: **plugin** is the one term (Apps labels its section "Plugins" in every
  locale). "MCP" only on its own row (UX contract §4.8); none of the banned
  words of UX contract §8 (sandbox, container, node, provisioning, …) in
  primary copy; five locales in the same PR.
- `McpServersConfiguration.vue` has 789 lines. A step that adds to it splits
  it first (components under 300 lines, AGENTS.md).
- No hostnames, IPs or secrets of real installations — this repository is
  public. Dev defaults only.
- A plugin compose file used in a walk passes the compose check first (§0 #25).
- Schema, Docker / compose / CI and dependency changes are ask-first rows in
  §0. Stop if a step needs one that is not ticked.

---

## 0. Decision checklist

"Changed" and "new" mark the rows of the 2026-10-18 review; `R<n>` points at
its finding in [`05_review.md`](./05_review.md).

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | **MCP is the one protocol for tools written in other languages, and Synaplan speaks both revisions.** Streamable HTTP. `McpClient` asks `server/discover` first (2026-07-28) and falls back to the `initialize` handshake (2025-11-25), caching the era per origin as the specification's compatibility section prescribes (OP15, `02` §10). No second RPC, no gRPC plugin protocol, no runtime inside PHP (WASM, embedded Python). A program that speaks MCP only on stdio runs behind a bridge container next to Synaplan, never inside it. | Proposed (changed, R1) | |
| 2 | **Code in other languages runs out of process only.** Existing PHP plugins keep working unchanged; nothing in this plan removes or narrows the in-process path. From an out-of-process plugin's folder Synaplan reads `manifest.json`, `locales/` and `settings.schema.json` — it never loads or runs other code from it. | Proposed | |
| 3 | **Shared servers are `BMCPSERVERS` rows with `BUSERID = 0`** — the BCONFIG convention for "the installation". Only administrators create, edit, test and delete them; `AuditLogWriter` records who. No new column in Phase 1. Alternative with `BSCOPE` / `BCREATEDBY` columns: **schema change — ask first.** | Proposed | |
| 4 | **Anyone can switch a shared server off for themselves**, whether an administrator added it or a plugin declared it. Stored per person in BCONFIG (`MCP` / `OFF_FOR_ME`, JSON list of ids). There is no "always on" for administrators in v1. | Proposed | |
| 5 | **Network rule by origin.** Shared servers — an administrator's or a plugin's — may reach the organisation network (RFC 1918, ULA, 100.64/10) through the guard's operator mode; **loopback stays refused** (from the backend it is the container itself — Caddy's admin API answers on `127.0.0.1:2019`, `02` §2); link-local (cloud metadata) and every other reserved range stay refused; a name with any refused answer is refused. `MCP_PUBLIC_SERVERS_ONLY=1` removes the allowance, mirroring `OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY`. A person's own servers stay public-only. | Proposed | |
| 6 | **Every MCP request connects to the address that was checked** (HttpClient `resolve`, as `HttpToolExecutor` does), for both scopes. Today `McpClient` checks the name and resolves it again when connecting. Called out in the OP2 PR as hardening. | Proposed | |
| 7 | **A signed call token on every call to a shared server whose sign-in is "Synaplan signs each request"** (auth mode `synaplan`: the only mode for plugin rows, a choice for an administrator's rows). `Authorization: Bearer <JWT>`, ES256, `typ` `synaplan-call+jwt`, `kid` = RFC 7638 thumbprint; claims `iss`, `aud` (the URL Synaplan calls), `sub` (#8), optional `email`, `iat`, `exp` (60 s), `jti`, and the request binding `htm`, `htu`, `bh` (SHA-256 of the body). One token per HTTP request. Keys: an installation P-256 key pair, the private key encrypted in BCONFIG, the public keys at `GET /.well-known/synaplan-jwks.json`. **Nothing secret is copied to a service.** Minted with `firebase/php-jwt`, already a dependency. Test vector and verifiers in four languages: `02` §3. Replaces the HMAC header set of the 2026-10-16 draft. | Proposed (changed, R2 R3) | |
| 8 | **`sub` is a stable pseudonym only Synaplan can compute:** `u_` + 32 hex of HMAC-SHA256(pseudonym key, scope + `"\n"` + `"user:"` + person id). The pseudonym key is an installation secret that never leaves Synaplan and does not rotate with the signing keys. Scope is `mcp:<serverId>` for an administrator's server and `plugin:<id>` for every server and the service of one plugin. `system` for discovery and **Test**. `email` only for an administrator's server with "Share the person's email" ticked. A person's own servers get no token. | Proposed (changed, R2) | |
| 9 | **Manifest v2 keys, additive and enforced, with a published JSON Schema** (#28): `provides.mcpServers[]` (OP18); `provides.service`, `provides.settings`, `provides.context`, `provides.plugs[].transport = "http"` and a `locales/` folder (OP7). A URL is a literal `http(s)://` address — normally the compose service name — or `env:NAME`. Errors name the field (`InvalidPluginManifestException`). A plugin without `backend/` is first-class. The draft's `network:operator` permission is dropped: placing the folder is the operator's act, so plugin rows get the administrator's network rule. | Proposed (changed) | |
| 10 | **Plugin-declared MCP servers are shared rows owned by the plugin**, marked `BSOURCE = plugin:<id>:<key>`. **Schema change (one nullable column and a unique index, raw idempotent `ADD COLUMN IF NOT EXISTS`) — ask first.** Needed by OP18 (moved from OP7). Alternative without a column: a BCONFIG map `PLUGIN_MCP` / `<id>.<key>` → server id. | Ask | |
| 11 | **One checked route to a plugin's service:** `/api/v1/user/{userId}/plugins/{name}/svc/{path}`. Core checks the person and that the plugin is enabled and not turned off by them, strips cookies, `Authorization` and incoming `X-Synaplan-*`, adds the call token (#7, `aud` = the service URL), 15 s timeout, 1 MiB request / 5 MiB response, no redirects, JSON / text / image responses only, no streaming in v1. | Proposed (changed) | |
| 12 | **Plug ports over HTTP:** one generic remote adapter per port (`web_search`, `extraction`, `rerank`) and one remote chat-context provider, registered by the kernel from the manifest at container build (restart required, as for PHP plugins). `health()` stays config-only (`plugins/README.md` rule 4). The reviewer confirms the compile-time registration in `Kernel.php`. | Proposed | |
| 13 | **Plugin slash commands stop appearing as runnable tools** (`PluginCommandToolSource` goes). They are listed today but `ToolCallRunner` refuses them; none of Synaform, synafastbill, Synamail or synaScriber uses `chatCommands[].tool` (checked 2026-10-16). Tools come from `provides.mcpServers`. | Proposed | |
| 14 | **UI tiers.** (a) Settings rendered by core from a schema (Phase 3). (b) MCP Apps and service pages in a sandbox that passes theme and data but never the session (Phase 4). The stable MCP Apps specification (2026-01-26) requires web hosts to load the view through a sandbox proxy on a **second origin** with `allow-scripts allow-same-origin`; serving one in every deployment shape (dev, self-hosted, platform, mobile WebView) is **infrastructure — ask first**. OP-S1 decides between conformance and a documented same-origin `srcdoc` deviation. (c) Today's ES-module pages stay for bundled and operator-trusted PHP plugins. | Ask (changed, R7) | |
| 15 | **E2E MCP stub:** `frontend/tests/e2e/stub-servers/mcp/mcp-stub-server.ts`, dependency-free, answering both protocol eras, verifying the call token with WebCrypto and recording requests; a default service in `docker-compose.test.yml`, an opt-in profile in `docker-compose.yml`. **Docker / CI config — ask first.** | Ask (changed) | |
| 16 | **No new composer or npm dependency in Phases 1–3.** The token uses `firebase/php-jwt` (present). The settings form renders a small JSON Schema subset (string, number, boolean, enum, secret) with house components. | Proposed | |
| 17 | **No change to `frontend/src/stores/config.ts` or `*RuntimeConfig*` frontend files** (store-required). New data comes from its own endpoints. | Locked (mobile policy) | |
| 18 | **Flags in BCONFIG, global only:** `MCP.INSTANCE_SERVERS_ENABLED` (Phases 1–2) and `PLUGINS.SERVICES_ENABLED` (Phase 3), code default off, seeded on for new installs after the stub E2E is green. `MCP.PROTOCOL_MODE` (`auto` \| `legacy`, default `auto`) is the operator's way back if a server misbehaves on modern requests. Flag off ⇒ admin controls absent, shared rows ignored, proxy route 404 (U11). Plugin health goes through the module status path, not a hand-written block (AGENTS.md). | Proposed (changed) | |
| 19 | **Copy rules:** **plugin** is the one term; "MCP" only on its own row; no banned words in primary copy; five locales in the same PR; plugin-supplied strings come from the plugin's `locales/{de,en,es,fr,tr}.json`, English as fallback. | Locked (UX contract) | |
| 20 | **Distribution is not in this plan.** In v1 a plugin ships as a folder whose compose file references a published image (or builds its own). Registry, signed images and in-app install are the owner's call after Phase 3; they replace the tarball design of open-plugin-platform S3, because nothing third-party runs inside PHP any more. | Proposed (changed) | |
| 21 | **Out-of-process plugins are installation-wide.** No per-person install: present, configured and enabled ⇒ everyone has it; anyone can turn it off for themselves (#4); an administrator can switch it off for everyone. PHP plugins keep per-person install, because they run per-person SQL and symlinks. | Proposed (new, R10) | |
| 22 | **Started together through Compose `include:`.** Every Synaplan compose file (`docker-compose.yml`, `deploy/compose.yaml`, `docker-compose.test.yml`) defines a `plugins` network. The PHP services join it, the backend under the alias `synaplan`; the database, Redis, Qdrant and every other service do not. A plugin's `compose.yaml` names only `plugins`. The operator adds one `include:` line to `docker-compose.override.yml` (dev; then gitignored) or `compose.override.yaml` (self-hosted; scripted starts pass it as `SYNAPLAN_COMPOSE_OVERRIDE`). Self-hosted installs mount `./plugins` read-only at `/plugins-local`, for out-of-process plugins only: a folder with `backend/` or `frontend/` is refused there. **Docker / compose — ask first.** | Ask (new, R4) | |
| 23 | **Registered on start and every minute.** `app:plugin:sync` runs after the migrations when the backend starts and every minute in the scheduler's tick lane (`ScheduledJobs::LANES` and `_docker/backend/lib/container-runtime.sh`, part of the #22 ask). Declared servers become shared rows (upsert by `BSOURCE`). A folder that disappears disables its rows and never deletes them, so ids stay valid for assistants and Saved Tasks. Every change is an audit event. | Proposed (new) | |
| 24 | **Shared servers offer accepted tools only** (`02` §1.5). An administrator's row accepts by hash of name, description and input schema at **Test** or save; a new or changed tool is held back until an administrator accepts it, and Admin shows how many wait. A plugin's row accepts by name — its manifest's `tools` list, or the names listed at first registration — because the operator ships folder and image together. | Proposed (new, R8) | |
| 25 | **Two checks that Synaplan runs.** (a) The **compose check** — `app:plugin:compose-check`, fed each included plugin file as rendered by `docker compose -f <file> config --format json` and run in the Synaplan image, so the host needs only Docker. It runs before every `make up` and in `deploy/scripts/validate-release.sh`, and refuses a plugin file that reuses a Synaplan service name, joins a network other than `plugins`, or sets `privileged`, `cap_add`, `devices`, host `network_mode` / `pid` / `ipc`, the Docker socket or a bind mount outside its folder. A plain `docker compose up` skips it; the docs say so. (b) The **conformance check** — `app:plugin:check <id\|url>` reports pass, warn or fail for the era, the tools and their annotations, token enforcement, health and the network rule. "This language works" means "passes (b)". | Proposed (new, R6 R9) | |
| 26 | **A shared server never slows anyone else** (OP16). A failed discovery is cached for 60 s; a per-server circuit breaker is shared by all workers; `max_duration` equals `MCP.NODE_TIMEOUT`; a shared server's catalog older than 4 minutes is served and refreshed in the background by the worker (stale-while-revalidate), so no person's turn waits for `tools/list`; a server's `ttlMs` hint is honoured within 60 s – 1 h. | Proposed (new, R5) | |
| 27 | **Administrators manage shared servers and plugins in Admin → Plugins** (UX contract §2.3, §4.5): tabs for plugins and MCP servers, health pills, **Test**, accepted tools. Apps → MCP shows everyone the "For everyone" rows with **Turn off for me**, and administrators a **Manage** link to Admin. Alternative (the draft): the administrator's controls inline on Apps → MCP. | Owner's call (new, R11) | |
| 28 | **Machine-readable contracts:** `docs/plugins/manifest.schema.json` (JSON Schema 2020-12) and `docs/plugins/service.openapi.yaml` (OpenAPI 3.1, the Phase 3 endpoints). `PluginManifest` and the schema are tested against the same fixtures. | Proposed (new, R9) | |

---

## 1. Why — facts this plan rests on

Full write-up with file references in [`01_research.md`](./01_research.md);
the review's evidence in [`05_review.md`](./05_review.md).

1. **Plugins are PHP in the worker.** `Kernel::getPlugins()` loads
   `plugins/*/manifest.json`, autowires `backend/` and imports its routes.
   Routes, plug adapters and chat context exist only as PHP classes. Plugin
   pages are ES modules that `PluginView.vue` imports into the app, where they
   act with the signed-in person's session.
2. **The protocol works today with every server that still accepts the 2025
   handshake.** Synaplan's real `McpClient` listed and called tools on servers
   built with the official SDKs — Python
   `mcp` 2.3.0, TypeScript `@modelcontextprotocol/sdk` 1.32.1 and
   `@modelcontextprotocol/server` 2.3.1, Go `go-sdk` v1.8.0 — and on the
   official stdio reference server behind `supergateway` 4.2.0. Input schemas
   and annotations arrived intact, so the approval rules apply as they are
   ([`STATUS.md`](./STATUS.md), 2026-10-16 and 2026-10-18).
3. **MCP moved on.** Revision **2026-07-28** removed the `initialize`
   handshake and sessions, added `server/discover`, required `Mcp-Method` /
   `Mcp-Name` headers and cache hints on `tools/list`, and set a twelve-month
   deprecation window. A TypeScript v2 server in its modern-only posture
   already refuses Synaplan (`-32022`), while the TypeScript v1 server and the
   bridge refuse modern requests. Synaplan needs both eras (OP15).
4. **Policy blocks it.** Registering servers on the Docker network through the
   API: 3 × HTTP 400 "This server URL is not allowed (must be a public
   http(s) endpoint)". A custom HTTP tool to a FastAPI service saves and lists
   but every call fails ("Only https URLs are allowed"); OpenAPI import of
   that service is refused.
5. **MCP connections are per person** (`BMCPSERVERS.BUSERID`); templates are
   hardcoded in `frontend/src/config/mcpServerTemplates.ts`; a plugin cannot
   declare a server.
6. **Plugin tools are a dead end today.** `PluginCommandToolSource` lists
   slash commands that opt in with `tool.sideEffect`; `ToolCallRunner` answers
   "This tool cannot run as a Saved Task step".
7. **Nothing starts together yet.** A self-hosted install has no plugins
   mount (plugins are baked into the image). An included compose file lands on
   `<project>_default`, out of the backend's reach. A same-named service in an
   included file is merged into Synaplan's own service without an error,
   `privileged: true` and a mount of the host's root filesystem included
   (`spike-2` part 3).
8. **The building blocks exist:** the guard's operator-network mode (used by
   OpenAI-compatible endpoints), ES256 minting and key-set validation, a
   published installation key (federation), `AuditLogWriter`,
   `HttpSidecarHealthProbe`, the isolated artifact frame, the
   `docker-compose.override.yml` pattern the compose header already documents,
   and `validate-release.sh`, which runs on every scripted self-hosted start.

**Reach per language** (what a developer can use, and how it is proven):

| Path | Languages | Proof |
| ---- | --------- | ----- |
| Official MCP SDK, Tier 1 | TypeScript, Python, C#, Go, Rust, Ruby | Python, TypeScript v1 + v2, Go measured; the rest by `app:plugin:check` |
| Official MCP SDK, Tier 2 / 3 | Java; Swift, PHP, Kotlin | `app:plugin:check` (OP19) |
| Any program that speaks MCP on stdio | any | measured behind `supergateway` 4.2.0 (`spike-2`) |
| The call token check | any with an ES256 JWT library: PyJWT, `jose`, `golang-jwt`, Microsoft.IdentityModel, Nimbus, `ruby-jwt`, `jsonwebtoken`, `firebase/php-jwt` | PHP, Python, Node.js (WebCrypto, no package) and Go (standard library) measured |
| Phase 3 service endpoints | any HTTP server | OpenAPI 3.1 (#28) |

---

## 2. Target shape

```text
Developer (any language)
  MCP server: an official SDK, or a stdio tool behind a bridge
      speaks Streamable HTTP 2026-07-28; Synaplan falls back to 2025-11-25
  optional HTTP service (Phase 3)
  both check  Authorization: Bearer <call token, ES256, 60 s>
      against GET http://synaplan/.well-known/synaplan-jwks.json

Operator (Phase 2)
  plugins/<id>/manifest.json + compose.yaml
  override file → include: plugins/<id>/compose.yaml
  compose check before make up / validate-release

Docker networks
  plugins ............ backend (alias synaplan) · worker · scheduler · plugin services
  synaplan-network ... PHP services · db · redis · qdrant · tika · …   (no plugin; self-hosted: internal)

Synaplan backend
  app:plugin:sync (start + every minute) → BMCPSERVERS  BUSERID = 0, BSOURCE = plugin:<id>:<key>
  BMCPSERVERS  BUSERID = 0  → everyone   (an administrator's or a plugin's)
               BUSERID > 0  → one person
  McpClient ── eras · network rule by origin · pinned connect · call token
            └─ circuit breaker · negative cache (OP16)
  McpToolRegistry (accepted tools) → McpClientToolSource → ToolRegistry
     ├─ chat planner  mcp_fetch / mcp_action  (topic switch tool_mcp)
     ├─ Saved Tasks   tool_call
     ├─ assistants    tools.mcpServers
     └─ /v1 gateways  native tool calling (mcp__{id}__{tool})
  ToolExecutionGate · ApprovalPolicy  (unchanged: read auto · write approve · destructive block)

Phase 3 — plugins/<id>/ also declares
    provides.service     → /api/v1/user/{u}/plugins/<id>/svc/*   (checked, token)
    provides.plugs[]     → Remote{WebSearch,Extraction,Rerank} adapter per declaration
    provides.context     → remote chat-context provider
    provides.settings    → settings page rendered by Synaplan
  locales/{de,en,es,fr,tr}.json

Phase 4 — isolated views
  MCP Apps ui:// resources and service pages → sandbox proxy (second origin, #14), strict CSP,
  message bridge: theme + data in, actions out — never the session
```

---

## 3. Journeys

Details, wireframes and copy in [`03_journeys.md`](./03_journeys.md).

| Journey | Who | Walked in |
| ------- | --- | --------- |
| **J-OP-1** — An administrator offers an internal tool to everyone | Ada (admin) | OP5 |
| **J-OP-2** — A person uses it in chat and turns it off for themselves | Ben | OP5 |
| **J-OP-3** — The service is down, or refuses Synaplan | Ada, Ben | OP5, OP16 |
| **J-OP-4** — A plugin that is a manifest plus a service | operator, Ben | OP11 |
| **J-OP-5** — A tool shows its own view in the chat | Ben | OP13 |
| **J-OP-6** — A developer, in any language, from zero to a tool in chat, following the docs only | Dev | OP6, OP20 |
| **J-OP-7** — An operator starts a tool together with Synaplan | Olga (operator), Ben | OP18, OP20 |
| **J-OP-8** — A shared server changes its tools | Ada | OP5 |

---

## 4. What we do not rebuild

| Already here | Use it |
| ------------ | ------ |
| MCP client, SSE-framed answers, size cap, OAuth refresh | `McpClient` (sessions stay for the 2025 era) |
| Tool discovery with a 5-minute cache | `McpToolRegistry` |
| One registry for every tool source, unique names | `ToolRegistry`, `ToolSourceInterface` |
| Approval policy incl. MCP `allowWrite` hard block | `ApprovalPolicy`, `ToolExecutionGate`, Approvals inbox |
| Planner nodes for MCP read and write, Saved Task tool step | `McpFetchRunner`, `McpActionRunner`, `ToolCallRunner` |
| SSRF guard with operator mode and pinning | `SsrfGuard::pinnedIps()`, `isOperatorNetworkIp()` |
| JWT minting and key-set validation | `firebase/php-jwt` (`AppleClientSecretGenerator`), `web-token` (`JwtValidator`) |
| An installation key behind a public well-known URL | `FederationSigner`, `/.well-known/synaplan-federation` |
| Secrets encrypted at rest | `EncryptionService`, `PlugKeyStore` |
| Audit trail for admin actions | `AuditLogWriter` |
| Health probe with memo and 2 s timeout | `HttpSidecarHealthProbe` |
| Manifest validation that names the field | `PluginManifest`, `InvalidPluginManifestException` |
| Per-person plugin install, assistant packs | `PluginManager`, `PluginAgentInstaller`, `app:plugin:install*` (PHP plugins) |
| Optional services started with Synaplan | compose profiles and the `docker-compose.override.yml` pattern in the `docker-compose.yml` header |
| A check on every scripted self-hosted start | `deploy/scripts/validate-release.sh` |
| Isolated HTML in the app | `ArtifactPanel.vue` (`sandbox="allow-scripts"`, CSP `default-src 'none'`) |
| Apps directory with a Plugins section | `AppsView.vue` (section id `extensions`, label "Plugins"), `frontend/src/apps/catalog.ts` |

---

## 5. Step table

Step ids are stable: OP1–OP14 keep the meaning they had on 2026-10-16, new
steps are OP15–OP20, and the order is the table's.

| Step | Commit title | Class | Depends |
| ---- | ------------ | ----- | ------- |
| OP0 | `docs(planning): out-of-process plugins — research and plan` | no-app-impact | — |
| **Phase 1 — tools for everyone** | | | |
| OP15 | `feat(mcp): speak MCP 2026-07-28 and fall back to 2025-11-25` | backend-only | OP0, §0 #1 #18 (can land first) |
| OP16 | `fix(mcp): a slow or broken MCP server no longer slows every turn` | backend-only | OP0, §0 #26 (can land first) |
| OP1 | `feat(mcp): administrators add MCP servers for everyone` | backend-only | OP0, §0 #3 #4 #18 #24 |
| OP2 | `feat(mcp): administrator-added MCP servers may use the organisation network` | backend-only | OP1, §0 #5 #6 |
| OP3 | `feat(mcp): Synaplan signs each call to a shared server with a short-lived token` | backend-only | OP1, §0 #7 #8 |
| OP4 | `test(e2e): MCP stub server for tool journeys` | no-app-impact | OP0, §0 #15 |
| OP5 | `feat(apps): MCP servers for everyone, with a switch per person` | ota-candidate | OP1–OP4, OP16, §0 #27 |
| OP6 | `docs(plugins): build a Synaplan tool in any language` | no-app-impact | OP2, OP3, OP5, OP15 |
| **Phase 2 — start together** | | | |
| OP17 | `chore(compose): a plugins network and an operator plugins folder` | no-app-impact | OP0, §0 #22 |
| OP18 | `feat(plugins): a plugin folder registers its MCP servers for everyone` | backend-only | OP1–OP3, OP17, §0 #9 #10 #21 #23 |
| OP19 | `feat(plugins): check a plugin's compose file and its service before they run` | backend-only | OP18, §0 #25 |
| OP12 | `feat(admin): plugins and shared MCP servers on the system status page` | ota-candidate | OP18 |
| OP20 | `docs(plugins): start a tool together with Synaplan` | no-app-impact | OP18, OP19 |
| **Phase 3 — a plugin with its own service** | | | |
| OP7 | `feat(plugins): plugins declare a service, settings and chat context in the manifest` | backend-only | OP18, §0 #9 #28 |
| OP8 | `feat(plugins): one checked route from a plugin to its own service` | backend-only | OP7, §0 #11 |
| OP9 | `feat(plugs): web search, extraction, rerank and chat context from a plugin service` | backend-only | OP7, OP8, §0 #12 |
| OP10 | `fix(tools): stop offering plugin slash commands as runnable tools` | backend-only | OP0, §0 #13 (can land first) |
| OP11 | `feat(plugins): settings pages from a schema, in five languages` | ota-candidate | OP7, OP8 |
| **Phase 4 — isolated views** | | | |
| OP-S1 | spike: MCP Apps host bridge and its sandbox origin (STATUS entry, branch never merged) | no-app-impact | OP5, §0 #14 |
| OP13 | `feat(chat): show a tool's own view in the chat, isolated` | ota-candidate | OP-S1 |
| OP14 | `feat(plugins): plugin pages served by the plugin's service, isolated` | ota-candidate | OP-S1, OP8, OP11 |

Phase 1 is useful on its own: after OP6 a developer in any language ships a
tool for everyone without a plugin folder. OP10, OP15, OP16 and OP17 can land
at any time; OP18 and the rest of Phase 2 wait until J-OP-1…J-OP-3 are walked,
and Phase 3 waits for J-OP-7.

---

## 6. Gates

Per commit:

```bash
make ci-local            # lint, phpstan (src + tests), deptrac, PHPUnit, Vitest, vue-tsc
```

Per PR, in addition:

```bash
make test-e2e                                              # OP1, OP3, OP5, OP8, OP11–OP14, OP18 (HTTP contracts the UI consumes)
make -C frontend test-e2e-layout                           # OP5, OP11–OP14
make -C frontend generate-schemas && docker compose exec -T frontend npm run check:types   # after OpenAPI changes
docker compose exec -T backend php bin/console lint:container                             # OP7, OP9, OP18
node scripts/mobile-impact.mjs --base main --head HEAD     # class as in §5
_devextras/planning/20261016-out-of-process-plugins/spike-2/run.sh eras                   # OP15: all six servers answer through McpClient
docker compose exec -T backend php bin/console app:plugin:check http://mcp-stub:<port>/mcp  # OP19 onward: green for the stub and the OP20 examples
```

- The SSRF matrix (`02` §2), the token vector (`02` §3), the era matrix
  (`02` §10) and the compose-check cases (`02` §13) are unit tests, green
  before the PR.
- Flags off ⇒ `RoutingCharacterizationTest` and every MCP unit test unchanged.
- After OP2 and OP3, `spike/run.sh` runs against the admin API instead of the
  probe and its output goes into STATUS; after OP3, `spike-2/run.sh identity`
  also verifies a token minted by the running Synaplan.
- Locale parity (`frontend/tests/unit/i18n/localeParity.spec.ts`) green; no
  ledger additions.

### Track exit

- J-OP-1…J-OP-4, J-OP-6, J-OP-7 and J-OP-8 walked and entered in STATUS;
  J-OP-5 after Phase 4.
- A person who has not read this plan follows `docs/PLUGINS.md` from an empty
  dev stack to a tool answer in chat (J-OP-6), and an operator who has not read
  it starts a plugin folder with Synaplan (J-OP-7).
- `GET /api/v1/tools` lists only tools that can run (OP10).
- Plan archived with a dated note when the last step ships.

---

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| Widening the SSRF guard opens internal targets | Only for rows an administrator added or a plugin folder declared; pinned connect; loopback, link-local and reserved ranges always refused; kill switch env; matrix tests; audit log |
| Loopback reaches the backend container's own admin endpoints | Refused for MCP and plugin services (`02` §2). The same question for OpenAI-compatible endpoints, which accept loopback today, goes to that registry's owner (STATUS 2026-10-16) |
| A tool result carries a prompt injection, or a server changes its tool descriptions later | Results are untrusted text as today; write tools need approval, destructive tools stay blocked, `allowWrite` per server; new or changed tools wait for an administrator (#24); chat context from a service is capped and labelled |
| A service learns more about people than it needs | A pseudonym only Synaplan can compute (#8); email only when an administrator ticks it; a person's own servers get nothing |
| A captured call token is replayed | 60 s lifetime; bound to audience, method, URL and body; `jti` for services that de-duplicate writes; TLS recommended for services outside the Docker host |
| The signing key leaks | Private key encrypted at rest (`EncryptionService`); a rotation command publishes a new key and keeps the old one listed for 15 minutes; services that read the key set follow on their own |
| MCP keeps moving | Dual-era client (#1); the specification's twelve-month deprecation window; `spike-2/run.sh eras` re-run on every SDK major |
| A plugin's compose file rewrites Synaplan or reaches its data services | The `plugins` network (#22); the compose check before every scripted start (#25); a plain `docker compose up` skips the check — documented next to the `include:` line |
| Plugins share one network, so one plugin can reach another's service | Every plugin service checks the call token, which no plugin can mint; `app:plugin:check` fails a server that accepts an unsigned call; a bridged stdio tool ships the small verifying proxy (`02` §9) |
| A shared server is slow or down | OP16; one sentence with a recovery (U8); the status page row (OP12) |
| Three web nodes in production | Keys, rows and accepted tools live in the shared database; `app:plugin:sync` is idempotent (unique `BSOURCE`); a plugin runs on every node or on one host all nodes reach; ops notes in `synaplan-platform` (private) |
| Latency on the planner path | The modern era is one request per call; `MCP.NODE_TIMEOUT` (default 15 s, clamped 3–120) as total time; discovery off the request path for shared servers; `ConnectedSystemsHint` reads the cache only |
| A bridge is third-party code in every request | Pinned version; a native SDK server is the recommended way; the bridge only for existing stdio tools |
| MCP Apps need a second origin | OP-S1 decides before OP13 (#14) |
| Existing plugins break | Manifest keys are additive; the PHP path is unchanged; OP10 checked against the four external plugins |
| Routing snapshots move | Flags off ⇒ byte-identical; flag-on cases are new characterization cases |
| App release | No store-required file is touched (§0 #17); UI steps are `ota-candidate` |

---

## 8. Non-goals

- Running Python, Node.js, Go or WASM inside the PHP worker.
- Replacing PHP plugins or moving first-party features into services.
- Giving Synaplan the Docker socket to start, stop or discover plugins — no
  label-based discovery, no button that starts containers. Starting is the
  operator's `docker compose up`.
- A Synaplan SDK per language: the contract is MCP, one token check and, in
  Phase 3, an OpenAPI document. Examples, not SDKs.
- Opening the network allowance to URLs a person types (their own MCP servers
  and custom HTTP tools stay public-only).
- OAuth sign-in per person for shared servers (v1: none, a header with a key,
  or Synaplan's call token).
- A plugin registry, in-app download, or paid marketplace (§0 #20).
- MCP resources and prompts beyond tools, except the `ui://` resources Phase 4
  needs.
- Multi Round-Trip Requests in v1: a result of type `input_required` ends the
  call with one sentence (OP15, §9 #8).

---

## 9. Later (in this order)

| # | What | Why it waits |
| - | ---- | ------------ |
| 1 | Custom HTTP tools that an administrator shares on the organisation network | Same rule by origin as #5, but custom tools have their own share model (UX contract §4.1, roadmap row 12) |
| 2 | Person-held OAuth for shared servers | Needs a token per person on a shared row; the OAuth store is per row today |
| 3 | Script tools in the compute sidecar (a stored Python / Node function + schema, run offline) | Option D in `01` §6; useful for pure functions, depends on Secure Compute B4 |
| 4 | Distribution: registry, signed images, in-app install | Owner's call after Phase 3 (§0 #20) |
| 5 | Channels as plugin services (`ChannelTransport` over HTTP) | Open-plugin-platform §3.2 shape, now out of process |
| 6 | MCP resources as knowledge sources | Separate RAG decision |
| 7 | A scoped way back into Synaplan (`01` G6): the same keys mint a token whose audience is Synaplan, scoped to permissions the manifest declares, valid for one call | Needs a permission vocabulary and its consent copy first |
| 8 | Multi Round-Trip Requests: a tool asks the person a question through the chat's approval-card pattern | Depends on OP15 and the approval inbox; elicitation copy |
| 9 | Examples in more languages (C#, Java, Rust, Ruby, PHP), each green in `app:plugin:check`, run nightly | After OP19 and OP20 |
| 10 | Audience by group instead of everyone (IAM groups) | Needs the UX contract §4.1 share model for servers |
| 11 | `subscriptions/listen` instead of polling for tool-list changes | 2026-07-28 feature; the 4-minute background refresh is enough for v1 |

---

## 10. Change log

| Date | Change |
| ---- | ------ |
| 2026-10-16 | Draft: research, contracts, journeys, step briefs, spike (OP0). |
| 2026-10-18 | Review ([`05_review.md`](./05_review.md), [`spike-2/`](./spike-2/README.md)). Goal widened to any language and to starting together. §0 #1, #7, #8, #9, #11, #14, #15, #16, #18, #19, #20 changed; #21–#28 added. The HMAC header set is replaced by the call token. Phase 1 gains OP15 and OP16; a new Phase 2 "start together" (OP17–OP20, with OP12 moved into it); the old Phases 2 and 3 become Phases 3 and 4. J-OP-7 and J-OP-8 added. |

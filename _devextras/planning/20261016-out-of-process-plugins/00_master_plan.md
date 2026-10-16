# Out-of-process plugins — tools and plugins in Python, Node.js and Go

**Status:** Draft 2026-10-16, written from a research question of the same
day: *"How could we open the plugin system to external plugins and services
written in other languages? Could a Python developer add a tool to the web
interface, written in Python, Node.js or Go?"* The research and a tested
spike are in [`01_research.md`](./01_research.md) and [`spike/`](./spike/README.md).
Tick §0 before the first product PR.
**Priority:** Row 18 of [`../ROADMAP.md`](../ROADMAP.md), not started. Its
place among the queued rows is the owner's call.
**Goal:** A developer who does not write PHP gives Synaplan's AI a tool, and
later ships a whole plugin, as a service that runs next to Synaplan in any
language — never inside the PHP worker:

1. **Phase 1 — tools for everyone (OP1–OP6).** An administrator adds an MCP
   server once for everyone, also on the organisation's own network, and every
   call tells the server, signed, who is asking. Servers built with the
   official Python, Node.js and Go SDKs work as they are (measured, §1).
2. **Phase 2 — a plugin is a manifest plus a service (OP7–OP12).** A plugin
   folder without PHP declares its MCP servers, its service, its settings and
   its translations; core reaches the service through one checked route and
   talks HTTP to it for web search, extraction, rerank and chat context.
3. **Phase 3 — the service's own view, isolated (OP-S1, OP13–OP14).** MCP Apps
   and service pages render in a frame that never sees the session.

**Why now:** The protocol part is already done and measured: Synaplan's real
`McpClient` (protocol `2025-11-25`) listed and called tools on Python, Node.js
and Go servers without a change (§1). What blocks a developer is policy and
packaging: the SSRF guard refuses every internal address, MCP connections exist
only per person, and a plugin manifest cannot declare a service. The archived
open-plugin-platform plan chose in-process PHP plus curation; for other
languages, out of process is both safer and smaller.
**Binding UX:** [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) (U1–U12) —
surfaces in OP5, OP11, OP12, OP13, OP14.
**Class:** OP1–OP3, OP7–OP10 `backend-only`; OP5, OP11–OP14 `ota-candidate`;
OP0, OP4, OP6, OP-S1 `no-app-impact`.
**Prerequisite:** none on `main`. OP4 (§0 #15, Docker / CI) and OP7 (§0 #10,
schema) carry ask-first rows.

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Start here, decisions, target shape, step table, gates, risks |
| [`01_research.md`](./01_research.md) | The full write-up: how plugins work today, every extension point, the gaps, the measured evidence, five options compared |
| [`02_contracts.md`](./02_contracts.md) | Data, API and wire contracts: install-wide servers, network rule by origin, signed identity (with a test vector), manifest v2 keys, service proxy, HTTP contracts for plug ports, settings, isolated views |
| [`03_journeys.md`](./03_journeys.md) | Journeys J-OP-1…6, wireframes, copy, the five questions, empty / error / flag-off states |
| [`04_sprints.md`](./04_sprints.md) | One vibe brief per step: goal, files, brief to paste, exit criteria |
| [`spike/`](./spike/README.md) | The 2026-10-16 spike: Python, Node.js and Go MCP servers, a FastAPI service, the `McpClient` probe, `run.sh`, results |
| `STATUS.md` | Step log and measured results |

---

## Start here (60 seconds)

**What we build:** Synaplan already speaks MCP to servers a person connects
under Apps → MCP, and the AI uses their tools in chat (planner `mcp_fetch` /
`mcp_action`), in Saved Tasks, in assistants and on the `/v1` gateways. We let
an **administrator** connect a server **for everyone**, allow that server to
live **on the organisation's network**, and **sign every call** with the
person it runs for. That is enough for a Python, Node.js or Go developer to
ship a tool. Phase 2 lets a plugin folder declare such servers and a service
of its own, so a plugin needs no PHP at all.

**Where it lives:**

| You want to change… | Go to… |
| ------------------- | ------ |
| The MCP handshake, calls, size cap, SSRF check | `backend/src/Service/Mcp/McpClient.php` |
| Server rows and who sees them | `backend/src/Entity/McpServerConfig.php`, `backend/src/Repository/McpServerConfigRepository.php` |
| Cached tool discovery (5 min) | `backend/src/Service/Mcp/McpToolRegistry.php` |
| MCP flags (`MCP.CLIENT_ENABLED`, `NODE_TIMEOUT`, …) | `backend/src/Service/Mcp/McpClientConfig.php`; admin fields in `backend/src/Service/Admin/SystemConfigService.php` (`channels` → `mcp`) |
| The user API for servers | `backend/src/Controller/McpServerConfigController.php` |
| MCP tools in the tool registry | `backend/src/Service/Tool/Source/McpClientToolSource.php`, `backend/src/Service/Tool/ToolRegistry.php` |
| Chat planner use | `backend/src/Service/Multitask/Execution/Runner/McpFetchRunner.php`, `McpActionRunner.php`; `backend/src/Service/Message/ConnectedSystemsHint.php` |
| Saved Task / planner tool step | `backend/src/Service/Multitask/Execution/Runner/ToolCallRunner.php` |
| Approval rules (read auto, write approve, destructive block, `allowWrite`) | `backend/src/Service/Tool/Policy/ApprovalPolicy.php` (`hardBlock()`), `backend/src/Service/Tool/ToolExecutionGate.php` |
| The SSRF guard and its operator-network mode | `backend/src/Service/Security/SsrfGuard.php` (`pinnedIps($host, allowOperatorNetwork: true)`) |
| The precedent: admin targets on the LAN, kill switch | `backend/src/AI/Credential/OpenAiCompatibleEndpointRegistry.php::resolveUpstream()`, `OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY` |
| Connecting to the checked address | `backend/src/Service/Tool/Custom/HttpToolExecutor.php::assertSafe()` + the `resolve` option |
| HMAC conventions to reuse | `backend/src/Service/Voice/VoiceLiveInternalAuth.php` (timestamp + body, 60 s); `backend/src/Service/SavedTask/SavedTaskWebhookIngress.php` (`X-Synaplan-Signature: sha256=…`) |
| Gateway tool names | `backend/src/AI/Messages/Mcp/McpToolCatalogAdapter.php` (`mcp__{serverId}__{tool}`) |
| Audit log | `backend/src/Service/Iam/AuditLogWriter.php` |
| Apps → MCP page | `frontend/src/components/config/McpServersConfiguration.vue` (789 lines), `McpTemplatePicker.vue`, `frontend/src/config/mcpServerTemplates.ts`, `frontend/src/services/api/mcpServersApi.ts` |
| Plugin discovery, manifest, install | `backend/src/Kernel.php::getPlugins()`, `backend/src/Service/Plugin/PluginManifest.php`, `PluginManager.php`, `PluginAgentInstaller.php`, `backend/src/Command/InstallPluginCommand.php` |
| Plugin page and slash commands | `frontend/src/views/PluginView.vue`, `frontend/src/stores/commands.ts`, `frontend/src/views/ChatView.vue::runPluginChatCommand()` |
| Plug ports and their declaration check | `backend/src/Plug/PlugPorts.php`, `backend/src/Plug/*/…Interface.php`, `backend/src/Plug/DependencyInjection/PlugDeclarationCheckPass.php` |
| Module status and health probes | `backend/src/Module/`, `backend/src/Module/Probe/HttpSidecarHealthProbe.php` |
| The isolated-frame precedent | `frontend/src/components/chat/ArtifactPanel.vue`, `frontend/src/utils/chatArtifacts.ts` (CSP) |
| E2E stub servers | `frontend/tests/e2e/stub-servers/*`, `docker-compose.test.yml`, the `telegram-stub` profile in `docker-compose.yml` |
| Tests to extend | `backend/tests/Unit/Service/Mcp/McpClientTest.php`, `McpToolRegistryTest.php`, runner tests under `backend/tests/Unit/Service/Multitask/Execution/Runner/`, `frontend/tests/e2e/tests/mcp-config.spec.ts` |

**The loop that keeps you safe:** default-off flag → SSRF matrix tests written
first → stub E2E → one installation opts in → seeded default. The network
allowance is only ever widened for what an administrator added, never for a
URL a person typed.

**Rules for the AI assistant driving this:**

- One step = one PR. The commit title comes verbatim from §5: the type decides
  the release version (`feat` raises the minor).
- A claim about how an SDK behaves is proven by [`spike/run.sh`](./spike/run.sh)
  or by a test, never "should work". Record what you ran in STATUS.
- The SSRF matrix of [`02_contracts.md`](./02_contracts.md) §2 is a failing
  test before it is code.
- Secrets are write-only. Bearer tokens and signing secrets are never logged
  or returned; a signing secret is shown exactly once.
- Do not touch `frontend/src/stores/config.ts` or any `*RuntimeConfig*` file
  under `frontend/src`: they are `store-required` in
  `.github/mobile-impact-policy.json`. New data reaches the UI through its own
  endpoint (§0 #17).
- Copy: "MCP" only on its own row (UX contract §4.8); none of the banned words
  of UX contract §8 (sandbox, container, node, provisioning, …) in primary
  copy; five locales in the same PR.
- `McpServersConfiguration.vue` has 789 lines. A step that adds to it splits
  it first (components under 300 lines, AGENTS.md).
- No hostnames, IPs or secrets of real installations — this repository is
  public. Dev defaults only.
- Schema, Docker / CI and dependency changes are ask-first rows in §0. Stop if
  a step needs one that is not ticked.

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | **MCP is the one protocol for tools written in other languages** — Streamable HTTP, the version `McpClient` pins (today `2025-11-25`). No second RPC, no gRPC plugin protocol, no runtime inside PHP (WASM, embedded Python). | Proposed | |
| 2 | **Code in other languages runs out of process only.** Existing PHP plugins keep working unchanged; nothing in this plan removes or narrows the in-process path. | Proposed | |
| 3 | **Install-wide servers are `BMCPSERVERS` rows with `BUSERID = 0`** — the BCONFIG convention for "the installation". Only administrators create, edit, test and delete them; `AuditLogWriter` records who. No new column in Phase 1. Alternative with `BSCOPE` / `BCREATEDBY` columns: **schema change — ask first.** | Proposed | |
| 4 | **Anyone can switch an install-wide server off for themselves.** Stored per person in BCONFIG (`MCP` / `OFF_FOR_ME`, JSON list of ids). There is no "always on" for administrators in v1. | Proposed | |
| 5 | **Network rule by origin.** Install-wide servers may reach the organisation network (RFC 1918, ULA, 100.64/10) through the guard's operator mode; **loopback stays refused** (from the backend it is the container itself — Caddy's admin API answers on `127.0.0.1:2019`, `02` §2); link-local (cloud metadata) and every other reserved range stay refused; a name with any refused answer is refused. `MCP_PUBLIC_SERVERS_ONLY=1` removes the allowance, mirroring `OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY`. A person's own servers stay public-only. | Proposed | |
| 6 | **Every MCP request connects to the address that was checked** (HttpClient `resolve`, as `HttpToolExecutor` does), for both scopes. Today `McpClient` checks the name and resolves it again when connecting. Called out in the OP2 PR as hardening. | Proposed | |
| 7 | **Signed caller identity on every call to an install-wide server:** `X-Synaplan-Timestamp`, `X-Synaplan-User`, optional `X-Synaplan-User-Email`, `X-Synaplan-Signature: sha256=<hex>` over `timestamp \n user \n email \n raw body`; ±60 s like `VoiceLiveInternalAuth`. A per-server secret is generated by Synaplan, shown once, rotatable — like Saved Task webhook secrets. Test vector in `02` §3. | Proposed | |
| 8 | **`X-Synaplan-User` is a stable pseudonym per server** (`u_` + 32 hex of HMAC(secret, `user:<id>`)); `system` for discovery and tests. For a server an administrator added, the administrator may tick "Share the person's email" to add `X-Synaplan-User-Email`. Plugin-declared servers and services get the pseudonym only in v1. A person's own servers get no identity headers. | Proposed | |
| 9 | **Manifest v2 keys, additive and enforced:** `provides.mcpServers[]`, `provides.service`, `provides.settings`, `provides.context`, `provides.plugs[].transport = "http"`, a `locales/` folder, and the top-level `permissions` value `network:operator` (required before a declared URL may resolve to the organisation network). URLs only as `env:NAME`. Errors name the field (`InvalidPluginManifestException`). A plugin without `backend/` is first-class. | Proposed | |
| 10 | **Plugin-declared MCP servers are install-wide rows owned by the plugin**, marked `BSOURCE = plugin:<id>:<key>`, visible to the people who have the plugin installed. **Schema change (one nullable column, raw idempotent `ADD COLUMN IF NOT EXISTS`) — ask first.** Alternative without a column: a BCONFIG map `PLUGIN_MCP` / `<id>.<key>` → server id. | Ask | |
| 11 | **One checked route to a plugin's service:** `/api/v1/user/{userId}/plugins/{name}/svc/{path}`. Core checks the person and the installation, strips cookies and `Authorization`, adds the #7 headers, 15 s timeout, 1 MiB request / 5 MiB response, no redirects, JSON / text / image responses only, no streaming in v1. | Proposed | |
| 12 | **Plug ports over HTTP:** one generic remote adapter per port (`web_search`, `extraction`, `rerank`) and one remote chat-context provider, registered by the kernel from the manifest at container build (restart required, as for PHP plugins). `health()` stays config-only (`plugins/README.md` rule 4). The reviewer confirms the compile-time registration in `Kernel.php`. | Proposed | |
| 13 | **Plugin slash commands stop appearing as runnable tools** (`PluginCommandToolSource` goes). They are listed today but `ToolCallRunner` refuses them; none of Synaform, synafastbill, Synamail or synaScriber uses `chatCommands[].tool` (checked 2026-10-16). Tools come from `provides.mcpServers`. | Proposed | |
| 14 | **UI tiers.** (a) Settings rendered by core from a schema (Phase 2). (b) MCP Apps and service pages in a frame with `sandbox="allow-scripts"`, no `allow-same-origin`, a strict CSP, and a message bridge that passes theme and data but never the session (Phase 3, after OP-S1). (c) Today's ES-module pages stay for bundled and operator-trusted PHP plugins. | Proposed | |
| 15 | **E2E MCP stub:** `frontend/tests/e2e/stub-servers/mcp/mcp-stub-server.ts`, dependency-free, recording requests including identity headers; a default service in `docker-compose.test.yml`, an opt-in profile in `docker-compose.yml`. **Docker / CI config — ask first.** | Ask | |
| 16 | **No new composer or npm dependency in Phases 1–2.** The settings form renders a small JSON Schema subset (string, number, boolean, enum, secret) with house components. | Proposed | |
| 17 | **No change to `frontend/src/stores/config.ts` or `*RuntimeConfig*` frontend files** (store-required). New data comes from its own endpoints. | Locked (mobile policy) | |
| 18 | **Flags in BCONFIG, global only:** `MCP.INSTANCE_SERVERS_ENABLED` (Phase 1) and `PLUGINS.SERVICES_ENABLED` (Phase 2), code default off, seeded on for new installs after the stub E2E is green. Flag off ⇒ admin controls absent, install-wide rows ignored, proxy route 404 (U11). Plugin service health goes through the module status path, not a hand-written block (AGENTS.md). | Proposed | |
| 19 | **Copy rules:** "MCP" only on its own row; no banned words in primary copy; five locales in the same PR; plugin-supplied strings come from the plugin's `locales/{de,en,es,fr,tr}.json`, English as fallback. | Locked (UX contract) | |
| 20 | **Distribution is not in this plan.** Registry, signed images and in-app install are the owner's call after Phase 2; they replace the tarball design of open-plugin-platform S3, because nothing third-party runs inside PHP any more. | Proposed | |

---

## 1. Why — facts this plan rests on

Full write-up with file references in [`01_research.md`](./01_research.md).

1. **Plugins are PHP in the worker.** `Kernel::getPlugins()` loads
   `plugins/*/manifest.json`, autowires `backend/` and imports its routes.
   Routes, plug adapters and chat context exist only as PHP classes. Plugin
   pages are ES modules that `PluginView.vue` imports into the app, where they
   act with the signed-in person's session.
2. **The protocol works today.** Synaplan's real `McpClient` listed and called
   tools on servers built with the official SDKs — Python `mcp` 2.3.0,
   TypeScript `@modelcontextprotocol/sdk` 1.32.1, Go `go-sdk` 1.8.0. Input
   schemas and `readOnlyHint` arrived intact, so the approval rules apply as
   they are ([`STATUS.md`](./STATUS.md), 2026-10-16).
3. **Policy blocks it.** Registering those servers on the Docker network
   through the API: 3 × HTTP 400 "This server URL is not allowed (must be a
   public http(s) endpoint)". A custom HTTP tool to a FastAPI service saves and
   lists but every call fails ("Only https URLs are allowed"); OpenAPI import
   of that service is refused.
4. **MCP connections are per person** (`BMCPSERVERS.BUSERID`); templates are
   hardcoded in `frontend/src/config/mcpServerTemplates.ts`; a plugin cannot
   declare a server.
5. **Plugin tools are a dead end today.** `PluginCommandToolSource` lists
   slash commands that opt in with `tool.sideEffect`; `ToolCallRunner` answers
   "This tool cannot run as a Saved Task step".
6. **The building blocks exist:** the guard's operator-network mode (used by
   OpenAI-compatible endpoints), two HMAC conventions, `AuditLogWriter`,
   `HttpSidecarHealthProbe`, the isolated artifact frame, the Apps
   "Extensions" section.

---

## 2. Target shape

```text
Developer (Python · Node.js · Go)
  MCP server, official SDK ───────────────┐  Streamable HTTP, protocol 2025-11-25
  optional HTTP service (Phase 2) ──────┐ │  signed: X-Synaplan-Timestamp / -User / -Signature
                                        │ │
Synaplan backend                        │ │
  BMCPSERVERS  BUSERID = 0  → everyone  │ │   (Phase 2: BSOURCE = plugin:<id>:<key>)
               BUSERID > 0  → one person│ │
  McpClient ── network rule by origin ──┼─┘   everyone: operator network allowed, pinned
             └ McpCallerSignature       │     person:   public only, pinned
  McpToolRegistry → McpClientToolSource → ToolRegistry
     ├─ chat planner  mcp_fetch / mcp_action  (topic switch tool_mcp)
     ├─ Saved Tasks   tool_call
     ├─ assistants    tools.mcpServers
     └─ /v1 gateways  native tool calling (mcp__{id}__{tool})
  ToolExecutionGate · ApprovalPolicy  (unchanged: read auto · write approve · destructive block)

Phase 2 — plugins/<id>/ (no backend/ needed)
  manifest.json
    provides.mcpServers  → install-wide rows for people who have the plugin
    provides.service     → /api/v1/user/{u}/plugins/<id>/svc/*   (checked, signed)
    provides.plugs[]     → Remote{WebSearch,Extraction,Rerank} adapter per declaration
    provides.context     → remote chat-context provider
    provides.settings    → settings page rendered by Synaplan
  locales/{de,en,es,fr,tr}.json

Phase 3 — isolated views
  MCP Apps ui:// resources and service pages → frame sandbox="allow-scripts", strict CSP,
  message bridge: theme + data in, actions out — never the session
```

---

## 3. Journeys

Details, wireframes and copy in [`03_journeys.md`](./03_journeys.md).

| Journey | Who | Walked in |
| ------- | --- | --------- |
| **J-OP-1** — An administrator offers an internal tool to everyone | Ada (admin) | OP5 |
| **J-OP-2** — A person uses it in chat and turns it off for themselves | Ben | OP5 |
| **J-OP-3** — The service is down, or its secret was rotated | Ada, Ben | OP5 |
| **J-OP-4** — A plugin that is a manifest plus a service | operator, Ben | OP11 |
| **J-OP-5** — A tool shows its own view in the chat | Ben | OP13 |
| **J-OP-6** — A Python developer from zero to a tool in chat, following the docs only | Dev | OP6 |

---

## 4. What we do not rebuild

| Already here | Use it |
| ------------ | ------ |
| MCP client, sessions, SSE-framed answers, size cap, OAuth refresh | `McpClient` |
| Tool discovery with a 5-minute cache | `McpToolRegistry` |
| One registry for every tool source, unique names | `ToolRegistry`, `ToolSourceInterface` |
| Approval policy incl. MCP `allowWrite` hard block | `ApprovalPolicy`, `ToolExecutionGate`, Approvals inbox |
| Planner nodes for MCP read and write, Saved Task tool step | `McpFetchRunner`, `McpActionRunner`, `ToolCallRunner` |
| SSRF guard with operator mode and pinning | `SsrfGuard::pinnedIps()`, `isOperatorNetworkIp()` |
| HMAC with timestamp and skew | `VoiceLiveInternalAuth` |
| Secret shown once, encrypted at rest | Saved Task webhook secrets, `EncryptionService`, `PlugKeyStore` |
| Audit trail for admin actions | `AuditLogWriter` |
| Health probe with memo and 2 s timeout | `HttpSidecarHealthProbe` |
| Manifest validation that names the field | `PluginManifest`, `InvalidPluginManifestException` |
| Per-person plugin install, assistant packs | `PluginManager`, `PluginAgentInstaller`, `app:plugin:install*` |
| Isolated HTML in the app | `ArtifactPanel.vue` (`sandbox="allow-scripts"`, CSP `default-src 'none'`) |
| Apps directory with an Extensions section | `AppsView.vue`, `frontend/src/apps/catalog.ts` |

---

## 5. Step table

| Step | Commit title | Class | Depends |
| ---- | ------------ | ----- | ------- |
| OP0 | `docs(planning): out-of-process plugins — research and plan` | no-app-impact | — |
| **Phase 1** | | | |
| OP1 | `feat(mcp): administrators add MCP servers for everyone` | backend-only | OP0, §0 #3 #4 #18 |
| OP2 | `feat(mcp): administrator-added MCP servers may use the organisation network` | backend-only | OP1, §0 #5 #6 |
| OP3 | `feat(mcp): sign every call to an administrator-added server with the person it runs for` | backend-only | OP1, §0 #7 #8 |
| OP4 | `test(e2e): MCP stub server for tool journeys` | no-app-impact | OP0, §0 #15 |
| OP5 | `feat(apps): MCP servers for everyone in Apps, with a switch per person` | ota-candidate | OP1–OP4 |
| OP6 | `docs(plugins): build a Synaplan tool in Python, Node.js or Go` | no-app-impact | OP2, OP3, OP5 |
| **Phase 2** | | | |
| OP7 | `feat(plugins): plugins declare MCP servers and a service in the manifest` | backend-only | OP1–OP3, §0 #9 #10 |
| OP8 | `feat(plugins): one checked route from a plugin to its own service` | backend-only | OP7, §0 #11 |
| OP9 | `feat(plugs): web search, extraction, rerank and chat context from a plugin service` | backend-only | OP7, OP8, §0 #12 |
| OP10 | `fix(tools): stop offering plugin slash commands as runnable tools` | backend-only | OP0, §0 #13 (can land first) |
| OP11 | `feat(plugins): settings pages from a schema, in five languages` | ota-candidate | OP7, OP8 |
| OP12 | `feat(admin): plugin services on the system status page` | ota-candidate | OP7 |
| **Phase 3** | | | |
| OP-S1 | spike: MCP Apps host bridge in an isolated frame (STATUS entry, branch never merged) | no-app-impact | OP5 |
| OP13 | `feat(chat): show a tool's own view in the chat, isolated` | ota-candidate | OP-S1 |
| OP14 | `feat(plugins): plugin pages served by the plugin's service, isolated` | ota-candidate | OP-S1, OP8, OP11 |

Phase 1 is useful on its own: after OP6 a developer in any language ships a
tool for everyone without a plugin folder. Phase 2 starts only after Phase 1's
journeys are walked.

---

## 6. Gates

Per commit:

```bash
make ci-local            # lint, phpstan (src + tests), deptrac, PHPUnit, Vitest, vue-tsc
```

Per PR, in addition:

```bash
make test-e2e                                              # OP1, OP3, OP5, OP8, OP11–OP14 (HTTP contracts the UI consumes)
make -C frontend test-e2e-layout                           # OP5, OP11–OP14
make -C frontend generate-schemas && docker compose exec -T frontend npm run check:types   # after OpenAPI changes
docker compose exec -T backend php bin/console lint:container                             # OP7, OP9
node scripts/mobile-impact.mjs --base main --head HEAD     # class as in §5
```

- The SSRF matrix (`02` §2) and the signature test vector (`02` §3) are unit
  tests, green before the PR.
- Flags off ⇒ `RoutingCharacterizationTest` and every MCP unit test unchanged.
- After OP2 and OP3, `spike/run.sh` runs against the admin API instead of the
  probe and its output goes into STATUS.
- Locale parity (`frontend/tests/unit/i18n/localeParity.spec.ts`) green; no
  ledger additions.

### Track exit

- J-OP-1…J-OP-4 and J-OP-6 walked and entered in STATUS; J-OP-5 after Phase 3.
- A person who has not read this plan follows `docs/PLUGINS.md` from an empty
  dev stack to a tool answer in chat (J-OP-6).
- `GET /api/v1/tools` lists only tools that can run (OP10).
- Plan archived with a dated note when the last step ships.

---

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| Widening the SSRF guard opens internal targets | Only for rows an administrator added; pinned connect; loopback, link-local and reserved ranges always refused; kill switch env; matrix tests; audit log |
| Loopback reaches the backend container's own admin endpoints | Refused for MCP and plugin services (`02` §2). The same question for OpenAI-compatible endpoints, which accept loopback today, goes to that registry's owner (STATUS 2026-10-16) |
| A tool result carries a prompt injection | Results are untrusted text as today; write tools need approval, destructive tools stay blocked, `allowWrite` per server; chat context from a service is capped and labelled |
| A service learns more about people than it needs | Pseudonymous `X-Synaplan-User` by default; email only when an administrator ticks it; a person's own servers get nothing |
| Replay of a signed call | Timestamp in the signature, ±60 s window; documented for the service side |
| Three web nodes in production | The service URL must resolve on every node; health is probed from the node that answers; ops notes in `synaplan-platform` (private) |
| Latency on the planner path | `MCP.NODE_TIMEOUT` (default 15 s, clamped 3–120); discovery cached 5 minutes; `ConnectedSystemsHint` reads the cache only |
| A plugin service is down | One sentence with a recovery (U8), registry fallback for plug ports, status page row (OP12) |
| Existing plugins break | Manifest keys are additive; the PHP path is unchanged; OP10 checked against the four external plugins |
| Routing snapshots move | Flags off ⇒ byte-identical; flag-on cases are new characterization cases |
| App release | No store-required file is touched (§0 #17); UI steps are `ota-candidate` |

---

## 8. Non-goals

- Running Python, Node.js, Go or WASM inside the PHP worker.
- Replacing PHP plugins or moving first-party features into services.
- Opening the network allowance to URLs a person types (their own MCP servers
  and custom HTTP tools stay public-only).
- OAuth sign-in per person for install-wide servers (v1: none or bearer, plus
  the signed identity).
- A plugin registry, in-app download, or paid marketplace (§0 #20).
- MCP resources and prompts beyond tools, except the `ui://` resources Phase 3
  needs.

---

## 9. Later (in this order)

| # | What | Why it waits |
| - | ---- | ------------ |
| 1 | Custom HTTP tools that an administrator shares on the organisation network | Same rule by origin as #5, but custom tools have their own share model (UX contract §4.1, roadmap row 12) |
| 2 | Person-held OAuth for install-wide servers | Needs a token per person on a shared row; the OAuth store is per row today |
| 3 | Script tools in the compute sidecar (a stored Python / Node function + schema, run offline) | Option D in `01` §6; useful for pure functions, depends on Secure Compute B4 |
| 4 | Distribution: registry, signed images, in-app install | Owner's call after Phase 2 (§0 #20) |
| 5 | Channels as plugin services (`ChannelTransport` over HTTP) | Open-plugin-platform §3.2 shape, now out of process |
| 6 | MCP resources as knowledge sources | Separate RAG decision |

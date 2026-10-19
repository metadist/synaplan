# Research — opening the plugin system to other languages

**Question (2026-10-16):** How could we open Synaplan's plugin system to
external plugins and services written in other languages? Could a Python
developer add a tool to the web interface, written in Python, Node.js or Go?

**Answer:** Not as a plugin today, but yes as a tool. Plugin backends are PHP
classes loaded into the Symfony kernel, and plugin pages are JavaScript modules
mounted into the Vue app. A tool written as an MCP server in any of those
languages already works with Synaplan's MCP client — measured with the official
Python, Node.js and Go SDKs. It is blocked by policy and packaging, not by the
protocol: the SSRF guard refuses every internal address, MCP connections exist
only per person, and a manifest cannot declare an external service. The plan
in [`00_master_plan.md`](./00_master_plan.md) closes those gaps in three phases.

**Method:** Code read on `main` at `0c1dafd` (re-checked at `f6a60ab`). Two
experiments on the local dev stack with throwaway containers on the Synaplan
Docker network; no product code changed. Results:
[`STATUS.md`](./STATUS.md) (2026-10-16) and [`spike/results.md`](./spike/results.md);
reproduce with [`spike/run.sh`](./spike/run.sh).

---

## 1. How plugins work today

### 1.1 Discovery and loading

- `backend/src/Kernel.php::getPlugins()` globs `<PLUGINS_DIR>/*/manifest.json`
  (`PLUGINS_DIR` env, then `/plugins`, then `backend/plugins`) and **skips a
  plugin without a `backend/` folder**. For each plugin it registers a PSR-4
  prefix (`namespace` from the manifest, else `Plugin\<Id>`).
- `loadPluginServices()` autowires and autoconfigures everything under
  `backend/` (except `Entity`, `migrations`, `tests`); `loadPluginRoutes()`
  imports attribute routes from `backend/Controller`.
- Autoconfiguration tags plugin classes like core ones: a plugin class that
  implements `ContentExtractorInterface`, `WebSearchProviderInterface` or
  `RerankProviderInterface` reaches the core registry;
  `PlugDeclarationCheckPass` refuses one that the manifest did not declare in
  `provides.plugs`.
- The container is compiled once per environment, so a plugin change needs a
  cache clear and a worker restart. `/plugins` is mounted read-only in the
  three PHP services of `docker-compose.yml` (backend, worker, scheduler).

### 1.2 Installation, per person

`PluginManager::installPlugin($userId, $name)` creates
`uploads/<L1>/<L2>/<user>/plugins/<name>/` with symlinks `backend` and
`frontend` to the central plugin and `up` back to the person's root, then runs
`migrations/*.sql` with `:userId` and `:group` (`P_<slug>`). Entry points:
`app:plugin:install <user> <plugin>`, `app:plugin:install-verified-users`,
and `DefaultUserPluginProvisioner` for new accounts (`%default_user_plugins%`).
There is **no admin UI** for plugins; `PluginAgentInstaller` publishes
`provides.agents` packs when a plugin is enabled from the CLI.

### 1.3 The manifest

`backend/src/Service/Plugin/PluginManifest.php` parses and validates:
`name`, `version`, `description`, `capabilities`, `config`, `chatCommands`,
`provides.resourceKinds` (with their own `permissions` list), `provides.plugs`,
`provides.agents`. Top-level fields such as `routes`, `minSynaplanVersion`,
`author` and `permissions` (castingdata, serper_search) are read by nobody;
`serper_search` uses `permissions` only as documentation (`plugins/README.md`).

### 1.4 Backend extension points

| Seam | Contract | Example |
| ---- | -------- | ------- |
| HTTP routes | Symfony controllers under `/api/v1/user/{userId}/plugins/<name>/…`; each controller checks `{userId}` itself | `plugins/castingdata/backend/Controller/CastingDataController.php` (`canAccessPlugin()`) |
| Plug adapters | `provides.plugs[]` + an interface from `backend/src/Plug/<Port>/` | `plugins/serper_search/` |
| Chat context | `PluginContextProviderInterface` (`supports()`, `getContext()`), appended to the system prompt | `plugins/castingdata/backend/Service/CastingContextProvider.php` |
| Shareable data | `provides.resourceKinds` over `plugin_data` (`PluginDataService`) | docs/ADMIN.md "Plugin-declared kinds" |
| Assistant packs | `provides.agents` (`synaplan-bundle.v1` files) | `plugins/hello_world/agents/hello.json` |
| Settings | `BCONFIG` group `P_<slug>` or `plugin_data` | castingdata |

External plugins (Synaform, synafastbill, Synamail, synaScriber — separate
public repositories) import four core classes as their de-facto SDK:
`PluginDataService`, `ModelConfigService`, `RateLimitService`,
`OidcUserService` (code cleanup V1, archived). synaScriber already pairs its
PHP plugin with services that run elsewhere (Jitsi, Prosody, the transcriber
sidecar).

### 1.5 Frontend extension points

- **Plugin page.** `frontend/src/views/PluginView.vue` (route
  `/plugins/:pluginName`) does `import()` of
  `/api/v1/user/{id}/plugins/<name>/assets/index.js` and calls
  `mount(el, { userId, apiBaseUrl, pluginBaseUrl, config })`. Assets come from
  `PluginAssetController` with a MIME map. If the import fails, an
  `index.html` is injected with `innerHTML` — its scripts never run.
- **Where a person finds it.** Runtime config lists installed plugins
  (`ModulesRuntimeConfig`: name, version, description, capabilities,
  chatCommands); `useNavItems.ts` adds a Plugins entry, `AppsView.vue` a
  section with the id `extensions`, labelled "Plugins" in every locale.
- **Slash commands.** `frontend/src/stores/commands.ts::pluginCommands()` adds each
  `chatCommands[]` entry to the composer; `ChatView.vue::runPluginChatCommand()`
  posts `{ message, chatId: 'webchat', lang }` to
  `/api/v1/user/{id}/plugins/<name><endpoint>` and renders `{ message }` — the
  AI pipeline is bypassed.
- **Limits.** No translation hook (`plugins/README.md` says so for v1),
  styling by copying CSS variables (castingdata, hello_world use inline
  styles), the sample uses `alert()`.

### 1.6 Trust model

A plugin is code execution: PHP in the worker with the database, the
container's secrets and the network; plugin JavaScript in the app origin with
the person's session (castingdata calls the API with `credentials:
'include'`). The archived open-plugin-platform plan states this and accepts it
— "no plugin sandboxing / process isolation… the trust model is curation +
signatures, not containment" — for PHP plugins.

---

## 2. What already speaks other languages

### 2.1 The MCP client

- Per person: `BMCPSERVERS` (`McpServerConfig`, owner `BUSERID`), managed
  under Apps → MCP (`/apps/mcp`, `McpServersConfiguration.vue`), API
  `/api/v1/mcp-servers`. Auth `none`, `bearer` (header + token, encrypted,
  write-only) or `oauth` (RFC 9728 discovery; admin gate
  `MCP.OAUTH_CONNECTORS_ENABLED`, seeded off). `allow_write` per server.
- `McpClient`: Streamable HTTP, protocol `2025-11-25`, one session per
  operation (`initialize` → `notifications/initialized` → request → `DELETE`),
  SSE-framed answers parsed, 512 KiB response cap, up to 20 `tools/list`
  pages, `max_redirects: 0`, timeout `MCP.NODE_TIMEOUT` (15 s, clamped 3–120).
  Every target goes through `SsrfGuard::isBlockedUrl()` — at save time in the
  controller and again before each session.
- Tool annotations `readOnlyHint` / `destructiveHint` become the side-effect
  class (`SideEffect::fromHints`); `ApprovalPolicy::hardBlock()` blocks every
  non-read MCP tool unless the server allows writes.
- Used by: the chat planner (`mcp_fetch` read, `mcp_action` write — topic
  switch `tool_mcp`, seeded on for `general`), the sorter hint
  `ConnectedSystemsHint`, Saved Tasks and planner `tool_call`
  (`ToolCallRunner`), assistants (`tools.mcpServers`, ids per person), and the
  `/v1` gateways (`GatewayToolCatalog`, names `mcp__{serverId}__{tool}`).
- Flags: `MCP.CLIENT_ENABLED` (code default off, seeded on by
  `McpConfigSeeder`); admin fields under Admin → System configuration →
  Inbound Channels → MCP servers.
- Templates are hardcoded in `frontend/src/config/mcpServerTemplates.ts`
  (Custom, GitHub, n8n, and OAuth templates for Atlassian, Notion, Higgsfield).

### 2.2 Custom HTTP tools and OpenAPI import

Apps → Custom tools (`CustomToolController`, `CustomToolService`): a request
template (`method`, literal `https://` origin, `{{input.*}}` only in path and
query, headers, body, response mapping), a credential from the vault, OpenAPI
import (preview, apply). `HttpToolExecutor` refuses plain HTTP unless
`TOOLS.CUSTOM_HTTP_ALLOW_PLAIN_HTTP`, runs the SSRF guard, pins the resolved
address, refuses redirects and caps the body (`CUSTOM_HTTP_MAX_RESPONSE_BYTES`,
1 MiB). Flag `TOOLS.CUSTOM_HTTP_ENABLED`, code default off. Used by Saved
Tasks, planner `tool_call` and the `/v1` gateways; shareable through IAM.
FastAPI, Express or Go services export OpenAPI, so this path fits them too —
for public HTTPS addresses.

### 2.3 Sidecars

The repository already runs services in other languages, each wired through a
**core** PHP client and a `FeatureModule` under `backend/src/Module/Sidecar/`
with `HttpSidecarHealthProbe`:

| Module | Service | Language |
| ------ | ------- | -------- |
| `ComputeModule` | `sidecars/synaplan-compute` | Go |
| `LiveVoiceModule` | `sidecars/synaplan-voice` (HMAC callbacks to `/internal/voice/*`) | Node.js |
| `OpendeskSttModule` | `sidecars/synaplan-transcriber` | Node.js |
| `DoclingModule` | Docling image | Python |
| `TikaModule` | Apache Tika image | Java |
| `OfficeConvertModule`, `PiperTtsModule`, `SearxngModule`, `LocalAiModule` | external images | various |

The pattern is proven; it is closed to third parties because each sidecar needs
a core pull request. Protocol adapters show the open variant:
`HttpRerankAdapter` speaks four wire shapes (TEI, Jina, Cohere, Voyage), so any
service in any language that speaks one of them plugs in.

### 2.4 Calling into Synaplan

Any language can already call Synaplan: REST with scoped API keys
(`ApiKeyScope`; `/api/v1/user/{id}/plugins` maps to `messages:*` / `chat`),
OpenAI- and Anthropic-compatible `/v1`, Synaplan's own MCP server at `/mcp`,
and Saved Task webhooks in and out (`X-Synaplan-Signature: sha256=…`, see
`docs/N8N.md`).

### 2.5 The compute sidecar

`sidecars/synaplan-compute` (Go) runs Python or Node.js that **the AI writes**
in a hardened container (`NetworkMode=none`, read-only root, non-root, caps
dropped). It is a capability for file work, not a way for a developer to ship
a tool; a stored "script tool" would be a separate feature (`00` §9 #3).

---

## 3. Inventory

| Extension point | Language | Installed by | Open to other languages today? |
| --------------- | -------- | ------------ | ------------------------------ |
| Plugin backend (routes, services) | PHP, in-process, full privileges | Operator: files in `/plugins`, `app:plugin:install` | No |
| Plug adapters (web search, extraction, rerank) | PHP interface | Operator | No |
| Chat context | PHP | Operator | No |
| Slash commands | Endpoint must be a PHP route | Operator | No |
| Plugin page | JavaScript, runs in the app origin with the person's session | Operator | UI only, in JS |
| Assistant packs | Declarative JSON | Admin enables (CLI) | Yes, no code |
| MCP servers | Any | Each person | Yes — public HTTPS only |
| Custom HTTP tools | Any | Each person, flag off by default | Yes — public HTTPS only |
| Sidecars | Any for the service | Core pull request | Proven, closed |
| Calling into Synaplan | Any | Person | Yes |

The tool registry (`ToolSource`) has seven sources: `builtin`, `mcp`,
`document`, `skill`, `plugin`, `custom`, `compute`. Two accept code in any
language (`mcp`, `custom`); `plugin` cannot run at all (G5).

---

## 4. Gaps

| # | Gap | Where |
| - | --- | ----- |
| G1 | A manifest cannot declare an out-of-process backend; routes, adapters and context are PHP only | `Kernel.php`, `PluginManifest.php` |
| G2 | The SSRF guard refuses every internal address for MCP and custom HTTP tools; no administrator allowance. The February MCP plan asked for "explicitly allowed via env var"; it was never built | `McpServerConfigController::validate()`, `McpClient::withSession()`, `HttpToolExecutor::assertSafe()`, `OpenApiImporter` |
| G3 | `McpClient` checks the name, then resolves it again when connecting (no pinned connect) | `McpClient::post()` vs `HttpToolExecutor` |
| G4 | MCP connections exist only per person; templates are hardcoded; an assistant pack cannot reference a server (`tools.mcpServers` holds per-person ids) | `BMCPSERVERS.BUSERID`, `mcpServerTemplates.ts`, `AgentBundleSection` |
| G5 | Plugin tools are listed but cannot run: `PluginCommandToolSource` lists slash commands with `tool.sideEffect`; `ToolCallRunner` answers "This tool cannot run as a Saved Task step" | `Service/Tool/Source/PluginCommandToolSource.php`, `ToolCallRunner::run()` |
| G6 | A service never learns who is calling, and has no scoped way back | `McpClient::requestHeaders()` |
| G7 | Plugin UI is trusted JavaScript in the app origin: no isolation, no translations, no house components | `PluginView.vue`, `plugins/README.md` |
| G8 | Distribution is filesystem-only, PHP plugins need a restart, no registry | `docker-compose.yml` (`./plugins:/plugins:ro`), open-plugin-platform S3 not built |

Found on the way, outside this plan's scope:

- **A UX trap in custom HTTP tools.** A tool to a plain-HTTP or internal
  address is saved and listed, then fails on every call ("Only https URLs are
  allowed"). The validator only checks the URL's shape. For the owner of
  custom tools.
- **Loopback is the backend container itself.** `SsrfGuard::isOperatorNetworkIp()`
  includes 127/8 and `::1`, and `OpenAiCompatibleEndpointRegistry` accepts them
  for admin-configured upstreams. From the backend, loopback reaches Caddy's
  admin API (`127.0.0.1:2019` answered a GET of `/config/` on the dev stack,
  2026-10-16), which also has mutating routes. Whether a crafted base URL can
  reach one through that registry was not tested; for the registry's owner.
  This plan refuses loopback for MCP and plugin services (`02` §2).

---

## 5. Evidence (2026-10-16)

### 5.1 Setup

Dev stack from `docker compose up -d`. Four throwaway containers on the Synaplan
Docker network (sources in [`spike/servers/`](./spike/servers/)):

| Container | What | Version |
| --------- | ---- | ------- |
| `py-tool` | MCP server, Python, official SDK (`MCPServer`, stateless Streamable HTTP), tool `word_stats` (`readOnlyHint`) | `mcp` 2.3.0, Python 3.12 |
| `node-tool` | MCP server, Node.js, official TypeScript SDK on Express, tool `slugify` | `@modelcontextprotocol/sdk` 1.32.1, Express 5.3.0, Zod 4.6.7, Node 22 |
| `go-tool` | MCP server, Go, official SDK, tool `reverse_text` | `github.com/modelcontextprotocol/go-sdk` v1.8.0, Go 1.25 |
| `py-rest` | FastAPI REST service with OpenAPI, `GET /word-stats` | FastAPI 0.143.2, uvicorn 0.54.0 |

Note: the Python SDK 2.x renamed `FastMCP` to `MCPServer`
(`from mcp.server.mcpserver import MCPServer`); `mcp.server.fastmcp` raises a
migration error. Older tutorials need `mcp<2`.

### 5.2 Experiment A — the real API, as the demo user

| Call | Result |
| ---- | ------ |
| `GET /api/v1/mcp-servers` | `client_enabled: true` |
| `POST /api/v1/mcp-servers` with `http://py-tool:8000/mcp`, `http://node-tool:8000/mcp`, `http://go-tool:8000/mcp` | 3 × HTTP 400 "This server URL is not allowed (must be a public http(s) endpoint)" |
| `POST /api/v1/tools/custom` to `http://py-rest:8001/word-stats` | HTTP 201, listed in `GET /api/v1/tools?source=custom` |
| `POST /api/v1/tools/custom/1/try` | HTTP 400 "Only https URLs are allowed" |
| `POST /api/v1/tools/custom/import-openapi/preview` from `http://py-rest:8001/openapi.json` | HTTP 400 "This URL is not allowed" |

The custom tool was deleted afterwards.

### 5.3 Experiment B — the real `McpClient`

[`spike/mcp_client_probe.php`](./spike/mcp_client_probe.php) builds the real
`App\Service\Mcp\McpClient` in the backend container. The only substitution:
the SSRF guard is replaced by its own operator-network mode
(`isBlockedIp($ip, allowOperatorNetwork: true)`), which is what an
administrator-added server would get (`00` §0 #5).

| Server | Today (`isBlockedUrl`) | `tools/list` | `tools/call` |
| ------ | ---------------------- | ------------ | ------------ |
| Python | blocked | `word_stats` · schema `{text: string}` required · `readOnlyHint: true` | `{"words": 6, "characters": 41, "sentences": 2, "runtime": "python"}` |
| Node.js | blocked | `slugify` · JSON Schema draft-07 · `readOnlyHint: true` | `{"slug": "hello-plugin-world-from-node", "runtime": "node"}` |
| Go | blocked | `reverse_text` · `additionalProperties: false` · `readOnlyHint: true`, `idempotentHint: false` | `{"reversed": "nalpanys", "runtime": "go"}` |

All three answered `initialize` with `protocolVersion: "2025-11-25"`, accepted
the `MCP-Protocol-Version` header Synaplan sends on every later request, and ran
stateless (no `Mcp-Session-Id`), so Synaplan's session teardown had nothing to
delete (checked with a raw `initialize` against each server).

### 5.4 What the SDKs offer that Synaplan does not use yet

- **Structured output.** The Go server also returned `structuredContent`
  (its typed result); the Python and Node.js servers, as written, returned
  text blocks only. `McpClient::callTool()` keeps `content` only — enough
  today, worth keeping in mind for typed Saved Task inputs.
- **MCP Apps** (`io.modelcontextprotocol/ui`). A tool can point at a `ui://`
  HTML resource (`text/html;profile=mcp-app`) that the host renders in a
  sandboxed iframe, with requested permissions and CSP domains. The Python SDK
  ships it in core (`mcp.server.apps`); the TypeScript and C# SDKs ship it as a
  separate package. Synaplan's client neither negotiates the extension nor
  reads resources. `ArtifactPanel.vue` already renders untrusted HTML in
  `sandbox="allow-scripts"` with CSP `default-src 'none'` — the starting point
  for Phase 3.

---

## 6. Options

| Option | What the developer writes | Core work | Covers | Isolation |
| ------ | ------------------------- | --------- | ------ | --------- |
| **A. MCP as the tool protocol, with administrator-added servers** | An MCP server with the official SDK (about 15 lines, proven) and a container | Install-wide servers, network rule by origin, signed caller identity, Apps rows | AI tools in chat, Saved Tasks, assistants, `/v1` gateways | Own process or host; sees tool arguments and identity only |
| **B. Service-backed plugins** (`provides.service`) | An HTTP service and a manifest; optionally a small `index.js` | Checked proxy route, HMAC identity headers, HTTP contracts for plug ports and context, executor clean-up for plugin tools | Also pages, slash commands, search / extraction / rerank providers, chat context | Same as A |
| **C. Declarative and isolated UI** | A JSON Schema for settings, or HTML served as an MCP Apps `ui://` resource | Core renders settings with house components and five locale files; tool views in an isolated frame | Settings and tool views without trusting plugin JS | `sandbox="allow-scripts"`, strict CSP |
| **D. Script tools in the compute sidecar** | A Python or Node.js function and its JSON Schema | Store the script, run it through the Go compute sidecar | Pure data transforms, nothing to host | Hardened container, offline by default |
| **E. More runtimes inside PHP** (WASM, embedded Python) | Code compiled to WASM | A new runtime in the FrankenPHP worker | The same as PHP plugins | Weak; not recommended |

**A** is the smallest change with the largest reach: every place that uses MCP
today gains the tool without new code. **B** is the only option that also
opens the non-tool extension points (search, extraction, rerank, context,
pages). **C** is what makes plugin UI meet the UX contract (themes, five
locales, house form controls) without trusting plugin code. **D** is a useful
complement for functions, not for services. **E** keeps the trust problem and
adds a runtime.

Recommendation: A, then B with the settings half of C, then the isolated
views of C. D and distribution later (`00` §9).

---

## 7. Precedents to reuse

| Need | Precedent |
| ---- | --------- |
| Admin-configured targets on the LAN, with a kill switch | `OpenAiCompatibleEndpointRegistry::resolveUpstream()`: `pinnedIps($host, allowOperatorNetwork: true)`, `OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY` |
| Connect to the checked address | `HttpToolExecutor` (`resolve` option) |
| HMAC with replay protection | `VoiceLiveInternalAuth` (timestamp + body, 60 s, constant-time compare) |
| Secret shown once, signed outbound calls | Saved Task webhooks (`SavedTaskWebhookIngress`, `OutboundWebhookRunner`) |
| Health of a remote service | `HttpSidecarHealthProbe` (2 s, memoised, reset per request) |
| "Declare it or it does not load" | `PlugDeclarationCheckPass` |
| Admin enables a plugin and everyone gets something | `PluginAgentInstaller` (assistant packs shared with everyone) |
| Isolated HTML in the app | `ArtifactPanel.vue`, `frontend/src/utils/chatArtifacts.ts` |
| A protocol adapter any language can implement | `HttpRerankAdapter` |
| A stub server for E2E | `frontend/tests/e2e/stub-servers/*` + `docker-compose.test.yml` |

---

## 8. Earlier plans and how this one relates

| Plan (archived) | What it said | Here |
| --------------- | ------------ | ---- |
| `2026-archive/2026-04-05-topic-docs/architecture/2026-04-plugin-architecture.md` | Central `/plugins`, symlinks per person, per-person SQL migrations | Unchanged; manifest-only plugins use the same install |
| `2026-archive/20260218-mcp-and-api-enhancements/02-mcp-integration/01-PLUGIN-ARCHITECTURE.md` | MCP as a plugin; "block private IP ranges … unless explicitly allowed via env var" | The allowance arrives as the rule by origin (`00` §0 #5) |
| `2026-archive/20260822-open-plugin-platform/README.md` | Manifest v2 `provides.*`, channels as plugins, a signed-tarball registry; non-goal: no isolation, "a plugin is code execution" | Same manifest direction; for other languages, out of process instead of curation alone (`00` §0 #2, #20) |
| `2026-archive/20260903_tools_approval_workflows/01_sprint_1_registry_refactor.md` | `chatCommands[].tool` as an opt-in, "manifest v2 `provides.tools` supersedes this later" | `provides.mcpServers` is that successor; the opt-in goes (`00` §0 #13) |
| `2026-archive/20260903_ai_plugs/06_sprint_6_plugin_adapters.md` | `provides.plugs`, declaration check | Extended with `transport: "http"` (`00` §0 #12) |
| `2026-archive/20260910-feature-modules/05_sprint_5_compile_time_and_plugin_extraction.md` | FM25: Higgsfield as a plugin; release class for `plugins/*/frontend/**` open | Still open; this plan adds no plugin `frontend/` code to the app |
| `2026-archive/20261014-code-cleanup-V1/00_master_plan.md` | The plugin SDK is four PHP classes | Out-of-process plugins need none of them |

---

## 9. The question, answered per phase

The 2026-10-16 phases; the review renumbered them (§10.3).

| Can a Python, Node.js or Go developer… | Today | After Phase 1 | After Phase 2 | After Phase 3 |
| -------------------------------------- | ----- | ------------- | ------------- | ------------- |
| give the AI a tool for chat, Saved Tasks, assistants and `/v1` | Yes, if the server has a public HTTPS address and each person connects it | Yes, an administrator adds it once for everyone, also on the internal network; calls are signed | Also declared by a plugin | Same |
| add a page to the web interface | Only as a JS module served by a PHP plugin | Same | A settings page from a schema, no JS; a JS page can reach the service through the checked route | The service's own page, isolated |
| add a slash command | Only through a PHP route | Same | Yes, through `/svc/…` | Same |
| add a web search, extraction or rerank provider | No | No | Yes, over HTTP | Same |
| add chat context | No | No | Yes, over HTTP | Same |
| show the tool's own view in a chat | No | No | No | Yes (MCP Apps) |

---

## 10. What changed by 2026-10-18

The plan review ([`05_review.md`](./05_review.md)) re-checked this write-up
against the code on `main` at `297d0cc67`, the MCP specification of
2026-10-18 and a second spike ([`spike-2/`](./spike-2/README.md)). The facts
above stand except where this section says otherwise.

### 10.1 The protocol moved

- **MCP 2026-07-28** is the current revision: no `initialize` handshake, no
  `Mcp-Session-Id`, a required `server/discover`, per-request `_meta`,
  required `Mcp-Method` / `Mcp-Name` headers, `ttlMs` / `cacheScope` on list
  results, `resultType` on every result, Multi Round-Trip Requests instead of
  server-to-client requests, and a deprecation window of at least twelve
  months. §2.1 describes the 2025-11-25 client Synaplan still is.
- **Ten official SDK languages** (modelcontextprotocol.io/docs/sdk): Tier 1
  TypeScript, Python, C#, Go, Rust, Ruby; Tier 2 Java; Tier 3 Swift, PHP,
  Kotlin. The TypeScript SDK's current line is `@modelcontextprotocol/server`
  2.x (`createMcpHandler`); §5's Node.js server uses the v1 package.
- **Measured:** Python 2.3.0, Go v1.8.0 and TypeScript v2 in its default
  posture still answer Synaplan's handshake; TypeScript v2 with
  `legacy: 'reject'` refuses it (`-32022`); TypeScript v1 and the
  `supergateway` 4.2.0 bridge refuse modern requests. An existing stdio-only
  server (`server-everything` 1.0.0) works behind the bridge.
- **MCP Apps** (`io.modelcontextprotocol/ui`) is stable in its 2026-01-26
  revision and requires web hosts to use a sandbox proxy on a different
  origin, with `allow-scripts allow-same-origin` (§5.4 assumed the
  `ArtifactPanel` frame would do).

### 10.2 Gaps this write-up did not list

| # | Gap | Where |
| - | --- | ----- |
| G9 | A self-hosted install cannot receive a plugin folder: `deploy/compose.yaml` mounts none, plugins are baked into the image | `_docker/backend/Dockerfile` (`COPY plugins/ /plugins/`) |
| G10 | Nothing starts a plugin's service with Synaplan; an included compose file lands on `<project>_default`, and a same-named service is merged into Synaplan's own | `docker-compose.yml`, `deploy/compose.yaml`, `deploy/scripts/lib.sh` |
| G11 | A failed tool discovery is not cached, so a hanging server is retried on every catalog build; `McpClient` sets an idle `timeout` but no `max_duration` | `McpToolRegistry::toolsFor()`, `McpClient::post()` |
| G12 | The 2026-10-16 identity draft: the pseudonym is reversible by the service, and the signature does not bind method, path or audience | draft `02` §3 (replaced) |

G6 stays half open: the call token tells a service who is calling; a scoped
way back into Synaplan is `00` §9 Later #7.

### 10.3 The question, answered per phase, after the review

| Can a developer in any language… | Today | Phase 1 | Phase 2 (start together) | Phase 3 | Phase 4 |
| -------------------------------- | ----- | ------- | ------------------------ | ------- | ------- |
| give the AI a tool for chat, Saved Tasks, assistants and `/v1` | Yes, with a public HTTPS address, connected by each person | An administrator adds it once for everyone, also on the internal network; calls carry a token | An operator drops a folder and adds one `include:` line; it starts with Synaplan | Same | Same |
| run an existing stdio-only tool | No | Behind a bridge, added by an administrator | Shipped as a plugin folder with the bridge | Same | Same |
| add a page to the web interface | Only as a JS module served by a PHP plugin | Same | Same | A settings page from a schema; a JS page can reach the service through the checked route | The service's own page, isolated |
| add a slash command | Only through a PHP route | Same | Same | Yes, through `/svc/…` | Same |
| add a web search, extraction or rerank provider, or chat context | No | No | No | Yes, over HTTP | Same |
| show the tool's own view in a chat | No | No | No | No | Yes (MCP Apps) |

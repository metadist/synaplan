# Out-of-process plugins — steps

Roadmap: [`00_master_plan.md`](./00_master_plan.md) §5. Contracts:
[`02_contracts.md`](./02_contracts.md). Journeys and copy:
[`03_journeys.md`](./03_journeys.md).

Each step has a **vibe brief**: paste it into the coding agent as the task,
after pointing it at `00_master_plan.md`. The exit criteria are the definition
of done; the agent reports each one with the command it ran. A step starts
only when the §0 rows it names are ticked.

The `ota-candidate` steps (OP5, OP11, OP12, OP13, OP14) carry a **User-flow**
block and the five exit bullets of [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) §6.

---

## Before OP1 — local setup

```bash
make up                                                  # dev stack
_devextras/planning/20261016-out-of-process-plugins/spike/run.sh
```

`run.sh` starts the Python, Node.js and Go MCP servers and a FastAPI service on
the Synaplan network and prints today's behaviour (all three refused by the
API, all three working through the probe). Keep them running while you build
OP1–OP3; they are the real-world counterpart of the OP4 stub. Remove them with
`spike/run.sh --down`.

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
- Do: switch every "use" call site of `02` §1.3 and leave the "own" ones.
- Do: `AdminMcpServersController` + `Service/Mcp/InstanceMcpServerService`
  (`02` §1.4 without `signing_secret`, `share_email` and `rotate-secret`,
  which OP3 adds); `AuditLogWriter` events of `02` §1.1.
- Do: person API additions (`instance_servers_enabled`, `everyone`,
  `PATCH /api/v1/mcp-servers/{id}/for-me`) with full OpenAPI annotations;
  `make -C frontend generate-schemas`.
- Do not: change the network rule (OP2) — install-wide rows still pass
  `isBlockedUrl()` in OP1; add identity headers (OP3); touch Vue files; add a
  column; seed the flag on.

### Files

Reads: `00` §0 #3 #4 #18, `02` §1; `Controller/McpServerConfigController.php`,
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
> Install-wide MCP servers are `BMCPSERVERS` rows with `BUSERID = 0`; a person
> sees them when `MCP.INSTANCE_SERVERS_ENABLED` is on and they have not
> switched the server off for themselves. Add the three repository methods,
> then switch only the call sites listed as "use" in §1.3 — keep the "own"
> ones untouched. Write the admin controller thin (validation + one service
> call), `ROLE_ADMIN` only, with full OpenAPI annotations, and record every
> change through `AuditLogWriter`. With the flag off, every existing MCP test
> and `frontend/tests/e2e/tests/mcp-config.spec.ts` must pass unchanged. Then
> `make -C frontend generate-schemas`, `make ci-local`.

### Exit criteria

1. Flag off: all MCP unit tests and `mcp-config.spec.ts` unchanged and green;
   `GET /api/v1/mcp-servers` returns `everyone: []`; the admin routes and
   `…/for-me` answer 404.
2. Flag on: a row created through `POST /api/v1/admin/mcp-servers` appears in
   a person's `GET /api/v1/tools?source=mcp`, in `catalogForUser()`, and is
   resolved by `McpFetchRunner`, `McpActionRunner`, `ToolCallRunner` and both
   gateway loops (unit tests with a mocked client).
3. `PATCH …/for-me {"enabled": false}` removes it for that person only.
4. Non-admin session → 403; a restricted API key → 403.
5. Audit rows for created, updated, deleted, tested.
6. `make ci-local` green; generated schemas committed; `vue-tsc` green.

---

## OP2 — Administrator-added servers may use the organisation network (backend-only)

### Goal

- Do: `SsrfGuard::operatorTargetIps(string $host)` (public or organisation
  network, never loopback; `[]` when any answer is refused) — `02` §2.
- Do: `Service/Mcp/McpTargetResolver` applying the `02` §2 table by row scope
  and `MCP_PUBLIC_SERVERS_ONLY`; `McpClient` uses it for every request of a
  session with `resolve` and `max_redirects: 0`; both controllers validate
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
> receives `resolve => [host => pinned ip]` on every request of the session.
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

## OP3 — Sign every call with the person it runs for (backend-only)

### Goal

- Do: `Service/Mcp/McpCallerSignature` producing the `02` §3.1 headers;
  per-row options in BCONFIG `MCP` / `SERVER.<id>` (encrypted
  `{signingSecret, shareEmail}`); secret = 32 random bytes as 64 hex; created
  on `POST /api/v1/admin/mcp-servers` and returned once as `signing_secret`;
  `POST …/{id}/rotate-secret`; `share_email` on create / update.
- Do: `McpClient` encodes the JSON once and sends those bytes as `body`
  (`Content-Type: application/json`), signs exactly those bytes, signs
  bodiless requests over the empty string; headers only for install-wide (and
  later plugin) rows.
- Do: thread the acting person through `callTool(…, ?int $actingUserId)`:
  runners, gateway loops, approval resume (the approval's owner), Saved Tasks
  (the task's owner); discovery and **Test** sign as `system`.
- Do not: send identity headers to a person's own servers; log or return the
  secret except in the two responses named above.

### Files

Reads: `00` §0 #7 #8, `02` §1.1 and §3; `Service/Voice/VoiceLiveInternalAuth.php`,
`Service/SavedTask/SavedTaskWebhookIngress.php`,
`Service/Security/EncryptionService.php`, `Service/Mcp/McpClient.php`.
Touches: `Service/Mcp/McpCallerSignature.php` (new), `McpClient.php`,
`InstanceMcpServerService.php`, `AdminMcpServersController.php`, the callers of
`McpClient::callTool()`, tests.

### Vibe brief

> Read `00_master_plan.md` §0 #7 #8 and `02_contracts.md` §3. Implement OP3.
> Start with a unit test that reproduces the §3.2 test vector exactly (secret,
> person id 2, timestamp 1792130000 → the given user and signature). Then make
> `McpClient` send the exact bytes it signs: encode once, send as `body`. Add
> the headers only for install-wide rows; a person's own servers must see no
> `X-Synaplan-*` header (assert it). Pass the acting person from every caller
> of `callTool()`; discovery and the admin's Test sign as `system`. The secret
> is generated on create, returned once, rotatable, stored encrypted, never
> logged. Regenerate schemas, `make ci-local`.

### Exit criteria

1. The test vector test is green; a captured MockHttpClient request verifies
   with the Python snippet of `02` §3.3 (run it once, paste the result into
   STATUS).
2. Email header only when `share_email` is on; no identity header on person
   rows.
3. After `rotate-secret`, a signature made with the old secret fails the
   service-side check.
4. `make ci-local` green; schemas regenerated.

---

## OP4 — MCP stub server (no-app-impact; §0 #15 ask first)

### Goal

- Do: `frontend/tests/e2e/stub-servers/mcp/mcp-stub-server.ts`, dependency-free
  (Node `http`, like the Telegram stub): `POST /mcp` JSON-RPC — `initialize`
  echoes `2025-11-25`, `notifications/initialized` → 202, `tools/list` → two
  tools (`stub_word_count` with `readOnlyHint: true`, `stub_create_note` with
  `readOnlyHint: false`), `tools/call` → deterministic text; records method,
  headers (including `X-Synaplan-*`) and body; `GET /__requests`,
  `POST /__reset`, `POST /__down?on=1` (answer 503).
- Do: service `mcp-stub` in `docker-compose.test.yml` (default) and profile
  `mcp-stub` in `docker-compose.yml`; helper
  `frontend/tests/e2e/helpers/mcpStub.ts`; a line in `docs/E2E_TESTING.md`.
- Do not: add an npm dependency; use the real SDKs in the stub (they live in
  `spike/`).

### Vibe brief

> Read `00_master_plan.md` §0 #15 (must be ticked) and the Telegram stub
> (`frontend/tests/e2e/stub-servers/telegram/telegram-stub-server.ts`) plus its
> compose entries. Implement OP4: a single-file MCP stub with the behaviour in
> `04_sprints.md` OP4, wired like the Telegram stub. Prove it with `curl`
> (initialize, tools/list, tools/call) and with
> `_devextras/planning/20261016-out-of-process-plugins/spike/mcp_client_probe.php`
> pointed at `http://mcp-stub:<port>/mcp`.

### Exit criteria

1. `docker compose --profile mcp-stub up -d mcp-stub` starts it; the curl
   handshake and the probe list both tools.
2. `GET /__requests` returns the recorded headers; `/__down` makes the next
   call fail.
3. `node scripts/mobile-impact.mjs --base main --head HEAD` → no-app-impact.

---

## OP5 — MCP servers for everyone in Apps, with a switch per person (ota-candidate)

**User-flow:** J-OP-1, J-OP-2, J-OP-3 in [`03_journeys.md`](./03_journeys.md);
wireframes `03` §2, copy `03` §4, states `03` §5.

### Goal

- Do: first split `frontend/src/components/config/McpServersConfiguration.vue`
  (789 lines) into components under 300 lines with no behaviour change (its
  own commit inside the PR), then add the "For everyone" section, the
  administrator's scope choice and email switch, the one-time secret card,
  **New secret**, **Turn off for me** / **Turn on for me**.
- Do: read the flag from `GET /api/v1/mcp-servers` (`instance_servers_enabled`),
  never from runtime config; admin controls only for `authStore.isAdmin`.
- Do: confirmations through `useDialog()`, toasts through `useNotification()`,
  house buttons and form controls; five locales.
- Do: `frontend/tests/e2e/tests/mcp-everyone.spec.ts` (`@ci`) on the OP4 stub:
  admin adds + tests + sees the secret once; a second user sees the row, turns
  it off, the tool leaves `GET /api/v1/tools?source=mcp`, turns it on again.
- Do: seed `MCP.INSTANCE_SERVERS_ENABLED = 1` for new installs only
  (`BConfigSeeder::insertIfMissing` in `McpConfigSeeder`); existing installs
  keep their value (AGENTS.md: BCONFIG defaults are bootstrap-only).
- Do not: touch `frontend/src/stores/config.ts` or any `*RuntimeConfig*` file;
  add a rail item; add a second icon for an existing action.

### Vibe brief

> Read `00_master_plan.md` (rules for the AI assistant), `03_journeys.md` §1–§5
> and AGENTS.md "Buttons", "Form controls", "Icons", "i18n". Implement OP5.
> Commit 1 splits `McpServersConfiguration.vue` without changing behaviour
> (`mcp-config.spec.ts` stays green). Commit 2 adds the "For everyone" surface
> exactly as wireframed, with the copy from §4 in all five locales and the
> states from §5; read the flag from the servers response, not runtime config.
> Write `mcp-everyone.spec.ts` on the MCP stub. Walk J-OP-1…3 in the browser in
> light and dark at 320 px and enter the walk in STATUS. Gates: `make ci-local`,
> `make test-e2e`, `make -C frontend test-e2e-layout`, mobile-impact.

### Exit criteria

1. Named journeys J-OP-1, J-OP-2, J-OP-3 walked in the browser (U10), entered
   in STATUS.
2. Later-self findability: a person finds the server under Apps → MCP → For
   everyone, and Apps → Connected counts it, in ten seconds (U2).
3. Kind-specific consequence copy for "Turn off for me", **New secret** and
   **Delete** in all five locales (U3).
4. Empty, error and flag-off states of `03` §5 present (U5, U8, U11).
5. Light, dark, V2 and 320 px checked (U9).
6. `mcp-everyone.spec.ts` and `mcp-config.spec.ts` green; locale parity green.

---

## OP6 — Build a Synaplan tool in Python, Node.js or Go (no-app-impact)

### Goal

- Do: `docs/PLUGINS.md` (new): what a tool is, the three minimal servers from
  `spike/servers/` with the `02` §3.3 signature check in front, honest
  `readOnlyHint` / `destructiveHint`, a compose snippet that joins the
  Synaplan network and sets `SYNAPLAN_SIGNING_SECRET`, adding the server for
  everyone (J-OP-1), using it in chat with "MCP data sources" on, approvals,
  troubleshooting from `03` §5.
- Do: runnable examples under `docs/examples/mcp-tools/{python,node,go}/`
  (server, Dockerfile, compose snippet) with pinned SDK versions; link from
  `plugins/README.md` and `docs/CONNECTIONS.md`.
- Do not: put examples under `plugins/` (the kernel would scan them).

### Vibe brief

> Read `01_research.md` §5, `02_contracts.md` §3 and §9, and the spike servers.
> Write `docs/PLUGINS.md` and the three examples. Every command in the page
> must run as written on a fresh dev stack; run each one and paste the outputs
> into the PR. Then ask someone who has not read this plan to follow the page
> (J-OP-6) and enter their walk in STATUS.

### Exit criteria

1. Each example starts with its compose snippet, verifies a signed call and
   answers `tools/call` through Synaplan's **Test**.
2. J-OP-6 walked by a person new to the plan; gaps fixed in the page.

---

## OP7 — Plugins declare MCP servers and a service (backend-only; §0 #10 ask first)

### Goal

- Do: `PluginManifest` parses and validates the `02` §4.2 fields with errors
  that name the field; `env:` resolution; manifest-only plugins listed and
  installable (the kernel already skips them).
- Do: one signing secret per plugin (BCONFIG `PLUGIN_SERVICE` / `<id>`,
  encrypted), `app:plugin:secret <id> [--rotate]`.
- Do: enabling a plugin upserts its MCP rows by `BSOURCE` (migration: raw
  idempotent `ALTER TABLE BMCPSERVERS ADD COLUMN IF NOT EXISTS BSOURCE
  VARCHAR(128) NULL`, no Schema API — Galera rule); uninstall for the last
  person disables, never deletes; the visible set includes plugin rows for
  people who have the plugin (`02` §1.2).
- Do: `app:plugin:list` shows declared servers and service, configured or not.
- Do: fixture plugin `backend/tests/Fixtures/plugins/declared/fixture_service/`
  (manifest only).
- Do not: load PHP for manifest-only plugins; send email to plugin services.

### Vibe brief

> Read `00_master_plan.md` §0 #9 #10 (ticked), `02_contracts.md` §1.2 and §4,
> `docs/MIGRATIONS.md` and AGENTS.md "Production Platform Specifics (Galera)".
> Implement OP7: parse and validate the new manifest keys (one test per
> invalid field asserting the field name in the error), add the `BSOURCE`
> migration as raw idempotent SQL, upsert plugin rows on install, include them
> in `findVisibleToUser()` for people who have the plugin, and add
> `app:plugin:secret`. Use the fixture plugin for the integration test.
> `make ci-local`, `make -C backend migrate` on a fresh and on an existing dev
> database.

### Exit criteria

1. Valid fixture parses; each invalid field fails with its name.
2. Install twice → one row per declared server; uninstall for the last person
   → row disabled, id kept.
3. A person without the plugin does not see its rows; with it, they do.
4. Migration idempotent (run twice); `doctrine:schema:validate` green.
5. `make ci-local` green.

---

## OP8 — One checked route from a plugin to its own service (backend-only)

### Goal

- Do: `Controller/Plugin/PluginServiceProxyController` +
  `Service/Plugin/PluginServiceClient` implementing `02` §5 in that order of
  checks; target through `McpTargetResolver`-style rules (organisation network
  only with `network:operator`); signed with the plugin's secret.
- Do: flag `PLUGINS.SERVICES_ENABLED` (code default off, admin field, row in
  `docs/FEATURE_FLAGS.md`).
- Do not: change `ChatView.vue` — `"endpoint": "/svc/chat"` already works.

### Vibe brief

> Read `02_contracts.md` §2, §3 and §5. Implement OP8. Test every check of §5
> with MockHttpClient: wrong person, plugin not installed, no service
> declared, env unset, refused target, header stripping (`Cookie`,
> `Authorization`, incoming `X-Synaplan-*`), caps, content-type allow-list,
> 5xx → 502 with copy, and the added `nosniff` / `CSP: sandbox` headers. Extend
> the MCP stub with `/svc/*` echo routes for an E2E of a `/svc/chat` slash
> command. `make ci-local`, `make test-e2e`.

### Exit criteria

1. All §5 checks covered by tests; a person can never reach another person's
   route.
2. The fixture plugin's slash command reaches the stub through `/svc/chat`
   and renders its reply (E2E).
3. `make ci-local` and `make test-e2e` green.

---

## OP9 — Web search, extraction, rerank and chat context from a plugin service (backend-only)

### Goal

- Do: `Plug/WebSearch/Adapter/RemoteWebSearchAdapter`,
  `Plug/Extraction/Adapter/RemoteExtractor`, `Plug/Rerank/Adapter/RemoteRerankAdapter`,
  `Service/Plugin/RemoteContextProvider`, each speaking `02` §6.
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
3. Flag off or env unset ⇒ adapters report unavailable, routing unchanged
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
> other schema keyword is a manifest error reported at install time. Walk
> J-OP-4 up to "Word tools answered." in light, dark, 320 px; enter it in
> STATUS. Gates: `make ci-local`, `make test-e2e`, `test-e2e-layout`.

### Exit criteria

1. Named journey J-OP-4 walked in the browser (U10).
2. Later-self findability: the plugin is under Apps → Extensions and its
   settings open in one click (U2).
3. Consequence copy for **Save** and **Test** in all five locales; plugin
   labels in the person's language with English fallback (U3).
4. Empty, error (service down) and flag-off states (U5, U8, U11).
5. Light, dark, V2 and 320 px (U9).

---

## OP12 — Plugin services on the system status page (ota-candidate)

**User-flow:** J-OP-4 (Ada's last step) and J-OP-3 for services.

### Goal

- Do: `FeatureStatusReporter::build()` gains a `plugins` group next to
  `modules`: label, configured (env set), healthy (`HttpSidecarHealthProbe` on
  `provides.service.health`), message, the declared MCP servers' last
  **Test**; `FeatureStatusView.vue` renders it with the module row component.
- Do not: create a `FeatureModule` class per plugin (they are dynamic) or a
  hand-written status block.

### Exit criteria

1. J-OP-4's "Word tools · Running" and "Not reachable" walked (U10).
2. An administrator finds a down service from Admin → System status in ten
   seconds (U2).
3. Row copy in five locales (U3); not configured / not reachable / flag off
   states (U5, U8, U11); light, dark, 320 px (U9).

---

## OP-S1 — Spike: MCP Apps host bridge in an isolated frame (no-app-impact)

Branch `spike/mcp-apps-host`, never merged. Answer each question with yes /
no / partly, the versions tested and a code pointer, as a dated STATUS entry:

1. Which revision of `io.modelcontextprotocol/ui` do the Python (in core) and
   TypeScript (package) SDKs ship, and is it stable?
2. Does a `ui://` resource run in an `ArtifactPanel`-style frame
   (`sandbox="allow-scripts"`, `srcdoc`, CSP from `chatArtifacts.ts`) with
   only message passing?
3. Which host messages does a minimal app need (initialize, tool input and
   result, size, theme), and can each be served without the session?
4. How do "send a message" and "call a tool" from the view map onto the
   composer and `ToolExecutionGate`?
5. Does it work in the mobile app's WebView (Capacitor) and in Safari?

A "no" on 2 or 5 moves OP13 to its fallback: show the tool's text result with
an **Open in browser** link to a page the service serves, and record it.

---

## OP13 — Show a tool's own view in the chat, isolated (ota-candidate)

**User-flow:** J-OP-5. Contract `02` §8. Starts after OP-S1 is answered.

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

# Sprint 4 — Custom tools (HTTP + OpenAPI import)

**Track 4 (`synaplan/`), sprint 4 of 5.** Steps `TL28`–`TL37`.

**Goal:** A user declares "call our API" as a tool — method, URL template, headers, input schema, response
mapping, side-effect class, credential — or imports operations from an OpenAPI 3.x spec. The tool enters the
registry (`source: custom`), obeys the same `ApprovalPolicy` as every other tool, runs through `SsrfGuard`, and
can be shared (IAM kind `tool`) without exposing the owner's credential. No code, no scripting.
**Depends on:** S2 (policy, approvals). Track 1 S1 (`ShareableResourceKindInterface`, `BAUDITLOG`) for sharing
— behind an interface with an owner-only fallback. Track 2 S4 (assistant tool picker) consumes what this sprint
registers. Master plan §0 rows 7, 8, 12; §4.4; §12 rows 8, 9.
**Unlocks:** S5 (a tool is a workflow step); a plugin-free integration path for customers.
**Repos:** `synaplan/` only.
**Flag:** `TOOLS.CUSTOM_HTTP_ENABLED` (default off). Off ⇒ `CustomToolSource` returns `[]`, `/api/v1/tools/custom*` 404, no nav tab.

---

## 0. Why this sprint exists

A new integration today means PHP (a plugin) or an MCP server. Most requests are "create a ticket in our
helpdesk" / "look up a customer in our CRM": one HTTP call with a credential. A declarative tool covers that
without engineering, and because it is a registry entry it is governed like any other write-class action.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/Tool/` (S1), `Policy/ApprovalPolicy.php` (S2) | `ToolSourceInterface` to implement; class declared on the row, not derived |
| `backend/src/Service/Security/SsrfGuard.php` | `isBlockedUrl()`, `isBlockedHost()`, `isBlockedIp()` — call on the *resolved* host after template rendering |
| `backend/src/Service/Mcp/McpClient.php`, `McpOAuthDiscovery.php`, `backend/src/Service/UrlContentService.php` | Existing outbound HTTP through `SsrfGuard`; response cap and untrusted-content marker to reuse |
| `backend/src/Entity/Credential.php` (`BCREDENTIALS`: `BOWNERID`, `BKIND`, `BSECRET`), `Connection.php` (`BCONNECTIONS.BCREDENTIALID`) | Credential vault; add kind `http_header` |
| `backend/src/Controller/McpServerConfigController.php`, `SavedTaskController.php` | CRUD + OpenAPI style; URL validation through `SsrfGuard`; 404-for-other-owner pattern |
| `frontend/src/components/config/McpServersConfiguration.vue`, `ConnectionsConfiguration.vue`, `frontend/src/views/ConfigView.vue` | The Connections page the tab joins |
| `frontend/src/components/config/messagesGateway/GatewayActiveTools.vue` | How tools are shown to a user today |
| `_devextras/planning/202609_iam/00_master_plan.md` §4.2 | Kind contract for `ToolResourceKind` |

---

## 2. Developer steps

### 2.1 `TL28` — `BTOOLS` migration

```sql
CREATE TABLE IF NOT EXISTS BTOOLS (
  BID BIGINT NOT NULL AUTO_INCREMENT, BOWNERID BIGINT NOT NULL,
  BNAME VARCHAR(64) NOT NULL, BTITLE VARCHAR(191) NOT NULL, BDESCRIPTION TEXT NULL,
  BTYPE VARCHAR(16) NOT NULL DEFAULT 'http', BSIDEEFFECT VARCHAR(16) NOT NULL DEFAULT 'write',
  BSPEC JSON NOT NULL, BINPUTSCHEMA JSON NULL, BCREDENTIALID BIGINT NULL,
  BENABLED TINYINT(1) NOT NULL DEFAULT 1, BSOURCEREF VARCHAR(512) NULL,
  BCREATED BIGINT NOT NULL, BUPDATED BIGINT NOT NULL,
  PRIMARY KEY (BID), UNIQUE KEY uq_tools_owner_name (BOWNERID, BNAME), KEY idx_tools_credential (BCREDENTIALID)
);
```

Raw `addSql`. `BNAME` matches `^[a-z][a-z0-9_]{2,63}$` (model-facing tool name `custom:{name}`). Deleting a
credential sets `BCREDENTIALID = NULL` in application code (no FK cascade relied on).

### 2.2 `TL29` — executor, templates, registry source

`backend/src/Service/Tool/Custom/`: `CustomTool` entity + `CustomToolRepository`; `CustomToolSource implements
ToolSourceInterface` (`source: custom`, `ownerId`, `inputSchema = BINPUTSCHEMA`, class = `BSIDEEFFECT`); `HttpToolExecutor`:

1. `TemplateRenderer` accepts **only** `{{input.<key>}}`, `{{response.<key>}}`, `{{credential.header}}`; any other token, nested braces, filters or expressions ⇒ `InvalidToolTemplateException` at save time.
2. Render URL, headers, query, body from validated input (JSON Schema check).
3. Resolve the host (`dns_get_record`) and call `SsrfGuard::isBlockedIp()` on every resolved address plus `isBlockedUrl()` on the rendered URL; `https` required unless `TOOLS.CUSTOM_HTTP_ALLOW_PLAIN_HTTP` (admin, default off).
4. `HttpClientInterface` with `max_redirects: 0` (a 3xx is a failure with reason), `timeout: 15`, `max_duration: 20`, response capped at `TOOLS.CUSTOM_HTTP_MAX_RESPONSE_BYTES` (default 1 MiB, streamed and cut).
5. `{{credential.header}}` resolves from `BCREDENTIALS.BSECRET` (kind `http_header`, JSON `{ "name": "Authorization", "value": "Bearer …" }`) at send time only; the executor logs method, host, status, duration — never headers or body.
6. Response mapping (`response.summary`, `response.fields`) → `ToolResult` with `provenance: 'tool'`; the loops wrap it as untrusted content when it re-enters a prompt (existing `UrlContentService` marker).

### 2.3 `TL30` — CRUD routes

`CustomToolController`: `GET/POST /api/v1/tools/custom`, `GET/PATCH/DELETE /api/v1/tools/custom/{id}`. `CustomToolSpecValidator`
enforces the template subset, allowed spec keys (`method`, `url`, `headers`, `query`, `body`, `response`), method ∈
`GET HEAD POST PUT PATCH DELETE`, `BSIDEEFFECT` ∈ `read write destructive`, credential owned by the caller. Responses never
include the credential secret (only `credentialId` + label). Full OpenAPI; regenerate Zod.

### 2.4 `TL31` — "Try it"

`POST /api/v1/tools/custom/{id}/try` with `{ input }`: `read` executes and returns the mapped result plus the raw status;
`write` / `destructive` return the fully resolved request (method, URL, headers with the credential value replaced by `***`,
body) **without sending**. Rate-limited per user (`RateLimitService`, 30 / minute).

### 2.5 `TL32` — OpenAPI import

`OpenApiImporter`: `POST /api/v1/tools/custom/import-openapi/preview` accepts `{ url }` (fetched through `SsrfGuard`, ≤ 2 MiB)
or an uploaded JSON/YAML document (`symfony/yaml`, already a dependency). Parses OpenAPI 3.0/3.1 only; lists ≤ 200 operations
with `operationId`, summary, method, path, guessed class (`GET/HEAD → read`, `POST/PUT/PATCH → write`, `DELETE → destructive`)
and the request schema flattened to `BINPUTSCHEMA` (path + query + body properties). `/apply` takes the selected operations
with the owner's class adjustments and a credential, creates one row per operation (`BTYPE = openapi_op`,
`BSOURCEREF = {specUrl}#{operationId}`). `$ref` resolution is local-only; remote refs are rejected.

### 2.6 `TL33` — IAM kind `tool`

`ToolResourceKind implements ShareableResourceKindInterface` (tagged `app.iam.resource_kind`, track 1). `use` ⇒ the tool
appears in the caller's registry (`CustomToolSource::describe(userId)` unions owned and shared rows, `meta.shared = true`);
the call runs with the **owner's** credential and writes `BAUDITLOG` (`BACTION = tool.call`, `BACTORID = caller`,
`BRESOURCEID = toolId`). `edit` ⇒ CRUD. Without track 1 merged, `NullAccessGate` yields owner-only. A shared tool's
`sideEffect` is shown to the caller and cannot be changed by them (the declaration is reviewed by whoever shares).

### 2.7 `TL34` — editor UI

Tab **Custom tools** beside MCP servers on `/channels/connections` (`ConfigView.vue`). Components (each < 300 lines):
`CustomToolsConfiguration.vue` (list, enable toggle, share button), `CustomToolEditor.vue` (name, title, description, class as
three radio options in plain words, method, URL, headers, body, input fields → JSON Schema, response mapping, credential
picker), `CustomToolTryPanel.vue`, `OpenApiImportWizard.vue` (URL/upload → operation table with class dropdown → apply).
`customToolsApi.ts` on `httpClient` + Zod. Five locales: Custom tool / Try it / Reads data / Changes something / Deletes
something (master plan §5). `useDialog` for delete; tokens only; dark + V2 + 320px.

### 2.8 `TL35` — assistant tool picker seam (track 2 S4)

Descriptors from `GET /api/v1/tools` carry `title`, `sideEffect`, `source`, `shared`; track 2's picker filters `source: custom`
and stores the selection in the assistant definition (`tools.custom[]`, `tools.policy[tool]`). This step guarantees the contract
(serializer test) and adds the `?source=custom` filter; if track 2 S4 has not merged, it is the last PR of the sprint and waits.

### 2.9 `TL36` — C6 security tests

See §3.

### 2.10 `TL37` — `custom_tools` bundle section

`CustomToolsBundleSection implements BundleSectionInterface` (track 2 S6 registry,
[`../202609_agent_builder/06_sprint_6_portability_and_packs.md`](../202609_agent_builder/06_sprint_6_portability_and_packs.md)).
Exports `name`, `title`, `description`, `type`, `sideEffect`, `spec`, `inputSchema`, `sourceRef`, `enabled`; **never**
`BCREDENTIALID` or any secret. Import creates rows with `BENABLED = 0` when the spec references `{{credential.header}}` and adds
the checklist item "needs a credential". Keyed by owner-scoped `name`; unknown keys rejected.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C6 never bypass `SsrfGuard` | `HttpToolExecutorSsrfTest`: `{{input.host}}` rendering to `127.0.0.1`, `169.254.169.254`, `[::1]`, a hostname resolving to RFC 1918, and a redirect to an internal host all refuse; import spec URL to an internal host refuses |
| C6 credentials never leak | `HttpToolExecutorRedactionTest`: credential value absent from `BAPPROVALS.BARGS`, `BPREVIEW`, "Try it" output, logs (`TestLogger`), bundle export; `CustomToolControllerTest` responses carry `credentialId` only |
| C5 | Disabled or deleted tool ⇒ `ToolNotRegisteredException` in all loops |
| C2 / C3 | Untouched surfaces; `ApprovalPolicyTest` gains custom-tool rows (`destructive → block` by default) |
| C8 | Custom tools are not exposed through `McpServerFactory` (`McpServerToolsTest` asserts the tool list unchanged); `/v1` and Messages gateways only see custom tools when the user has them and the flag is on |

Also: `TemplateRendererTest` (subset accepted, everything else rejected), `OpenApiImporterTest` (3.0 + 3.1 fixtures, class
guessing, remote `$ref` rejected, 201st operation dropped with notice), `ToolResourceKindTest` (shared call runs with owner
credential, audit actor = caller), frontend specs for the four components, i18n parity for namespace `customTools`. Full gate both sides.

---

## 4. Exit criteria / demo

1. Flag off: no tab, routes 404, registry unchanged.
2. Flag on: create "Create helpdesk ticket" (POST, bearer credential, class "changes something"); "Try it" shows the resolved request with `***`; in chat the assistant proposes the tool, `ApprovalCard` appears, approve creates the ticket, the reply quotes `response.summary`.
3. Import the helpdesk OpenAPI spec: read operations run silently, `DELETE` operations are blocked by default.
4. Share the tool with the Support group (`use`): a member calls it, the ticket is created with the owner's credential, the audit row names the member.
5. Export → import on another instance: the tool arrives disabled with "needs a credential"; attaching one enables it.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| TL28 | `feat(tools): add BTOOLS migration for custom HTTP tools` | backend-only | — |
| TL29 | `feat(tools): add HttpToolExecutor with strict templates, SsrfGuard and registry source` | backend-only | TL28, S2 |
| TL30 | `feat(tools): add custom tool CRUD routes with spec validation` | backend-only | TL29 |
| TL31 | `feat(tools): add Try it endpoint for custom tools` | backend-only | TL30 |
| TL32 | `feat(tools): import OpenAPI 3.x operations as custom tools` | backend-only | TL30 |
| TL33 | `feat(tools): register IAM resource kind tool with owner-credential execution and audit` | backend-only | TL30, track 1 S1 |
| TL34 | `feat(tools): add Custom tools tab with editor, Try it and OpenAPI import wizard` | ota-candidate | TL31, TL32 |
| TL35 | `feat(tools): expose custom tool descriptors to the assistant tool picker` | backend-only | TL30, track 2 S4 |
| TL36 | `test(tools): prove SsrfGuard coverage and credential redaction for custom tools (C6)` | backend-only | TL33 |
| TL37 | `feat(tools): register custom_tools bundle section without credentials` | backend-only | TL30, track 2 S6 |

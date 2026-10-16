# Contracts — data, API and wire

Plan of record: [`00_master_plan.md`](./00_master_plan.md). Every section here
is cited by a step in [`04_sprints.md`](./04_sprints.md). A change to a
contract is a dated STATUS entry first, then a PR.

---

## 1. Install-wide MCP servers (OP1)

### 1.1 Data

| What | Where | Notes |
| ---- | ----- | ----- |
| The server | `BMCPSERVERS`, `BUSERID = 0` | Same columns as a person's row. `BAUTHMODE` `none` or `bearer` only (no OAuth in v1). |
| Who may use it | everyone with the flag on, minus people who switched it off | §1.2 |
| Off for one person | BCONFIG `BOWNERID = <person>`, `MCP` / `OFF_FOR_ME`, JSON list of server ids | Written only through `PATCH …/for-me` |
| Server options and signing secret | BCONFIG `BOWNERID = 0`, `MCP` / `SERVER.<id>`, encrypted (`EncryptionService`) JSON `{"signingSecret": "<64 hex>", "shareEmail": false}` | Deleted with the row |
| Who added or changed it | `AuditLogWriter`: `mcp_server.created`, `.updated`, `.deleted`, `.tested`, `.secret_rotated` with the admin as actor | Admin → Audit |
| Phase 2: declared by a plugin | `BSOURCE = plugin:<id>:<key>` (§0 #10, ask first) | §4.3 |

`BUSERID = 0` keeps every existing lookup safe: `findByIdAndUser($id, $person)`
never matches an install-wide row, so a call site that is not switched keeps
today's behaviour.

### 1.2 Who sees a server

With `MCP.INSTANCE_SERVERS_ENABLED` off: a person's own enabled rows (today).

With it on, for person P:

```text
visible(P) = own rows of P (enabled)
           ∪ install-wide rows (enabled, BSOURCE null, id ∉ OFF_FOR_ME(P))
           ∪ plugin rows (enabled, plugin installed for P, id ∉ OFF_FOR_ME(P))   ← Phase 2
```

New repository methods (OP1): `findVisibleToUser(int $userId): list<McpServerConfig>`,
`findUsableForUser(int $id, int $userId): ?McpServerConfig` (a visible row or
null), `findInstanceWide(): list<McpServerConfig>`. A server name is unique per
person; an install-wide name may equal a person's own name — the person's own
row wins in name lookups (bundles, Saved Task import), the id is unambiguous.

### 1.3 Call sites

Switch to `findVisibleToUser` / `findUsableForUser` — these resolve a server
for **use**:

| Call site | Today |
| --------- | ----- |
| `Service/Mcp/McpToolRegistry::catalogForUser()` | `findEnabledByUser` |
| `Service/Message/ConnectedSystemsHint` | `findEnabledByUser` |
| `Service/Mcp/ConfluenceLinkedPageReader`, `JiraLinkedIssueReader` | `findEnabledByUser` |
| `Service/Multitask/Execution/Runner/McpFetchRunner`, `McpActionRunner`, `ToolCallRunner::runMcp()` | `findByIdAndUser` |
| `AI/Messages/Tools/GatewayToolLoop`, `AI/OpenAI/OpenAiGatewayToolLoop` | `findByIdAndUser` |
| `MessageHandler/ResumeApprovalCommandHandler` | `findByIdAndUser` (owner of the approval) |
| `Controller/MessagesGatewayController` (`mcp_servers_configured`) | `findEnabledByUser` count |
| `Service/Chat/ConnectedAppProbe` (`mcpCount`) | `findByUser` count |
| `Service/SavedTask/Graph/SavedTaskGraphPortability` | `findByIdAndUser` (export), `findByUserAndName` (import: own first, then install-wide) |
| `Bundle/Section/AgentBundleSection` (name ↔ id for `tools.mcpServers`) | `findByUser` |

Keep **own rows only** — these manage a person's own connections:
`McpServerConfigController` CRUD, `McpOAuthController`,
`McpOAuthConsentService`, `McpOAuthTokenProvider`, `McpServersBundleSection`
(a person's export never carries an administrator's server),
`Connection/ConnectionService` (decide in OP5 whether the connections list
shows install-wide rows read-only).

### 1.4 API

All endpoints carry full OpenAPI annotations; `make -C frontend
generate-schemas` follows.

**Person** (existing controller, additive):

```http
GET /api/v1/mcp-servers
→ 200 { "success": true, "client_enabled": true, "oauth_connectors_enabled": false,
        "instance_servers_enabled": true,
        "servers":  [ …own rows, unchanged… ],
        "everyone": [ { "id": 12, "name": "Word tools", "host": "word-tools",
                        "managed_by": "admin", "plugin": null,
                        "allow_write": false, "enabled_for_me": true,
                        "tools": [ { "name": "word_stats", "description": "…" } ] } ] }

PATCH /api/v1/mcp-servers/{id}/for-me   { "enabled": false }
→ 200 { "success": true, "enabled_for_me": false }
→ 404 when the row is not install-wide or not visible
```

`host` is the host name only — never the full URL, never auth values. With the
flag off, `everyone` is `[]` and the `PATCH` answers 404 (U11).

**Administrator** (new `AdminMcpServersController`, `ROLE_ADMIN`; restricted
API keys never reach `/api/v1/admin/*`):

```http
GET    /api/v1/admin/mcp-servers
POST   /api/v1/admin/mcp-servers                 { name, url, auth_header?, auth_token?, allow_write?, share_email? }
       → 201 { success, server, signing_secret }  ← the only response that carries the secret
PATCH  /api/v1/admin/mcp-servers/{id}            (same fields; absent auth_token = keep, "" = clear)
DELETE /api/v1/admin/mcp-servers/{id}
POST   /api/v1/admin/mcp-servers/{id}/test       → { success, tools[] | error }   (live discovery, signed as "system")
POST   /api/v1/admin/mcp-servers/{id}/rotate-secret → { success, signing_secret }
```

---

## 2. Network rule by origin (OP2)

Decided after DNS resolution; every answer of a name counts.

| Target | A person's server | An administrator's server | Administrator's, `MCP_PUBLIC_SERVERS_ONLY=1` |
| ------ | ----------------- | ------------------------- | -------------------------------------------- |
| Public address | allowed | allowed | allowed |
| 10/8, 172.16/12, 192.168/16, fc00::/7, 100.64/10 | refused | allowed | refused |
| Loopback: 127/8, ::1, `localhost`, `*.localhost` | refused | **refused** | refused |
| 169.254/16, fe80::/10 (cloud metadata) | refused | refused | refused |
| Every other reserved range (0/8, multicast, documentation, 240/4, …) | refused | refused | refused |
| A name whose answers include any refused address | refused | refused | refused |
| Scheme `http://` | as today | only when every answer is an organisation-network address | — |

**Why loopback stays refused** although `SsrfGuard::isOperatorNetworkIp()`
includes it: from the backend container, loopback is the container itself, not
a separate service. On the dev stack Caddy's admin API answers on
`127.0.0.1:2019` there (a GET of `/config/` returned the live configuration,
2026-10-16), and that API has mutating routes (`/load`, `/stop`). The MCP
client POSTs to exactly the configured URL, so a loopback target is never
allowed for MCP servers or plugin services.

Rules:

- `SsrfGuard` gains `operatorTargetIps(string $host): list<string>` — the
  answers when every one of them is public or organisation-network **and not
  loopback**, else `[]`. `isBlockedIp()` and `isOperatorNetworkIp()` stay the
  single truth for everything else.
- New `Service/Mcp/McpTargetResolver::resolve(McpServerConfig): McpTarget`
  (url, host, pinned ip) applies the table by row scope and the kill switch.
- Every request of a session — `initialize`, `notifications/initialized`, the
  operation, the `DELETE` — uses `resolve => [host => pinned ip]` and
  `max_redirects: 0` (§0 #6). Literal IP hosts need no pin.
- `MCP_PUBLIC_SERVERS_ONLY` is an env flag read once (container parameter,
  default `false`), documented next to `OPENAI_COMPATIBLE_PUBLIC_UPSTREAMS_ONLY`
  in `backend/.env.example` and `docs/CONFIGURATION.md`.
- Saving re-checks with the same resolver; the admin sees the reason
  (copy in [`03_journeys.md`](./03_journeys.md) §5).

Test matrix (unit, literal IPs as `McpClientTest` does today): every row of
the table × {person, administrator, administrator + kill switch}, plus a name
with mixed answers (resolver double), plus "the request goes to the pinned
address" (MockHttpClient sees `resolve`).

---

## 3. Signed caller identity (OP3)

### 3.1 Headers

| Header | Value |
| ------ | ----- |
| `X-Synaplan-Timestamp` | Unix seconds at signing |
| `X-Synaplan-User` | `u_` + first 32 hex of HMAC-SHA256(secret, `"user:" + person id`); `system` for discovery and the admin's **Test** |
| `X-Synaplan-User-Email` | only for an administrator-added server with "Share the person's email" on |
| `X-Synaplan-Signature` | `sha256=` + hex HMAC-SHA256(secret, `timestamp + "\n" + user + "\n" + email + "\n" + raw body`); `email` is the empty string when the header is absent |

- Sent only to install-wide and plugin-declared rows; a person's own servers
  get none of these headers.
- The body is the exact bytes sent. `McpClient::post()` encodes the JSON once
  and sends it as `body` with `Content-Type: application/json` (today it passes
  `json`, which lets the client encode). `DELETE` and other bodiless requests
  sign the empty string.
- The receiving side accepts ±60 s (as `VoiceLiveInternalAuth`) and compares
  in constant time. Synaplan never sends the secret.
- **Rotation** replaces the secret at once; the service must be given the new
  one (copy in `03` §5). No overlap window in v1.
- The acting person reaches `McpClient`: `callTool(McpServerConfig $server,
  string $tool, array $arguments, ?int $actingUserId = null)` — `null` signs as
  `system`. Every runner and gateway loop passes the person the tool runs for
  (for a Saved Task: its owner).

### 3.2 Test vector

```text
secret     3f9a1c0e7b2d4a6f8e1c3b5a7d9f0e2c4b6a8d0f1e3c5b7a9d1f3e5c7b9a0d2f   (used as UTF-8 bytes)
person id  2
timestamp  1792130000
user       u_b541fdf0b220867c3e06d933ca59d3be
email      (none)
body       {"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"word_stats","arguments":{"text":"hello world"}}}
signature  sha256=bed56f197af1b29e59ddd3c8ad79610b45bc474c8752c0ccfdce7673bdfa8fbc
```

Computed on 2026-10-16 with PHP `hash_hmac` and Python `hmac`; the three
snippets below accept it and reject a changed body and a timestamp 100 s off.

### 3.3 Verification on the service side

Python:

```python
import hashlib, hmac, time


def verify_synaplan(headers: dict, body: bytes, secret: str, now: float | None = None) -> bool:
    ts = headers.get("x-synaplan-timestamp", "")
    user = headers.get("x-synaplan-user", "")
    email = headers.get("x-synaplan-user-email", "")
    sig = headers.get("x-synaplan-signature", "")
    if not ts.isdigit() or abs((now or time.time()) - int(ts)) > 60:
        return False
    base = f"{ts}\n{user}\n{email}\n".encode() + body
    expected = "sha256=" + hmac.new(secret.encode(), base, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, sig)
```

Node.js:

```js
import { createHmac, timingSafeEqual } from 'node:crypto'

export function verifySynaplan(headers, body, secret, now = Date.now() / 1000) {
  const ts = headers['x-synaplan-timestamp'] ?? ''
  const user = headers['x-synaplan-user'] ?? ''
  const email = headers['x-synaplan-user-email'] ?? ''
  const sig = headers['x-synaplan-signature'] ?? ''
  if (!/^\d+$/.test(ts) || Math.abs(now - Number(ts)) > 60) return false
  const base = Buffer.concat([Buffer.from(`${ts}\n${user}\n${email}\n`), Buffer.from(body)])
  const expected = 'sha256=' + createHmac('sha256', secret).update(base).digest('hex')
  return expected.length === sig.length && timingSafeEqual(Buffer.from(expected), Buffer.from(sig))
}
```

Go:

```go
func VerifySynaplan(h http.Header, body []byte, secret string, now time.Time) bool {
	ts := h.Get("X-Synaplan-Timestamp")
	sec, err := strconv.ParseInt(ts, 10, 64)
	if err != nil || now.Unix()-sec > 60 || sec-now.Unix() > 60 {
		return false
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(ts + "\n" + h.Get("X-Synaplan-User") + "\n" + h.Get("X-Synaplan-User-Email") + "\n"))
	mac.Write(body)
	expected := "sha256=" + hex.EncodeToString(mac.Sum(nil))
	return hmac.Equal([]byte(expected), []byte(h.Get("X-Synaplan-Signature")))
}
```

The services read the secret from their own environment
(`SYNAPLAN_SIGNING_SECRET` by convention in the docs and examples).

---

## 4. Manifest v2 additions (OP7)

### 4.1 Example — a plugin without PHP

```text
plugins/word_tools/
  manifest.json
  locales/de.json en.json es.json fr.json tr.json
  settings.schema.json
```

```json
{
  "id": "word_tools",
  "name": "word_tools",
  "version": "1.0.0",
  "description": "Text statistics from a Python service",
  "chatCommands": [
    { "command": "words", "endpoint": "/svc/chat", "description": "Count the words in a text" }
  ],
  "provides": {
    "mcpServers": [
      { "key": "word_tools", "label": "Word tools", "url": "env:WORD_TOOLS_MCP_URL", "allowWrite": false }
    ],
    "service": { "url": "env:WORD_TOOLS_URL", "health": "/health" },
    "settings": "settings.schema.json",
    "context": { "path": "/context", "channels": ["web", "widget"] },
    "plugs": [
      { "port": "web_search", "transport": "http", "key": "word_tools_search", "path": "/search", "label": "Word tools search" }
    ]
  },
  "permissions": ["network:operator"]
}
```

### 4.2 Fields

| Field | Rule | Error names |
| ----- | ---- | ----------- |
| `provides.mcpServers[].key` | `^[a-z0-9][a-z0-9_-]*$`, unique in the plugin | `provides.mcpServers[i].key` |
| `provides.mcpServers[].url`, `provides.service.url` | `env:<NAME>`; the variable is read at enable time; unset ⇒ the server or service is absent, listed by `app:plugin:list` as "not configured: WORD_TOOLS_URL is not set" | `…url` |
| `provides.mcpServers[].allowWrite` | boolean, default `false`; destructive tools stay blocked regardless | `…allowWrite` |
| `provides.service.health` | relative path, probed by `HttpSidecarHealthProbe` | `provides.service.health` |
| `provides.plugs[].transport` | absent = PHP class (today); `"http"` = remote adapter (§6), then `class` is not allowed and `path` is required | `provides.plugs[i].transport` |
| `provides.context` | `path` required; `channels` ⊆ `web`, `widget`, `whatsapp`, `email`, `telegram` | `provides.context.channels` |
| `provides.settings` | relative path to a JSON Schema file inside the plugin (§7) | `provides.settings` |
| `locales/` | `de`, `en`, `es`, `fr`, `tr` files; `en` required when `settings` or any label is a key | `locales/en.json` |
| `permissions` | `network:operator` is required before a declared URL may resolve to the organisation network; other values stay documentation | `permissions` |
| `chatCommands[].endpoint` | may start with `/svc/` (goes through §5) | — |
| `chatCommands[].tool` | ignored from OP10 on, with a log line naming the plugin | — |

### 4.3 What enabling a plugin does

`app:plugin:install`, `app:plugin:install-verified-users` and new-account
provisioning keep their per-person meaning. Additionally (OP7):

- Each declared MCP server becomes one install-wide row with
  `BSOURCE = plugin:<id>:<key>` (upsert by `BSOURCE`, so re-running is safe);
  it is visible to the people who have the plugin installed (§1.2) and signs
  with the plugin's secret (§5), so the operator configures one secret per
  plugin.
- Uninstalling for the last person leaves the row disabled, not deleted, so a
  re-install keeps its id (assistants and Saved Tasks reference ids).
- The kernel loads nothing for a plugin without `backend/` (unchanged); the
  manifest alone drives §4–§7.

---

## 5. The checked route to a plugin's service (OP8)

```http
GET|POST|PUT|PATCH|DELETE /api/v1/user/{userId}/plugins/{name}/svc/{path}
```

Checked in this order — the first failure answers:

1. `PLUGINS.SERVICES_ENABLED` on, else 404.
2. The plugin is installed for the signed-in person and `{userId}` is that
   person, else 404 (core does what each PHP controller does by hand today).
3. The manifest declares `provides.service` and its env URL is set, else 404.
4. The target passes §2 for an administrator's server (organisation network
   only with `network:operator`), pinned.

Forwarded: method, `{path}`, query string, `Content-Type`, the body (≤ 1 MiB).
Removed: `Cookie`, `Authorization`, every incoming `X-Synaplan-*`. Added: the
§3 headers signed with the plugin's secret — one per plugin, created in OP7,
stored encrypted in BCONFIG `PLUGIN_SERVICE` / `<id>`, shared by the plugin's
MCP rows and its service; `app:plugin:secret <id> [--rotate]` prints it once
for the operator.

Answered: 2xx and 4xx pass through; 5xx, timeout (15 s) or an unreachable
service become 502 with the copy of `03` §5. Response ≤ 5 MiB; `Content-Type`
must be `application/json`, `text/plain`, `text/markdown`, `image/png`,
`image/jpeg`, `image/gif` or `image/webp`, else 502. Every proxied response
gets `X-Content-Type-Options: nosniff` and `Content-Security-Policy: sandbox`.
No redirects, no streaming in v1.

API keys: the route sits under `/api/v1/user/{id}/plugins`, which
`ApiKeyScope` already maps to `messages:*` / `chat`.

Slash commands need no frontend change: `ChatView.vue` already posts to
`/api/v1/user/{id}/plugins/{name}{endpoint}`, so `"endpoint": "/svc/chat"`
reaches the service with `{ message, chatId, lang }` and renders its
`{ message }`.

---

## 6. HTTP contracts for plug ports and chat context (OP9)

All calls: `POST <service url><path>`, JSON, signed (§3) as the person when
there is one, else `system`; timeout per port as its built-in adapters use it.
A 5xx or timeout throws, so the registry falls back to the next provider
(`plugins/README.md` rule 4); an empty answer is a valid "nothing found".

**`web_search`** — from `WebSearchQuery` / `SearchResultSet`:

```json
{ "query": "solar subsidies 2026", "options": { "count": 5, "language": "de", "freshness": "pm", "site": null }, "wantAnswer": false }
```

```json
{ "results": [ { "title": "…", "url": "https://…", "description": "…", "kind": "web", "content": null, "publishedAt": null } ],
  "answer": null }
```

Capabilities (`WebSearchCapabilities`) come from the declaration:
`"capabilities": { "freshness": true, "country": false, "language": true, "siteFilter": true, "fullContent": false, "answer": false }`.

**`extraction`** — `multipart/form-data`: `file` (the bytes), `mime`, `ext`,
`family`, `describe`. The signature covers the raw multipart body. Answer:

```json
{ "text": "…", "markdown": null, "meta": { "pages": 3 }, "pages": null }
```

The strategy is recorded as `plugin:<id>`. `supports()` reads `"mimes"` /
`"families"` from the declaration (config only). The administrator adds the
adapter to a family's chain under Admin → Plugs → Extraction, as for built-in
extractors.

**`rerank`** — from `RerankProviderInterface`:

```json
{ "query": "…", "candidates": [ { "id": "c1", "text": "…" } ], "topK": 8 }
```

```json
{ "hits": [ { "id": "c1", "score": 0.91 } ] }
```

The latency budget (`RerankOptions::latencyBudgetMs`, default 800 ms) is the
timeout.

**Chat context** — from `PluginContextProviderInterface`, for the channels the
declaration lists and only when the person has the plugin installed:

```json
{ "message": "…", "classification": { "topic": "general", "language": "de", "source": "web" }, "channel": "web" }
```

```json
{ "context": "markdown, at most 4000 characters" }
```

Synaplan wraps it as `--- BEGIN PLUGIN CONTEXT: <label> ---` … `--- END
PLUGIN CONTEXT ---` (the castingdata shape), cuts at 4000 characters and treats
it as untrusted text. Timeout 3 s; a failure adds nothing.

---

## 7. Settings from a schema (OP11)

- `settings.schema.json` is a JSON Schema subset: `type: object` with
  `properties` of `string` (optional `format: uri`, `maxLength`), `number`,
  `integer`, `boolean`, `enum`, and `x-synaplan-secret: true` for write-only
  fields; `required`; `default`. Anything else is a manifest error.
- Labels and help are locale keys: `"title": "settings.api_url.label"`,
  resolved from `locales/<code>.json` for the person's language, English as
  fallback.
- Stored per person in `plugin_data` (`PluginDataService`, type `config`);
  secrets encrypted and never returned (the API answers `has_<field>: true`).
- API: `GET|PUT /api/v1/user/{userId}/plugins/{name}/settings` (schema,
  resolved labels, values), optional `POST …/settings/test` forwarded to the
  service as `POST /svc/settings/test`; the service answers
  `{ "ok": bool, "message": "one sentence" }`.
- `PluginView.vue` renders the form when the plugin declares `settings` and
  ships no `frontend/index.js`, with the house form controls and buttons
  (AGENTS.md).

---

## 8. Isolated views (Phase 3, after OP-S1)

- The frame: `<iframe sandbox="allow-scripts" srcdoc=…>` — never
  `allow-same-origin`, `allow-top-navigation`, `allow-popups-to-escape-sandbox`
  or `allow-forms` posting to the app — with a CSP meta like
  `frontend/src/utils/chatArtifacts.ts` plus the `connect`/`resource` domains
  the MCP App requested and an administrator allowed.
- Host → view: theme (light / dark and the token values it needs), locale,
  the tool input and result.
- View → host: size, "open this link" (the host asks first), "put this text
  in the composer" (never sends on its own), "call a tool" (through
  `ToolExecutionGate`, so approvals apply).
- Never passed: the session, cookies, tokens, other chats, the person's id
  (only the §3 pseudonym).
- OP-S1 maps these messages onto the MCP Apps extension
  (`io.modelcontextprotocol/ui`) and records the spec revision the SDKs ship.

---

## 9. Developer quickstart (seed for OP6)

The three servers in [`spike/servers/`](./spike/servers/) are the minimal
examples (Python 15 lines, Node.js and Go about 30). OP6 turns them into
`docs/PLUGINS.md` and runnable examples with:

1. the server (official SDK, stateless Streamable HTTP on `/mcp`, tools with
   `readOnlyHint` / `destructiveHint` set honestly);
2. the signature check of §3.3 in front of the MCP endpoint;
3. a compose snippet that joins the Synaplan network and sets
   `SYNAPLAN_SIGNING_SECRET`;
4. Apps → MCP → Add server → "Everyone on this Synaplan" (J-OP-1);
5. asking in chat with "MCP data sources" on for the topic (J-OP-2).

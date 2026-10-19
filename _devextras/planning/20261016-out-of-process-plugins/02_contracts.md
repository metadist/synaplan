# Contracts — data, API and wire

Plan of record: [`00_master_plan.md`](./00_master_plan.md). Every section here
is cited by a step in [`04_sprints.md`](./04_sprints.md). A change to a
contract is a dated STATUS entry first, then a PR. Sections §1–§9 keep the
numbers of the 2026-10-16 draft; §3 was rewritten and §10–§13 added by the
2026-10-18 review ([`05_review.md`](./05_review.md)).

---

## 1. Shared MCP servers (OP1)

### 1.1 Data

| What | Where | Notes |
| ---- | ----- | ----- |
| The server | `BMCPSERVERS`, `BUSERID = 0` | Same columns as a person's row. `BAUTHMODE` `none`, `bearer` or `synaplan` ("Synaplan signs each request", §3); no OAuth in v1. `synaplan` is refused on a person's row. |
| Who may use it | everyone with the flag on, minus people who switched it off | §1.2 |
| Off for one person | BCONFIG `BOWNERID = <person>`, `MCP` / `OFF_FOR_ME`, JSON list of server ids | Written only through `PATCH …/for-me` |
| Server options | BCONFIG `BOWNERID = 0`, `MCP` / `SERVER.<id>`, JSON `{"shareEmail": false, "accepted": {"<tool>": "<hash>"}}` | Deleted with the row. `hash` = SHA-256 of `name + "\n" + description + "\n" + canonical JSON of inputSchema` (§1.5) |
| Who added or changed it | `AuditLogWriter`: `mcp_server.created`, `.updated`, `.deleted`, `.tested`, `.tools_accepted` with the admin as actor; `plugin.registered`, `.changed`, `.removed` with the system as actor (OP18) | Admin → Audit |
| Declared by a plugin | `BSOURCE = plugin:<id>:<key>` (§0 #10, ask first) | §4.3, §12.4 |

`BUSERID = 0` keeps every existing lookup safe: `findByIdAndUser($id, $person)`
never matches a shared row, so a call site that is not switched keeps today's
behaviour.

### 1.2 Who sees a server

With `MCP.INSTANCE_SERVERS_ENABLED` off: a person's own enabled rows (today).

With it on, for person P:

```text
visible(P) = own rows of P (enabled)
           ∪ shared rows (enabled, BSOURCE null, id ∉ OFF_FOR_ME(P))
           ∪ plugin rows (enabled, plugin not switched off by an administrator, id ∉ OFF_FOR_ME(P))   ← Phase 2
```

New repository methods (OP1): `findVisibleToUser(int $userId): list<McpServerConfig>`,
`findUsableForUser(int $id, int $userId): ?McpServerConfig` (a visible row or
null), `findInstanceWide(): list<McpServerConfig>`. A server name is unique per
person; a shared name may equal a person's own name — the person's own row
wins in name lookups (bundles, Saved Task import), the id is unambiguous.

### 1.3 Call sites

Switch to `findVisibleToUser` / `findUsableForUser` — these resolve a server
for **use**:

| Call site | Today |
| --------- | ----- |
| `Service/Mcp/McpToolRegistry::catalogForUser()` | `findEnabledByUser` |
| `Service/Message/ConnectedSystemsHint` | `findEnabledByUser` |
| `Service/Mcp/ConfluenceLinkedPageReader`, `JiraLinkedIssueReader` | `findEnabledByUser`; both call `listTools()` live, outside the registry cache |
| `Service/Multitask/Execution/Runner/McpFetchRunner`, `McpActionRunner`, `ToolCallRunner::runMcp()` | `findByIdAndUser` |
| `AI/Messages/Tools/GatewayToolLoop`, `AI/OpenAI/OpenAiGatewayToolLoop` | `findByIdAndUser` |
| `MessageHandler/ResumeApprovalCommandHandler` | `findByIdAndUser` (owner of the approval) |
| `Controller/MessagesGatewayController` (`mcp_servers_configured`) | `findEnabledByUser` count |
| `Service/Chat/ConnectedAppProbe` (`mcpCount`) | `findByUser` count |
| `Service/SavedTask/Graph/SavedTaskGraphPortability` | `findByIdAndUser` (export), `findByUserAndName` (import: own first, then shared) |
| `Bundle/Section/AgentBundleSection` (name ↔ id for `tools.mcpServers`) | `findByUser` |

Keep **own rows only** — these manage a person's own connections:
`McpServerConfigController` CRUD, `McpOAuthController`,
`McpOAuthConsentService`, `McpOAuthTokenProvider`, `McpServersBundleSection`
(a person's export never carries an administrator's server),
`Service/Mcp/AtlassianToolArguments` (OAuth rows; passes the acting person in
OP3), `Service/Iam/Erasure/AccountRecordsEraser` (erasing a person never
touches `BUSERID = 0`; it does erase the person's `OFF_FOR_ME`),
`Connection/ConnectionService` (decide in OP5 whether the connections list
shows shared rows read-only).

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
                        "allow_write": false, "shares_email": false, "enabled_for_me": true,
                        "tools": [ { "name": "word_stats", "description": "…" } ] } ] }

PATCH /api/v1/mcp-servers/{id}/for-me   { "enabled": false }
→ 200 { "success": true, "enabled_for_me": false }
→ 404 when the row is not shared or not visible
```

`managed_by` is `admin` or `plugin`; `plugin` is `{ "id": "word_tools",
"label": "Word tools" }` or null. `tools` lists accepted tools only (§1.5).
`host` is the host name only — never the full URL, never auth values. With the
flag off, `everyone` is `[]` and the `PATCH` answers 404 (U11).

**Administrator** (new `AdminMcpServersController`, `ROLE_ADMIN`; restricted
API keys never reach `/api/v1/admin/*`):

```http
GET    /api/v1/admin/mcp-servers                 → rows incl. plugin rows, health, pending tool changes
POST   /api/v1/admin/mcp-servers                 { name, url, auth_mode: none|bearer|synaplan, auth_header?, auth_token?, allow_write?, share_email? }
       → 201 { success, server, trust: { jwks_url, audience } }    ← not secret; shown again on the row any time
PATCH  /api/v1/admin/mcp-servers/{id}            (same fields; absent auth_token = keep, "" = clear; plugin rows: enabled only)
DELETE /api/v1/admin/mcp-servers/{id}            → 409 for a plugin row whose folder is still there ("remove the plugin's folder first")
POST   /api/v1/admin/mcp-servers/{id}/test       → { success, tools[], pending[] | error }   (live discovery as "system")
POST   /api/v1/admin/mcp-servers/{id}/accept     { tools: [names] } → { success, tools[] }
```

`trust.jwks_url` is `<APP_URL>/.well-known/synaplan-jwks.json` (a service on
the `plugins` network uses `http://synaplan/.well-known/synaplan-jwks.json`);
`trust.audience` is the server's URL as saved.

### 1.5 Accepted tools (§0 #24)

- Discovery still lists everything; `McpToolRegistry` offers only accepted
  tools.
- **An administrator's row accepts by hash.** **Test** or save accepts every
  tool listed at that moment. A tool with a new name or a changed definition
  is **pending**: not offered, counted on the Admin row (J-OP-8), accepted with
  `POST …/accept`.
- **A plugin row accepts by name**: the names in
  `provides.mcpServers[].tools`, or, when the list is absent, the names listed
  at first registration — whatever their descriptions, because the operator
  ships folder and image together. A name outside that set is pending.
- Write tools stay subject to `allow_write` and the approval rules either way.
- A person's own servers are unchanged: they offer everything they list.

---

## 2. Network rule by origin (OP2)

Decided after DNS resolution; every answer of a name counts. Plugin rows
(Phase 2) use the administrator's column. Addresses on the `plugins` network
are Docker bridge addresses (RFC 1918), so they pass.

| Target | A person's server | An administrator's or a plugin's server | Shared, `MCP_PUBLIC_SERVERS_ONLY=1` |
| ------ | ----------------- | --------------------------------------- | ----------------------------------- |
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
- Every request — in the 2025 era `initialize`, `notifications/initialized`,
  the operation and the `DELETE`; in the 2026 era the one request — uses
  `resolve => [host => pinned ip]` and `max_redirects: 0` (§0 #6). Literal IP
  hosts need no pin.
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

## 3. The call token (OP3)

Replaces the HMAC header set of the 2026-10-16 draft, whose pseudonym the
service could reverse and whose signature did not bind the request
(`05_review.md` R2, R3).

### 3.1 The token

Sent as `Authorization: Bearer <token>` on every HTTP request to a shared row
with `BAUTHMODE = synaplan`, and on every request through the service proxy
(§5) and the plug-port adapters (§6).

Header: `{"typ": "synaplan-call+jwt", "alg": "ES256", "kid": "<kid>"}`.

| Claim | Value |
| ----- | ----- |
| `iss` | `APP_URL` of this Synaplan |
| `aud` | The URL Synaplan calls, as saved on the row or declared in the manifest (`http://word-tools:8000/mcp`); for the service proxy and plug ports the service URL |
| `sub` | The pseudonym of §3.3, or `system` for discovery, **Test** and work no person started |
| `email` | Only for an administrator's server with "Share the person's email" on |
| `iat`, `exp` | Unix seconds; `exp` = `iat` + 60 |
| `jti` | A UUID v4, unique per request; Synaplan logs it with the server id, the tool, the duration and the outcome |
| `htm` | The HTTP method |
| `htu` | The request URL without query and fragment |
| `bh` | base64url (no padding) of the SHA-256 of the exact body bytes; the empty body hashes the empty string |

- `McpClient` encodes the JSON once and sends those bytes as `body` with
  `Content-Type: application/json` (today it passes `json`, which lets the
  client encode), and mints `bh` over exactly those bytes.
- One token per HTTP request. In the 2025 era `initialize`,
  `notifications/initialized`, the operation and the `DELETE` each get their
  own.
- The acting person reaches `McpClient`: `callTool(McpServerConfig $server,
  string $tool, array $arguments, ?int $actingUserId = null)` — `null` means
  `system`. Every runner and gateway loop passes the person the tool runs for
  (for a Saved Task: its owner; for an approval: the approval's owner).
- The token is never logged or stored. A person's own servers never get one.

### 3.2 Keys and the published key set

- An installation key pair on P-256, generated on first use. The private key
  is encrypted with `EncryptionService` in BCONFIG `BOWNERID = 0`, `MCP` /
  `CALL_KEYS` (JSON list of `{kid, private, publicJwk, createdAt, retiredAt}`).
  Generation is race-safe across web nodes (insert if missing, then re-read).
- `kid` is the RFC 7638 thumbprint of the public JWK.
- `GET /.well-known/synaplan-jwks.json` (no authentication, `Cache-Control:
  public, max-age=300`) answers `{"keys": [ …public JWKs with kid, alg ES256,
  use sig… ]}`: the active key and keys retired less than 15 minutes ago.
- `php bin/console app:mcp:rotate-call-key` makes a new key active and retires
  the old one. Verifiers fetch the set again when they meet an unknown `kid`
  (at most once a minute), so rotation needs nothing on the service side.
- Minted with `firebase/php-jwt` (`JWT::encode($claims, $key, 'ES256', $kid,
  ['typ' => 'synaplan-call+jwt'])`), as `AppleClientSecretGenerator` does.

### 3.3 The pseudonym

```text
sub = "u_" + first 32 hex of HMAC-SHA256(pseudonym key, scope + "\n" + "user:" + person id)
```

- The pseudonym key is 32 random bytes, generated on first use, stored
  encrypted in BCONFIG `MCP` / `PSEUDONYM_KEY`, never returned by any API and
  never sent anywhere. It does not rotate with the call keys, so a person
  keeps their pseudonym at a service.
- `scope` is `mcp:<serverId>` for an administrator's server and `plugin:<id>`
  for every server and the service of one plugin: one plugin sees one person
  the same way everywhere, two plugins cannot link their pseudonyms.

### 3.4 Verification on the service side

1. Take the token from `Authorization: Bearer`.
2. Read the header: `typ` must be `synaplan-call+jwt`, `alg` must be `ES256`;
   find the key by `kid` in the key set from `SYNAPLAN_JWKS_URL` (cached; fetch
   again on an unknown `kid`).
3. Verify the signature; check `aud` equals `SYNAPLAN_AUDIENCE` (the service's
   own URL as Synaplan calls it); check `exp` and `iat` with 30 s leeway;
   check `iss` when `SYNAPLAN_ISSUER` is set.
4. Check `htm` equals the request method, `htu` the request URL, and `bh` the
   SHA-256 of the raw body.
5. Use `sub` as the person's stable id and `email` when present. A service that
   changes things may refuse a `jti` it has seen in the last two minutes.

Neither `SYNAPLAN_JWKS_URL` nor `SYNAPLAN_AUDIENCE` is secret. A plugin's
compose file sets both (§12.2); for an administrator's server the row shows
them (§1.4). The health path (`provides.service.health`) answers without a
token, because `HttpSidecarHealthProbe` counts 401 / 403 as down.

The tested implementations, with a pinned "now", are
[`spike-2/identity/`](./spike-2/identity/): PHP (`firebase/php-jwt`), Python
(PyJWT), Node.js (WebCrypto, no package) and Go (standard library). The
Python core:

```python
def verify(jwks: dict, token: str, audience: str, method: str, url: str, body: bytes, now: float) -> dict:
    header = jwt.get_unverified_header(token)
    if header.get("typ") != "synaplan-call+jwt":
        raise ValueError("wrong typ")
    key = next(jwt.PyJWK(k) for k in jwks["keys"] if k["kid"] == header.get("kid"))
    claims = jwt.decode(token, key, algorithms=["ES256"], audience=audience,
                        options={"require": ["exp", "iat", "sub", "jti", "htm", "htu", "bh"],
                                 "verify_exp": False, "verify_iat": False})
    if now > claims["exp"] + 30 or claims["iat"] > now + 30:
        raise ValueError("expired or not yet valid")
    if claims["htm"] != method or claims["htu"] != url:
        raise ValueError("wrong method or URL")
    if claims["bh"] != base64.urlsafe_b64encode(hashlib.sha256(body).digest()).rstrip(b"=").decode():
        raise ValueError("body hash mismatch")
    return claims
```

Node.js without a package:

```js
async function verify(jwks, token, audience, method, url, body, now) {
  const [h, p, s] = token.split('.')
  const header = JSON.parse(Buffer.from(h, 'base64url'))
  if (header.typ !== 'synaplan-call+jwt' || header.alg !== 'ES256') throw new Error('wrong typ or alg')
  const jwk = jwks.keys.find((k) => k.kid === header.kid)
  const key = await crypto.subtle.importKey('jwk', jwk, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify'])
  if (!(await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, key, Buffer.from(s, 'base64url'), Buffer.from(`${h}.${p}`))))
    throw new Error('signature verification failed')
  const claims = JSON.parse(Buffer.from(p, 'base64url'))
  if (claims.aud !== audience) throw new Error('wrong audience')
  if (now > claims.exp + 30 || claims.iat > now + 30) throw new Error('expired or not yet valid')
  if (claims.htm !== method || claims.htu !== url) throw new Error('wrong method or URL')
  if (claims.bh !== createHash('sha256').update(body).digest('base64url')) throw new Error('body hash mismatch')
  return claims
}
```

Go uses `crypto/ecdsa` with the JWK's `x` / `y` and the 64-byte `r || s`
signature (`verify_token.go`). Other languages use their standard ES256 JWT
library: Microsoft.IdentityModel (C#), Nimbus JOSE+JWT (Java), `jsonwebtoken`
(Rust), `ruby-jwt` (Ruby), `firebase/php-jwt` (PHP).

### 3.5 Test vector

From [`spike-2/identity/vector.json`](./spike-2/identity/vector.json), minted
by `firebase/php-jwt` on 2026-10-18 (ES256 signatures are randomised; this is
one fixed token). Only the public key is published.

```text
public JWK   {"kty":"EC","crv":"P-256","x":"Tw5U03TSBx1-dDHlEWkt0S8FZTbCSYi0FshgoshX36c",
              "y":"s3LwVMcgCX-YZ2yzRvZ4xQauUVCoh_zeKFSPsTifxjM","kid":"fZTJDpQY92cqiDSYBMVmZI6fEYGC-YZhfhK5o6RaFPg",
              "alg":"ES256","use":"sig"}
pseudonym    key 32560b53bf7bdecf001cf213f490be627bdcea7845acc16896b4cc15bc858923 (test value)
             scope mcp:12, person id 2 → u_dcb71f082ca0b370286788ccdd38cfa1
request      POST http://word-tools:8000/mcp
body         {"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"word_stats","arguments":{"text":"hello world"}}}
claims       iss http://localhost:8000 · aud http://word-tools:8000/mcp · sub u_dcb71f082ca0b370286788ccdd38cfa1
             iat 1792130000 · exp 1792130060 · jti 5d7c0f0e-2b8e-4c39-9a51-0d6f3c1e7a42
             htm POST · htu http://word-tools:8000/mcp · bh jDXxL-J1r6QojJTigs38W010VQctxJsAvleRj3cfsW8
token        (vector.json "token")
now          1792130030
```

Valid at `now`; refused when the body changes, for another audience, for
another method, with a changed `sub`, and at `now` + 170 s — in all four
languages (`spike-2/results.md`). OP3's unit test verifies this token and a
freshly minted one, and recomputes the pseudonym.

---

## 4. Manifest v2 additions (OP18, OP7)

### 4.1 Example — a plugin without PHP

```text
plugins/word_tools/
  manifest.json
  compose.yaml                (§12.2)
  service/                    (Dockerfile + source in any language; or an image: in compose.yaml)
  locales/de.json en.json es.json fr.json tr.json
  settings.schema.json        (Phase 3)
```

```json
{
  "id": "word_tools",
  "name": "word_tools",
  "label": "Word tools",
  "version": "1.0.0",
  "description": "Text statistics from a Python service",
  "minSynaplanVersion": "5.6.0",
  "chatCommands": [
    { "command": "words", "endpoint": "/svc/chat", "description": "Count the words in a text" }
  ],
  "provides": {
    "mcpServers": [
      { "key": "word_tools", "label": "Word tools", "url": "http://word-tools:8000/mcp", "tools": ["word_stats"], "allowWrite": false }
    ],
    "service": { "url": "http://word-tools:8001", "health": "/health" },
    "settings": "settings.schema.json",
    "context": { "path": "/context", "channels": ["web", "widget"] },
    "plugs": [
      { "port": "web_search", "transport": "http", "key": "word_tools_search", "path": "/search", "label": "Word tools search" }
    ]
  }
}
```

### 4.2 Fields

| Field | Rule | Error names |
| ----- | ---- | ----------- |
| `label` | display name; a locale key when `locales/` exists | `label` |
| `minSynaplanVersion` | SemVer; a lower running version refuses the plugin with a log line and an Admin row | `minSynaplanVersion` |
| `provides.mcpServers[].key` | `^[a-z0-9][a-z0-9_-]*$`, unique in the plugin | `provides.mcpServers[i].key` |
| `provides.mcpServers[].url`, `provides.service.url` | a literal `http(s)://` URL (normally the compose service name) or `env:<NAME>`; an unset variable ⇒ the server or service is absent, listed by `app:plugin:list` as "not configured: WORD_TOOLS_URL is not set" | `…url` |
| `provides.mcpServers[].tools` | optional list of tool names that are accepted (§1.5) | `…tools` |
| `provides.mcpServers[].allowWrite` | boolean, default `false`; destructive tools stay blocked regardless | `…allowWrite` |
| `provides.service.health` | relative path, probed by `HttpSidecarHealthProbe` without a token | `provides.service.health` |
| `provides.plugs[].transport` | absent = PHP class (today); `"http"` = remote adapter (§6), then `class` is not allowed and `path` is required | `provides.plugs[i].transport` |
| `provides.context` | `path` required; `channels` ⊆ `web`, `widget`, `whatsapp`, `email`, `telegram` | `provides.context.channels` |
| `provides.settings` | relative path to a JSON Schema file inside the plugin (§7) | `provides.settings` |
| `locales/` | `de`, `en`, `es`, `fr`, `tr` files; `en` required when `label`, `settings` or any label is a key | `locales/en.json` |
| `permissions` | documentation only; the draft's `network:operator` is dropped (§0 #9) | — |
| `chatCommands[].endpoint` | may start with `/svc/` (goes through §5) | — |
| `chatCommands[].tool` | ignored from OP10 on, with a log line naming the plugin | — |

The same rules are published as `docs/plugins/manifest.schema.json` (§0 #28);
`PluginManifest` and the schema are tested against the same valid and invalid
fixtures.

### 4.3 What a plugin folder does when Synaplan sees it

- A folder without `backend/` is an **out-of-process plugin**. It is
  installation-wide (§0 #21): no per-person install, no per-person SQL.
  `app:plugin:install*` keeps its meaning for PHP plugins.
- `app:plugin:sync` (§12.4) turns each declared MCP server into one shared row
  with `BSOURCE = plugin:<id>:<key>` (upsert by `BSOURCE`, so re-running is
  safe) and `BAUTHMODE = synaplan`.
- A folder that disappears disables its rows, never deletes them, so a returning
  plugin keeps its ids (assistants and Saved Tasks reference ids).
- In `/plugins-local` (self-hosted, §12.1) a folder with `backend/` or
  `frontend/` is refused: PHP plugins stay where the image puts them.
- The kernel loads nothing for a plugin without `backend/` (unchanged); the
  manifest alone drives §4–§7.
- The plugin appears in the runtime-config plugin list (`ModulesRuntimeConfig`,
  backend) for every person who has not turned it off, so its slash commands
  reach the composer without a frontend change.

---

## 5. The checked route to a plugin's service (OP8)

```http
GET|POST|PUT|PATCH|DELETE /api/v1/user/{userId}/plugins/{name}/svc/{path}
```

Checked in this order — the first failure answers:

1. `PLUGINS.SERVICES_ENABLED` on, else 404.
2. `{userId}` is the signed-in person, the plugin is enabled (registered, not
   switched off by an administrator) and the person has not turned it off,
   else 404 (core does what each PHP controller does by hand today).
3. The manifest declares `provides.service` and its URL resolves (literal, or
   the env variable is set), else 404.
4. The target passes §2 for a shared server, pinned.

Forwarded: method, `{path}`, query string, `Content-Type`, the body (≤ 1 MiB).
Removed: `Cookie`, `Authorization`, every incoming `X-Synaplan-*`. Added: the
call token of §3 with `aud` = the service URL, `sub` scoped `plugin:<id>`.

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

All calls: `POST <service url><path>`, JSON, with the call token (§3) as the
person when there is one, else `system`; timeout per port as its built-in
adapters use it. A 5xx or timeout throws, so the registry falls back to the
next provider (`plugins/README.md` rule 4); an empty answer is a valid
"nothing found". The endpoints are published in
`docs/plugins/service.openapi.yaml` (§0 #28).

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
`family`, `describe`. The token's `bh` covers the raw multipart body. Answer:

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
declaration lists and only for people who have not turned the plugin off:

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

## 8. Isolated views (Phase 4, after OP-S1)

- **The MCP Apps specification is binding here.** Its stable revision
  (2026-01-26, extension `io.modelcontextprotocol/ui`) requires a web host to
  render the view through a **sandbox proxy on a different origin** from the
  host, with `allow-scripts allow-same-origin` on the proxy frame. The proxy
  loads the raw HTML into an inner frame with the CSP the server declared
  (`ui.csp`), `frame-src 'none'` and `object-src 'none'` unless declared. It
  forwards every message that does not start with `ui/notifications/sandbox-`.
- Synaplan serves one origin today. A conforming host needs a second origin
  (for example a sandbox host name or port served by the same Caddy) in dev,
  self-hosted, platform and the mobile WebView — **infrastructure, ask first
  (§0 #14)**. The fallback is the draft's frame: `<iframe sandbox="allow-scripts"
  srcdoc=…>` without `allow-same-origin`, `allow-top-navigation`,
  `allow-popups-to-escape-sandbox` or `allow-forms`, CSP as in
  `frontend/src/utils/chatArtifacts.ts`. It is a documented deviation and
  OP-S1 measures which MCP Apps still work in it.
- Host → view: theme (light / dark and the token values it needs), locale,
  the tool input and result.
- View → host: size, "open this link" (the host asks first), "put this text
  in the composer" (never sends on its own), "call a tool" (through
  `ToolExecutionGate`, so approvals apply).
- Never passed: the session, cookies, tokens, other chats, the person's id
  (only the §3.3 pseudonym).
- OP-S1 maps these messages onto the extension and records the specification
  revision the SDKs ship.

---

## 9. Developer quickstart (seed for OP6 and OP20)

The servers in [`spike/servers/`](./spike/servers/) and
[`spike-2/servers/`](./spike-2/servers/) are the minimal examples. OP6 and
OP20 turn them into `docs/PLUGINS.md` and runnable examples:

1. The server on a current SDK line, stateless Streamable HTTP on `/mcp`,
   tools with `readOnlyHint` / `destructiveHint` set honestly:
   Python `mcp` 2.x (`MCPServer`, `stateless_http=True`); TypeScript
   `@modelcontextprotocol/server` 2.x with `createMcpHandler` in its default
   posture (not `legacy: 'reject'` before OP15 ships); Go `go-sdk` ≥ v1.8 with
   `StreamableHTTPOptions{Stateless: true}`.
2. Bind `0.0.0.0` in the container and keep the SDK's `Host` / `Origin` guard
   by naming the compose service in its allowed hosts (the TypeScript
   factories drop the localhost default when bound beyond localhost).
3. The token check of §3.4 in front of `/mcp`, with `SYNAPLAN_JWKS_URL` and
   `SYNAPLAN_AUDIENCE` from the environment.
4. An existing tool that speaks MCP only on stdio, in any language: run it
   behind a pinned bridge (`supergateway --stdio "<command>" --outputTransport
   streamableHttp --port 8000`, measured with 4.2.0) with the token check of
   §3.4 in a small proxy in front of it; the OP20 example ships that proxy.
   Without it every other plugin on the `plugins` network could call the
   tool, and `app:plugin:check` fails it.
5. Either add the server for everyone as an administrator (J-OP-1), or ship
   it as a plugin folder with a manifest and a compose file (§12, J-OP-7).
6. `app:plugin:check` (§13.2) green.
7. Ask in chat with "MCP data sources" on for the topic (J-OP-2).

---

## 10. Protocol eras (OP15)

`McpClient` decides the era once per origin (scheme, host, port) and caches it
for an hour in the app cache; `MCP.PROTOCOL_MODE = legacy` skips the probe.

| Answer to a modern `server/discover` | Era | Measured on (2026-10-18) |
| ------------------------------------ | --- | ------------------------ |
| 200 with `supportedVersions` that contains `2026-07-28` | modern | Python `mcp` 2.3.0, Go `go-sdk` v1.8.0, TypeScript `@modelcontextprotocol/server` 2.3.1 (both postures) |
| JSON-RPC `-32022` with `data.supported` | modern: retry with a listed version Synaplan speaks; none ⇒ fail with copy | specification |
| Any other 4xx, or a JSON-RPC error that is not a modern one (`-32601`, `-32000`) | legacy | TypeScript `@modelcontextprotocol/sdk` 1.32.1 (400, `-32000`), `supergateway` 4.2.0 (404, `-32601`) |
| Network error or timeout | unknown: nothing cached; the call fails as today | — |

**A modern request** is one POST with `Content-Type: application/json`,
`Accept: application/json, text/event-stream`, `MCP-Protocol-Version:
2026-07-28`, `Mcp-Method: <method>`, `Mcp-Name: <tool name>` for `tools/call`,
the call token or the row's auth header, and `params._meta` with
`io.modelcontextprotocol/protocolVersion`, `io.modelcontextprotocol/clientInfo`
(`synaplan-mcp-client`, the app version) and
`io.modelcontextprotocol/clientCapabilities` (`{}`). No handshake, no
`DELETE`.

- `resultType` absent or `complete` ⇒ as today. `input_required` ⇒
  `McpClientException`, copy "This tool asks for input Synaplan cannot give
  yet." (Multi Round-Trip Requests are §9 Later #8).
- `ttlMs` and `cacheScope` of `tools/list` go to OP16. Tool lists are per
  server in v1, discovered as `system`.
- `-32020` (headers and body disagree) is a Synaplan bug: logged as an error.
- **A legacy request** is today's session: `initialize` →
  `notifications/initialized` → the operation → `DELETE`.
- A cached modern era that answers a non-modern 4xx is probed again once.

Test matrix (unit, MockHttpClient): every row above, the per-origin cache, the
re-probe, the headers on every modern request, and `input_required`. Then
`spike-2/run.sh eras` with the dual-era client: all six servers answer.

---

## 11. Shared-server stability (OP16)

| Today | From OP16 |
| ----- | --------- |
| A failed discovery is not cached; the next turn tries again | The failure is cached for 60 s; callers get no tools from that server; **Test** bypasses the cache |
| No circuit breaker | Per server, shared by all workers through the app cache: 3 consecutive failures (connect error, timeout, 5xx, invalid JSON) within 120 s open it for 60 s; while open, calls fail at once with "{name} is not answering right now. Synaplan will try again in a minute."; then one trial call |
| `timeout` = `MCP.NODE_TIMEOUT` (idle) | Also `max_duration` = `MCP.NODE_TIMEOUT` on every request |
| Discovery on the request path when the cache is cold | A shared server's catalog older than 4 minutes is served and refreshed in the background by the worker (a Messenger message, stale-while-revalidate); **Test**, save and `app:plugin:sync` prime it; a person's own servers keep today's on-demand discovery |
| Fixed 5-minute cache | `ttlMs` > 0 is honoured within 60 s – 1 h |

What a person sees in chat stays as today: the tool error is explained in the
answer (#2377), naming the server.

---

## 12. Starting together (OP17, OP18)

### 12.1 Networks and folders (OP17)

| Compose file | `plugins` network | Joined by | Alias | Plugin folder |
| ------------ | ----------------- | --------- | ----- | ------------- |
| `docker-compose.yml` (dev) | new | backend, worker, scheduler | backend: `synaplan` | `./plugins:/plugins:ro` (exists) |
| `deploy/compose.yaml` (self-hosted) | new | backend, worker, scheduler | backend: `synaplan` | new: `./plugins:/plugins-local:ro`, `PLUGINS_LOCAL_DIR=/plugins-local` |
| `docker-compose.test.yml` (E2E) | new | backend, worker | backend: `synaplan` | the fixture plugin |
| `deploy/quickstart/compose.yaml` | — | — | — | none (one file, no host folders): shared servers through Admin only |

The database, Redis, Qdrant, Tika, Centrifugo and every other service stay off
`plugins`. `.gitignore` gains `docker-compose.override.yml` and
`deploy/compose.override.yaml`.

### 12.2 A plugin's compose file

```yaml
# plugins/word_tools/compose.yaml
services:
  word-tools:
    image: ghcr.io/example/word-tools:1.0.0     # or build: ./service
    restart: unless-stopped
    environment:
      SYNAPLAN_JWKS_URL: http://synaplan/.well-known/synaplan-jwks.json
      SYNAPLAN_AUDIENCE: http://word-tools:8000/mcp
      WORD_TOOLS_API_KEY: ${WORD_TOOLS_API_KEY:-}  # the plugin's own secrets come from the project .env
    healthcheck:                                 # the provides.service.health path of §4.1
      test: ["CMD", "wget", "-q", "-O", "/dev/null", "http://localhost:8001/health"]
    networks: [plugins]

networks:
  plugins: {}
```

Rules, enforced by the compose check (§13.1): every service is named after the
plugin id with dashes (`word-tools`, `word-tools-worker`); only the `plugins`
network; an image with a pinned tag or digest, or a `build:` inside the folder;
no published ports unless the tool serves the browser (a warning); declaring
`networks: plugins: {}` lets the file render on its own for the check.

### 12.3 Including it

| Where | The line goes into | Loaded by |
| ----- | ------------------ | --------- |
| Dev | `docker-compose.override.yml` | `make up` (`scripts/compose-up.sh` passes no `-f`) and `docker compose up -d` |
| Self-hosted, by hand | `compose.override.yaml` next to `compose.yaml` | `docker compose up -d` |
| Self-hosted, scripted (`deploy/scripts/*`, Elestio, AWS) | the same file, passed as `SYNAPLAN_COMPOSE_OVERRIDE` | `deploy/scripts/lib.sh::compose()`, because it calls `docker compose -f compose.yaml`, which skips the automatic override |

```yaml
include:
  - plugins/word_tools/compose.yaml
```

Measured (`spike-2` part 3): an included file that names no network lands on
`<project>_default` (A); naming `plugins` joins Synaplan's network whether or
not the file declares it (B, C); included files read the project `.env` (D);
`-f compose.yaml` skips the override (E); a same-named service is merged into
Synaplan's (F) — hence §13.1.

### 12.4 Registration (OP18)

`php bin/console app:plugin:sync` reads every plugin root (`/plugins`,
`/plugins-local`), validates each manifest and, per declared MCP server,
upserts the shared row of §4.3. It runs after the migrations in
`_docker/backend/docker-entrypoint.sh` and every minute in the scheduler's
tick lane, which lists its commands twice: `ScheduledJobs::LANES` and
`run_scheduler_tick_lane()` in `_docker/backend/lib/container-runtime.sh`.
It is idempotent, safe on three web nodes (unique index on `BSOURCE`), and
audits what changed.

| State | Means | Shown |
| ----- | ----- | ----- |
| registered | rows enabled, tools accepted per §1.5 | Admin → Plugins "Running" / "Not answering" (OP12) |
| not configured | an `env:` URL is unset | `app:plugin:list`; Admin "Not configured: WORD_TOOLS_URL is not set" |
| refused | an invalid manifest field, a `minSynaplanVersion` above the running version, or code in `/plugins-local` | `app:plugin:list`; Admin row with the field and the fix |
| switched off | an administrator turned it off for everyone | Admin; absent for people |
| removed | the folder is gone; rows disabled, ids kept | Admin row with **Delete** |

### 12.5 Start order

No `depends_on` in either direction. Synaplan registers the rows while the
service may still be starting; discovery fails fast and is retried (§11) until
it answers. The service fetches the key set on its first request.

---

## 13. Checks (OP19)

### 13.1 The compose check

`php bin/console app:plugin:compose-check` runs in the Synaplan image. It
reads the include list of the override file, renders each included plugin file
on its own (`docker compose -f <file> config --format json`, done by the
calling script), and checks it against Synaplan's own service names. A failure
names the plugin, the key and the fix; the start stops.

| Refused | Why |
| ------- | --- |
| A service name Synaplan uses (`backend`, `worker`, `db`, …) or one not named after the plugin id | Compose merges it into Synaplan's service (`spike-2` F) |
| A network other than `plugins`; `network_mode` other than the default | It would reach the database and the other data services |
| `privileged`, `cap_add`, `devices`, `pid: host`, `ipc: host`, `userns_mode: host`, `security_opt` with `unconfined` | Host access |
| A bind mount of `/var/run/docker.sock`, or of any path outside the plugin folder | Host and Docker access |
| `extends` or `volumes_from` pointing outside the file | Reaches into Synaplan's services |

Run by `scripts/compose-up.sh` (so `make up`) and
`deploy/scripts/validate-release.sh` before Compose starts anything. A plain
`docker compose up` skips it; the `include:` documentation says so.

### 13.2 The conformance check

`php bin/console app:plugin:check <plugin id | url>` prints one line per check
and exits non-zero on a failure:

| Check | Fail | Warn |
| ----- | ---- | ---- |
| Reachable from the backend | name, connection or HTTP error | — |
| Network rule (§2) | refused target | — |
| Era (§10) | neither era answers | the 2025 era only (the specification replaced it on 2026-07-28) |
| `tools/list` | invalid answer, no tools | a tool without a description; without `readOnlyHint` / `destructiveHint` (treated as writing); a name the `/v1` gateways cannot carry |
| Token enforcement (`synaplan` rows) | accepts a call without a token, or a token for another audience | accepts a changed body or an expired token |
| Health (`provides.service.health`) | not 2xx within 2 s without a token | — |
| Size | — | `tools/list` over 256 KiB |

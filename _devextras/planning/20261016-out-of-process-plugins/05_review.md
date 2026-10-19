# Review — out-of-process plugins plan (2026-10-18)

**Question put to the review:** Does the plan let a team simply start external
tools together with Synaplan, written in any language, and extend the platform
with them — safely, and so that it holds up on a self-hosted install and in
production?

**Verdict:** The direction is right and most of the 2026-10-16 draft stands:
MCP for tools, code in other languages out of process only, servers for
everyone as `BUSERID = 0` rows, the network rule by origin, pinned connects,
journey-first UX. Five things block the goal as written, and the update fixes
four of them in the plan; the fifth becomes the first question of OP-S1:

1. **It targets an MCP revision that is no longer current.** MCP 2026-07-28
   dropped the `initialize` handshake; a modern-only server already refuses
   Synaplan's client (R1).
2. **Its identity design protects nobody from the service** and does not bind
   the request: the pseudonym is reversible by the service that holds the
   secret, and a signed request can be replayed to another path (R2, R3).
3. **"Start together" has no path.** A self-hosted install cannot receive a
   plugin folder, an included compose file lands on the wrong network, and the
   operator has to copy a secret by hand before the tool can start (R4).
4. **A shared server that hangs slows every person's turn**, because failed
   discovery is never cached and runs on the planner path (R5).
5. **The isolated-view frame (the draft's Phase 3, now Phase 4) contradicts
   the stable MCP Apps spec**, which requires a separate-origin sandbox (R7).

**Method.** Code read on `main` at `297d0cc67`; every row of the master plan's
"Where it lives" table and every call site of `02` §1.3 checked against the
code; the MCP specification (2026-07-28), the SDK pages and the MCP Apps
specification (2026-01-26) read on 2026-10-18; a second spike
([`spike-2/`](./spike-2/README.md)) run twice in the cloud sandbox. It drove the
real `McpClient` against six servers on current SDK lines and a stdio bridge,
checked a call token in PHP, Python, Node.js and Go, reversed the draft
pseudonym, and tested how Docker Compose 5.3.1 treats an included plugin file.
Raw output: [`spike-2/results.md`](./spike-2/results.md).

---

## 1. What stands

- **Out of process for every language other than PHP** (§0 #2), and PHP
  plugins unchanged. Nothing found argues for code inside the worker.
- **MCP as the tool protocol** (§0 #1). The official SDK list now has ten
  languages: Tier 1 TypeScript, Python, C#, Go, Rust, Ruby; Tier 2 Java;
  Tier 3 Swift, PHP, Kotlin (modelcontextprotocol.io/docs/sdk, 2026-10-18).
  Any program that speaks MCP on stdin/stdout, in any language, reaches
  Synaplan through a bridge container (measured, R9).
- **Servers for everyone as `BUSERID = 0` rows** (§0 #3). Confirmed safe:
  every lookup in `McpServerConfigRepository` is keyed by the user id, so a
  call site that is not switched keeps today's behaviour.
- **The network rule by origin, loopback refused, the kill switch, the pinned
  connect** (§0 #5, #6). G3 is real: `McpClient::withSession()` checks the
  name, `post()` resolves it again.
- **The journeys, the five questions and the copy discipline**; the step
  discipline (one step per PR, commit titles, mobile classes — `no-app-impact`
  is a valid class in `.github/mobile-impact-policy.json`).
- **References checked and correct:** UX contract §4.8 ("`MCP` only on its own
  row") and §8 (banned words); `McpServersConfiguration.vue` is 789 lines;
  `ToolCallRunner` answers "This tool cannot run as a Saved Task step";
  `Kernel::getPlugins()` skips a folder without `backend/`;
  `ArtifactPanel.vue` uses `sandbox="allow-scripts"` with CSP
  `default-src 'none'`; `ApiKeyScope` maps `/api/v1/user/{id}/plugins` to
  `messages:*` / `chat`; `docs/PLUGINS.md` does not exist yet.

---

## 2. Findings

Severity: **Blocker** — the goal fails or a promise of the plan is false;
**High** — a security or stability defect a reviewer would stop the PR for;
**Medium** — a gap that costs a sprint later; **Low** — accuracy.

| # | Severity | Finding | Change in the plan |
| - | -------- | ------- | ------------------ |
| R1 | Blocker | MCP revision 2026-07-28 is current; `McpClient` speaks only 2025-11-25 | New step OP15 (dual-era client); §0 #1 revised |
| R2 | Blocker | The draft pseudonym is reversible by the service; rotation changes every identity | §0 #8 revised; `02` §3 rewritten |
| R3 | High | The draft signature does not bind method, path, query or audience | §0 #7 revised: a signed call token; `02` §3 rewritten |
| R4 | Blocker | No path to start a plugin together with Synaplan, in dev or self-hosted | New Phase 2 (OP17–OP20); §0 #21–#23 |
| R5 | High | A hanging shared server adds up to 15 s to every person's planned turn | New step OP16; §0 #26 |
| R6 | High | An included compose file can rewrite Synaplan's own services and join its internal network | §0 #22, #25; OP19 (mandatory compose check) |
| R7 | High | The isolated-view frame contradicts the stable MCP Apps spec | §0 #14 → ask first; OP-S1 question 0 |
| R8 | Medium | A shared server offers every tool it lists, also later additions | §0 #24 (accepted tools) |
| R9 | Medium | "Any language" is framed as three languages; the TypeScript example uses the v1 API; no machine-readable contract | §0 #25, #28; OP6, OP20 |
| R10 | Medium | Per-person plugin install does not fit plugins that run for everyone | §0 #21 |
| R11 | Medium | Administrator management sits on Apps → MCP; the UX contract puts installation-wide controls in Admin | §0 #27 (owner's call) |
| R12 | Medium | G6's second half — a scoped way back into Synaplan — is not addressed | §9 Later #7, with a design note |
| R13 | Low | Accuracy gaps in call sites, paths and timeouts | Fixed in place, listed under R13 |

### R1 — The plan is built on a superseded MCP revision (Blocker)

The plan pins "the version `McpClient` pins (today `2025-11-25`)". MCP
**2026-07-28** (released 2026-07-28) removed the `initialize` /
`notifications/initialized` handshake and the `Mcp-Session-Id` header. Every
request now carries its version and client capabilities in `_meta`; servers
MUST implement `server/discover`; POSTs MUST carry `Mcp-Method` and
`Mcp-Name`; `tools/list` results carry `ttlMs` and `cacheScope`; results carry
`resultType`; server-to-client requests became Multi Round-Trip Requests. A
deprecation window of at least twelve months now applies. The four Tier 1
SDKs of the release date speak the new revision.

Measured (`spike-2` part 1): servers on current SDKs in their default
posture still answer Synaplan's handshake — Python `mcp` 2.3.0, Go `go-sdk`
v1.8.0, TypeScript `@modelcontextprotocol/server` 2.3.1 with
`createMcpHandler`. A TypeScript v2 endpoint with `legacy: 'reject'` refuses
it: `MCP server answered HTTP 400 (… -32022 "Unsupported protocol version:
2025-11-25" … "supported":["2026-07-28"])`. The other direction matters too:
the TypeScript v1 server (`@modelcontextprotocol/sdk` 1.32.1) and the stdio
bridge (`supergateway` 4.2.0) refuse a modern request (`-32000`, `-32601`).
**Synaplan needs a dual-era client**: modern first, legacy fallback, the era
cached per origin, exactly as the spec's compatibility section prescribes.
That also removes three of the four HTTP round trips per operation in the
modern era (no handshake, no `DELETE`), which matters once one server serves
every person.

### R2 — The draft pseudonym is reversible by the service (Blocker)

Draft `02` §3.1: `X-Synaplan-User` = `u_` + HMAC-SHA256(**signing secret**,
`"user:" + person id`). The service holds that secret — it needs it to check
the signature — and Synaplan user ids are small sequential integers. The
service recovers the id by counting. Measured with the draft's own test
vector: `u_b541fdf0b220867c3e06d933ca59d3be is Synaplan user id 2 (found after
2 tries)`; a table of 1,000,000 ids takes 1.56 s on one core. The pseudonym
therefore gives no protection against the party it exists for (§0 #8: "a
service learns more about people than it needs"). Second defect: rotating the
signing secret changes every person's pseudonym, so any per-person state the
service keeps breaks on rotation.

**Fix:** derive the pseudonym from a key that never leaves Synaplan and does
not rotate with signing keys — HMAC-SHA256(installation pseudonym key, scope +
`"\n"` + `"user:"` + id), scope `mcp:<serverId>` or `plugin:<id>` (one plugin's
servers and service see the same person the same way).

### R3 — The draft signature does not bind the request (High)

The draft base string is `timestamp \n user \n email \n body`. It covers
neither the method nor the path, query or audience. For MCP alone (one POST
endpoint) that is tolerable. For the service proxy of the draft's Phase 2
(`GET|POST|PUT|PATCH|DELETE /svc/{path}`, now Phase 3) it is not: a signed bodiless `GET`
captured on the network verifies for a `DELETE` of any other path of the same
service for 60 seconds. There is also no key identifier, so a service cannot
tell "signed with an old key" from "forged".

**Fix, and the larger design change of this review:** replace the bespoke
HMAC header set with a **short-lived, audience-bound call token** — a JWT
signed ES256 by an installation key pair, sent as `Authorization: Bearer`,
with claims `iss`, `aud` (the URL Synaplan calls), `sub` (the R2 pseudonym or
`system`), optional `email`, `iat`, `exp` (60 s), `jti`, and request binding
`htm`, `htu`, `bh` (SHA-256 of the body). Synaplan publishes the public keys
at `/.well-known/synaplan-jwks.json`. Why this shape:

- **Nothing secret to copy.** A plugin trusts Synaplan's published keys. That
  is what makes "start together" a zero-step affair (R4) and removes the
  "secret shown once" card and its rotation journey.
- **Any language, ordinary tools.** Measured (`spike-2` part 2): a token
  minted by PHP with `firebase/php-jwt` (already in `composer.json`) verified
  in Python (PyJWT), in Node.js with WebCrypto and no package, and in Go with
  the standard library; all four refused a changed body, another audience,
  another method, a forged subject and an expired token. ES256 rather than
  EdDSA because .NET's standard JWT validation (Microsoft.IdentityModel 8.x)
  has no EdDSA (open issue #2426).
- **No cross-service impersonation.** A plugin can only verify, never sign;
  `aud` stops a token for one service from working at another.
- **Precedent in the code.** Synaplan already mints ES256 JWTs with
  `firebase/php-jwt` (`Service/Auth/AppleClientSecretGenerator`), validates
  ES256 against a key set with `web-token` (`Service/Auth/JwtValidator`), mints
  HS256 JWTs for Centrifugo and live voice (`RealtimeTokenService`,
  `VoiceLiveTicketService`), and publishes an installation key for federation
  (`FederationSigner`, `/.well-known/synaplan-federation`).

Outbound Saved Task webhooks keep their HMAC: they target third parties that
already hold a per-webhook secret.

### R4 — "Start together" has no path (Blocker for the goal)

What the operator would have to do today with the draft's design, and why it
fails:

1. **Self-hosted installs cannot receive a plugin folder.** `deploy/compose.yaml`
   mounts no plugins directory; plugins are baked into the image
   (`_docker/backend/Dockerfile`: `COPY plugins/ /plugins/`). The draft's
   "manifest plus a service" has no way in without building an image.
2. **An included compose file lands on the wrong network.** Measured
   (`spike-2` part 3, A): a plugin file included from the override file whose
   service names no network joins `<project>_default`; the backend, on
   `synaplan-network`, cannot reach it by name. Naming Synaplan's network
   works (B), but the key differs between dev (`synaplan-network`) and
   self-host (`internal`), so one plugin file cannot serve both.
3. **Scripted self-host starts skip the override file.** `deploy/scripts/lib.sh`
   calls `docker compose -f compose.yaml`, which disables the automatic
   `compose.override.yaml` (E); the scripts read `SYNAPLAN_COMPOSE_OVERRIDE`
   instead.
4. **The draft's identity needs a hand-copied secret** shown once, after
   Synaplan generated it — so on a first `docker compose up` the tool starts
   without its secret, and every rotation repeats the copy.
5. **Per-person install** (`app:plugin:install-verified-users`) is a
   command-line step per plugin (R10).

**Fix (new Phase 2, OP17–OP20):** every Synaplan compose file defines a
`plugins` network that only the PHP services join, the backend under the
alias `synaplan`; a plugin folder (manifest + `compose.yaml`) names only that
network and is started by one `include:` line in the operator's override file;
self-hosted installs get a read-only operator folder `./plugins` mounted at
`/plugins-local` (out-of-process plugins only); Synaplan registers the
declared servers on start and every minute; the call token (R3) means nothing
is copied. Measured live (3b): the backend reached the plugin by name, the
plugin reached the key endpoint through the alias, and `db` did not even
resolve from the plugin.

### R5 — One hanging shared server slows everyone (High)

`McpToolRegistry::toolsFor()` caches only success: when `listTools()` throws
inside the cache callback, nothing is stored, and the next call tries again.
`catalogForUser()` runs on the request path — the planner's `mcp_fetch` /
`mcp_action` catalogs, `McpClientToolSource` (`GET /api/v1/tools`, Saved
Tasks, `tool_call`) and the `/v1` gateway catalog. With per-person servers a
hanging server hurts its owner; once it is shared, it hurts every person on
every planned turn, up to `MCP.NODE_TIMEOUT` (15 s) per request. That value
is Symfony HttpClient's idle `timeout`, with no `max_duration`, so a server
that trickles bytes can hold a request longer. ("Stability is UX", AGENTS.md.)

**Fix (OP16):** cache a failed discovery for 60 s; a per-server circuit
breaker shared by all workers; `max_duration` equal to the node timeout;
shared servers' catalogs refreshed by the scheduler so a person's turn never
waits for `tools/list`; honour `ttlMs` within bounds.

### R6 — An included compose file can rewrite Synaplan (High)

Measured (`spike-2` part 3, F): with Compose 5.3.1 an included file that
defines a service named like one of Synaplan's is **merged into it without an
error** — the stand-in `backend` came out with `privileged: true`, the host's
root filesystem mounted and an injected variable. An included file can also name
Synaplan's internal network and reach MariaDB, Redis and Qdrant directly (B,
with `synaplan-network`). An operator who includes a plugin's compose file
grants it the operator's Docker. That is the same trust as installing a PHP
plugin, but it must not happen by accident.

**Fix:** a compose check that runs in the Synaplan image (the host needs only
Docker) before every start that `make up` and `deploy/scripts/validate-release.sh`
perform. It refuses a plugin file that defines a service name Synaplan uses,
joins any network other than `plugins`, or sets `privileged`, `cap_add`,
`devices`, host `network_mode` / `pid` / `ipc`, the Docker socket, or a bind
mount outside the plugin folder. A plain `docker compose up` skips it; the
docs say so (OP19).

### R7 — The isolated views versus the MCP Apps spec (High)

The MCP Apps extension (`io.modelcontextprotocol/ui`) is **Stable** in its
2026-01-26 revision. For web hosts it requires a sandbox proxy on a
**different origin** from the host, with `allow-scripts allow-same-origin`,
which loads the view with the CSP the server declared. Draft §0 #14 / `02` §8
plan a `srcdoc` frame on the app's origin without `allow-same-origin`, which
does not conform. A conforming host needs a second origin in every deployment
shape: dev, self-hosted, the platform, and the mobile WebView. That is
infrastructure (ask first). OP-S1 now starts with that question.

### R8 — A shared server offers whatever it lists (Medium)

The draft offers every listed tool of a shared server to everyone, including
tools that appear later. Measured: the official reference server
(`server-everything`, behind the bridge) lists `get-env` — "Returns all
environment variables" — annotated read-only, so the approval rules would let
the AI call it without asking. A server that changes a tool's description can
also inject instructions after the administrator tested it ("tool poisoning").
**Fix:** shared servers offer **accepted tools** only; a new or changed tool
waits for an administrator (§0 #24).

### R9 — "Any language" needs more than three examples (Medium)

- The plan and its examples say Python, Node.js and Go. The protocol reach is
  wider (ten SDK languages; any stdio program through a bridge — measured with
  `supergateway` 4.2.0 and the reference stdio server).
- The Node.js example teaches the TypeScript **v1** API
  (`@modelcontextprotocol/sdk`). The current line is `@modelcontextprotocol/server`
  v2 with `createMcpHandler`, which serves both eras by default. A v2 endpoint
  with `legacy: 'reject'` breaks Synaplan until OP15 ships, so the docs say so.
- The TypeScript SDK's framework factories validate `Host` / `Origin` against
  localhost by default (DNS-rebinding protection) and drop that check when the
  server binds beyond localhost, as it must in a container. The docs should
  name the compose service in `allowedHosts` rather than lose the check. A
  docs row, not a design issue.
- The service's HTTP contracts (the draft's Phase 2, now Phase 3) exist only
  as prose. A JSON Schema for the manifest and an OpenAPI 3.1 document for the
  service endpoints let developers in any language validate and generate code;
  a conformance command (`app:plugin:check`) makes "supported language" a test
  result rather than a list (§0 #25, #28).

### R10 — Per-person install does not fit (Medium)

Draft §4.3 makes a plugin's servers "visible to the people who have the plugin
installed". J-OP-4 therefore needs `app:plugin:install-verified-users` and new
accounts depend on the `%default_user_plugins%` container parameter. Per-person
install exists because PHP plugins run per-person SQL migrations and symlinks;
an out-of-process plugin needs neither. **Fix:** out-of-process plugins are
installation-wide, like servers for everyone; each person can turn one off; an
administrator can switch it off for everyone (§0 #21).

### R11 — Where administrators manage shared servers (Medium)

UX contract §2.3: "Installation-wide controls live in **Admin**,
administrators only"; §4.5 describes the admin infrastructure page (tabs,
health pills, a **Test** with a human result). The draft puts add, edit,
delete and rotate on Apps → MCP, visible to administrators only. That is
defensible under §2.5 ("advanced controls appear in context"), but it is a
decision, not a default. The update proposes Admin → Plugins for management
(plugins and MCP servers for everyone, health, accepted tools), with Apps →
MCP showing every person the "For everyone" rows and administrators a
"Manage" link (§0 #27, owner's call).

### R12 — The way back into Synaplan (Medium)

`01` G6 names two gaps: a service never learns who is calling, and it "has no
scoped way back". The draft closes the first only. "Enhance the platform"
needs the second (read a file the person handed over, post a message). The
call token makes it cheap later: the same keys can mint a token for Synaplan
as audience, scoped to permissions the manifest declares. It needs a
permission vocabulary first, so it goes to §9 Later #7 with that design note,
explicitly out of v1.

### R13 — Accuracy (Low)

- `02` §1.3 misses two callers: `Service/Mcp/AtlassianToolArguments` calls
  `callTool()` (own rows; it needs the acting person in OP3), and
  `Service/Iam/Erasure/AccountRecordsEraser` erases `McpServerConfig` rows by
  owner (it must keep leaving `BUSERID = 0` rows alone).
  `ConfluenceLinkedPageReader` and `JiraLinkedIssueReader` call `listTools()`
  live, outside the registry cache.
- `FeatureStatusReporter` lives in `backend/src/Service/Config/`.
- `HttpSidecarHealthProbe` counts 401 / 403 as down, so a plugin's health
  path must answer without a token.
- `docker-compose.override.yml` is not in `.gitignore`; once operators add
  `include:` lines to it, it should be.
- OP0's `spike/run.sh` mounts the temp folder writable into containers that
  install as root (`node_modules`, `go.mod`), so a second run as a normal user
  cannot delete it. `spike-2/run.sh` mounts sources read-only.
- `01` §1.5 calls the Apps section "Extensions": that is its id
  (`AppsView.vue`); every locale labels it "Plugins". The plan keeps **plugin**
  as the one term in copy.

---

## 3. Decisions the update puts to the owner

New or changed rows in [`00_master_plan.md`](./00_master_plan.md) §0, all
"Proposed" until ticked:

| Row | Decision | Recommendation |
| --- | -------- | -------------- |
| #1 | Dual-era MCP client (2026-07-28 first, 2025-11-25 fallback) | Agree; OP15 can land before OP1 |
| #7 | Signed call token (ES256 JWT, published keys) instead of HMAC headers | Agree; it is what makes "start together" need no secret |
| #8 | Pseudonym from a Synaplan-only key, scoped per server or plugin | Agree; the draft's is reversible |
| #14 | MCP Apps sandbox on a second origin | Ask first (infrastructure) |
| #21 | Out-of-process plugins are installation-wide | Agree |
| #22 | `plugins` network, `synaplan` alias, `include:`, `/plugins-local` | Ask first (Docker / CI) |
| #24 | Shared servers offer accepted tools only | Agree |
| #25 | Mandatory compose check; `app:plugin:check` | Agree |
| #27 | Administrator management in Admin → Plugins | Owner's call (UX contract §2.3 vs §2.5) |

## 4. What the update leaves alone

The step IDs OP1–OP14 keep their meaning; new steps are OP15–OP20. The
schema question (§0 #10, one nullable `BSOURCE` column) and the E2E stub
(§0 #15) stay ask-first. The Phase 3 contracts for plug ports, chat context
and settings (`02` §6, §7) are unchanged apart from the token. Distribution
(registry, signed images) stays out of scope; compose `include:` with an image
reference is how a plugin ships in v1.

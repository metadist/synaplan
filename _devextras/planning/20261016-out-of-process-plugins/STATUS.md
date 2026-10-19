# Out-of-process plugins — status

Every result here names the command, the versions and the stack it came from.
A walk is a dated entry, not a chat message.

| Step | State | Note |
| ---- | ----- | ---- |
| OP0 | done | Research, contracts, journeys, step briefs, spike (2026-10-16); reviewed and updated 2026-10-18 (`05_review.md`, `spike-2/`) |
| **Phase 1** | | |
| OP15 | planned | Dual-era `McpClient`: 2026-07-28 first, 2025-11-25 fallback — §0 #1 #18; can land first |
| OP16 | planned | Negative cache, circuit breaker, `max_duration`, background refresh — §0 #26; can land first |
| OP1 | planned | Shared MCP servers, per-person off switch, admin API, accepted tools, audit — §0 #3 #4 #18 #24 |
| OP2 | planned | Network rule by origin, pinned connect, `MCP_PUBLIC_SERVERS_ONLY` — §0 #5 #6 |
| OP3 | planned | Signed call token (ES256, published keys), pseudonym only Synaplan can compute — §0 #7 #8 |
| OP4 | planned | MCP stub server, both eras, verifies the token — §0 #15 (ask first: Docker / CI) |
| OP5 | planned | Admin → Plugins and Apps → MCP "For everyone"; J-OP-1…3, J-OP-8 — §0 #27 |
| OP6 | planned | `docs/PLUGINS.md` part 1 and examples in three languages; J-OP-6 |
| **Phase 2** | | |
| OP17 | planned | `plugins` network, `synaplan` alias, `/plugins-local` — §0 #22 (ask first: compose) |
| OP18 | planned | `app:plugin:sync`: a plugin folder registers its MCP servers for everyone — §0 #9 #10 (ask first: schema) #21 #23; J-OP-7 |
| OP19 | planned | Compose check before every scripted start; `app:plugin:check` — §0 #22 #25 |
| OP12 | planned | Plugins and shared servers on the status page |
| OP20 | planned | `docs/PLUGINS.md` part 2 and four plugin examples; J-OP-6 (bridge), J-OP-7 |
| **Phase 3** | | |
| OP7 | planned | Remaining manifest v2 keys, JSON Schema and OpenAPI — §0 #9 #28 |
| OP8 | planned | Checked route to a plugin's service — §0 #11 |
| OP9 | planned | Plug ports and chat context over HTTP — §0 #12 |
| OP10 | planned | `fix(tools)`: plugin slash commands no longer listed as tools — §0 #13; can land first |
| OP11 | planned | Settings from a schema; J-OP-4 |
| **Phase 4** | | |
| OP-S1 | planned | MCP Apps host bridge and its sandbox origin — §0 #14 (ask first: second origin) |
| OP13 | planned | A tool's own view in the chat; J-OP-5 |
| OP14 | planned | Plugin pages from the service, isolated |

---

## 2026-10-18 — plan review and review spike

Stack: the cloud sandbox, `main` at `297d0cc67`, Docker 28.5.2, Compose
5.3.1. The dev stack was not running; the probes ran in the backend image
(`f81adaf8baf0`, PHP 8.5.7) with this checkout's `backend/` and `vendor/`
mounted read-only. Commands: `spike-2/run.sh` (all parts, run twice, exit 0)
and, on the host, the TypeScript v2 server against raw modern requests. Raw
output: [`spike-2/results.md`](./spike-2/results.md). Findings and decisions:
[`05_review.md`](./05_review.md).

| Question | Answer | Evidence |
| -------- | ------ | -------- |
| Is the MCP revision the plan pins still current? | **No** — 2026-07-28 removed the `initialize` handshake and sessions, added `server/discover` and required `Mcp-Method` / `Mcp-Name` | modelcontextprotocol.io specification 2026-07-28 changelog and versioning page |
| Does today's `McpClient` reach servers on the current SDK lines? | **Mostly** — Python `mcp` 2.3.0, Go `go-sdk` v1.8.0, TypeScript `@modelcontextprotocol/sdk` 1.32.1 and `@modelcontextprotocol/server` 2.3.1 (default posture) list and call; the TypeScript v2 server with `legacy: 'reject'` answers HTTP 400 `-32022` | `spike-2/run.sh eras` |
| Do all servers speak the modern era? | **No** — TypeScript v1 (400 `-32000`) and `supergateway` 4.2.0 (404 `-32601`) refuse `server/discover`; a dual-era client is needed | `eras/era_probe.mjs` |
| Can an existing stdio-only MCP tool run next to Synaplan? | **Yes** — the official `server-everything` 1.0.0 behind `supergateway` 4.2.0 listed 13 tools and answered `echo` through `McpClient`; it also lists `get-env`, annotated read-only | `spike-2/run.sh eras` |
| Does the draft pseudonym protect the person from the service? | **No** — `u_b541fdf0b220867c3e06d933ca59d3be` is user id 2, found on the second try; a table of 1,000,000 ids takes 1.56 s | `identity/pseudonym_reversal.py` |
| Can every language check a Synaplan call with ordinary tools? | **Yes** for the four tried — an ES256 token minted by `firebase/php-jwt` verified in PHP, Python (PyJWT 2.15.1), Node.js 22 (WebCrypto, no package) and Go 1.25 (standard library); all refused a changed body, another audience, another method, a forged subject and an expired token | `spike-2/run.sh identity` |
| Do the Python and Node.js snippets of `02` §3.4 work as printed? | **Yes** — extracted from the file and run against `vector.json`: valid accepted; changed body, other audience and expired refused | Node.js 22.14 (host); Python 3.12.15 + PyJWT 2.15.1 (container) |
| Does an included compose file reach Synaplan's backend? | **Only if it names Synaplan's network** — without, it lands on `<project>_default`; the key differs between dev and self-host, so the plan adds a `plugins` network to every file | `compose/semantics.sh` A–C |
| Can an included compose file change Synaplan's own services? | **Yes** — a same-named service is merged without an error: `privileged: true`, a mount of the host's root filesystem and an injected variable reached the stand-in backend | `compose/semantics.sh` F |
| Do scripted self-hosted starts load `compose.override.yaml`? | **No** — `deploy/scripts/lib.sh` calls `docker compose -f compose.yaml`, which skips it; `SYNAPLAN_COMPOSE_OVERRIDE` is the way in | `compose/semantics.sh` E |
| Does a `plugins` network keep a plugin away from the database? | **Yes** — the backend reached `word-tools`, the plugin reached `http://synaplan/`, and `db` did not resolve from the plugin | `spike-2/run.sh compose` (live) |
| Does a failed discovery get cached? | **No** — the cache callback throws, nothing is stored, the next catalog build tries again (code read) | `McpToolRegistry::toolsFor()` |
| Can a self-hosted install receive a plugin folder today? | **No** — `deploy/compose.yaml` mounts none; plugins are baked into the image | `_docker/backend/Dockerfile` `COPY plugins/ /plugins/` |

Not tested: a dual-era `McpClient` (OP15), the token against a running
Synaplan (OP3), registration from a folder (OP18), MCP Apps in a
separate-origin frame (OP-S1), a chat turn using a tool (no local text
generation in the sandbox).

---

## 2026-10-16 — OP0 research spike

Stack: local dev stack (`docker compose up -d`) on `main` at `f6a60ab71`; the
backend container runs PHP 8.4. Servers started by
[`spike/run.sh`](./spike/run.sh) on the Synaplan Docker network. Raw output:
[`spike/results.md`](./spike/results.md).

| Question | Answer | Evidence |
| -------- | ------ | -------- |
| Does Synaplan's `McpClient` work with an MCP server written in Python? | **Yes** — `tools/list` and `tools/call`, schema and `readOnlyHint` intact | `mcp` 2.3.0 (`MCPServer`), stateless Streamable HTTP; probe output |
| … in Node.js? | **Yes** | `@modelcontextprotocol/sdk` 1.32.1, Express 5.3.0, Zod 4.6.7, Node 22 |
| … in Go? | **Yes** (also returned `structuredContent`) | `github.com/modelcontextprotocol/go-sdk` v1.8.0, Go 1.25 |
| Do the servers accept Synaplan's protocol version? | **Yes** — each answered `initialize` with `protocolVersion: "2025-11-25"` and ran without a session id | raw `initialize` with curl on the network |
| Can a person register them through the API today? | **No** — 3 × HTTP 400 "This server URL is not allowed (must be a public http(s) endpoint)" | `POST /api/v1/mcp-servers` as `demo@synaplan.com` |
| Can a FastAPI service be a custom HTTP tool today? | **Partly** — saved (201) and listed, but every call fails ("Only https URLs are allowed"); OpenAPI import refused ("This URL is not allowed") | `POST /api/v1/tools/custom`, `…/try`, `…/import-openapi/preview`; tool deleted after |
| Does any external plugin use `chatCommands[].tool`? | **No** — Synaform, synafastbill, Synamail, synaScriber manifests checked | `gh api repos/metadist/<repo>/contents/<plugin>/manifest.json` |
| Is loopback a separate service from the backend's view? | **No** — it is the backend container; Caddy's admin API answers on `127.0.0.1:2019` | `php -r 'file_get_contents("http://127.0.0.1:2019/config/")'` in `synaplan-backend` (GET only) |
| Do the proposed signature snippets agree with PHP? | **Yes** — PHP `hash_hmac`, Python, Node.js and Go produce / accept the draft `02` §3.2 vector and reject a changed body and a timestamp 100 s off. Superseded on 2026-10-18: the scheme itself was replaced (`05_review.md` R2, R3) | snippets of the draft `02` §3.3 run in the three server containers |

Not tested: a full chat turn using the tool (local text generation does not
run in the cloud sandbox; the planner path is covered by the existing runner
tests and by OP5's E2E), MCP Apps rendering (OP-S1).

Two findings for other owners (`01` §4): the custom HTTP tool validator accepts
addresses that can never run; OpenAI-compatible endpoints accept loopback for
admin-configured upstreams, and loopback reaches the backend's own Caddy admin
API.

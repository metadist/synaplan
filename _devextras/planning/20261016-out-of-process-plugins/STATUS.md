# Out-of-process plugins — status

Every result here names the command, the versions and the stack it came from.
A walk is a dated entry, not a chat message.

| Step | State | Note |
| ---- | ----- | ---- |
| OP0 | done | Research, contracts, journeys, step briefs, spike |
| OP1 | planned | Install-wide MCP servers, per-person off switch, admin API, audit — §0 #3 #4 #18 |
| OP2 | planned | Network rule by origin, pinned connect, `MCP_PUBLIC_SERVERS_ONLY` — §0 #5 #6 |
| OP3 | planned | Signed caller identity, secret once, rotation — §0 #7 #8 |
| OP4 | planned | MCP stub server — §0 #15 (ask first: Docker / CI) |
| OP5 | planned | Apps → MCP "For everyone"; J-OP-1…3 |
| OP6 | planned | `docs/PLUGINS.md` and examples; J-OP-6 |
| OP7 | planned | Manifest v2 keys, plugin rows — §0 #9 #10 (ask first: schema) |
| OP8 | planned | Checked route to a plugin's service — §0 #11 |
| OP9 | planned | Plug ports and chat context over HTTP — §0 #12 |
| OP10 | planned | `fix(tools)`: plugin slash commands no longer listed as tools — §0 #13; can land first |
| OP11 | planned | Settings from a schema; J-OP-4 |
| OP12 | planned | Plugin services on the status page |
| OP-S1 | planned | MCP Apps host bridge spike |
| OP13 | planned | A tool's own view in the chat; J-OP-5 |
| OP14 | planned | Plugin pages from the service, isolated |

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
| Do the proposed signature snippets agree with PHP? | **Yes** — PHP `hash_hmac`, Python, Node.js and Go produce / accept the `02` §3.2 vector and reject a changed body and a timestamp 100 s off | snippets of `02` §3.3 run in the three server containers |

Not tested: a full chat turn using the tool (local text generation does not
run in the cloud sandbox; the planner path is covered by the existing runner
tests and by OP5's E2E), MCP Apps rendering (OP-S1).

Two findings for other owners (`01` §4): the custom HTTP tool validator accepts
addresses that can never run; OpenAI-compatible endpoints accept loopback for
admin-configured upstreams, and loopback reaches the backend's own Caddy admin
API.

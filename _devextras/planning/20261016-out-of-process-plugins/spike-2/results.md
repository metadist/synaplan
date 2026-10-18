# Review spike — results (2026-10-18)

Output of [`run.sh`](./run.sh), run twice on the cloud sandbox (the second
time from a clean temp folder; both exit 0). `main` at `297d0cc67`, Docker
28.5.2, Compose 5.3.1. The probes ran in the backend image's PHP 8.5.7
(OpenSSL 3.0.20) with this checkout's `backend/` and `vendor/`
(`firebase/php-jwt` v7.2.1). Verifiers: Python 3.12.15 with PyJWT 2.15.1 and
cryptography 50.0.2, Node.js 22.23.3, Go 1.25.14.

## Part 1 — MCP protocol eras

Raw requests from [`eras/era_probe.mjs`](./eras/era_probe.mjs): the legacy
handshake Synaplan's `McpClient` sends today, and the 2026-07-28 probe.

```text
=== http://rv-py:8000/mcp            (Python mcp 2.3.0, stateless)
    legacy initialize 2025-11-25: HTTP 200, protocolVersion 2025-11-25
    modern server/discover 2026-07-28: HTTP 200, supportedVersions ["2026-07-28"]
=== http://rv-go:8000/mcp            (Go go-sdk v1.8.0, Stateless: true)
    legacy initialize 2025-11-25: HTTP 200, protocolVersion 2025-11-25
    modern server/discover 2026-07-28: HTTP 200, supportedVersions ["2026-07-28","2025-11-25","2025-06-18","2025-03-26","2024-11-05"]
=== http://rv-node1:8000/mcp         (TypeScript @modelcontextprotocol/sdk 1.32.1, v1)
    legacy initialize 2025-11-25: HTTP 200, protocolVersion 2025-11-25
    modern server/discover 2026-07-28: HTTP 400, error -32000 "Bad Request: Unsupported protocol version: 2026-07-28 (supported versions: 2025-11-25, 2025-06-18, 2025-03-26, 2024-11-05, 2024-10-07)"
=== http://rv-node2:8000/mcp         (TypeScript @modelcontextprotocol/server 2.3.1, createMcpHandler default)
    legacy initialize 2025-11-25: HTTP 200, protocolVersion 2025-11-25
    modern server/discover 2026-07-28: HTTP 200, supportedVersions ["2026-07-28"]
=== http://rv-node2-modern:8000/mcp  (same, legacy: 'reject')
    legacy initialize 2025-11-25: HTTP 400, error -32022 "Unsupported protocol version: 2025-11-25"
    modern server/discover 2026-07-28: HTTP 200, supportedVersions ["2026-07-28"]
=== http://rv-bridge:8000/mcp        (supergateway 4.2.0 + @modelcontextprotocol/server-everything 1.0.0 on stdio)
    legacy initialize 2025-11-25: HTTP 200, protocolVersion 2025-11-25
    modern server/discover 2026-07-28: HTTP 404, error -32601 "Method not found"
```

The real `McpClient` (guard in operator-network mode, as in OP0):

```text
=== http://rv-py:8000/mcp
    tools/call word_stats({"text":"Synaplan tools in any language."}) -> isError=false content=[{"text":"{\n  \"words\": 5, … \"runtime\": \"python\"\n}","type":"text"}]
=== http://rv-go:8000/mcp
    tools/call reverse_text({"text":"synaplan"}) -> isError=false content=[{"type":"text","text":"{\"reversed\":\"nalpanys\",\"runtime\":\"go\"}"}]
=== http://rv-node1:8000/mcp
    tools/call slugify({"text":"TypeScript SDK v1"}) -> isError=false content=[{"type":"text","text":"{\"slug\":\"typescript-sdk-v1\",\"runtime\":\"node\"}"}]
=== http://rv-node2:8000/mcp
    tools/call slugify({"text":"TypeScript SDK v2 default"}) -> isError=false content=[{"type":"text","text":"{\"slug\":\"typescript-sdk-v2-default\",\"runtime\":\"node-v2\",\"era\":\"legacy\"}"}]
=== http://rv-node2-modern:8000/mcp
    FAILED: MCP server answered HTTP 400 ({"jsonrpc":"2.0","error":{"code":-32022,"message":"Unsupported protocol version: 2025-11-25","data":{"supported":["2026-07-28"],"requested":"2025-11-25"}},"id":1})
=== http://rv-bridge:8000/mcp
    tools/list -> echo, get-annotated-message, get-env, get-resource-links, get-resource-reference,
                  get-structured-content, get-sum, get-tiny-image, gzip-file-as-resource,
                  toggle-simulated-logging, toggle-subscriber-updates, trigger-long-running-operation,
                  simulate-research-query
    tools/list -> get-env: Returns all environment variables, helpful for debugging MCP server configuration | annotations={"readOnlyHint":true,…}
    tools/call echo({"message":"a stdio server behind a bridge"}) -> isError=false content=[{"type":"text","text":"Echo: a stdio server behind a bridge"}]
```

Every server listed its tools with input schemas and annotations intact
(full lines in the run log). Two more facts from the same servers, probed by
hand on the host with the TypeScript v2 server: a modern `tools/list` and
`tools/call` are one request each, and a modern request without the
`Mcp-Method` header is refused with HTTP 400, `-32020` "the body names method
tools/call but the required Mcp-Method header is absent". `tools/list` from
that SDK carries `"ttlMs": 0, "cacheScope": "private"`.

## Part 2 — the call token in four languages

[`vector.json`](./identity/vector.json): ES256, `kid` = RFC 7638 thumbprint,
token minted by [`mint_token.php`](./identity/mint_token.php) with
`firebase/php-jwt`. "now" is pinned to 1792130030.

```text
php valid:                   as expected (accepted)
php changed body:            as expected (refused: body hash mismatch)
php other audience:          as expected (refused: wrong audience)
php other method:            as expected (refused: wrong method or URL)
php forged subject:          as expected (refused: Signature verification failed)
php expired (now + 170 s):   as expected (refused: Expired token)
python valid:                   as expected (accepted)
python changed body:            as expected (refused: body hash mismatch)
python other audience:          as expected (refused: Audience doesn't match)
python other method:            as expected (refused: wrong method or URL)
python forged subject:          as expected (refused: Signature verification failed)
python expired (now + 170 s):   as expected (refused: expired or not yet valid)
node   valid:                   as expected (accepted)
node   changed body:            as expected (refused: body hash mismatch)
node   other audience:          as expected (refused: wrong audience)
node   other method:            as expected (refused: wrong method or URL)
node   forged subject:          as expected (refused: signature verification failed)
node   expired (now + 170 s):   as expected (refused: expired or not yet valid)
go     valid:                   as expected (accepted)
go     changed body:            as expected (refused: body hash mismatch)
go     other audience:          as expected (refused: wrong audience)
go     other method:            as expected (refused: wrong method or URL)
go     forged subject:          as expected (refused: signature verification failed)
go     expired (now + 170 s):   as expected (refused: expired or not yet valid)
```

The Node.js verifier uses WebCrypto and the Go verifier the standard library:
neither needs a package. Python needs PyJWT with `cryptography`.

The 2026-10-16 draft pseudonym, from the draft's own test vector
([`pseudonym_reversal.py`](./identity/pseudonym_reversal.py), standard library):

```text
u_b541fdf0b220867c3e06d933ca59d3be is Synaplan user id 2 (found after 2 tries, 0.0001 s)
a full table of 1,000,000 ids takes 1.56 s on one core
```

## Part 3 — starting a plugin together with Synaplan

[`compose/semantics.sh`](./compose/semantics.sh), `docker compose config`
only, a stand-in Synaplan file with `backend` on `synaplan-network` and
`plugins`, `db` on `synaplan-network`, and a plugin file included from the
override file:

```text
A. The plugin file names no network:
   {'backend': ['plugins', 'synaplan-network'], 'db': ['synaplan-network'], 'word-tools': ['default']}
B. The plugin file names the shared 'plugins' key (defined only by Synaplan's file):
   {'backend': ['plugins', 'synaplan-network'], 'db': ['synaplan-network'], 'word-tools': ['plugins']}
C. The plugin file declares 'plugins' itself as well:
   {'backend': ['plugins', 'synaplan-network'], 'db': ['synaplan-network'], 'word-tools': ['plugins']}
D. The plugin file reads a variable that only the project .env sets:
   {'API_KEY': 'from-project-env'}
E. Which files load (self-host layout: compose.yaml + compose.override.yaml):
   docker compose config:                 backend db word-tools
   docker compose -f compose.yaml config: backend db
F. The plugin file defines a service with the name of one of Synaplan's:
   exit 0; Synaplan backend now has privileged=True, environment={'APP': '1', 'INJECTED': 'yes'}, volumes=['/var/run/docker.sock']
```

The live stack of [`compose/`](./compose/) (BusyBox stand-ins):

```text
--- the backend reaches the plugin by its service name
word-tools
--- the plugin reaches Synaplan's published keys through the alias
{"keys":[]}
--- the plugin cannot reach the database
wget: bad address 'db:3306'
```

## Not tested

A dual-era `McpClient` (OP15 builds it; the raw modern requests above are
what it will send), the call token against a running Synaplan (OP3), a
plugin row registered from a folder (OP18), MCP Apps in a separate-origin
frame (OP-S1), and a chat turn using any tool (no local text generation in
the sandbox).

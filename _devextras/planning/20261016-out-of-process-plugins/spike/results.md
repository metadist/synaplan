# OP0 spike — results (2026-10-16)

Output of [`run.sh`](./run.sh) on the local dev stack, `main` at `f6a60ab71`,
network `workspace_synaplan-network` (the name follows the checkout folder).

## Experiment A — the real API as `demo@synaplan.com`

```text
### GET /api/v1/mcp-servers
{"success":true,"client_enabled":true,"oauth_connectors_enabled":false,"servers":[]}  [HTTP 200]
### POST /api/v1/mcp-servers
{"success":false,"error":"This server URL is not allowed (must be a public http(s) endpoint)"}  [HTTP 400]
### POST /api/v1/mcp-servers
{"success":false,"error":"This server URL is not allowed (must be a public http(s) endpoint)"}  [HTTP 400]
### POST /api/v1/mcp-servers
{"success":false,"error":"This server URL is not allowed (must be a public http(s) endpoint)"}  [HTTP 400]
### POST /api/v1/tools/custom
{"success":true,"tool":{"id":2,"name":"op0_spike_word_stats","title":"op0_spike_word_stats","description":null,"type":"http","sideEffect":"read","spec":{"method":"GET","url":"http:\/\/py-rest:8001\/word-stats","query":{"text":"{{input.text}}"}},"inputSchema":null,"credentialId":null,"enabled":true,"sourceRef":null,"created":1792131379,"updated":1792131379,"registryName":"custom:op0_spike_word_stats"}}  [HTTP 201]
### GET /api/v1/tools?source=custom
{"success":true,"tools":[{"name":"custom:op0_spike_word_stats","title":"op0_spike_word_stats","description":"","sideEffect":"read","source":"custom","policy":null,"policyException":null,"shared":false,"inputSchema":{"type":"object","properties":[]}}]}  [HTTP 200]
### POST /api/v1/tools/custom/2/try
{"success":false,"error":"Only https URLs are allowed"}  [HTTP 400]
### DELETE /api/v1/tools/custom/2
{"success":true}  [HTTP 200]
### POST /api/v1/tools/custom/import-openapi/preview
{"success":false,"error":"This URL is not allowed"}  [HTTP 400]
```

The three `POST /api/v1/mcp-servers` lines are `py-tool`, `node-tool` and
`go-tool`, in that order.

## Experiment B — the real `McpClient`, SSRF guard in its operator-network mode

```text
=== Python (mcp 2.3.0, MCPServer)
    URL: http://py-tool:8000/mcp
    Today (SsrfGuard::isBlockedUrl): BLOCKED
    tools/list -> word_stats: Count words, characters and sentences in a text. | inputSchema={"properties":{"text":{"title":"Text","type":"string"}},"required":["text"],"type":"object","title":"word_statsArguments"} | annotations={"readOnlyHint":true}
    tools/call word_stats({"text":"Synaplan plugins in any language. Really!"}) -> isError=false content=[{"text":"{\n  \"words\": 6,\n  \"characters\": 41,\n  \"sentences\": 2,\n  \"runtime\": \"python\"\n}","type":"text"}]

=== Node.js (@modelcontextprotocol/sdk 1.32.1)
    URL: http://node-tool:8000/mcp
    Today (SsrfGuard::isBlockedUrl): BLOCKED
    tools/list -> slugify: Turn a title into a URL slug. | inputSchema={"$schema":"http://json-schema.org/draft-07/schema#","type":"object","properties":{"text":{"type":"string"}},"required":["text"]} | annotations={"readOnlyHint":true}
    tools/call slugify({"text":"Hello Plugin World from Node"}) -> isError=false content=[{"type":"text","text":"{\"slug\":\"hello-plugin-world-from-node\",\"runtime\":\"node\"}"}]

=== Go (modelcontextprotocol/go-sdk 1.8.0)
    URL: http://go-tool:8000/mcp
    Today (SsrfGuard::isBlockedUrl): BLOCKED
    tools/list -> reverse_text: Reverse a text. | inputSchema={"type":"object","properties":{"text":{"type":"string","description":"the text to reverse"}},"required":["text"],"additionalProperties":false} | annotations={"idempotentHint":false,"readOnlyHint":true}
    tools/call reverse_text({"text":"synaplan"}) -> isError=false content=[{"type":"text","text":"{\"reversed\":\"nalpanys\",\"runtime\":\"go\"}"}]
```

## Raw `initialize` against each server

```text
=== py-tool
"protocolVersion":"2025-11-25"
no Mcp-Session-Id header (stateless)
=== node-tool
"protocolVersion":"2025-11-25"
no Mcp-Session-Id header (stateless)
=== go-tool
"protocolVersion":"2025-11-25"
no Mcp-Session-Id header (stateless)
py-tool structuredContent: no
node-tool structuredContent: no
go-tool structuredContent: yes
```

## Signature snippets of `02_contracts.md` §3.3 against the §3.2 vector

```text
python valid: True
python tampered body rejected: True
python stale rejected: True
node valid: true
node tampered body rejected: true
node stale rejected: true
go valid: true
go tampered body rejected: true
go stale rejected: true
```

PHP (`hash_hmac` in `synaplan-backend`) produced the same user
`u_b541fdf0b220867c3e06d933ca59d3be` and signature
`sha256=bed56f197af1b29e59ddd3c8ad79610b45bc474c8752c0ccfdce7673bdfa8fbc`.

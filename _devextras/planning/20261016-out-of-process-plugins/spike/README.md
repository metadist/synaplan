# OP0 spike — MCP servers in Python, Node.js and Go against Synaplan

Not product code. It answers one question with evidence: does Synaplan's real
MCP client work with tools written in other languages, and what stops it
today? Findings: [`../01_research.md`](../01_research.md) §5 and
[`../STATUS.md`](../STATUS.md); raw output: [`results.md`](./results.md).

## Run

```bash
make up                                                          # the dev stack
_devextras/planning/20261016-out-of-process-plugins/spike/run.sh
_devextras/planning/20261016-out-of-process-plugins/spike/run.sh --down   # remove the servers
```

Extra arguments are passed to the probe, so another MCP server on the Synaplan
network can be checked the same way (OP4 uses this for its stub):

```bash
_devextras/planning/20261016-out-of-process-plugins/spike/run.sh \
  http://mcp-stub:<port>/mcp stub_word_count '{"text":"a b c"}'
```

## What it does

| Part | What |
| ---- | ---- |
| `servers/python/server.py` | MCP server on the official Python SDK (`mcp` 2.3.0, `MCPServer`), tool `word_stats` |
| `servers/python/rest.py` | FastAPI service with OpenAPI, for the custom HTTP tool path |
| `servers/node/server.mjs` | MCP server on the official TypeScript SDK (1.32.1) with Express, tool `slugify` |
| `servers/go/main.go` | MCP server on the official Go SDK (v1.8.0), tool `reverse_text` |
| `mcp_client_probe.php` | Builds the real `App\Service\Mcp\McpClient` in the backend container; swaps only the SSRF guard for its own operator-network mode |
| `run.sh` | Starts the four servers on the Synaplan network with pinned versions, waits for them, runs experiment A (the real API as the demo user) and B (the probe) |

`run.sh` copies the sources to `$TMPDIR/synaplan-op0-spike` before the
containers mount them, so installs never write into the repository. Experiment
A deletes the custom tool it creates (and any MCP server row, should the API
ever accept one). The servers keep running until `--down`.

## Caveats

- The probe's guard allows the whole operator network including loopback; the
  plan does not (`../02_contracts.md` §2).
- The Python SDK 2.x renamed `FastMCP` to `MCPServer`; code written for 1.x
  needs `mcp<2`.
- No chat turn is run: the cloud sandbox has no local text generation. The
  planner path is covered by the runner unit tests and by OP5's E2E.

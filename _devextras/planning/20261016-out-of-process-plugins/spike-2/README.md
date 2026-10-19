# Review spike (2026-10-18) — eras, call token, starting together

Not product code. It backs the findings of [`../05_review.md`](../05_review.md)
with measurements, the way [`../spike/`](../spike/README.md) backed OP0. Raw
output: [`results.md`](./results.md).

## Run

```bash
make up    # once, so the backend image exists; the stack itself need not run
_devextras/planning/20261016-out-of-process-plugins/spike-2/run.sh            # all parts
_devextras/planning/20261016-out-of-process-plugins/spike-2/run.sh eras       # one part
_devextras/planning/20261016-out-of-process-plugins/spike-2/run.sh --down     # remove the era servers
```

`SYNAPLAN_BACKEND_IMAGE` picks the image whose PHP runs the probes (default:
the running `synaplan-backend`, else the newest image built for the compose
service `backend`). The checkout's `backend/` and `vendor/` are mounted
read-only, so the probe runs today's `McpClient` without the database.

## What it does

| Part | What | Answers |
| ---- | ---- | ------- |
| `eras` | Six MCP servers on the network `op-review-spike`: Python `mcp` 2.3.0, Go `go-sdk` v1.8.0, TypeScript `@modelcontextprotocol/sdk` 1.32.1 (v1), TypeScript `@modelcontextprotocol/server` 2.3.1 (v2) in its default and in its modern-only posture, and the official stdio reference server behind `supergateway` 4.2.0. [`eras/era_probe.mjs`](./eras/era_probe.mjs) asks each for both protocol eras; [`../spike/mcp_client_probe.php`](../spike/mcp_client_probe.php) drives the real `McpClient` against each | Which servers Synaplan's client reaches today, and which it cannot |
| `identity` | [`identity/mint_token.php`](./identity/mint_token.php) mints and checks the call token of `02` §3 with `firebase/php-jwt` (already in `composer.json`); [`verify_token.py`](./identity/verify_token.py) (PyJWT), [`verify_token.mjs`](./identity/verify_token.mjs) (WebCrypto, no package) and [`verify_token.go`](./identity/verify_token.go) (standard library) verify the published [`vector.json`](./identity/vector.json) and refuse five tampered cases; [`pseudonym_reversal.py`](./identity/pseudonym_reversal.py) recovers the user id from the 2026-10-16 draft's pseudonym | Whether any language can check a Synaplan call with ordinary tools; whether the draft pseudonym protects anyone |
| `compose` | [`compose/semantics.sh`](./compose/semantics.sh) (`docker compose config` only) and a live stack from [`compose/`](./compose/) | How Compose treats a plugin's file that the operator includes; whether a `plugins` network keeps a plugin away from the database |

No private key is published: `vector.json` holds the public key and one token,
which is all a verifier needs. `MINT=1 ./run.sh identity` writes a fresh
`vector.json` from a new key pair; the checks stay the same. The pseudonym key
in `mint_token.php` is a **test value, published on purpose** — like the HMAC
secret of the 2026-10-16 draft.

## Caveats

- The McpClient probe swaps the SSRF guard for its operator-network mode,
  as in OP0; the plan's network rule (`02` §2) stays stricter.
- `supergateway` 4.2.0 answers only the 2025 era; a bridge is a stopgap for
  stdio-only tools, not the recommended way to write a new one.
- No chat turn runs: the cloud sandbox has no local text generation.

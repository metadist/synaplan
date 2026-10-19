# freeapi-fake

An in-memory fake of the Synaplan Demo (FreeAPI) server on `web.synaplan.com`.
It lets you develop and test the Synaplan Demo client offline: key issuance with
proof of work, status, self-revoke, and the OpenAI-compatible gateway
(`/v1/models`, `/v1/chat/completions` incl. streaming, `/v1/embeddings`,
`/v1/images/generations`). Answers are canned; nothing calls a real AI provider.

Limits, aliases and error codes are read from the vendored contract in
`backend/tests/Fixtures/freeapi-contract/v1/`, so the fake follows the contract
whenever the fixtures are updated. State is lost on restart.

## Run

```bash
node _devextras/freeapi-fake/server.mjs            # listens on :8787
node _devextras/freeapi-fake/selftest.mjs          # walks every route, exits non-zero on a mismatch
```

In Docker the contract directory is mounted at `/contract`. With the compose
profile `freeapi-fake` (once it exists) point the backend at it with
`FREEAPI_BASE_URL=http://freeapi-fake:8787`.

Node >= 20, no dependencies.

## Environment

| Variable | Default | Purpose |
| -------- | ------- | ------- |
| `PORT` | `8787` | Listen port (`0` picks a free port). |
| `CONTRACT_DIR` | `../../backend/tests/Fixtures/freeapi-contract/v1` (relative to the script) | Contract JSON directory. |
| `FAKE_BASE_URL` | `http://freeapi-fake:8787` | `base_url` returned by issuance and status. |
| `FAKE_TERMS_URL` | `https://web.synaplan.com/demo/terms` | `terms_url` returned by issuance. |
| `FAKE_POW_BITS` | `pow_bits` from `limits.json` | Proof-of-work difficulty. Use a low value (e.g. `8`) in tests. |
| `FAKE_MESSAGES_PER_DAY` | `messages_per_day` from `limits.json` | Daily message limit per key. |
| `FAKE_IMAGES_PER_DAY` | `images_per_day` from `limits.json` | Daily image limit per key. |
| `FAKE_PROTOCOL_MIN` | `1` | Minimum protocol; lower `protocol` / `X-Synaplan-Protocol` answers `freeapi_upgrade_required`. |
| `FAKE_FORCE_ERROR` | empty | A `freeapi_*` code from `errors.json`; every gateway and status call answers it. |

## Simulated errors

- `FAKE_FORCE_ERROR=<code>` forces the error on the gateway routes and `GET /v1/free/status`
  (issuance and revoke stay usable, so a test can still mint a key).
- The request header `X-Fake-Error: <code>` forces it for that single request on any route
  except `/healthz`.
- Errors use the OpenAI shape `{error:{message,type,param:null,code}}` with `http`, `type` and
  `message` from `errors.json`. 429 and 503 carry `Retry-After` (seconds until midnight Berlin
  time for `freeapi_limit_reached` / `freeapi_full_today`, otherwise 60).
- Natural errors: invalid issuance field (400 `invalid_request`), wrong proof of work
  (`freeapi_pow_required`), alias outside its route (`freeapi_not_included`), daily message /
  image / embed-token limit (`freeapi_limit_reached`), missing, unknown or revoked key
  (401 `invalid_api_key`), unknown route (404), body over 1 MiB (413).

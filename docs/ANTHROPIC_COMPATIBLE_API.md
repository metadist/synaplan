# Anthropic-Compatible Messages API

Synaplan exposes an Anthropic Messages API-compatible gateway so Claude Code and other Anthropic-protocol clients can use your instance as their backend.

> This is **not** Synaplan’s native chat SSE at `/api/v1/messages/stream`.  
> The gateway lives at **`POST /v1/messages`** (Claude Code calls `/v1/messages?beta=true`).

## Quick start (Claude Code)

1. Create an API key in Synaplan (**Channels → API Keys**).
2. Enable the gateway under **Channels → AI Agents** (admin: turn on “Enable Messages gateway”).
3. Save a BYO Anthropic key on that page (or allow the operator key carefully).
4. Configure Claude Code:

```bash
export ANTHROPIC_BASE_URL="https://your-synaplan-host"
export ANTHROPIC_API_KEY="sk_your_synaplan_api_key"
# or: export ANTHROPIC_AUTH_TOKEN="sk_your_synaplan_api_key"
# Set exactly one credential variable.
claude
```

`ANTHROPIC_API_KEY` is sent as `x-api-key`; `ANTHROPIC_AUTH_TOKEN` as `Authorization: Bearer`. Setting both is a common foot-gun — use exactly one.

## Endpoints

| Method | Path | Notes |
| ------ | ---- | ----- |
| `POST` | `/v1/messages` | Inference (streaming SSE when `stream: true`) |
| `POST` | `/v1/messages/count_tokens` | Optional; proxied for Anthropic models, 404 otherwise (Claude Code estimates locally) |
| `GET`  | `/v1/models` | Existing OpenAI-shaped list; Claude Code model discovery can use it |

## Auth

Same Synaplan API keys as the OpenAI-compatible API:

- `x-api-key: sk_…`
- `Authorization: Bearer sk_…`

## Feature flags (`BCONFIG` group `MESSAGES_GATEWAY`)

Defaults are **off** except budget notices:

| Setting | Default | Meaning |
| ------- | ------- | ------- |
| `ENABLED` | `0` | Master switch |
| `ALLOW_OPERATOR_KEY` | `0` | Fall back to the install-wide Anthropic key |
| `UPSTREAM_URL` | `https://api.anthropic.com` | Admin-set global upstream (HTTPS; http only for loopback/private) |
| `MODEL_ALIASES` | `{}` | Map Claude Code model IDs → catalog IDs |
| `BUDGET_NOTICE_ENABLED` | `1` | One-time ≥90% budget notice in the response |

Env fallback for local smoke tests: `MESSAGES_GATEWAY_UPSTREAM_URL` (DB value wins in production).

## Metering

Usage is recorded as `API_CHAT` with `source: MESSAGES_API`, including Anthropic cache token fields. Rate limits use the `MESSAGES` action. Cost budget is enforced before the request; concurrent streams may slightly overshoot (documented limitation).

## Related

- User docs: [docs.synaplan.com — Claude Code](https://docs.synaplan.com/) (page `claude-code`)
- OpenAI-compatible sibling: [OPENAI_COMPATIBLE_API.md](./OPENAI_COMPATIBLE_API.md)
- UI: **Channels → AI Agents**

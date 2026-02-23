# API Improvements: OpenAI Compatibility & Enhancements

Synaplan exposes an **OpenAI-compatible API** so any developer using an OpenAI SDK can point it at Synaplan instead. All changes are **additive** — existing endpoints are untouched.

## Plan Files

| # | File | Feature | Status |
|---|------|---------|--------|
| 1 | [01-OPENAI-COMPATIBLE.md](./01-OPENAI-COMPATIBLE.md) | `POST /v1/chat/completions`, `GET /v1/models`, `Bearer` auth, OpenAI-format SSE | Done |
| 2 | [02-API-CUSTOM-PROMPTS.md](./02-API-CUSTOM-PROMPTS.md) | `promptTopic` / `promptId` params on stream endpoint | Done |
| 3 | [03-URL-SCREENSHOT-FIX.md](./03-URL-SCREENSHOT-FIX.md) | URL content extraction tool (naming fix + backend + pipeline) | Done |
| 4 | [04-TEST-STRATEGY.md](./04-TEST-STRATEGY.md) | Test matrix | Reference |

## Reference

- [00-ORIGINAL-PLAN.md](./00-ORIGINAL-PLAN.md) — Earlier "API Standardization" plan that preceded this work. Phase 1 (OpenAI compat) is now done. Phase 2 (Tools/Knowledge API aliases) is future work.

## Principles

- **No regressions.** Existing `/api/v1/` endpoints are untouched.
- **Additive.** New `/v1/` endpoints are a thin translation layer over existing services.
- **Minimal.** Reuse `AiFacade`, `ModelConfigService`, `ApiKeyAuthenticator` — no new services.

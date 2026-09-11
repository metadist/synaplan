# A2Agent provider — status

Step log for [`00_master_plan.md`](./00_master_plan.md). One row per step;
the PR column is filled when the branch is opened. Decisions are in the plan's
§10, not here.

## Steps

| Step | Name | State | Branch / PR | Notes |
| ---- | ---- | ----- | ----------- | ----- |
| S0 | Zero-code spike via the OpenAI-compatible endpoint registry | skipped | — | Decided 2026-09-11 (§4 row 11). Its checks run as S1b step 0. |
| S1a | Base-class refactor (`AbstractChatCompletionsCloudProvider`, TrustedTokens moved onto it) | done | `plan/new-token-provider` / #1832 | Same branch as S1b–S3 per product request. TrustedTokens tests kept. |
| S1b | `A2AgentProvider` + key catalog + defaults + six catalog rows (BIDs 361–366) + data migration | done | `plan/new-token-provider` / #1832 | `ChatCompletionsUpstreams` registers `a2agent` so Desktop / Claude Code aliases reach these models. In-app chat uses the new provider. |
| S2 | Frontend: icon, CN badge, key help (5 locales), `a2agent` mix, regenerated schemas | done | `plan/new-token-provider` / #1832 | |
| S3 | Docs (`CONFIGURATION`, `PRICING_MAINTENANCE`, `PRICE_DRIFT_PROCEDURE`, `ANTHROPIC_COMPATIBLE_API`, `README`) | done | `plan/new-token-provider` / #1832 | Platform `.env` change remains ops in `synaplan-platform`, never committed. |

## S1b step 0 — live verification (fill against the dev key)

| Check | Result | Date | Consequence |
| ----- | ------ | ---- | ----------- |
| `GET /v1/models` shape + five ids listed | `{"data":[{"id":…}]}` (17 ids). Four lowercase ids present. MiniMax is `MiniMax-M3` (mixed case), not `minimax-m3`. | 2026-09-11 | Catalog `providerId` is `MiniMax-M3`. Probe works unchanged. |
| Stream returns final usage chunk (`include_usage`) | Yes — last chunk carries `usage` plus `reasoning_content` deltas on DeepSeek V4 Pro. | 2026-09-11 | `parseUsage()` unchanged. |
| Reasoning format per model (`reasoning_content` vs inline `<think>`) | DeepSeek V4 Pro/Flash and Qwen3.8 MAX/Flash: `delta.reasoning_content`. MiniMax M3: inline `<think>` in `content`, no `reasoning_content`. | 2026-09-11 | Keep `features.reasoning` on all five; frontend already renders both. |
| `tools` / `tool_choice` pass through; `delta.tool_calls` stream (all five) | Non-stream `tool_calls` confirmed on DeepSeek V4 Pro and MiniMax M3. | 2026-09-11 | `a2agent` added to `CatalogToolUse::CAPABLE_CHAT_SERVICES`. |
| `response_format: json_schema` accepted | Accepted on `qwen3.8-flash`; returned `{"ping":"pong"}`. | 2026-09-11 | `a2agent` added to `OPENAI_JSON_SCHEMA_PROVIDERS`. |
| Vision `image_url` data URL on `qwen3.8-flash` | Works for PNG ≥ 10×10 px (`Red` on a 32×32 sample). 1×1 PNG is rejected. | 2026-09-11 | PIC2TEXT twin ships. |
| `max_tokens` accepted; max output per model | `max_tokens` accepted; MiniMax hit `finish_reason=length` at 64 on a thinking reply. | 2026-09-11 | Catalog `max_tokens` / `max_output` stay 32768. |
| Thinking latency on a short prompt; can thinking be disabled | Short "pong" still emits reasoning (DeepSeek ~125 tokens). `thinking: {type: disabled}` turns it off (18 tokens, no `reasoning_content`). `enable_thinking: false` is ignored. | 2026-09-11 | MAIN stays DeepSeek V4 Pro. Disable flag exists if chat latency becomes a problem. |
| 429 shape; `/v1/usage` fields | 429 `{"type":"rate_limit_error","message":"Upstream rate limit exceeded…"}`. `/v1/usage`: `quota.limit/remaining/used` USD, windows 20/5h, 40/1d, 200/7d. Dev key: $500 remaining, `mode=quota_limited`. | 2026-09-11 | Documented in STATUS; catalog prices unchanged. |
| Billed rate vs public list for the key's group | `/v1/usage.used = 0` after the probe set — no invoice delta yet. | 2026-09-11 | Keep public group rates; compare after first live invoices. |

## Findings

- `AI/Messages/Translator/ChatCompletionsUpstreams` registers `a2agent` at `https://a2agent.me/v1/chat/completions`. The Messages gateway translates Anthropic Messages to Chat Completions for Desktop / Claude Code aliases; in-app chat uses `A2AgentProvider` directly.
- New BIDs 361–366 land via `ModelSeeder` and `Version20260911180000` (INSERT if absent, fingerprint-safe upsert). Operator toggles are never overwritten.
- Live model id for MiniMax is **`MiniMax-M3`** (case-sensitive). The marketing slug `minimax-m3` is not in `GET /v1/models`.
- Thinking can be turned off on DeepSeek V4 Pro with `thinking: {type: "disabled"}`. `A2AgentProvider` maps ChatHandler's `reasoning` toggle to that field (off → disabled; on → omit so the gateway default stays on). TrustedTokens does not receive the field.

# A2Agent provider — status

Step log for [`00_master_plan.md`](./00_master_plan.md). One row per step;
the PR column is filled when the branch is opened. Decisions are in the plan's
§10, not here.

## Steps

| Step | Name | State | Branch / PR | Notes |
| ---- | ---- | ----- | ----------- | ----- |
| S0 | Zero-code spike via the OpenAI-compatible endpoint registry | skipped | — | Decided 2026-09-11 (§4 row 11). Its checks run as S1b step 0. |
| S1a | Base-class refactor (`AbstractChatCompletionsCloudProvider`, TrustedTokens moved onto it) | pending | — | `refactor(backend)`. TrustedTokens tests must pass unchanged. |
| S1b | `A2AgentProvider` + key catalog + defaults + Messages upstream + six catalog rows (BIDs 361–366) | pending | — | `feat(backend)`. Step 0 verification table below must be filled first. |
| S2 | Frontend: icon, CN badge, key help (5 locales), `a2agent` mix, regenerated schemas | pending | — | `feat(frontend)`, `ota-candidate`. |
| S3 | Docs (`CONFIGURATION`, `PRICING_MAINTENANCE`, `PRICE_DRIFT_PROCEDURE`, `ANTHROPIC_COMPATIBLE_API`, `README`), synaplan-docs page, platform key rollout | pending | — | `docs`. Platform `.env` change is ops in `synaplan-platform`, never committed. |

## S1b step 0 — live verification (fill against the dev key)

| Check | Result | Date | Consequence |
| ----- | ------ | ---- | ----------- |
| `GET /v1/models` shape + five ids listed | | | |
| Stream returns final usage chunk (`include_usage`) | | | |
| Reasoning format per model (`reasoning_content` vs inline `<think>`) | | | |
| `tools` / `tool_choice` pass through; `delta.tool_calls` stream (all five) | | | |
| `response_format: json_schema` accepted | | | |
| Vision `image_url` data URL on `qwen3.8-flash` | | | |
| `max_tokens` accepted; max output per model | | | |
| Thinking latency on a short prompt; can thinking be disabled | | | |
| 429 shape; `/v1/usage` fields | | | |
| Billed rate vs public list for the key's group | | | |

## Findings

(none yet)

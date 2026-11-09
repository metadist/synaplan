# Findings — coding backend

Read on 2026-11-09 from this repository. Every path is relative to the
repository root. Prices are USD per million tokens from
`backend/src/Model/ModelCatalog.php`, before operator markup.

## 1. The two gateways

| | Anthropic shape | OpenAI shape |
| --- | --- | --- |
| Route | `POST /v1/messages` | `POST /v1/chat/completions` |
| Code | `backend/src/AI/Messages/MessagesGateway.php`, `prepare()` | `backend/src/Controller/OpenAICompatibleController.php`, `resolveModel()` |
| Also | `POST /v1/messages/count_tokens`, `GET /v1/models` | `GET /v1/models` |
| Auth | `x-api-key: sk_…` or `Authorization: Bearer sk_…` | Bearer |
| Switch | `MESSAGES_GATEWAY.ENABLED`, shipped off | Always on |
| Model lookup | `MessagesModelResolver`: catalog key → `providerId` → name → `MODEL_ALIASES` → strip `-YYYYMMDD` | `providerId` → name → Chat default when `model` is omitted |
| Empty model | 404. Exception: paired Desktop key gets the Chat default (`DesktopOmittedModel`). | Chat default, or 404 |
| Unknown model | 404 | 404 |
| Router | Not called | Not called |
| Usage row | `BUSELOG`, action `MESSAGES`, `BMETADATA.source = MESSAGES_API` | `source = OPENAI_API` |
| Who pays | Operator key: the cost budget. User key: tokens stored, cost 0, Pro plan required. | Same |

Client tools (Bash, Read, Edit) are relayed. The editor runs them.
Synaplan runs a tool only when a switch says so (MCP, web search,
`analyze_image`).

`MessagesGateway` translates onto the row's provider: Anthropic
passthrough, Gemini, OpenAI Chat Completions, OpenAI Responses (rows
with `meta.api = responses`, GPT-6 Sol is one). No new provider is
needed.

`ApiSessionClient::fromRequest()` labels a session. A full-access key is
always "Claude Code". The `User-Agent` is read only for
`synaplan-desktop`.

## 2. Facts that shape the steps

| Fact | Where | Consequence |
| ---- | ----- | ----------- |
| A missing `DEFAULTMODEL` row is "no value". | `ModelConfigService::getConfiguredDefaultModel()` returns `null`. | No migration. No seeder row. |
| `getDefaultModel()` falls back to the best usable model by quality. | `ModelConfigService` lines 424–460 | Never use it for the pin. |
| `GET /api/v1/config/models/defaults` uses that fallback. | `ModelDefaultsService::userDefaults()` → `reportedDefault()` | The page reads the pin from `/api/v1/coding/model`, not from the defaults route. |
| Saves drop unknown capabilities. | `ModelDefaultsService::write()` checks `PickerCapabilities::ALL`. | Add `CODING` to `PickerCapabilities::ALL`. |
| Lock and allow-list are checked on save. | `ModelDefaultsService::assertGroupPolicyAllows()` | Save through `ModelDefaultsService::save()`. |
| Deleting one default row exists. | `ConfigRepository::deleteValue($ownerId, 'DEFAULTMODEL', 'CODING')` | **Clear** calls it. |
| Window and output accessors exist. | `Model::getContextWindowTokens()`, `Model::getMaxOutputTokens()`, `Model::hasFeature()` | No parsing in new code. |
| Long-context price accessor exists. | `ModelCatalog::contextPricing(string $providerId): ?array` | Use it on the page. |
| Catalog key helper exists. | `GroupPolicyService::catalogKey($service, $providerId, $tag)` | Use it in `GET /v1/coding`. |
| Source of a stored default exists. | `GroupPolicyService::sourceOfStoredModel($userId, 'CODING', $modelId)` → `user` / `group` / `admin` | Use it for "Your group chose this model." |
| Lock check exists. | `LayeredConfigResolver::isLocked('DEFAULTMODEL', 'CODING', $userId)` | Use it to disable the picker. |
| `/v1/*` scope. | `ApiKeyScope::prefixScopes()` returns `desktop:messages` for any `/v1/` path. | A Desktop key may read `GET /v1/coding`. The pin is still not applied to it. |
| Paired Desktop and free API detection exist. | `ApiKeyScope::isPairedDesktop()`, `ApiKeyScope::isFreeApiKey()` | Used to exclude those keys from the pin. |
| Size ratchet, backend. | `backend/tests/Architecture/sizeRatchetBaseline.json` | `MessagesGateway.php` 1,025 (file 1,022). `prepare()` 284. `OpenAICompatibleController.php` 851 (file 851). `UsageStatsService.php` 960 (file 960). These files may only shrink. |
| Size ratchet, frontend. | `frontend/tests/unit/architecture/sizeRatchetBaseline.json`, 800 lines per file, 100 per function | `UsageStatistics.vue` is 793. `AIModelsConfiguration.vue` is 1,932 and frozen. |
| Wording guard. | `frontend/tests/unit/i18n/wordingGuard.spec.ts` | "coding client(s)" is banned in `nav.`, `pageTitles.`, `apps.`, `tours.` keys. |
| Locale home. | `frontend/src/i18n/locales/*/tools.json`, key `messagesGateway` | New page strings go there. The Apps label `aiAgents` is in `core.json`. |
| E2E. | `frontend/tests/e2e/tests/*.spec.ts` | No spec covers the Coding page today. |
| Policies list. | `PolicyAllowList::DEFAULT_MODEL_SETTINGS` and `PoliciesTab.vue` line 234 | Both need `CODING` (CB5). |
| Mobile impact. | `.github/mobile-impact-policy.json` | `_devextras/**` and `docs/**` are `no-app-impact`. `backend/**` is `backend-only`. `frontend/**` is `ota-candidate`. |

## 3. What each editor needs

Confirmed against the editors' docs on 2026-11-09. Re-check in the week
CB3 is written.

### Claude Code

```bash
export ANTHROPIC_BASE_URL="https://<host>"
export ANTHROPIC_API_KEY="sk_…"
# or ANTHROPIC_AUTH_TOKEN. Set exactly one.
```

The base URL is the host, not `/v1`. On first start, answer **yes** to
"use this key" and "trust this folder".

Claude Code picks the model itself. Set all of these to the pin id to
stop the split: `ANTHROPIC_MODEL`, `ANTHROPIC_DEFAULT_OPUS_MODEL`,
`ANTHROPIC_DEFAULT_SONNET_MODEL`, `ANTHROPIC_DEFAULT_HAIKU_MODEL`,
`ANTHROPIC_SMALL_FAST_MODEL`.

It does not read `context_window` from `GET /v1/models`. For a Claude
id, its own table is right. For any other id, it packs as if the name
were a Claude model.

### Pi

File `~/.pi/agent/models.json`. A custom provider needs `baseUrl` and
`api`.

| `api` | Synaplan base |
| ----- | ------------- |
| `anthropic-messages` | `https://<host>` |
| `openai-completions` | `https://<host>/v1` |

Each model needs `id`, `contextWindow`, `maxTokens`, and `cost`.
Defaults when omitted: **128,000** and **16,384**.

### OpenCode

File `opencode.jsonc`. Current schema: `providers.<id>.package`,
`settings.baseURL`, `models.<key>.modelID`, `limit.context`,
`limit.output`. Older files use `provider`, `npm`, `options.baseURL`.
Emit the current one.

Packages: `@opencode/ai/providers/anthropic-compatible` or
`@opencode/ai/providers/openai-compatible`.

### Everyone else

Cursor, Continue, Cline, Aider, Neovim clients: OpenAI shape,
`https://<host>/v1`, the key, the pin id. Write the window into their
settings by hand.

## 4. Where a second model appears today

| Behaviour | When | With a pin |
| --------- | ---- | ---------- |
| `MODEL_ALIASES` | Always, install-wide | Ignored for that account |
| Vision rewrite | `VISION_MODE=auto` and the model has no vision | Skipped |
| Chat default | `model` omitted on Chat Completions, or paired Desktop | Desktop unchanged; others get the pin |
| Server search, MCP, `analyze_image` | Switches | Stay. A tool may use its own model. |
| Session summary | `SESSION_SUMMARY_ENABLED`, sorting model | Stays. The page says so. |
| Assistant alias | `assistant:slug` on Chat Completions | Keeps the assistant's model |

## 5. Models worth pinning

About 60 active chat rows have `tool_use`. Under 500,000 tokens the
session dies on a medium repository. Chat and vision rows can share a
provider id, so the pin stores the chat row's id.

| Use | Provider id | Window | Max output | In / out | Screenshots |
| --- | ----------- | ------ | ---------- | -------- | ----------- |
| Daily Claude | `claude-sonnet-5-5` | 1,000,000 | 128,000 | $2 / $10 | yes |
| Heavier Claude | `claude-opus-5-5` | 1,000,000 | 128,000 | $4 / $20 | yes |
| Highest Claude | `claude-fable-5` | 1,000,000 | 128,000 | $10 / $50 | yes |
| OpenAI, balanced | `gpt-6-sol` | 1,050,000 | 128,000 | $2 / $10; $4 / $15 above 272,000 input | yes |
| OpenAI, flagship | `gpt-5.6-sol` | 1,050,000 | 128,000 | $4 / $20; $8 / $30 above 272,000 | yes |
| OpenAI, cheap | `gpt-6-luna` | 1,050,000 | 128,000 | $0.10 / $0.50 | yes |
| Gemini | `gemini-3.1-pro-preview` | 1,048,576 | 65,536 | $2 / $12; $4 / $18 above 200,000 | yes |
| Cheap 1M | `deepseek-v4-flash` (A2Agent) | 1,000,000 | 32,768 | $0.14 / $0.28 | no |
| Cheap 1M | `zai-org/GLM-5.3-Flash` | 1,048,576 | 32,768 | $0.15 / $0.30 | yes |

Weak as the only model:

| Provider id | Window | Why |
| --- | --- | --- |
| `claude-haiku-4-5-20251001` | 200,000 | Claude Code's fast slot. Fine as a label, poor as the one model. |
| `gpt-5.4` | 270,000 | Tight for an agent session. |
| `grok-4.7` | 500,000 | On the line. The warning does not fire. |
| Groq `openai/gpt-oss-120b` | 131,072 | Cheap. The window is the limit. No vision. |

The seeded Chat default is `anthropic:claude-sonnet-5:chat`. It is not
copied to `CODING`.

## 6. Statistics today

`UsageStatsService::getUserStats()` returns rate-limit counters, the
cost budget, channel counts from `BMETADATA.source`, and the latest
rows with tokens and cache fields. There is no per-account total by
model. `getOverallStats()` (admin) groups by model, top 10, mixed with
web chat.

`UsageStatistics.vue` already draws `MESSAGES_API` and `OPENAI_API` with
a console icon.

## 7. Release class

| Change | Class |
| ------ | ----- |
| PHP on the gateways, usage, config | backend-only |
| The Apps page, cards, five locales | ota-candidate |
| This folder, the two API guides | no-app-impact |

A `feat` subject bumps the minor version. CB1 is the first `feat`.

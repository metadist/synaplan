# Findings — developer backend

Read on 2026-11-09 from this repository, and corrected the same day
against Copilot's review of the first draft. Every path is relative to
the repository root. Prices are USD per million tokens from
`backend/src/Model/ModelCatalog.php`, before operator markup.

The product shape is in [`00_master_plan.md`](./00_master_plan.md).
This file is the code those steps have to fit.

## 1. The two gateways

| | Anthropic shape | OpenAI shape |
| --- | --- | --- |
| Route | `POST /v1/messages` | `POST /v1/chat/completions` |
| Code | `backend/src/AI/Messages/MessagesGateway.php`, `prepare()` | `backend/src/Controller/OpenAICompatibleController.php` |
| Model lookup | `MessagesModelResolver::resolve()` | `resolveModel()` (private, lines 762–807) |
| Also | `POST /v1/messages/count_tokens`, `GET /v1/models` | `GET /v1/models` |
| Auth | `x-api-key: sk_…` or `Authorization: Bearer sk_…` | Bearer |
| Switch | `MESSAGES_GATEWAY.ENABLED`, shipped off | Always on |
| Empty model | 404. Exception: a paired Desktop key gets the Chat default (`DesktopOmittedModel`) before `resolve()`. | Chat default via `getDefaultModel('CHAT')`, for every key |
| Unknown model | 404 | Not 404. An unknown non-empty name falls through to the Chat default, then 404 only if that is also missing |
| Aliases | `MODEL_ALIASES`, after a catalog-key hit, before provider id / name | Not consulted |
| Router | Not called | Not called |
| Billed usage row | One row, action `MESSAGES`, `BMETADATA.source = MESSAGES_API`, tokens kept. `key_source` is `user` or `operator`. Own key sets `zero_cost`. | The facade writes action `AI_CALL`. The controller then writes a second row (§6). |
| Who pays | Operator key: the cost budget. User key: tokens stored, cost 0, Pro plan required. | The facade meters the provider call. The controller's second row is not the bill. |

**Messages resolve order** (`MessagesModelResolver::resolve`):

1. Catalog key, when the string contains `:` and `ModelCatalog::findBidByKey` finds an active row. Aliases are not applied to a catalog-key hit.
2. Alias map: exact key, then a trailing `*` pattern. This rewrites the candidate **before** provider id / name.
3. Active row by provider id, then by name (`findActiveModel`).
4. Strip a trailing `-YYYYMMDD` and retry step 3.
5. Null. The gateway answers 404.

**Chat Completions resolve order** (`resolveModel`):

1. Configured CHAT default, if that row is tag `chat` and its provider id or name equals the string.
2. Configured SORT default, on the same test. A picture-to-text default is skipped because the tag test fails.
3. `findActiveByTagAndIdentity('chat', string)`.
4. Any active row by provider id, then by name.
5. `getDefaultModel('CHAT', $userId)`, which may fall back to the best usable model. This step runs for an unknown non-empty name and for an empty name.
6. Null. The controller answers 404.

Editor-chooses mode calls these two functions as they are. The
one-model branch stands in front of them and does not call them.

Client tools (Bash, Read, Edit) are relayed. The editor runs them.
Synaplan runs a tool only when a switch says so (MCP, web search,
`analyze_image`).

`MessagesGateway` translates onto the row's provider: Anthropic
passthrough, Gemini, OpenAI Chat Completions, OpenAI Responses (rows
with `meta.api = responses`, GPT-6 Sol is one). No new provider is
needed.

`ApiSessionClient::fromRequest()` labels a session. A full-access key
is always "Claude Code". The `User-Agent` is read only for
`synaplan-desktop`.

## 2. Where the response model comes from

`MessagesModelResolver::resolveSelected()` sets `requested`,
`displayModel`, and `providerModelId` from the row. A one-model call
that stops there records the pin id as `requested_model`. The
selection class has to overwrite `requested` and `displayModel` with
the editor's string after `resolveSelected()` returns.

Chat Completions already prints `displayModel`:
`handleNonStream` and `handleStream` take it as an argument. Setting
the field is enough on that route.

`/v1/messages` does not print `displayModel`.

| Translator | What it puts in `model` | File |
| ---------- | ----------------------- | ---- |
| OpenAI messages | The request body, which the gateway rewrote to the upstream id. Non-stream and `message_start`. | `Translator/OpenAiMessagesTranslator.php` (1,450 lines; size baseline 1,499) |
| Gemini messages | The request body, same way. | `Translator/GeminiMessagesTranslator.php` |
| Anthropic passthrough | The upstream event, unchanged. `message_start` carries the upstream id. The translator emits raw chunks (`$emit($content)`). | `Translator/AnthropicPassthroughTranslator.php` |

The translator context built in `prepare()` has no display-model
field. CB2 does not thread a new parameter through the OpenAI
translator. `CodingResponseModel` rewrites the editor-facing payload
on the way out (master plan §5.3).

## 3. Facts that shape the steps

| Fact | Where | Consequence |
| ---- | ----- | ----------- |
| A missing `DEFAULTMODEL` row is "no value". | `getConfiguredDefaultModel()` returns null. | No migration. No seeder. No row means editor chooses. |
| That getter skips an inactive row and continues. | `ModelConfigService.php` lines 471–492 | A retired user pin would silently become the group pin, or unset. The pin reader does not use it. An inactive stored id stays invalid and the gateway returns 404. |
| `getDefaultModel()` falls back to the best usable model. | Lines 424–460 | Never use it for the pin. Chat Completions still uses it in editor-chooses mode, including for an unknown name. |
| Group rows are `BGROUPCONFIG`, keyed by `BGROUPID`. | `GroupConfig` | A group id written as `BCONFIG.BOWNERID` would be some other user's config. Group writes go through `putGroupConfig()` only. |
| The group layer is invisible until the key is allow-listed. | `LayeredConfigResolver::usesGroupLayer()` → `PolicyAllowList::contains()` | CB1 adds `CODING` to `DEFAULT_MODEL_SETTINGS`, or a group row never resolves. |
| Group default models merge first-wins. | `PolicyAllowList::mergeRule()` for `DEFAULTMODEL.*` | Two groups: the first group in the member's list supplies the value. Same rule as Chat. |
| Group values may be a catalog key or a numeric id. | `modelIdFromStored()` | The reader accepts both. User saves through `ModelDefaultsService::save()` store a numeric id. |
| The token `editor` is not a model id. | `modelIdFromStored('editor')` is null | It is the opt-out. Only `ConfigRepository::setValue()` writes it. `save()` and `putGroupConfig()` reject it (`validateStoredValue`). |
| Locks are installation-wide. | `isLocked()` reads owner 0. `setLocks()` throws `MissingInstanceDefaultException` unless that row exists and is non-empty. | No group lock. No owner-0 `CODING` row. The Policies screen does not offer a lock for this setting. |
| Saves drop unknown capabilities. | `ModelDefaultsService::write()` checks `PickerCapabilities::ALL` | Add `CODING` there, and to the `CHAT` tag list, and to both `CAPABILITY_TAGS` maps. |
| Deleting one default row exists. | `ConfigRepository::deleteValue` and `setValue` | Opt-out and "use the group model" call them. |
| Window, output, long-context price. | `Model::getContextWindowTokens()`, `getMaxOutputTokens()`, `ModelCatalog::contextPricing($providerId)` | `contextPricing` returns null, or `threshold_tokens`, `price_in_above`, `price_out_above`, and sometimes `cache_price_in_above`. The config-models payload does not include it. |
| `/v1/*` scope. | `ApiKeyScope::prefixScopes()` returns `desktop:messages` for any `/v1/` path | A Desktop key may read `GET /v1/coding`. The one-model branch still does not apply to it. |
| Paired Desktop and free API. | `ApiKeyScope::isPairedDesktop()`, `isFreeApiKey()` | `isPinnable()` is false for both. |
| Size ratchet, backend. | `sizeRatchetBaseline.json` | `MessagesGateway.php` 1,025 (file 1,022). `prepare()` 284. `OpenAICompatibleController.php` 851 (file 851). `UsageStatsService.php` 960 (file 960). `OpenAiMessagesTranslator.php` baseline 1,499 (file 1,450). These files may only shrink. |
| Size ratchet, frontend. | 800 lines per file | `UsageStatistics.vue` is 793. `AIModelsConfiguration.vue` is 1,932 and frozen. `MessagesGatewayConfiguration.vue` is the Claude Code page; the new page is a new component so this file gains one link, not the mode control. |
| Wording guard. | `wordingGuard.spec.ts` | "coding client(s)" is banned in `nav.`, `pageTitles.`, `apps.`, `tours.` keys. |
| Locale home. | `tools.json`, namespace list in `namespaces.ts` | New page strings are the `developerBackend` key. Add that key to the `tools` list. Catalog copy is `apps.items.developerBackend`. |
| Policies list. | `PolicyAllowList::DEFAULT_MODEL_SETTINGS` and `PoliciesTab.vue` line 234 | The constant is CB1, because the read path needs it. The picker is CB5. The lock control is not copied onto `CODING`. |
| Screens today. | `catalog.ts` id `claude-code`; `CodingGatewayAdminTab.vue`; admin route `/admin/setup?tab=gateway` | Connection and the install switch already have pages. The model choice does not. |
| Mobile impact. | `.github/mobile-impact-policy.json` | `_devextras/**` and `docs/**` are `no-app-impact`. `backend/**` is `backend-only`. `frontend/**` is `ota-candidate`. |

## 4. What each editor needs

Confirmed against the editors' docs on 2026-11-09. Re-check in the week
CB3 is written.

The blocks in editor-chooses mode do not assign a model. The blocks in
one-model mode assign the pin id everywhere below.

### Claude Code

```bash
export ANTHROPIC_BASE_URL="https://<host>"
export ANTHROPIC_API_KEY="sk_…"
# or ANTHROPIC_AUTH_TOKEN. Set exactly one.
```

The base URL is the host, not `/v1`. On first start, answer **yes** to
"use this key" and "trust this folder".

Five variables, and they may differ: `ANTHROPIC_MODEL`,
`ANTHROPIC_DEFAULT_OPUS_MODEL`, `ANTHROPIC_DEFAULT_SONNET_MODEL`,
`ANTHROPIC_DEFAULT_HAIKU_MODEL`, `ANTHROPIC_SMALL_FAST_MODEL`.
One-model mode sets all five to the pin id. Editor-chooses mode prints
them as comments.

Claude Code does not read `context_window` from `GET /v1/models`. For
a Claude id, its own table is right. For any other id, it packs as if
the name were a Claude model. The one-model block says so when the pin
is not an Anthropic id, and points at Pi or OpenCode.

### Pi

File `~/.pi/agent/models.json`. A custom provider needs `baseUrl` and
`api`.

| `api` | Synaplan base |
| ----- | ------------- |
| `anthropic-messages` | `https://<host>` |
| `openai-completions` | `https://<host>/v1` |

Each model needs `id`, `contextWindow`, `maxTokens`, and `cost`.
Defaults when omitted: **128,000** and **16,384**. Editor-chooses mode
shows one object per highlighted row and says to repeat it. One-model
mode fills the one object.

### OpenCode

File `opencode.jsonc`. Current schema: `providers.<id>.package`,
`settings.baseURL`, `models.<key>.modelID`, `limit.context`,
`limit.output`. Older files use `provider`, `npm`, `options.baseURL`.
Emit the current one.

Packages: `@opencode/ai/providers/anthropic-compatible` or
`@opencode/ai/providers/openai-compatible`.

### Everyone else

Cursor, Continue, Cline, Aider, Neovim clients, and a client the
developer wrote: OpenAI shape, `https://<host>/v1`, the key. In
editor-chooses mode the model id is whatever they send, and the block
says to take the window from the list. In one-model mode the block
names the pin id. The window is a comment either way.

## 5. Where a second model appears today

| Behaviour | When | Editor chooses | One model |
| --------- | ---- | -------------- | --------- |
| `MODEL_ALIASES` | Messages only, install-wide | Unchanged | Not consulted |
| Vision rewrite | `VISION_MODE=auto` and the model has no vision | Unchanged | Skipped. Images are forwarded. |
| Chat default | Omitted model on Chat Completions, unknown Chat Completions name, or paired Desktop on Messages | Unchanged | Not reached for a pinnable key |
| Server search, MCP, `analyze_image` | Switches | Stay | Stay. A tool may use its own model. |
| Session summary | Sorting model | Stays | Stays. The page says so. |
| Assistant alias | `assistant:slug` on Chat Completions | Keeps the assistant's model | Keeps the assistant's model |
| Group Chat default | The chain | Not a coding model | Not read. Coding has its own group row. |

## 6. Statistics today

`UsageStatsService::getUserStats()` returns rate-limit counters, the
cost budget, channel counts from `BMETADATA.source`, and the latest
rows. There is no per-account total by model.

Chat Completions writes **two** rows:

1. `AiFacade::withUsage()` → `AiChatUsageMeter::record()` → action
   `AI_CALL`. This row has the provider, the model, the tokens, and
   the cost. Its source is `usage_source` from the options, or
   `ai_facade` when the controller does not set one. The OpenAI
   controller does not set one today.
2. `recordChatUsage(FacadeMeteredChat::messages(..., 'OPENAI_API'))`.
   When `facade_metered` is set, `logMetadata()` clears `usage`,
   `model`, `model_id`, `provider`, and both texts. The surviving row
   is the message-allowance marker. Summing `source = OPENAI_API`
   without excluding it counts zeros and misses the bill.

`AiChatUsageMeter::claim()` copies `usage_source`, `chat_id`,
`agentId`, and `agentVersionId` off the options. It does not copy a
client, a requested model, or a payment source. Those have to be added
to `claim()` or they never reach the billed row, and they must be
removed from the options the provider sees.

Messages is the opposite shape: `MessagesGateway::recordUsage()` writes
the billed row directly (action `MESSAGES`, source `MESSAGES_API`,
`key_source`, `zero_cost` when the source is `user`). The pipeline
meter does not write a second billed row for that call.

`UserProviderKeyResolver::resolve()` returns `source` `user` or
`operator`. `MessagesGateway::resolveCredential()` maps a local host
(`isLocalHost`) to `operator`. A cost of 0 is also what a free catalog
row and a local model produce on an operator key. The card cannot
tell those apart from an own key unless `key_source` is on the row.

`UsageOutcome` writes action `DEMO` with source `OPENAI_API` and a
subject such as `key`. Those rows are not coding calls.

`UsageStatistics.vue` already draws `MESSAGES_API` and `OPENAI_API`
with a console icon.

## 7. Models worth offering

About 60 active chat rows have `tool_use`. The editor-chooses list is
every active chat row the account may use. The one-model picker is the
`tool_use` subset, because a coding agent calls tools. Under 500,000
tokens the session dies on a medium repository. The pin stores the
chat row's id so the vision twin is not billed by mistake.

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

Weak as the only model. Fine as one entry in editor-chooses mode.

| Provider id | Window | Why |
| --- | --- | --- |
| `claude-haiku-4-5-20251001` | 200,000 | Claude Code's fast slot. Fine as a label, poor as the one model. |
| `gpt-5.4` | 270,000 | Tight for an agent session. |
| `grok-4.7` | 500,000 | On the line. The warning does not fire. |
| Groq `openai/gpt-oss-120b` | 131,072 | Cheap. The window is the limit. No vision. |

The seeded Chat default is `anthropic:claude-sonnet-5:chat`. It is not
copied to `CODING`.

## 8. Release class

| Change | Class |
| ------ | ----- |
| PHP on the gateways, usage, config | backend-only |
| The new Apps page, the link on the Claude Code page, five locales | ota-candidate |
| This folder, the two API guides | no-app-impact |

A `feat` subject bumps the minor version. CB1 is the first `feat`.

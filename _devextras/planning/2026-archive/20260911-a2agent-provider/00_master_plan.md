# A2Agent provider — Chinese frontier models for the Asian market

**Date:** 2026-09-11
**Status:** Plan of record. §4 decision checklist **ticked by the product
owner on 2026-09-11** (log in §10). Implementation may start now, S1a first.
Step state lives in [`STATUS.md`](./STATUS.md).
**Trigger:** Product request to add 3–4 models the catalog does not have and
to open the Asian market with Chinese models, using the token gateway
[a2agent.me](https://a2agent.me/) (Omnimodel Technology Limited).
**Shape of the work:** the same class of change as the TrustedTokens
provider added on 2026-08-29 (BIDs 331–337): one OpenAI-compatible cloud
provider with a fixed base URL, a platform API key, catalog rows, and the
admin/frontend surfaces every keyed provider has — preceded by one
`refactor:` PR that extracts the shared base class both resellers use.
**Related:**
[`../20260826-provider-aware-model-catalog/README.md`](../20260826-provider-aware-model-catalog/README.md)
(read-time availability filtering — new rows are invisible until a key exists),
[`../20260910-feature-modules/00_master_plan.md`](../20260910-feature-modules/00_master_plan.md)
(Intermezzo — keyed cloud providers stay core, registries become lazy),
[`../20260910_roadmap_update.md`](../20260910_roadmap_update.md) §1 (binding
order of work; this plan runs in parallel with the two bugfixes by decision
§10 row 10).

---

## 0. TL;DR

| Question | Answer |
| -------- | ------ |
| Is A2Agent OpenAI-compatible? | **Yes.** Chat Completions at `POST https://a2agent.me/v1/chat/completions`, `Authorization: Bearer sk-…`, plus the Responses API (`/v1/responses`), the Anthropic Messages API (`/v1/messages`) and a Gemini face on the **same key**. `GET /v1/models` exists (answers `401 {"code":"INVALID_API_KEY"}` without a valid key — verified 2026-09-11), so key validation and the hourly model-list health probe work like Groq/xAI/TrustedTokens. |
| How do we integrate it? | **First-class provider `A2Agent`** (`getName() = 'a2agent'`) on a **shared base class** for fixed-URL OpenAI-compatible cloud providers, extracted from `TrustedTokensProvider` first (S1a). Only a first-class provider gets the key card in the setup wizard, catalog prices, the jurisdiction badge, "Use as default" bindings, the Messages-gateway route and the health probe. |
| Which models? | Five chat rows + one vision twin, each either absent from the catalog or materially cheaper/larger than the existing route: **Qwen3.8 MAX**, **DeepSeek V4 Pro**, **DeepSeek V4 Flash**, **MiniMax M3**, **Qwen3.8 Flash** (+ `pic2text` twin). See §3. |
| Effort | ~4 developer-days: S1a base-class refactor 1, S1b provider + catalog 1–1.5, S2 frontend ½–1, S3 docs ½. The zero-code S0 spike is skipped (§4 row 11); its checks run as S1b step 0 against the live dev key already in `backend/.env`. |
| Release class | `refactor(backend)` (S1a, patch), `feat(backend): add A2Agent provider` (S1b, minor), `feat(frontend)` (S2), `docs` (S3). Backend files `backend-only`; frontend files `ota-candidate`; nothing `store-required`. |

---

## 1. What A2Agent is (verified 2026-09-11)

Source: [a2agent.me](https://a2agent.me/), [/models](https://a2agent.me/models),
[/pricing](https://a2agent.me/pricing), [/integrations](https://a2agent.me/integrations)
and the per-client guides ([Cline](https://a2agent.me/integrations/cline),
[Claude Code](https://a2agent.me/integrations/claude-code),
[Codex CLI](https://a2agent.me/integrations/codex-cli)), per-model pages
(e.g. [glm-5.3](https://a2agent.me/models/glm-5.3),
[deepseek-v4-pro](https://a2agent.me/models/deepseek-v4-pro),
[kimi-k2.6](https://a2agent.me/models/kimi-k2.6)). **`/docs` returns 404** —
there is no public API reference beyond the integration guides. Everything
not listed here is measured in S1b step 0.

| Fact | Value |
| ---- | ----- |
| Base URL (OpenAI SDKs) | `https://a2agent.me/v1` |
| Chat Completions | `POST /v1/chat/completions` (alias `/chat/completions`) |
| Responses API | `POST /v1/responses` (+ `/responses`, `/backend-api/codex/responses`); `GET /v1/responses` upgrades to WebSocket |
| Anthropic Messages | `POST /v1/messages` with `x-api-key` + `anthropic-version`; `count_tokens` answers 404 by design |
| Model list | `GET /v1/models` (route exists; needs a valid key) |
| Usage | `GET /v1/usage` (per-key usage and balance) |
| Images | `/v1/images/generations` and `/edits` answer 404 for every platform sold here — **no media models** |
| Auth | `Authorization: Bearer sk-…` (OpenAI face), `x-api-key` (Anthropic face) |
| Model ids | **Case-sensitive, exact match.** Most ids are lowercase (`glm-5.3` works, `GLM-5.3` may not). MiniMax is the mixed-case exception: `MiniMax-M3`, not `minimax-m3`. Copy ids from the models page. |
| Routing | The gateway resolves the upstream "group" from the model id on every request; a key with no group assigned is rejected with a structured error before any upstream call |
| Billing | Per token, USD, against a prepaid balance (Stripe / card); balance never expires; no minimum. Prices "include public group rate multipliers — final billing follows the actual API key group" |
| Subscriptions | Basic $19.90 (≈$5/day, $20/month cap), Plus $59.90, Pro $99 — daily caps make subscriptions unsuitable for a production key; the platform key is **pay-as-you-go** (§4 row 9) |
| Entity / audience | Omnimodel Technology Limited; "serves only users and entities outside mainland China". Marketing: 10–50 % below official list, "we do not persist request payloads", "user data is never used for training" |
| Upstream platforms | Z.ai (GLM), Moonshot (Kimi), DeepSeek, Alibaba Qwen, MiniMax — all mainland-China model operators. Where the gateway itself is hosted is not published. |

### 1.1 Full catalog and current Synaplan coverage (USD per 1M tokens, 2026-09-11)

| A2Agent id | Ctx | Tags | In | Out | Already in Synaplan? |
| ---------- | --- | ---- | -- | --- | -------------------- |
| `glm-5` | 200K | agent | 1.00 | 3.20 | no (older tier — skip) |
| `glm-5.1` | 200K | coding | 1.15 | 4.00 | no (older tier — skip) |
| `glm-5.2` | 1M | coding | 1.40 | 4.40 | TrustedTokens BID 309 ($1.50/$4.50, 230K, DE) |
| `glm-5.3` | 1M | coding | 1.40 | 4.40 | TrustedTokens BID 331 ($1.50/$4.50, 1M, DE) |
| `glm-5.3-flash` | 1M | vision | 0.15 | 0.50 | TrustedTokens BID 332/333 ($0.15/$0.30, DE) |
| `kimi-k2.5` | 256K | vision | 0.60 | 3.00 | HuggingFace BID 200/201 ($0.45/$2.25) |
| `kimi-k2.6` | 256K | vision | 0.95 | 4.00 | HuggingFace BID 202/203 ($0.75/$3.50) |
| `kimi-k2.7-code` | 256K | coding | 0.95 | 4.00 | HuggingFace BID 242/243 ($0.74/$3.50) |
| `kimi-k3` | 1M | vision | 3.00 | 15.00 | HuggingFace BID 328/329 ($2.85/$14.25) |
| `deepseek-v4-pro` | 1M | reasoning | 0.435 | 0.87 | TrustedTokens BID 337 (`…V4-Pro-0813`, **$2.25/$6.75, 200K**) — **selected** |
| `deepseek-v4-flash` | 1M | reasoning | 0.14 | 0.28 | TrustedTokens BID 336 (`…Flash-0731`, $0.15/$0.30, **400K**) — **selected** (1M window) |
| `qwen3.5-plus` | 1M | vision | 0.50 | 3.00 | no |
| `qwen3.6-flash` | 1M | vision | 0.20 | 1.20 | no (only open-weight Qwen3.6 27B/35B on Groq/TT) |
| `qwen3.7-flash` | 1M | vision | 0.20 | 0.80 | no |
| `qwen3.7-plus` | 1M | vision | 1.20 | 4.80 | no |
| `qwen3.7-max` | 1M | reasoning | 1.70 | 5.10 | no |
| `qwen3.8-flash` | 1M | vision | 0.15 | 0.47 | **no — selected** |
| `qwen3.8-max` | 1M | reasoning | 2.00 | 6.00 | **no — selected** |
| `minimax-m2.5` | 1M | coding | 0.30 | 1.20 | no |
| `minimax-m2.7` | 200K | coding | 0.30 | 1.20 | no |
| `MiniMax-M3` (marketing slug `minimax-m3`) | 1M | agent | 0.30 | 1.20 | **no — selected** |

Two whole vendor families are missing from the catalog today: **Alibaba's
commercial Qwen line** (MAX / Plus / Flash — the catalog only has the small
open-weight Qwen3.6 27B/35B) and **MiniMax** (nothing at all).

---

## 2. What already exists in Synaplan (do not rebuild)

| Exists | Where | Reuse for A2Agent |
| ------ | ----- | ----------------- |
| Fixed-URL OpenAI-compatible cloud provider with `ProviderKeyStore`, lazy client rebuild on key change, `reasoning_content` streaming, tool deltas, structured output, vision via `image_url` data URLs | `backend/src/AI/Provider/TrustedTokensProvider.php` (400 lines) | **The template for the base class (S1a).** After the extraction, `TrustedTokensProvider` and `A2AgentProvider` are thin subclasses that differ only in constants (name, display name, base URI, env var, default models, description). |
| Generic admin-registered OpenAI-compatible endpoints (base URL + key + headers per endpoint, model import, capability probe) | `OpenAICompatibleProvider`, `AI/Credential/OpenAiCompatibleEndpointRegistry` (`CONFIG_GROUP = 'openai_compatible'`), `AdminModelsImportEndpointController`, `OpenAiCompatibleEndpointsPanel.vue` | Not the production shape (shows as "OpenAI Compatible", no prices, no defaults, no badge). Available as a manual fallback for operators who want a model outside the selected set. |
| Provider resolution by `BMODELS.BSERVICE` (lowercased) through tagged services | `AI/Service/ProviderRegistry`, `config/services.yaml` `app.ai.chat` / `app.ai.vision` tags; `tests/Integration/AI/ProviderRegistryKeyTest.php` asserts tag key == `getName()` | Register `app.ai.chat` + `app.ai.vision` with `key: 'a2agent'`. |
| Encrypted platform key store, env bootstrap, admin key cards, live validation, setup wizard | `AI/Credential/ProviderKeyStore` (`SUPPORTED_PROVIDERS`), `ProviderKeyCatalog` (`envVar`, `consoleUrl`, `validation` GET), `AdminProviderKeysController`, `ProviderKeyCard.vue`, `SetupProviderStep.vue`, `ModelsAndKeysTab.vue` | Add one `ProviderKeyCatalog` entry + one `SUPPORTED_PROVIDERS` item; the UI cards are data-driven. |
| Hourly model-list health probe for every keyed provider | `AI/Health/Probe/PlatformKeyModelListProbe` (reuses the `ProviderKeyCatalog` validation URL; parses `{"data":[{"id":…}]}`) | Automatic once the catalog entry exists — S1b step 0 confirms the `/v1/models` JSON shape. |
| Static model catalog + idempotent seeder; operator toggles never overwritten; additive rows need **no migration** | `Model/ModelCatalog.php`, `Seed/ModelSeeder.php`, `docs/PRICING_MAINTENANCE.md` ("New BIDs land on existing installs through `ModelSeeder`") | Add rows with `'service' => 'A2Agent'`. Highest BID today is **360** → block 361–366 (re-check at implementation time; parallel branches allocate too). |
| Recommended bindings per provider ("Use as default", first-run auto-repair) | `AI/Credential/ProviderDefaultsService::PROVIDER_DEFAULTS` + `PREFERENCE_ORDER`; `Command/ApplyProviderDefaultsCommand` | Add an `'a2agent'` block (MAIN / FAST / PIC2TEXT). |
| Read-time availability filtering: rows of providers without a key are hidden from users, greyed for admins | `ChatReadinessService::providerAvailability()`, `ModelConfigService::usableProviders()`, `GET /api/v1/config/models` | Rows ship `selectable=1, active=1` like TrustedTokens; nobody sees them until a key is saved. |
| Anthropic-compatible Messages gateway that translates to Chat Completions for OpenAI-compatible providers | `AI/Messages/Translator/ChatCompletionsUpstreams::URLS`, `OpenAiMessagesTranslator`, `MessagesGateway` error copy, `docs/ANTHROPIC_COMPATIBLE_API.md` | Add `'a2agent' => 'https://a2agent.me/v1/chat/completions'`. Desktop and Claude Code aliases then reach the A2Agent models too. |
| Dual tool-calling gate and structured-output translation, both keyed by provider name | `AI/Tool/CatalogToolUse::CAPABLE_CHAT_SERVICES`, `AI/StructuredOutput/StructuredOutputCapability::OPENAI_JSON_SCHEMA_PROVIDERS` | Add `'a2agent'` **only after S1b step 0 proves** `tools` and `response_format: json_schema` pass through the gateway. |
| Provider icon + jurisdiction flag badge, key-help links, speed-config model mixes | `frontend/src/utils/providerIcons.ts`, `providerHelp.ts` (`ProviderHelpId`), `modelMixes.ts` (`ModelMixId`), `components/icons/ServiceIcon.vue`, i18n `providerHelp.*` and the mix labels (`"europe": "Europe Mix"`, `en.json:67`) | Add the `a2agent` branches, a `providerHelp.a2agent` block in **five** locales, the `a2agent` mix (§5.3). |
| Retirement registry for models a provider drops | `ModelCatalog::RETIREMENTS`, `ModelRetirementSeeder`, `ModelCatalogRetirementTest` | Nothing now; this is the exit path when the gateway drops an id (§7). |
| Dev key already present | `backend/.env` (untracked) carries `A2AGENT_API_KEY=sk-…` (pay-as-you-go, added 2026-09-11); `backend/.env.example` currently has `A2AGENT_API_KEY=sk-` at l. 149 | The env bootstrap imports it into the encrypted store on first use once `services.yaml` maps it. S1b replaces the `.env.example` line with the house block (empty value + comments, next to TrustedTokens). |

---

## 3. Model selection

### 3.1 Selected set (5 chat rows + 1 vision twin — decided 2026-09-11)

| # | BID | Catalog name | `providerId` | Tag | In / Out ($/1M) | Ctx | `features` | Why this one |
| - | --- | ------------ | ------------ | --- | --------------- | --- | ---------- | ------------ |
| 1 | 361 | **Qwen3.8 MAX** | `qwen3.8-max` | `chat` | 2.00 / 6.00 | 1M | `reasoning`, `tool_use`, `code`, `multilingual` | Alibaba's current flagship. The most-used commercial model family in the Asian market and entirely absent from the catalog (only the small open-weight 27B/35B exist). The "we offer Qwen" headline model. |
| 2 | 362 | **DeepSeek V4 Pro** | `deepseek-v4-pro` | `chat` | 0.435 / 0.87 | 1M | `reasoning`, `tool_use`, `code`, `multilingual` | The best-known Chinese brand. The existing route (TrustedTokens BID 337) costs **5× more** ($2.25/$6.75) with a 200K window; this is the direct upstream tier at 1M. **MAIN default** (§4 row 5). |
| 3 | 363 | **DeepSeek V4 Flash** | `deepseek-v4-flash` | `chat` | 0.14 / 0.28 | 1M | `reasoning`, `tool_use`, `code`, `multilingual` | The cheapest 1M-context model on the gateway. The TrustedTokens route (BID 336, Flash-0731) is EU-hosted but capped at 400K; this is the full window at the same price. |
| 4 | 364 | **MiniMax M3** | `MiniMax-M3` | `chat` | 0.30 / 1.20 | 1M | `tool_use`, `reasoning`, `code`, `multilingual` | A whole vendor the catalog lacks. Agent-tagged, 1M context, very cheap — the natural TOOLS / agentic pick. Live `GET /v1/models` id is mixed-case. |
| 5 | 365 | **Qwen3.8 Flash** | `qwen3.8-flash` | `chat` | 0.15 / 0.47 | 1M | `reasoning`, `tool_use`, `vision`, `multilingual` | The FAST tier (SORT / PLAN / SUMMARIZE) — the only vision-tagged model in the set, so FAST and PIC2TEXT share one vendor. DeepSeek V4 Flash is the cheaper-output fallback in the mix. |
| 5b | 366 | **Qwen3.8 Flash (Vision)** | `qwen3.8-flash` | `pic2text` | 0.15 / 0.47 | — | `vision`, `ocr`, `multilingual` | Twin row so PIC2TEXT can bind (same pattern as BID 333 / 311). Without it the provider has no vision default and `getCapabilities()` must not claim `vision`. |

BIDs 361–366 assume 360 is still the highest catalog id when S1b starts —
re-check with `rg -n "'id' => 3[6-9][0-9]" backend/src/Model/ModelCatalog.php`
and shift the block if a parallel branch took them.

Vendor coverage of the set: Alibaba (2), DeepSeek (2), MiniMax (1). GLM and
Kimi are deliberately *not* duplicated (§3.2), so the catalog ends up with all
five Chinese vendor families reachable — three via A2Agent, GLM via
TrustedTokens (EU-hosted), Kimi via HuggingFace/DeepInfra.

### 3.2 Deliberately skipped

| Model | Reason |
| ----- | ------ |
| `glm-5.2`, `glm-5.3`, `glm-5.3-flash` | Already served by TrustedTokens at ≈ the same price **on German GPUs under EU jurisdiction**. Duplicating them on a CN-jurisdiction route adds nothing and confuses the picker. |
| `kimi-k2.5` … `kimi-k3` | Already served via HuggingFace → DeepInfra **cheaper** ($0.75/$3.50 vs $0.95/$4.00 for K2.6). No value in a second route. |
| `glm-5`, `glm-5.1`, `qwen3.5-plus`, `qwen3.6-flash`, `qwen3.7-*`, `minimax-m2.5`, `minimax-m2.7` | Older tiers of families the set already covers with their newest release. Adding every point release is catalog noise and price-drift work; add on request. |

### 3.3 Catalog row (reference shape — copy for the other five)

```php
// ==================== A2AGENT (Omnimodel, Chinese frontier models) ====================
// Snapshot 2026-09-11 from https://a2agent.me/models (USD per 1M, public
// group rate; final billing follows the key's group — see PRICING_MAINTENANCE.md).
// OpenAI-compatible API at https://a2agent.me/v1; model ids are
// case-sensitive (MiniMax is `MiniMax-M3`, not `minimax-m3`). Not covered
// by LiteLLM sync — verify manually against
// GET https://a2agent.me/v1/models. Upstream operators are mainland-China
// model vendors; jurisdiction is recorded as CN so the badge tells users where
// the prompt goes.
[
    'id' => 362,
    'service' => 'A2Agent',
    'name' => 'DeepSeek V4 Pro',
    'tag' => 'chat',
    'selectable' => 1,
    'active' => 1,
    'providerId' => 'deepseek-v4-pro',
    'priceIn' => 0.435,
    'inUnit' => 'per1M',
    'priceOut' => 0.87,
    'outUnit' => 'per1M',
    'quality' => 10,
    'rating' => 1,
    'json' => [
        'description' => 'DeepSeek V4 Pro via A2Agent — flagship reasoning model with a 1M context window. Reasoning + tools. Routed through the A2Agent gateway to DeepSeek (China).',
        'max_tokens' => 32768,
        'params' => ['model' => 'deepseek-v4-pro'],
        'features' => ['reasoning', 'tool_use', 'code', 'multilingual'],
        'meta' => [
            'context_window' => '1000000',
            'max_output' => '32768',
            'host' => 'a2agent.me',
            'upstream' => 'DeepSeek',
            'jurisdiction' => 'CN',
        ],
    ],
],
```

Notes on the row: `max_tokens` / `max_output` 32768 is the TrustedTokens
convention and is confirmed in S1b step 0 (§5.2); A2Agent publishes no
cache-read rate, so `cache_read_price_per_1M` is omitted; `context_window`
`1000000` follows the gateway's "1M" — align to the upstream vendor's
documented number in S1b step 0 (TrustedTokens wrote `1048576` for GLM).

---

## 4. Decision checklist (ticked 2026-09-11)

| # | Decision | Decided | Agree? |
| - | -------- | ------- | ------ |
| 1 | **First-class provider** `A2Agent` (`a2agent`) rather than a documented admin-registered endpoint. | First-class | ☑ |
| 2 | **Provider class shape.** First extract a shared base class for fixed-URL OpenAI-compatible cloud providers (fixed base URI + key store + chat/stream/vision/`reasoning_content`/tools) in a separate `refactor:` PR, move TrustedTokens onto it with tests green, then A2Agent is a thin subclass. Second reseller = the "repeated 3+ times → extract" trigger in AGENTS.md. | Base class (S1a) | ☑ |
| 3 | **Model set** = §3.1: Qwen3.8 MAX, DeepSeek V4 Pro, **DeepSeek V4 Flash**, MiniMax M3, Qwen3.8 Flash + vision twin. | Five + twin | ☑ |
| 4 | **`meta.jurisdiction` = `CN`**, flag badge `circle-flags:cn`. The badge answers "where does my prompt go"; the model operators are mainland-China vendors even if the gateway entity sits elsewhere. | `CN` | ☑ |
| 5 | **Recommended defaults** (`ProviderDefaultsService`): MAIN (CHAT/TOOLS/ANALYZE) = `deepseek-v4-pro`, FAST (SORT/PLAN/SUMMARIZE) = `qwen3.8-flash`, PIC2TEXT = `qwen3.8-flash:pic2text`. S1b step 0 measures thinking latency; if DeepSeek V4 Pro cannot be made non-thinking on short prompts and it hurts chat, record the finding in `STATUS.md` before changing the binding. | DeepSeek V4 Pro main | ☑ |
| 6 | **`PREFERENCE_ORDER` position:** after `xai`, before `ollama` (last cloud provider; never auto-chosen over an EU/US key the operator also holds). | After `xai` | ☑ |
| 7 | **Speed-config mix `a2agent`** ("A2Agent Mix": CHAT/ANALYZE → DeepSeek V4 Pro → Qwen3.8 MAX → MiniMax M3; SORT → Qwen3.8 Flash → DeepSeek V4 Flash; PIC2TEXT → Qwen3.8 Flash). Provider-named like `openai`/`xai`, **not** a region. The `europe` mix is untouched. | Add the mix | ☑ |
| 8 | **Compliance copy.** Key-help text and `docs/CONFIGURATION.md` state plainly: prompts are processed by mainland-China model vendors through a reseller; A2Agent serves users outside mainland China; operators handling EU personal data decide per their DPA. No "sovereign" wording. | Plain statement | ☑ |
| 9 | **Production key = pay-as-you-go balance**, never a subscription plan. The owner created a PAYG key on 2026-09-11; it is in the dev `backend/.env`. The web-node `.env` in `synaplan-platform` gets it during S3 rollout (ops, never committed). | PAYG, key exists | ☑ |
| 10 | **Roadmap slot: start now**, in parallel with the two production bugfixes. Catalog/provider maintenance of the TrustedTokens kind, not Wave 5 product scope; it does not touch module descriptors or registries and does not conflict with Intermezzo (the provider builds its client lazily, so the S3 lazy-locator work there is unaffected). | Now | ☑ |
| 11 | **S0 spike skipped.** Implement from the integration guides; the verification table (§5.2 step 0) is filled against the live dev key during S1b instead of in a separate spike. | Skip S0 | ☑ |

---

## 5. Target design

### 5.1 S1a — base class refactor (`refactor(backend): extract fixed-endpoint chat completions provider base`)

1. `backend/src/AI/Provider/Concerns/` already holds `ChatCompletionsToolSupport`;
   add `backend/src/AI/Provider/AbstractChatCompletionsCloudProvider.php`
   (name final at implementation): abstract, implements `ChatProviderInterface`,
   `ToolCallingChatProviderInterface`, `VisionProviderInterface`; owns the
   lazy `\OpenAI\Client` built from `ProviderKeyStore` (rebuilt on key change),
   `chat()`, `chatStream()` (usage, finish reason, `reasoning_content`,
   tool deltas), `explainImage()` / `extractTextFromImage()` /
   `compareImages()`, `buildChatOptions()` (max_tokens, temperature,
   `stream_options.include_usage`, structured output, tool options),
   `parseUsage()`, `imageToDataUrl()`, `getStatus()`, `isAvailable()`.
2. Abstract hooks the subclass fills: `providerName()`, `getDisplayName()`,
   `getDescription()`, `baseUri()`, `envVarName()` + hint,
   `getDefaultModels()`, `getCapabilities()`. Error messages use the display
   name (`'%s chat error: '`) so log lines stay attributable.
3. `TrustedTokensProvider` becomes the first subclass. **Behaviour unchanged:**
   `TrustedTokensProviderTest` (metadata, capabilities, defaults, no-key
   status, required env, chat preconditions, `buildChatOptions` structured
   output via reflection) passes without edits other than the reflection
   target if `buildChatOptions` moves to the parent.
4. Mistral / Groq / xAI / OpenAICompatible are **not** migrated in this PR —
   they carry provider-specific extras (audio, media, per-endpoint clients).
   Note them as candidates in the class docblock, nothing more.
5. Gate: `make -C backend lint && make -C backend phpstan && make -C backend
   test` unfiltered; `tests/Characterization/` snapshots must not move.

### 5.2 S1b — provider + catalog (`feat(backend): add A2Agent provider`)

**Step 0 — live verification against the dev key** (replaces the skipped
spike; run once the provider class exists, before the gates in step 9 are
decided; record results in `STATUS.md`):

| Check | Why it matters | Decides |
| ----- | -------------- | ------- |
| `GET /v1/models` JSON shape is `{"data":[{"id":"…"}]}` and lists the five ids | `PlatformKeyModelListProbe` parses that shape; key validation reuses the URL | Probe works unchanged, or needs a shape note |
| Streaming with `stream_options: {include_usage: true}` returns a final usage chunk | `parseUsage()` and cost tracking depend on it | Whether usage must be estimated |
| Reasoning arrives as `delta.reasoning_content` (DeepSeek/Qwen style) — or inline `<think>…</think>` in `content` (MiniMax M2-series habit) | Frontend already renders both (`StreamController` wraps reasoning in `<think>`; `ChatView.vue` parses it), but `features: ['reasoning']` and the Thinking toggle assume the former | Whether MiniMax M3 keeps `reasoning`; whether a `params` flag is needed |
| `tools` / `tool_choice` pass through and `delta.tool_calls` stream back for all five | `CatalogToolUse::CAPABLE_CHAT_SERVICES` forces `tool_use` on every chat row of a listed service | Add `a2agent` to the list, or leave it out and flag rows individually |
| `response_format: {type: json_schema}` accepted; falls back to `json_object`? | `StructuredOutputCapability::OPENAI_JSON_SCHEMA_PROVIDERS` and the SORT/PLAN routers | Add `a2agent` to the list or not |
| `image_url` with a `data:` URL on `qwen3.8-flash` | Vision twin + `explainImage()` | Whether the `pic2text` row ships |
| `max_tokens` accepted (vs `max_completion_tokens`); actual max output per model | Row `max_tokens` / `meta.max_output` | Row values |
| Latency of a thinking model on a short prompt; whether thinking can be switched off (`enable_thinking`, `thinking: {type: disabled}`) | MAIN default (§4 row 5), FAST-tier suitability | Default bindings |
| Rate limits / 429 shape; `/v1/usage` fields | Operator docs, price-drift verification | Docs text |
| Billed price vs public list for the key's group (`/v1/usage` after a few calls) | Catalog stores the public rate; resale billing needs the real one | `PRICING_MAINTENANCE.md` caveat wording |

**Steps:**

1. `backend/src/AI/Provider/A2AgentProvider.php` extends the S1a base:
   `PROVIDER_NAME = 'a2agent'`, display `A2Agent`,
   `BASE_URI = 'https://a2agent.me/v1'`, `DEFAULT_CHAT_MODEL = 'deepseek-v4-pro'`,
   `DEFAULT_VISION_MODEL = 'qwen3.8-flash'`, capabilities `['chat', 'vision']`,
   env var `A2AGENT_API_KEY` with hint "Get your API key from
   https://a2agent.me/ (dashboard → API keys)". Description names the vendors
   and the jurisdiction plainly (§4 row 8).
2. `backend/config/services.yaml`: `env(A2AGENT_API_KEY): ''` in the
   parameters block (next to `TRUSTEDTOKENS_API_KEY`, ~l. 79);
   `a2agent: '%env(A2AGENT_API_KEY)%'` in the `ProviderKeyStore` env map
   (~l. 333); service definition with `$keyStore`, `$uploadDir`, tags
   `app.ai.chat` and `app.ai.vision`, `key: 'a2agent'`.
3. `AI/Credential/ProviderKeyCatalog::PROVIDERS['a2agent']`: `envVar`
   `A2AGENT_API_KEY`, `consoleUrl` `https://a2agent.me/`, `freeTier: false`
   (pay-as-you-go; subscription plans are unsuitable for the platform key), `recommended: false`, validation `GET
   https://a2agent.me/v1/models` with `Authorization: Bearer {key}`.
4. `AI/Credential/ProviderKeyStore::SUPPORTED_PROVIDERS` + class docblock.
5. `AI/Credential/ProviderDefaultsService`: `PROVIDER_DEFAULTS['a2agent']`
   per §4 row 5; `PREFERENCE_ORDER` per §4 row 6.
   `Command/ApplyProviderDefaultsCommand` argument help text.
6. `Controller/AdminProviderKeysController`: add `a2agent` to the OpenAPI
   `provider` path enum → `make -C frontend generate-schemas` → `vue-tsc`.
7. `Service/Admin/SystemConfigService`: `cloud` section field list +
   `A2AGENT_API_KEY` descriptor (`source: 'database'`, description names
   the models and "Chinese model vendors via the A2Agent gateway").
8. `AI/Messages/Translator/ChatCompletionsUpstreams::URLS['a2agent']`;
   docblock of `OpenAiMessagesTranslator`; provider list in the
   `MessagesGateway` error message.
9. `AI/Tool/CatalogToolUse::CAPABLE_CHAT_SERVICES` and
   `AI/StructuredOutput/StructuredOutputCapability::OPENAI_JSON_SCHEMA_PROVIDERS`
   — **each only if step 0 said yes**.
10. `Model/ModelCatalog.php`: section comment + six rows (BIDs 361–366 or the
    next free block), §3.3 shape.
11. `backend/.env.example`: replace the interim `A2AGENT_API_KEY=sk-` (l. 149)
    with the house block after TrustedTokens — comment lines (what it serves,
    get-key URL, "docs: the integration guides at
    https://a2agent.me/integrations", case-sensitive model ids including
    `MiniMax-M3`, CN jurisdiction)
    and an **empty** value like every other key.
12. Tests (§6). Gate: `make -C backend lint && make -C backend phpstan &&
    make -C backend test` — unfiltered. Routing snapshots in
    `tests/Characterization/` must not drift (no classifier change here; if
    they do, stop and look). Smoke: `docker compose restart backend worker`,
    Admin → AI Providers shows the A2Agent card as configured (env
    bootstrap), one chat on `deepseek-v4-pro` streams with a usage line.

### 5.3 S2 — frontend surfaces (`feat(frontend): A2Agent provider card, icon and mix`)

1. `utils/providerIcons.ts`: `getProviderIcon` → a gateway glyph (no brand
   icon in Iconify; e.g. `mdi:transit-connection-variant`), placed **before**
   the generic fallbacks; `getProviderFlag` → `circle-flags:cn` (§4 row 4);
   `isLocalSelfHostedProvider` stays false.
2. `utils/providerHelp.ts`: `'a2agent'` in `ProviderHelpId`, `BY_PROVIDER`,
   `A2AGENT_API_KEY` in the env map.
3. i18n `providerHelp.a2agent.{title,message}` in `en`, `de`, `es`, `fr`,
   `tr` — same change, all five; the parity test enforces it and the ledger
   may only shrink. Message per §4 row 8.
4. `utils/modelMixes.ts`: `'a2agent'` in `ModelMixId`, an `a2agent()`
   candidate helper, the mix definition per §4 row 7 with
   `icon: { kind: 'service', service: 'A2Agent' }`, label key next to
   `"europe": "Europe Mix"` in all five locales.
5. Regenerated `src/generated/api-schemas.ts` from S1b step 6; `vue-tsc` green.
6. Gate: `make -C frontend lint && docker compose exec -T frontend npm run
   check:types && make -C frontend test`, then `make test-e2e` (provider key
   card is on an admin route the `@ci` suite touches; layout unchanged, so
   the Mobile job is not required). Check the card, badge and mix tile in
   light, dark and V2.

### 5.4 S3 — docs and rollout (`docs: A2Agent provider`)

- `docs/CONFIGURATION.md`: an "A2Agent (Chinese frontier models, gateway)"
  section after TrustedTokens — env var, what it serves, base URL, the
  jurisdiction sentence, the case-sensitive-id rule (`MiniMax-M3`), PAYG note.
- `docs/PRICING_MAINTENANCE.md`: source = `GET https://a2agent.me/v1/models`
  + the `/models` page; "not in LiteLLM → `unmatched` bucket, verify
  manually"; **the key-group caveat** ("public rate; billed rate follows the
  key's group — compare against `/v1/usage` after the first invoices");
  verification-date row in the provider table.
- `docs/PRICE_DRIFT_PROCEDURE.md`: add the A2Agent source to the list.
- `docs/ANTHROPIC_COMPATIBLE_API.md`: A2Agent in the Chat-Completions host
  list. `README.md`: AI Chat provider line + provider table row (🇨🇳,
  `A2AGENT_API_KEY`, the five models).
- `synaplan-docs` (separate repo): provider page entry; `synaplan-platform`
  (private): `A2AGENT_API_KEY` in the live compose `.env` on the web nodes,
  one node at a time via `updatewebN.sh` — ops work, never committed here.
- `node scripts/mobile-impact.mjs --base <base> --head <head>` on each PR:
  expected `backend-only` (S1a/S1b), `ota-candidate` (S2), `no-app-impact`
  (S3). No policy change needed — every touched path is already allow-listed.

### 5.5 Not done on purpose

- No use of A2Agent's native `/v1/messages` or `/v1/responses` faces. Synaplan
  talks Chat Completions to every OpenAI-compatible provider; one code path.
- No media rows (`text2pic` etc.) — the gateway has none.
- No feature-module descriptor: keyed cloud providers stay core (feature-modules
  §0 row 3). The class must stay free of constructor side effects so the lazy
  `ProviderRegistry` (S3 there) can wrap it unchanged.
- Mistral / Groq / xAI are not moved onto the S1a base class in this plan.

---

## 6. Tests

| Test | Mirrors | Asserts |
| ---- | ------- | ------- |
| `backend/tests/AI/Provider/TrustedTokensProviderTest.php` | itself | **Unchanged assertions** after S1a (regression proof for the extraction) |
| `backend/tests/AI/Provider/A2AgentProviderTest.php` | `TrustedTokensProviderTest` | name/display/description, capabilities `chat`+`vision`, default models, unavailable without key, `A2AGENT_API_KEY` required, `chat()` requires model and key, `buildChatOptions` merges `json_schema` (or omits it, per step 0) |
| `backend/tests/Unit/Model/ModelCatalogTest.php::testA2AgentModelsAreAvailableWithExpectedApiIds` | the TrustedTokens block (l. 733) | six keys resolve (`a2agent:qwen3.8-max:chat`, …, `a2agent:qwen3.8-flash:pic2text`), BIDs, `service === 'A2Agent'`, `meta.jurisdiction === 'CN'`, prices |
| `tests/Integration/AI/ProviderRegistryKeyTest` | existing | passes automatically (tag key == `getName()`) |
| `CatalogToolUse` / `StructuredOutputCapability` unit tests | existing | extend only if `a2agent` was added to those lists |
| `OpenAiMessagesTranslatorTest` | existing | `ChatCompletionsUpstreams::supports('a2agent')` and the fixed URL |
| `frontend/tests/unit/utils/providerIcons.spec.ts` | TrustedTokens cases (l. 58, 91) | icon and `circle-flags:cn`, not local |
| `frontend/tests/unit/utils/modelMixes.spec.ts` | Europe-mix case (l. 207) | the `a2agent` mix resolves CHAT/SORT/PIC2TEXT on an install that serves A2Agent, and is `available: false` otherwise |
| `tests/unit/i18n/localeParity.spec.ts` | existing | five-locale parity for `providerHelp.a2agent` and the mix label |

---

## 7. Risks and how the plan handles them

| Risk | Handling |
| ---- | -------- |
| **Jurisdiction / GDPR.** Prompts reach mainland-China model vendors; A2Agent's "no payload persistence" says nothing about upstream retention. | `CN` badge on every row and in the picker (§4 row 4); explicit copy in key help and docs (§4 row 8); `europe` mix untouched; the "Approved providers only" sovereignty setting planned for Wave 5 §7.2 will cover it install-wide. |
| **Small reseller, thin docs** (`/docs` 404, group-based routing, "operator can pin a Claude Code version range"). Outage or model churn is plausible. | Hourly `PlatformKeyModelListProbe` already flags a missing id; `ModelCatalog::RETIREMENTS` is the exit path; `PREFERENCE_ORDER` last among cloud providers so no install is auto-repaired onto it. |
| **Billed rate ≠ catalog rate** ("final billing follows the actual API key group"). | Catalog stores the public rate (same as every provider); `PRICING_MAINTENANCE.md` caveat + a first-invoice check against `/v1/usage` in S3. If the production key's group differs materially, adjust `priceIn/Out` in a follow-up (`ModelPriceHistoryRepository` keeps billing time-travel intact). |
| **Case-sensitive model ids.** | Catalog `providerId` is the exact upstream id (MiniMax is `MiniMax-M3`); the section comment says so; step 0 verifies each. |
| **Thinking output format differs per vendor** (`reasoning_content` vs inline `<think>`). | Step 0 check; the frontend handles both today; `features.reasoning` set per measured behaviour. |
| **Base-class refactor regresses TrustedTokens.** | Its test file keeps every assertion; the refactor PR is separate and lands green before any A2Agent code; no Mistral/Groq/xAI migration in scope. |
| **Skipped spike moves discovery into S1b.** | Step 0 is the first task of S1b and gates steps 9–10; findings go to `STATUS.md` before the PR is opened. |
| **Catalog BID collision** with a parallel branch. | Allocate at implementation time from the then-current maximum; `ModelCatalogTest` fails on duplicate ids. |
| **`CAPABLE_CHAT_SERVICES` forces `tool_use` on every chat row.** | Only add `a2agent` when all five rows verified tool calling in step 0; otherwise flag rows individually and leave the service out of the list. |
| **A real key in a tracked file.** | Checked 2026-09-11: `backend/.env.example` holds only the `sk-` placeholder; the key is in untracked `backend/.env`. S1b step 11 normalizes the example line to an empty value. Never paste the key into docs, tests, or `STATUS.md`. |

---

## 8. Out of scope

- A "China/Asia" region mix or any region-flagged preset (§4 row 7 rationale).
- Per-user A2Agent keys (keys stay install-wide, as for every provider).
- Duplicating GLM or Kimi through A2Agent (§3.2).
- Media generation, embeddings, STT/TTS through A2Agent (not offered).
- Any change to `ProviderRegistry`, module descriptors, or the OpenAI-compatible
  endpoint registry.
- Migrating Mistral / Groq / xAI onto the new base class.
- Chinese (`zh`) UI locale — a separate product decision; the current five
  locales are the contract.

---

## 9. How work is executed

Feature branch per sprint (`refactor/chat-completions-provider-base`,
`feat/a2agent-provider-backend`, `feat/a2agent-provider-frontend`,
`docs/a2agent-provider`), Conventional Commits with the type that matches the
release bump, full local gate before every commit (`make ci-local`), `make
test-e2e` before the frontend push, mobile-impact classification on each PR,
five locales, no AI attribution. `STATUS.md` in this directory is the step log.

---

## 10. Decision log — 2026-09-11

| # | Decision |
| - | -------- |
| 1 | First-class `A2Agent` provider, not an admin-registered endpoint. |
| 2 | Extract a shared base class for fixed-URL OpenAI-compatible cloud providers first (S1a, `refactor:`); TrustedTokens moves onto it; A2Agent is a thin subclass. |
| 3 | Model set: Qwen3.8 MAX, DeepSeek V4 Pro, DeepSeek V4 Flash, MiniMax M3, Qwen3.8 Flash + Qwen3.8 Flash (Vision). GLM and Kimi are not duplicated. |
| 4 | `meta.jurisdiction = 'CN'`, badge `circle-flags:cn`. |
| 5 | MAIN default DeepSeek V4 Pro; FAST Qwen3.8 Flash; PIC2TEXT Qwen3.8 Flash (Vision). |
| 6 | `PREFERENCE_ORDER`: after `xai`, before `ollama`. |
| 7 | Add a provider-named `a2agent` speed-config mix; no region mix; `europe` untouched. |
| 8 | Plain compliance wording in key help and docs. |
| 9 | Platform key is pay-as-you-go; the owner created it on 2026-09-11 and placed it in the dev `backend/.env`. |
| 10 | Start now, in parallel with the two production bugfixes; not Wave 5 scope; no Intermezzo conflict. |
| 11 | S0 spike skipped; its verification table runs as S1b step 0 against the live dev key. |

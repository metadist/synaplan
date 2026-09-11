# A2Agent provider — Chinese frontier models for the Asian market

**Date:** 2026-09-11
**Status:** Proposal. §4 decision checklist is **not ticked**; no code from
this file yet.
**Trigger:** Product request to add 3–4 models the catalog does not have and
to open the Asian market with Chinese models, using the token gateway
[a2agent.me](https://a2agent.me/) (Omnimodel Technology Limited).
**Shape of the work:** the same class of change as the TrustedTokens
provider added on 2026-08-29 (BIDs 331–337): one OpenAI-compatible cloud
provider with a fixed base URL, a platform API key, catalog rows, and the
admin/frontend surfaces that every keyed provider has.
**Related:**
[`../20260826-provider-aware-model-catalog/README.md`](../20260826-provider-aware-model-catalog/README.md)
(read-time availability filtering — new rows are invisible until a key exists),
[`../20260910-feature-modules/00_master_plan.md`](../20260910-feature-modules/00_master_plan.md)
(Intermezzo — keyed cloud providers stay core, registries become lazy),
[`../20260910_roadmap_update.md`](../20260910_roadmap_update.md) §1 (binding
order of work).

---

## 0. TL;DR

| Question | Answer |
| -------- | ------ |
| Is A2Agent OpenAI-compatible? | **Yes.** Chat Completions at `POST https://a2agent.me/v1/chat/completions`, `Authorization: Bearer sk-…`, plus the Responses API (`/v1/responses`), the Anthropic Messages API (`/v1/messages`) and a Gemini face on the **same key**. `GET /v1/models` exists (answers `401 {"code":"INVALID_API_KEY"}` without a valid key — verified 2026-09-11), so key validation and the hourly model-list health probe work like Groq/xAI/TrustedTokens. |
| How do we integrate it? | **First-class provider `A2Agent`** (`getName() = 'a2agent'`), mirroring `TrustedTokensProvider` — not a hand-registered OpenAI-compatible endpoint. Only a first-class provider gets the key card in the setup wizard, catalog prices, the jurisdiction badge, "Use as default" bindings, the Messages-gateway route and the health probe. The endpoint registry is still used — for the **zero-code spike** (S0). |
| Which models? | Four chat rows + one vision twin, chosen so each is either absent from the catalog or materially cheaper/larger than the existing route: **Qwen3.8 MAX**, **DeepSeek V4 Pro**, **MiniMax M3**, **Qwen3.8 Flash** (+ `pic2text` twin). See §3. |
| Effort | ~3 developer-days (S0 ½, S1 1–1.5, S2 ½, S3 ½). +1 day if the base-class refactor in §4 row 2 is chosen. |
| Release class | `feat(backend): add A2Agent provider` → minor bump. Backend files `backend-only`; frontend files `ota-candidate`; no `store-required` path is touched. |

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
not listed here must be measured in S0.

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
| Model ids | **Lowercase, case-sensitive, exact match** (`glm-5.3` works, `GLM-5.3` may not). Copy ids from the models page. |
| Routing | The gateway resolves the upstream "group" from the model id on every request; a key with no group assigned is rejected with a structured error before any upstream call |
| Billing | Per token, USD, against a prepaid balance (Stripe / card); balance never expires; no minimum. Prices "include public group rate multipliers — final billing follows the actual API key group" |
| Subscriptions | Basic $19.90 (≈$5/day, $20/month cap), Plus $59.90, Pro $99 — **daily caps make subscriptions unsuitable for a production Synaplan key**; use pay-as-you-go balance |
| Entity / audience | Omnimodel Technology Limited; "serves only users and entities outside mainland China". Marketing: 10–50 % below official list, "we do not persist request payloads", "user data is never used for training" |
| Upstream platforms | Z.ai (GLM), Moonshot (Kimi), DeepSeek, Alibaba Qwen, MiniMax — all Chinese model operators. Where the gateway itself is hosted is not published. |

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
| `deepseek-v4-pro` | 1M | reasoning | 0.435 | 0.87 | TrustedTokens BID 337 (`…V4-Pro-0813`, **$2.25/$6.75, 200K**) |
| `deepseek-v4-flash` | 1M | reasoning | 0.14 | 0.28 | TrustedTokens BID 336 (`…Flash-0731`, $0.15/$0.30, 400K) |
| `qwen3.5-plus` | 1M | vision | 0.50 | 3.00 | no |
| `qwen3.6-flash` | 1M | vision | 0.20 | 1.20 | no (only open-weight Qwen3.6 27B/35B on Groq/TT) |
| `qwen3.7-flash` | 1M | vision | 0.20 | 0.80 | no |
| `qwen3.7-plus` | 1M | vision | 1.20 | 4.80 | no |
| `qwen3.7-max` | 1M | reasoning | 1.70 | 5.10 | no |
| `qwen3.8-flash` | 1M | vision | 0.15 | 0.47 | **no** |
| `qwen3.8-max` | 1M | reasoning | 2.00 | 6.00 | **no** |
| `minimax-m2.5` | 1M | coding | 0.30 | 1.20 | no |
| `minimax-m2.7` | 200K | coding | 0.30 | 1.20 | no |
| `minimax-m3` | 1M | agent | 0.30 | 1.20 | **no** |

Two whole vendor families are missing from the catalog today: **Alibaba's
commercial Qwen line** (MAX / Plus / Flash — the catalog only has the small
open-weight Qwen3.6 27B/35B) and **MiniMax** (nothing at all).

---

## 2. What already exists in Synaplan (do not rebuild)

| Exists | Where | Reuse for A2Agent |
| ------ | ----- | ----------------- |
| Fixed-URL OpenAI-compatible cloud provider with `ProviderKeyStore`, lazy client rebuild on key change, `reasoning_content` streaming, tool deltas, structured output, vision via `image_url` data URLs | `backend/src/AI/Provider/TrustedTokensProvider.php` (400 lines) | **The template.** `A2AgentProvider` differs only in constants (name, display name, base URI, env var, default models, description). |
| Generic admin-registered OpenAI-compatible endpoints (base URL + key + headers per endpoint, model import, capability probe) | `OpenAICompatibleProvider`, `AI/Credential/OpenAiCompatibleEndpointRegistry` (`CONFIG_GROUP = 'openai_compatible'`), `AdminModelsImportEndpointController`, `OpenAiCompatibleEndpointsPanel.vue` | **S0 spike vehicle** — register `https://a2agent.me/v1` with a trial key in Admin → AI Models → OpenAI-compatible endpoints, import models, test chat/stream/tools/vision/JSON with zero code. Not the production shape (shows as "OpenAI Compatible", no prices, no defaults, no badge). |
| Provider resolution by `BMODELS.BSERVICE` (lowercased) through tagged services | `AI/Service/ProviderRegistry`, `config/services.yaml` `app.ai.chat` / `app.ai.vision` tags; `tests/Integration/AI/ProviderRegistryKeyTest.php` asserts tag key == `getName()` | Register `app.ai.chat` + `app.ai.vision` with `key: 'a2agent'`. |
| Encrypted platform key store, env bootstrap, admin key cards, live validation, setup wizard | `AI/Credential/ProviderKeyStore` (`SUPPORTED_PROVIDERS`), `ProviderKeyCatalog` (`envVar`, `consoleUrl`, `validation` GET), `AdminProviderKeysController`, `ProviderKeyCard.vue`, `SetupProviderStep.vue`, `ModelsAndKeysTab.vue` | Add one `ProviderKeyCatalog` entry + one `SUPPORTED_PROVIDERS` item; the UI cards are data-driven. |
| Hourly model-list health probe for every keyed provider | `AI/Health/Probe/PlatformKeyModelListProbe` (reuses the `ProviderKeyCatalog` validation URL; parses `{"data":[{"id":…}]}`) | Automatic once the catalog entry exists — S0 confirms the `/v1/models` JSON shape. |
| Static model catalog + idempotent seeder; operator toggles never overwritten; additive rows need **no migration** | `Model/ModelCatalog.php`, `Seed/ModelSeeder.php`, `docs/PRICING_MAINTENANCE.md` ("New BIDs land on existing installs through `ModelSeeder`") | Add rows with `'service' => 'A2Agent'`. Highest BID today is **360** → next free 361 (re-check at implementation time; parallel branches allocate too). |
| Recommended bindings per provider ("Use as default", first-run auto-repair) | `AI/Credential/ProviderDefaultsService::PROVIDER_DEFAULTS` + `PREFERENCE_ORDER`; `Command/ApplyProviderDefaultsCommand` | Add an `'a2agent'` block (MAIN / FAST / PIC2TEXT). |
| Read-time availability filtering: rows of providers without a key are hidden from users, greyed for admins | `ChatReadinessService::providerAvailability()`, `ModelConfigService::usableProviders()`, `GET /api/v1/config/models` | Rows can ship `selectable=1, active=1` like TrustedTokens; nobody sees them until a key is saved. |
| Anthropic-compatible Messages gateway that translates to Chat Completions for OpenAI-compatible providers | `AI/Messages/Translator/ChatCompletionsUpstreams::URLS`, `OpenAiMessagesTranslator`, `MessagesGateway` error copy, `docs/ANTHROPIC_COMPATIBLE_API.md` | Add `'a2agent' => 'https://a2agent.me/v1/chat/completions'`. Desktop and Claude Code aliases then reach the A2Agent models too. |
| Dual tool-calling gate and structured-output translation, both keyed by provider name | `AI/Tool/CatalogToolUse::CAPABLE_CHAT_SERVICES`, `AI/StructuredOutput/StructuredOutputCapability::OPENAI_JSON_SCHEMA_PROVIDERS` | Add `'a2agent'` **only after S0 proves** `tools` and `response_format: json_schema` pass through the gateway. |
| Provider icon + jurisdiction flag badge, key-help links, speed-config model mixes | `frontend/src/utils/providerIcons.ts`, `providerHelp.ts` (`ProviderHelpId`), `modelMixes.ts` (`ModelMixId`), `components/icons/ServiceIcon.vue`, i18n `providerHelp.*` and the mix labels (`"europe": "Europe Mix"`, `en.json:67`) | Add the `a2agent` branches, a `providerHelp.a2agent` block in **five** locales, optionally an `a2agent` mix (§5.6). |
| Retirement registry for models a provider drops | `ModelCatalog::RETIREMENTS`, `ModelRetirementSeeder`, `ModelCatalogRetirementTest` | Nothing now; this is how a dropped A2Agent id is handled later (the gateway already dropped nothing we depend on, but it is a small reseller — see §7). |

---

## 3. Model selection

### 3.1 Recommended set (4 chat rows + 1 vision twin)

| # | Catalog name | `providerId` | Tag | In / Out ($/1M) | Ctx | `features` | Why this one |
| - | ------------ | ------------ | --- | --------------- | --- | ---------- | ------------ |
| 1 | **Qwen3.8 MAX** | `qwen3.8-max` | `chat` | 2.00 / 6.00 | 1M | `reasoning`, `tool_use`, `code`, `multilingual` | Alibaba's current flagship. The most-used commercial model family in the Asian market and entirely absent from the catalog (only the small open-weight 27B/35B exist). The "we offer Qwen" headline model. |
| 2 | **DeepSeek V4 Pro** | `deepseek-v4-pro` | `chat` | 0.435 / 0.87 | 1M | `reasoning`, `tool_use`, `code`, `multilingual` | The best-known Chinese brand. The existing route (TrustedTokens BID 337) costs **5× more** ($2.25/$6.75) with a 200K window; this is the direct upstream tier at 1M. Proposed MAIN default (§5.4). |
| 3 | **MiniMax M3** | `minimax-m3` | `chat` | 0.30 / 1.20 | 1M | `tool_use`, `reasoning`, `code`, `multilingual` | A whole vendor the catalog lacks. Agent-tagged, 1M context, very cheap — the natural TOOLS / agentic pick. |
| 4 | **Qwen3.8 Flash** | `qwen3.8-flash` | `chat` | 0.15 / 0.47 | 1M | `reasoning`, `tool_use`, `vision`, `multilingual` | The FAST tier (SORT / PLAN / SUMMARIZE) — the cheapest 1M-context model on the gateway and the only vision-tagged one in the set. |
| 4b | **Qwen3.8 Flash (Vision)** | `qwen3.8-flash` | `pic2text` | 0.15 / 0.47 | — | `vision`, `ocr`, `multilingual` | Twin row so PIC2TEXT can bind (same pattern as BID 333 / 311). Without it the provider has no vision default and `getCapabilities()` must not claim `vision`. |

Vendor coverage of the set: Alibaba (2), DeepSeek (1), MiniMax (1). GLM and
Kimi are deliberately *not* duplicated (§3.2), so the catalog ends up with all
five Chinese vendor families reachable — three via A2Agent, GLM via
TrustedTokens (EU-hosted), Kimi via HuggingFace/DeepInfra.

### 3.2 Deliberately skipped

| Model | Reason |
| ----- | ------ |
| `glm-5.2`, `glm-5.3`, `glm-5.3-flash` | Already served by TrustedTokens at ≈ the same price **on German GPUs under EU jurisdiction**. Duplicating them on a CN-jurisdiction route adds nothing and confuses the picker. |
| `kimi-k2.5` … `kimi-k3` | Already served via HuggingFace → DeepInfra **cheaper** ($0.75/$3.50 vs $0.95/$4.00 for K2.6). No value in a second route. |
| `deepseek-v4-flash` | Attractive ($0.14/$0.28) but functionally covered by TrustedTokens BID 336 at $0.15/$0.30 (EU). Candidate for a later add if operators ask for the 1M window. |
| `glm-5`, `glm-5.1`, `qwen3.5-plus`, `qwen3.6-flash`, `qwen3.7-*`, `minimax-m2.5`, `minimax-m2.7` | Older tiers of families the set already covers with their newest release. Adding every point release is catalog noise and price-drift work; add on request. |

### 3.3 Catalog row (reference shape — copy for the other four)

```php
// ==================== A2AGENT (Omnimodel, Chinese frontier models) ====================
// Snapshot 2026-09-11 from https://a2agent.me/models (USD per 1M, public
// group rate; final billing follows the key's group — see PRICING_MAINTENANCE.md).
// OpenAI-compatible API at https://a2agent.me/v1; model ids are lowercase and
// case-sensitive. Not covered by LiteLLM sync — verify manually against
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
        'description' => 'DeepSeek V4 Pro via A2Agent — flagship reasoning model with a 1M context window. Reasoning + tools. Routed through the A2Agent gateway to DeepSeek.',
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
convention and must be confirmed in S0 (§5.1); A2Agent publishes no cache-read
rate, so `cache_read_price_per_1M` is omitted; `context_window` `1000000`
follows the gateway's "1M" — align to the upstream vendor's documented number
during S0 (TrustedTokens wrote `1048576` for GLM).

---

## 4. Decision checklist (product owner — tick before S1)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **First-class provider** `A2Agent` (`a2agent`) rather than a documented admin-registered endpoint. | First-class | ☐ |
| 2 | **Provider class shape.** (a) Copy `TrustedTokensProvider` → `A2AgentProvider` with new constants (fast, ~400 duplicated lines — the pattern Mistral/Groq/TT already follow), or (b) first extract `AbstractChatCompletionsCloudProvider` (fixed base URI + key store + chat/stream/vision/`reasoning_content`/tools) in a separate `refactor:` PR, move TrustedTokens onto it with tests green, then A2Agent is ~60 lines. Second reseller = the "repeated 3+ times → extract" trigger in AGENTS.md. | **(b)** if capacity allows; (a) is acceptable | ☐ |
| 3 | **Model set** = §3.1 (Qwen3.8 MAX, DeepSeek V4 Pro, MiniMax M3, Qwen3.8 Flash + vision twin). | §3.1 | ☐ |
| 4 | **`meta.jurisdiction` = `CN`**, flag badge `circle-flags:cn`. The badge answers "where does my prompt go"; the model operators are mainland-China vendors even if the gateway entity sits elsewhere. Alternative: `HK` for the gateway entity (not recommended — hides the upstream). | `CN` | ☐ |
| 5 | **Recommended defaults** (`ProviderDefaultsService`): MAIN (CHAT/TOOLS/ANALYZE) = `deepseek-v4-pro`, FAST (SORT/PLAN/SUMMARIZE) = `qwen3.8-flash`, PIC2TEXT = `qwen3.8-flash:pic2text`. Alternative MAIN: `qwen3.8-max` (flagship, 4.6× dearer). S0 measures thinking latency before this is final. | DeepSeek V4 Pro main | ☐ |
| 6 | **`PREFERENCE_ORDER` position:** after `xai`, before `ollama` (last cloud provider; never auto-chosen over an EU/US key the operator also holds). | After `xai` | ☐ |
| 7 | **Speed-config mix `a2agent`** ("A2Agent Mix": CHAT/ANALYZE → DeepSeek V4 Pro → Qwen3.8 MAX → MiniMax M3; SORT → Qwen3.8 Flash; PIC2TEXT → Qwen3.8 Flash). Provider-named like `openai`/`xai`, **not** a region ("Asia") — the models are Chinese, the market is Asian, a flag on a mix would be wrong for both. The `europe` mix is untouched. | Add the mix | ☐ |
| 8 | **Compliance copy.** Key-help text and `docs/CONFIGURATION.md` state plainly: prompts are processed by mainland-China model vendors through a Hong Kong-style reseller; A2Agent serves users outside mainland China; operators handling EU personal data decide per their DPA. No "sovereign" wording. | State it | ☐ |
| 9 | **Production key = pay-as-you-go balance**, never a subscription plan (daily caps $5–$50 would silently fail the platform mid-day). Recorded in `synaplan-platform` ops docs, not here. | PAYG | ☐ |
| 10 | **Roadmap slot.** This is catalog/provider maintenance of the TrustedTokens kind, not Wave 5 product scope; it does not touch module descriptors or registries and may land before or during Intermezzo without conflict (the provider builds its client lazily, so the S3 lazy-locator work is unaffected). Owner places it. | Standalone `feat` PR(s), owner schedules | ☐ |

---

## 5. Target design (Path B)

### 5.1 S0 — spike with zero code (½ day, needs a trial key)

Register `https://a2agent.me/v1` as an OpenAI-compatible endpoint in the
admin UI (Admin → AI Models → OpenAI-compatible endpoints), import
`deepseek-v4-pro`, `qwen3.8-max`, `qwen3.8-flash`, `minimax-m3`, and record
the answers below in `STATUS.md` (create it when S0 starts). Every unknown
here decides a gate in S1.

| Check | Why it matters | Decides |
| ----- | -------------- | ------- |
| `GET /v1/models` JSON shape is `{"data":[{"id":"…"}]}` and lists the five ids | `PlatformKeyModelListProbe` parses that shape; key validation reuses the URL | Probe works unchanged, or needs a shape note |
| Streaming with `stream_options: {include_usage: true}` returns a final usage chunk | `parseUsage()` and cost tracking depend on it | Whether usage must be estimated |
| Reasoning arrives as `delta.reasoning_content` (DeepSeek/Qwen/GLM style) — or inline `<think>…</think>` in `content` (MiniMax M2-series habit) | Frontend already renders both (`StreamController` wraps reasoning in `<think>`; `ChatView.vue` parses it), but `features: ['reasoning']` and the Thinking toggle assume the former | Whether MiniMax M3 keeps `reasoning`; whether a `params` flag is needed |
| `tools` / `tool_choice` pass through and `delta.tool_calls` stream back for all four | `CatalogToolUse::CAPABLE_CHAT_SERVICES` forces `tool_use` on every chat row of a listed service | Add `a2agent` to the list, or leave it out and flag rows individually |
| `response_format: {type: json_schema}` accepted; falls back to `json_object`? | `StructuredOutputCapability::OPENAI_JSON_SCHEMA_PROVIDERS` and the SORT/PLAN routers | Add `a2agent` to the list or not |
| `image_url` with a `data:` URL on `qwen3.8-flash` | Vision twin + `explainImage()` | Whether the `pic2text` row ships |
| `max_tokens` accepted (vs `max_completion_tokens`); actual max output per model | Row `max_tokens` / `meta.max_output` | Row values |
| Latency of a thinking model on a short prompt; whether thinking can be switched off (`enable_thinking`, `thinking: {type: disabled}`) | MAIN default choice (§4 row 5), FAST-tier suitability | Default bindings |
| Rate limits / 429 shape; `/v1/usage` fields | Operator docs, price-drift verification | Docs text |
| Billed price vs public list for the trial key's group | Catalog stores the public rate; resale billing needs the real one | `PRICING_MAINTENANCE.md` caveat wording |

Exit: table filled; any "no" has a decision recorded. Remove the spike
endpoint afterwards (or keep it for dev only).

### 5.2 S1 — backend provider + catalog (`feat(backend): add A2Agent provider`)

1. `backend/src/AI/Provider/A2AgentProvider.php` (or the base class + thin
   subclass per §4 row 2): `PROVIDER_NAME = 'a2agent'`, display `A2Agent`,
   `BASE_URI = 'https://a2agent.me/v1'`, `DEFAULT_CHAT_MODEL = 'deepseek-v4-pro'`,
   `DEFAULT_VISION_MODEL = 'qwen3.8-flash'`, capabilities `['chat', 'vision']`,
   `getRequiredEnvVars()` → `A2AGENT_API_KEY` with hint "Get your API key from
   https://a2agent.me/ (dashboard → API keys)". Description names the vendors
   and the jurisdiction plainly.
2. `backend/config/services.yaml`: `env(A2AGENT_API_KEY): ''` in the
   parameters block; `a2agent: '%env(A2AGENT_API_KEY)%'` in the
   `ProviderKeyStore` env map (~l. 333); service definition with
   `$keyStore`, `$uploadDir`, tags `app.ai.chat` and `app.ai.vision`,
   `key: 'a2agent'`.
3. `AI/Credential/ProviderKeyCatalog::PROVIDERS['a2agent']`: `envVar`
   `A2AGENT_API_KEY`, `consoleUrl` `https://a2agent.me/`, `freeTier: true`
   (trial credit on sign-up), `recommended: false`, validation `GET
   https://a2agent.me/v1/models` with `Authorization: Bearer {key}`.
4. `AI/Credential/ProviderKeyStore::SUPPORTED_PROVIDERS` + class docblock.
5. `AI/Credential/ProviderDefaultsService`: `PROVIDER_DEFAULTS['a2agent']`
   per §4 row 5; `PREFERENCE_ORDER` per §4 row 6.
   `Command/ApplyProviderDefaultsCommand` argument help text.
6. `Controller/AdminProviderKeysController`: add `a2agent` to the OpenAPI
   `provider` path enum → `make -C frontend generate-schemas` → `vue-tsc`.
7. `Service/Admin/SystemConfigService`: `cloud` section field list +
   `A2AGENT_API_KEY` descriptor (`source: 'database'`, description names
   the models and "Chinese model vendors via gateway").
8. `AI/Messages/Translator/ChatCompletionsUpstreams::URLS['a2agent']`;
   docblock of `OpenAiMessagesTranslator`; provider list in the
   `MessagesGateway` error message.
9. `AI/Tool/CatalogToolUse::CAPABLE_CHAT_SERVICES` and
   `AI/StructuredOutput/StructuredOutputCapability::OPENAI_JSON_SCHEMA_PROVIDERS`
   — **each only if S0 said yes**.
10. `Model/ModelCatalog.php`: section comment + five rows (BIDs 361–365 or
    the next free block at implementation time), §3.3 shape.
11. `.env.example`: a block after TrustedTokens (get-key URL, "docs: the
    integration guides at https://a2agent.me/integrations", note on
    lowercase model ids).
12. Tests (§6). Gate: `make -C backend lint && make -C backend phpstan &&
    make -C backend test` — unfiltered. The routing snapshots in
    `tests/Characterization/` must not drift (no classifier change here; if
    they do, stop and look).

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
4. `utils/modelMixes.ts` (if §4 row 7 is ticked): `'a2agent'` in
   `ModelMixId`, an `a2agent()` candidate helper, the mix definition with
   `icon: { kind: 'service', service: 'A2Agent' }`, label key next to
   `"europe": "Europe Mix"` in all five locales.
5. Regenerated `src/generated/api-schemas.ts` from S1 step 6; `vue-tsc` green.
6. Gate: `make -C frontend lint && docker compose exec -T frontend npm run
   check:types && make -C frontend test`, then `make test-e2e` (provider key
   card is on an admin route the `@ci` suite touches; layout unchanged, so
   the Mobile job is not required).

### 5.4 S3 — docs and rollout (`docs: A2Agent provider`)

- `docs/CONFIGURATION.md`: an "A2Agent (Chinese frontier models, gateway)"
  section after TrustedTokens — env var, what it serves, base URL, the
  jurisdiction sentence, the lowercase-id rule, PAYG note.
- `docs/PRICING_MAINTENANCE.md`: source = `GET https://a2agent.me/v1/models`
  + the `/models` page; "not in LiteLLM → `unmatched` bucket, verify
  manually"; **the key-group caveat** ("public rate; billed rate follows the
  key's group — compare against `/v1/usage` after the first invoices");
  verification-date row in the provider table.
- `docs/PRICE_DRIFT_PROCEDURE.md`: add the A2Agent source to the list.
- `docs/ANTHROPIC_COMPATIBLE_API.md`: A2Agent in the Chat-Completions host
  list. `README.md`: AI Chat provider line + provider table row (🇨🇳,
  `A2AGENT_API_KEY`, the four models).
- `synaplan-docs` (separate repo): provider page entry; `synaplan-platform`
  (private): `A2AGENT_API_KEY` in the live compose `.env` on the web nodes,
  one node at a time — ops work, never committed here.
- `node scripts/mobile-impact.mjs --base <base> --head <head>` on each PR:
  expected `backend-only` (S1), `ota-candidate` (S2), `no-app-impact` (S3).
  No policy change needed — every touched path is already allow-listed.

### 5.5 Not done on purpose

- No use of A2Agent's native `/v1/messages` or `/v1/responses` faces. Synaplan
  talks Chat Completions to every OpenAI-compatible provider; one code path.
- No media rows (`text2pic` etc.) — the gateway has none.
- No feature-module descriptor: keyed cloud providers stay core (feature-modules
  §0 row 3). The class must stay free of constructor side effects so the lazy
  `ProviderRegistry` (S3 there) can wrap it unchanged.

---

## 6. Tests

| Test | Mirrors | Asserts |
| ---- | ------- | ------- |
| `backend/tests/AI/Provider/A2AgentProviderTest.php` | `TrustedTokensProviderTest` | name/display/description, capabilities `chat`+`vision`, default models, unavailable without key, `A2AGENT_API_KEY` required, `chat()` requires model and key, `buildChatOptions` merges `json_schema` (or omits it, per S0) |
| `backend/tests/Unit/Model/ModelCatalogTest.php::testA2AgentModelsAreAvailableWithExpectedApiIds` | the TrustedTokens block (l. 733) | five keys resolve (`a2agent:qwen3.8-max:chat`, …, `a2agent:qwen3.8-flash:pic2text`), BIDs, `service === 'A2Agent'`, `meta.jurisdiction === 'CN'`, prices |
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
| **Case-sensitive model ids.** | Catalog `providerId` is the exact lowercase id; the section comment says so; S0 verifies each. |
| **Thinking output format differs per vendor** (`reasoning_content` vs inline `<think>`). | S0 check; the frontend handles both today; `features.reasoning` set per measured behaviour. |
| **Subscription caps.** | §4 row 9: PAYG only for the platform key. |
| **Catalog BID collision** with a parallel branch. | Allocate at implementation time from the then-current maximum; `ModelCatalogTest` fails on duplicate ids. |
| **`CAPABLE_CHAT_SERVICES` forces `tool_use` on every chat row.** | Only add `a2agent` when all four rows verified tool calling in S0; otherwise flag rows individually and leave the service out of the list. |

---

## 8. Out of scope

- A "China/Asia" region mix or any region-flagged preset (§4 row 7 rationale).
- Per-user A2Agent keys (keys stay install-wide, as for every provider).
- Duplicating GLM or Kimi through A2Agent (§3.2).
- Media generation, embeddings, STT/TTS through A2Agent (not offered).
- Any change to `ProviderRegistry`, module descriptors, or the OpenAI-compatible
  endpoint registry.
- Chinese (`zh`) UI locale — a separate product decision; the current five
  locales are the contract.

---

## 9. How work is executed

Feature branch per sprint (`feat/a2agent-provider-backend`, `…-frontend`,
`docs/a2agent-provider`), Conventional Commits with the type that matches the
release bump (`feat` for S1/S2, `docs` for S3, `refactor` for the optional
base-class PR), full local gate before every commit (`make ci-local`), `make
test-e2e` before the frontend push, mobile-impact classification on each PR,
five locales, no AI attribution. `STATUS.md` in this directory is the step log
from S0 on.

# AI Model Pricing — Maintenance Notes

Living playbook for keeping Synaplan's model prices correct **and** billed the way each provider actually charges. Written to be (a) uploadable/shareable so anyone can maintain prices, and (b) a guide an AI agent can follow to do this job well with minimal context.

> **Golden rule:** a price is only "verified" when BOTH are true — the **number** matches the official page AND the **billing mechanics** match (unit, tiers, discounts, what our provider code actually sends, refund-on-cancel). A right number with the wrong unit still bills wrong. See the playbook below.

## Why this matters (billing model)

- `BUSELOG.BCOST` stores the **raw provider cost** (from `CostCalculationService`).
- User is charged `rawCost × (1 + markup)`. **Default markup = 10%** (`RateLimitService::DEFAULT_MARKUP_PERCENT`, tunable via `BCONFIG` `BILLING/MARKUP_PERCENT`).
- Monthly cost budget per tier + top-ups gates further requests (`checkCostBudget()`).
- **Costs are resold to customers.** Margin is thin (10%), so if the catalog price is below the real provider price, we lose money on every call — the 10% doesn't even cover the gap.

## Where prices live

| What | File |
| ---- | ---- |
| Source of truth (prices, units, `pricing_mode`, `resolution_prices`) | `backend/src/Model/ModelCatalog.php` |
| Cost calc (per_token / per_character / per_image / per_second, cache discount) | `backend/src/Service/CostCalculationService.php` |
| Charge = raw × (1+markup) | `backend/src/Service/RateLimitService.php` |
| Auto price pull from LiteLLM | `backend/src/Command/SyncModelPricesCommand.php` (`app:sync-model-prices`, `--dry-run`) |
| Embedding pre-flight estimate | `backend/src/Service/Embedding/EmbeddingCostEstimator.php` |
| DB sync from catalog | `app:model:seed` |

Units (`inUnit`/`outUnit` in the catalog): the tokens `CostCalculationService::normaliseToPerUnit()` understands are — `per1m`/`per1mchars`/`per1mtokens` (÷1e6), `per1k`/`per1000`/`per1000chars` (÷1e3), `permin` (÷60), `perhour` (÷3600), `per1`/`perchar`/`perpic`/`perimage`/`persec`/`persecond` (as-is), and `-`/``/`free` (→ 0). Time-based media bills on **seconds**, so `permin`/`perhour` are converted down to per-second (fixed in #1314). Anything else falls through unchanged (per-1), so never author a unit that isn't in this list. `pricing_mode` is one of `per_token` (default), `per_image`, `per_character`, `per_second`.

## Verification playbook (do this for EVERY model)

For each provider block in `ModelCatalog.php`, work through all seven steps. Steps 4–6 are the ones people skip — and they are where silent overcharging/undercharging hides.

**Scope — which blocks to touch:**

- **LiteLLM-synced (do NOT hand-edit numbers):** Anthropic, OpenAI, Google, Groq, plus the xAI **chat/vision** rows. Their prices come from `app:sync-model-prices`. To verify, run `docker compose exec -T backend php bin/console app:sync-model-prices --dry-run` and eyeball the diff against the official pages; only touch the catalog for something LiteLLM gets wrong (record why).
- **Manual (the full 7-step playbook applies):** Kimi/HuggingFace, TheHive, Higgsfield, Mistral, Cloudflare Workers AI, and the xAI **Grok Imagine + voice** rows. These are the rows in the status table (§"NOT covered by LiteLLM sync").
- **Skip:** Piper / Triton (free/local, `pricing_mode` effectively free).

**When to re-verify (don't just trust a ✅):** treat any status row whose "verified" date is **older than 30 days** as unverified and redo the 7 steps. A price you didn't check this run is *not* verified, regardless of the table.

**If a price is not publicly verifiable** (credit-only dashboards, key-auth tiers, no public table — see Higgsfield): do NOT guess or leave it silently. (a) keep the current value, (b) label it `approximate` in a source comment with the reason + date, (c) write down the exact steps the account owner must take to confirm it, and (d) if the billing *unit* is structurally wrong (not just the number), open/link an issue. Then move on — a documented "cannot verify" is a completed step, a skipped one is not.

1. **Identify the exact upstream model.** Read `providerId` and `json.params.model` — the price must match *that* SKU/version (e.g. `mistral-large-latest` → "Mistral Large 3", not an older Large), not just the display name.
2. **Find the OFFICIAL price page** (see per-provider links below). Prefer the provider's own `/pricing` or docs over third-party trackers; use trackers only to cross-check.
3. **Verify the number** — input and output separately, in the provider's stated unit.
4. **Verify the BILLING UNIT & MECHANICS** — how do they actually charge?
   - Unit: per-token / per-character / per-image / per-second / per-minute / per-hour / per-request / per-credit.
   - Tiered? price varies by resolution, quality tier, image steps, reasoning effort, clip length.
   - Discounts: cached-input discount? batch discount? (record them even if we don't use them yet).
   - Does the catalog `pricing_mode` + unit match how `CostCalculationService` normalizes it? A correct number under the wrong `inUnit`/`outUnit` bills wrong. The ONLY accepted `inUnit`/`outUnit` tokens are the ones in `normaliseToPerUnit()` (listed under "Units" above) — any other string is silently billed per-1.
5. **Check what OUR provider code sends** — open the provider class in `backend/src/AI/Provider/`. If it sends parameters that change the price (inference steps, resolution, `n`/num_images, duration) they must match what the catalog price assumes. Example: TheHive scales price with inference steps, but our provider sends none → default steps → base rate is exact.
6. **Check cancellation/refund correctness** — does the provider refund failed/NSFW/cancelled generations? Does our provider send a cancel on Stop? This affects whether a charged cost is ever real. (Async media providers especially.)
7. **Apply & record** — update `ModelCatalog.php` (both `priceOut`/`priceIn` AND any `json.mode_prices` / `resolution_prices`), add a source comment with the URL + date, update this doc's status table + provider block, then run the gate and re-seed **in this exact order** (a filtered/`--filter` run does NOT count — see AGENTS.md):

   ```bash
   make -C backend lint
   make -C backend phpstan            # analyses src/ AND tests/ — never scope to one path
   make -C backend test               # full suite; covers CostCalculationService, ModelCatalog, SyncModelPrices tests
   make -C backend seed               # push catalog → DB
   ```

   (Pricing-relevant tests live in `tests/Service/CostCalculationServiceTest.php`, `tests/Unit/Service/CostCalculationServiceTest.php`, `tests/Unit/Model/ModelCatalogTest.php`, `tests/Command/SyncModelPricesCommandTest.php` — but run the unfiltered `make -C backend test`, not just these.)

Provider billing-mechanics cheat-sheet (verified 2026-07-13):

| Provider | Bills by | Tiered by | Refund on cancel? | Our code sends price-changing params? |
| -------- | -------- | --------- | ----------------- | ------------------------------------- |
| Anthropic / OpenAI / Google / Groq | per-token (in/out) | some per-image tiers (gpt-image, see #1315) | n/a (sync) | no |
| DeepInfra (Kimi via HF) | per-token, cache-read discount | — | n/a (sync) | pinned `:deepinfra` (else HF `:fastest`) |
| TheHive | per-image ($/1000) | inference steps (linear) | — | no (default steps → base rate) |
| Mistral | per-token; Voxtral per-min (STT) / per-char (TTS) | — | n/a | no |
| Cloudflare WAI | neurons → per-token equiv | — | n/a | no |
| Higgsfield | **per-clip credits** (not per-second!) | resolution, clip length | **yes** (failed/NSFW/cancelled auto-refunded) | resolution + duration (see #1317) |
| xAI (chat/vision) | per-token, cache-read discount | **prompt length** (2× above 200k) | n/a | `reasoning_effort` (changes output token volume, not the rate) |
| xAI (Grok Imagine) | image: per-image · video: **per-second** | video: resolution | **no** (no cancel endpoint) | resolution + duration |
| xAI (voice) | TTS: **per character** · STT: **per second** (authored $/hour) | — | n/a | text length · audio duration |

## HuggingFace routing — DECIDED: pin DeepInfra

`HuggingFaceProvider` called `router.huggingface.co` **without a suffix** → HF default = `:fastest` (NOT cheapest). Price varied per request, matched no catalog price → broke resale billing.

**Decision (2026-07-13): pin DeepInfra** on all Kimi models via `:deepinfra` suffix in `providerId` + `params.model`. Rationale: cheapest reliable HF partner → best margin at 10% markup; deterministic price → catalog is exact; native FP4 (no quality loss); cache-read $0.15.

- Mechanism: `getProviderId()` (BPROVID) is the model string sent to HF; `buildModelString()` passes any string containing `:` through verbatim. `modelKey()` replaces `:`→`-` so catalog keys stay valid. No Kimi models are seeded defaults → `findBidByKey` unaffected. BIDs are explicit/stable → user selections unaffected.
- **Tradeoff:** pinning removes HF auto-failover. Follow-up: add app-level provider fallback (not auto-routing). *(TODO — no issue yet.)*

### Kimi provider prices (per 1M In/Out, HF partners only) — snapshot 2026-07-04

| Provider | K2.5 | K2.6 | K2.7 Code | Vision | Notes |
| -------- | ---- | ---- | --------- | ------ | ----- |
| **DeepInfra (PINNED)** | $0.45 / $2.25 | $0.75 / $3.50 | $0.74 / $3.50 | yes | cache-read $0.15, native FP4 |
| Together | $0.50 / $2.80 | $0.83 / — | $0.95 / $4.00 | yes | was "Down" on 07-04 |
| Novita | $0.60 / $3.00 | $0.61 / — | $0.95 / $4.00 | yes | |
| Fireworks | $0.60 / $3.00 | $0.70 / — | $0.95 / $4.00 | yes | fastest/most reliable |

Catalog now set to DeepInfra rates. Note K2.7 was previously $0.95/$4.00 (ABOVE DeepInfra) → we had been overcharging customers; K2.5/K2.6 were below → we had been losing money.

### Kimi K3 (BIDs 328/329) — snapshot 2026-08-20

Same DeepInfra pin. HF partners serving K3 on 08-20: DeepInfra, Together, Fireworks, Featherless, Baseten.

| Provider | K3 | Notes |
| -------- | -- | ----- |
| **DeepInfra (PINNED)** | $2.85 / $14.25 | cache-read $0.285, native MXFP4 |
| Together / Fireworks / Novita | $3.00 / $15.00 | Moonshot first-party list price |

## Discontinuation detection — `app:models:check-availability`

Providers retire models without telling us, and until now we found out when a user hit a provider error. This command finds it first.

```bash
docker compose exec -T backend php bin/console app:models:check-availability --fail-on-drift; echo $?
```

It runs in **two stages, and the second one is the point**: the provider's model list is only a cheap pre-filter, then every model missing from that list is confirmed individually via `GET {listUrl}/{modelId}`. Only a model the provider itself answers `404`/`400` for is reported. Judging by list membership alone is wrong in both directions, verified against the live APIs on 2026-08-19:

- **False alarm (the mechanism, not the current status)** — the point is that list membership is not evidence either way; the confirm step is. On 2026-08-19 Gemini still served `imagen-4.0-generate-001` through `:predict` and left it out of `models.list` while answering `200` on a direct GET, so a list-only check wrongly reported all three Imagen rows as dead. **That example has since flipped:** Google hard-shut the Imagen 4 endpoints on 2026-08-17, direct GET now answers `404`, and the three rows are genuinely retired (see [Google Imagen 4 shutdown](#google-imagen-4-shutdown-2026-08-17) below). The lesson stands — only the per-model probe decides.
- **Blind spot** — xAI's `grok-tts` is also absent from `/v1/models`, and there it really is gone (`404`). Any heuristic that excused the Imagen case (per-capability coverage, tag families) hid this one.

What it deliberately does **not** do: change anything. No `BACTIVE=0`, no default repointing. A model also disappears from a listing through a rename, a region gate or an account tier, and an unattended deactivation on a false positive takes a working model away from every user of the install. Retirement stays a reviewed, human decision — now a reviewed registry entry rather than a reviewed migration (see [Retiring a model](#retiring-a-model)).

Reporting rules that keep it trustworthy:

- Providers with no key, no listing endpoint (HuggingFace validates via `whoami-v2`; Ollama et al. are per-install) or an unreachable API are **unchecked** — never read as "serves nothing".
- A probe that answers `401`/`429`/`5xx` leaves the model **unconfirmed**: shown in the console, excluded from the alert and the exit code.
- Findings carry both scopes independently: `database` (this install's active `BMODELS` rows) and `catalog` (what new installs still get). An operator who cleaned up locally must still learn that fresh installs keep receiving the dead model.
- A finding on a `ProviderDefaultsService` recommendation is marked and sorted first — `app:provider:apply-defaults --auto` assigns those unattended at container start.

Exit codes match `app:sync-model-prices`: `0` clean, `1` the command broke, `2` confirmed findings (only with `--fail-on-drift`). The scheduler role runs it daily with `--notify`, which posts to Discord when `DISCORD_WEBHOOK_URL` is set. Installs without cloud keys make no outbound request at all.

### First run against live APIs (2026-08-19)

Six confirmed retirements, each re-verified by hand: Groq dropped `llama-3.3-70b-versatile` (BID 9), `llama-3.1-8b-instant` (236), `qwen/qwen3-32b` (53) and `meta-llama/llama-4-scout-17b-16e-instruct` (17 — the Groq `PIC2TEXT` default), xAI dropped `grok-stt` (321 — the xAI `SOUND2TEXT` default) and `grok-tts` (320). Groq's current list is 13 models; `whisper-large-v3` and `openai/gpt-oss-*` are unaffected.

The four Groq rows were retired in `Version20260819080000` (#1513), which also added Groq Qwen 3.6 27B (324/325) as their successor. Re-running the check after that migration reports Groq at `7/7 matched`: the retired rows are out of the active set and the freshly added successor BIDs produce no false positive.

The two xAI rows were retired in `Version20260820120000` (#1514). Because the catalog has no xAI replacement for either capability, this is the **no-successor** variant of the policy: the rows are deactivated and their `DEFAULTMODEL` bindings are **deleted** instead of repointed, which hands the capability back to the normal resolution chain rather than binding the install to a provider whose key the operator may not hold. The xAI `SOUND2TEXT` recommendation was dropped from `ProviderDefaultsService` in the same change — without that, `app:provider:apply-defaults --auto` writes the dead binding back on the next container start.

### Google Imagen 4 shutdown (2026-08-17)

Google deprecated all three Imagen 4 IDs on 2026-06-15 and **hard-shut them down on 2026-08-17** (both the Gemini Developer API and Vertex). This is the same trio that was a *false alarm* on 2026-08-19 in the availability check's initial run — back then a direct GET still answered `200`. After the shutdown the direct GET answers `404`, so the two-stage check now **confirms** them Gone and the daily Discord digest lists them until they are retired.

| BID | Model | `providerId` | Successor |
| --- | ----- | ------------ | --------- |
| 115 | Imagen 4.0 | `imagen-4.0-generate-001` | `google:gemini-3.1-flash-image-preview:text2pic` (Nano Banana 2, BID 190) |
| 230 | Imagen 4.0 Fast | `imagen-4.0-fast-generate-001` | same |
| 231 | Imagen 4.0 Ultra | `imagen-4.0-ultra-generate-001` | same |

Retired via the registry (`ModelCatalog::RETIREMENTS`, no migration): the three catalog rows carry `active = selectable = 0` and a `RETIREMENTS` entry, and `ModelRetirementSeeder` stamps `BRETIREDON`/`BSUCCESSORID` on every install. Nano Banana 2 (`gemini-3.1-flash-image-preview`, BID 190) is already the seeded `DEFAULTMODEL.TEXT2PIC`/`PIC2PIC`, so no default binding is orphaned; all three tiers point at it because we do not carry the flat `gemini-3.1-flash-image` / `gemini-3-pro-image` variants Google's migration table names per tier. Google's [deprecations page](https://ai.google.dev/gemini-api/docs/deprecations) is the authority for the shutdown date.

## Maintenance links

**Official provider price pages** (use these first — step 2 of the playbook):

- Anthropic: https://www.anthropic.com/pricing#api
- OpenAI: https://openai.com/api/pricing/ · gpt-image: https://platform.openai.com/docs/pricing
- Google (Gemini/Veo): https://ai.google.dev/gemini-api/docs/pricing
- Groq: https://groq.com/pricing
- Mistral (**API tab**, not consumer): https://mistral.ai/pricing/api/
- Cloudflare Workers AI: https://developers.cloudflare.com/workers-ai/platform/pricing/
- TheHive: https://thehive.ai/pricing
- Higgsfield (dashboard only — no public table): https://cloud.higgsfield.ai/ · docs https://docs.higgsfield.ai/
- DeepInfra (Kimi partner we pin): https://deepinfra.com/pricing
- Kimi direct: https://platform.kimi.ai/docs/pricing/chat
- TrustedTokens (JSON catalog, not the JS marketing page): https://trustedtokens.eu/api/billing/models · docs https://trustedtokens.eu/docs/
- xAI: https://docs.x.ai/developers/pricing · models https://docs.x.ai/developers/models

**Tooling / cross-checks:**

- LiteLLM price DB (used by `app:sync-model-prices`): https://github.com/BerriAI/litellm/blob/main/model_prices_and_context_window.json
- HF inference partners: https://huggingface.co/inference/get-started
- HF provider routing policy (`auto`=`:fastest`, `:cheapest`, `:preferred`): https://huggingface.co/docs/inference-providers/en/index
- Per-model HF providers + price: `https://huggingface.co/<org>/<model>` → right sidebar
- Cross-provider price compare (trackers, cross-check only): https://inferencehub.org/ · https://artificialanalysis.ai/models · https://tokencost.app/

## NOT covered by LiteLLM sync — check manually against provider pages

Per-provider blocks in `ModelCatalog.php`. Status:

| Provider | Status | Source |
| -------- | ------ | ------ |
| Kimi/HuggingFace | ✅ done — pinned DeepInfra (see above) | deepinfra.com |
| **TheHive** | ✅ verified 2026-07-13 | https://thehive.ai/pricing |
| Higgsfield | ⚠️ NOT publicly verifiable — see below | dashboard only |
| **Mistral** | ✅ verified 2026-07-13 — all correct | https://mistral.ai/pricing/api/ |
| **Cloudflare** | ✅ verified 2026-07-13 — all correct | https://developers.cloudflare.com/workers-ai/platform/pricing/ |
| **TrustedTokens** | ✅ verified 2026-08-29 | https://trustedtokens.eu/api/billing/models |
| **xAI Grok Imagine + voice** | ✅ verified 2026-07-29 (chat rows are synced) | https://docs.x.ai/developers/pricing |
| Piper / Triton | n/a — free/local | — |

### xAI / Grok (verified 2026-07-29; Grok 4.6 rows added 2026-08-20)

OpenAI-compatible chat/vision at `https://api.x.ai/v1`, plus the Grok Imagine media endpoints and the voice endpoints (`/v1/tts`, `/v1/stt` — note: NOT OpenAI's `/v1/audio/*`). Catalog stores **USD per 1M tokens** for the token rows; cache-read rates live in `json.cache_read_price_per_1M`.

| BID | Model | Catalog in/out | Official (cache) | Long context (> 200k) | Context |
| --- | ----- | -------------- | ---------------- | --------------------- | ------- |
| 326 / 327 | `grok-4.6` (chat + vision) | $2.00 / $6.00 | $2.00 / $6.00 (cache $0.50) | $4.00 / $12.00 | 500k |
| 313 / 315 | `grok-4.5` (chat + vision) | $2.00 / $6.00 | $2.00 / $6.00 (cache $0.30) | $4.00 / $12.00 | 500k |
| 316 | `grok-imagine-image` | — / $0.02 per image | $0.02 (1k and 2k identical) | n/a | n/a |
| 317 | `grok-imagine-video` | — / $0.07 per second | 480p $0.05 · 720p $0.07 | n/a | 1–15 s |
| 318 | `grok-imagine-image-quality` | — / $0.05 per image | 1k $0.05 · 2k $0.07 | n/a | n/a |
| 319 | `grok-imagine-video-1.5` | — / $0.14 per second | 480p $0.08 · 720p $0.14 · 1080p $0.25 | n/a | 1–15 s |
| 320 | `grok-tts` | $0.000015 per character | $15.00 / 1M characters | n/a | max 15,000 chars |
| 321 | `grok-stt` | $0.10 per hour | $0.10/hr (REST) · $0.20/hr (streaming) | n/a | max 500 MB |

The **> 200k long-context tier doubles the whole request**, so it lives in `ModelCatalog::CONTEXT_PRICING` (keyed by `providerId`, which covers the chat and the vision row at once) rather than in the model rows.

`priceIn` is deliberately **0** on both Imagine rows. `RateLimitService` sets `inputQuantity` to the clip length in the `per_second` path and to 0 in the `per_image` path, so any non-zero input price would be billed a second time per second of video.

**Known deviations and limits — read before "fixing" a number:**

- **LiteLLM reports a $0.50/1M cache-read price for `xai/grok-4.5`; the official xAI docs say $0.30.** We follow the official value. The sync only rewrites `cache_read_price_per_1M` when the input/output price itself drifts, so our value stays put — do not "correct" it toward LiteLLM. (`grok-4.6` is different: there the official cache-read price really is $0.50, so catalog and LiteLLM agree.)
- **Cached tokens are not doubled in the long-context tier.** `CONTEXT_PRICING` overrides only `price_in`/`price_out`, so above 200k prompt tokens cache reads are billed at the base rate ($0.30 on `grok-4.5` instead of xAI's $0.60, $0.50 on `grok-4.6` instead of $1.00) — a small undercharge. Extending `CONTEXT_PRICING` with a `cache_price_above` key would be a small follow-up.
- **No pic2pic / image editing and no video editing or extension.** xAI bills input media separately ($0.002 per input image, $0.01 per input video second) and the `per_image` cost path pins `inputQuantity` to 0, so those inputs could not be attributed. `XaiProvider::editImage()` and `createVariations()` therefore throw. As long as only `text2pic` and `text2vid` are offered, billing is exact.
- **Read the Imagine table in the rendered page, never the "View as Markdown" export.** The markdown/plain-text view flattens the multi-row Imagine table and keeps only the FIRST resolution row, so `grok-imagine-video` looks like a flat `$0.050 / sec` and the 720p rate silently disappears. The `.../models/grok-imagine-video` page shows the same collapsed number. Trusting that export once already produced a 40% undercharge on the default 720p render. The rendered [pricing page](https://docs.x.ai/developers/pricing) is the only reliable source for media rows.
- **1080p is not a `grok-imagine-video` resolution.** Only `grok-imagine-video-1.5` (BID 319) offers it, which is why BID 317 caps `allowed_resolutions` at 480p/720p. `XaiProvider::resolutionFromOptions()` additionally intersects the request with the keys of `resolution_prices`, so a row that prices only 480p/720p can never render an unpriced tier even if its `allowed_resolutions` are missing. A row without `resolution_prices` bills every tier at `priceOut`, so there the requested resolution is honoured as-is.
- **Images are billed from `default_resolution`, not from the request.** The image path never passes a resolution into `calculateMediaCost()`, so `resolveResolution()` falls back to the catalog's `default_resolution` — and `XaiProvider::generateImage()` sends that same value to xAI. Changing `default_resolution` on BID 318 therefore moves the request AND the price together; changing only `priceOut` would desync them.
- **`grok-imagine-video-1.5` is image-to-video only.** It carries `features: ['image2video']` + `requires_reference_image`, so `MediaGenerationHandler` explains the missing reference image instead of leaking a provider 400. It is reachable through the IMG2VID default-model slot, which shares the `text2vid` BTAG. Because `XaiProvider` implements `SupportsInlineReferenceImage`, the handler passes the local upload path and the provider inlines it as a data URI — so image-to-video works on xAI without an internet-reachable `APP_URL`, unlike Higgsfield and Veo.
- **No refund on cancel.** xAI has no cancel endpoint for deferred video renders, so `cancelVideoOperation()` only stops our polling; the render completes upstream and stays billable.
- **`reasoning_effort` is a grok-4.3-only parameter**, and grok-4.3 is not in the catalog. xAI documents the knob for that model alone (`none` / `low` (default) / `medium` / `high`), so `XaiProvider::REASONING_EFFORT_MODELS` gates it. The reasoning depth of `grok-4.5` and `grok-4.6` — and therefore their output token volume — is not controllable, so a Thinking toggle cannot reduce their cost.
- **The voice rows MUST keep their `pricing_mode`.** BID 320 needs `pricing_mode: per_character` and BID 321 needs `per_second`; without it the cost path falls through to per-token and records $0.00 for every call (issue #886b). BID 321 is authored in `$/hour`, which `CostCalculationService` normalises to per-second, so the clip length must never be pre-divided.
- **Only the REST transcription rate is reachable.** xAI charges $0.20/hour for the streaming STT WebSocket, but `XaiProvider` implements the `POST /v1/stt` REST path only, so no request can be billed at the higher rate. If streaming STT is ever added it needs its own catalog row.
- **TTS has no per-request cap beyond the character limit.** `/v1/tts` rejects text over 15,000 characters, and the provider checks that locally so the user gets a readable message instead of a 400. Longer texts must be split by the caller — each chunk is billed separately.
- **The realtime Speech-to-Speech API is deliberately not wired up.** It bills per session minute ($0.05/min, plus $0.004 per text input message) over a WebSocket, and this application has no realtime-voice capability to attach it to. Adding it would need a new capability, a new pricing mode, and session-duration metering.
- **Embeddings and the server-side tools** (web search, X search, code execution) are intentionally not wired up: xAI publishes no price for `/v1/embeddings`, and without a price there can be no correct usage accounting.

### TrustedTokens (verified 2026-08-29)

German sovereign OpenAI-compatible inference (`https://api.trustedtokens.eu/v1`). Per-token rates come from the public billing catalog (not the JS-rendered marketing page); subscription plans (€50 / €200 / €2,000) are prepaid usage credits that draw down against these rates. Catalog stores **USD per 1M tokens** (same unit as every other cloud provider). Cache-read rates are authored in `json.cache_read_price_per_1M`.

| BID | Model | Catalog in/out | Official (×1e6) | Context |
| --- | ----- | -------------- | --------------- | ------- |
| 309 | `zai-org/GLM-5.2` | $1.50 / $4.50 | $1.50 / $4.50 (cache $0.30) | 230k |
| 331 | `zai-org/GLM-5.3` | $1.50 / $4.50 | $1.50 / $4.50 (cache $0.30) | 1M |
| 332 / 333 | `zai-org/GLM-5.3-Flash` (chat + vision) | $0.15 / $0.30 | $0.15 / $0.30 (cache $0.03) | 1M |
| 334 | `tngtech/DeepSeek-TNG-R1T2-Chimera` | $1.00 / $3.00 | $1.00 / $3.00 (cache $0.20) | 164k |
| 335 | `deepseek-ai/DeepSeek-V4-Flash` | $0.15 / $0.30 | $0.15 / $0.30 (cache $0.03) | 400k |
| 336 | `deepseek-ai/DeepSeek-V4-Flash-0731` | $0.15 / $0.30 | $0.15 / $0.30 (cache $0.03) | 400k |
| 337 | `deepseek-ai/DeepSeek-V4-Pro-0813` | $2.25 / $6.75 | $2.25 / $6.75 (cache $0.45) | 200k |
| 310 / 311 | `Qwen/Qwen3.6-35B-A3B-FP8` (chat + vision) | $0.25 / $1.50 | $0.25 / $1.50 (cache $0.05) | 262k |
| 312 | `openai/gpt-oss-120b` | $0.15 / $0.60 | $0.15 / $0.60 (cache $0.05) | 131k |

Not in LiteLLM → lands in the sync's `unmatched` bucket; re-verify via `curl https://trustedtokens.eu/api/billing/models`. New BIDs land on existing installs through `ModelSeeder` (`app:seed` on container start) — no data migration is required for additive catalog rows.

### TheHive (verified 2026-07-13)

Billed **$/1000 images** at default inference steps (SDXL/SDXL-Enh 20, Flux variants 4). Cost scales linearly with steps: `price × max(1, steps/default)`. We send default steps → base rate applies. Catalog was 2.5–12.5× too high (overcharging), now corrected:

| Model | Was | Now (=$/1000) |
| ----- | --- | ------------- |
| Flux Schnell | $0.01 | $0.003 |
| Flux Schnell Enhanced | $0.02 | $0.004 |
| SDXL | $0.02 | $0.003 |
| SDXL Enhanced | $0.05 | $0.004 |
| Custom Emoji | $0.01 | $0.004 |

### Higgsfield (investigated 2026-07-13 — left unchanged, cannot verify)

We call `platform.higgsfield.ai` with key-auth (`Authorization: Key {key}:{secret}`). Billing is **credit-based, no public USD price table**:

- Official API docs (docs.higgsfield.ai) have **no pricing page**; consumption is tracked only in the cloud.higgsfield.ai dashboard.
- USD-per-credit depends on the plan/credit-pack the account bought (~$0.075/cr Basic → ~$0.043/cr Ultra), so a generation has **no single fixed USD price**.
- API response returns **no cost/credit field** (`parseImagePayload`/`parseVideoPayload` extract only URLs).
- Third-party numbers (WaveSpeedAI Soul $0.09/$0.19, blog credit counts) are OTHER resellers/consumer app, not our key-auth tier.

Current catalog values are labelled "approximate (credits → USD)" and were left as-is:
- Images (per_image): Soul Standard $0.05, Reve $0.05
- Videos (per_second): DoP Lite $0.25, Turbo $0.35, Standard $0.50, Kling 2.1 Pro $0.60, Master $0.90

**Structural issue:** videos are priced `per_second` but Higgsfield bills **per clip** (fixed credits per generation, e.g. blog "DoP Lite 3cr/3s"). Same class of unit mismatch as Whisper/gpt-image (#1314/#1315). Consider an issue.

**To verify, the account owner must:** log into cloud.higgsfield.ai → note plan price ÷ monthly credits = USD/credit, and the in-app credit cost per model → `USD_per_gen = credits × USD/credit`. Only the account holder can see this.

Positive: cancel/refund path is sound — FAQ confirms failed/NSFW/cancelled requests are auto-refunded, and our provider sends a cancel on Stop (`cancelRemote`).

### Mistral (verified 2026-07-13 — all correct, no change)

Use the **API** price page https://mistral.ai/pricing/api/ (the plain /pricing page is JS-rendered consumer Le Chat plans). All 5 catalog entries already match:

| BID | Model | Catalog | Official |
| --- | ----- | ------- | -------- |
| 245 | Mistral Large 3 (`mistral-large-latest`) | $0.50 / $1.50 per1M | $0.50 / $1.50 |
| 244 | Mistral Medium 3.5 (`mistral-medium-latest`) | $1.50 / $7.50 per1M | $1.50 / $7.50 |
| 248 | Medium 3.5 Vision (same model id) | $1.50 / $7.50 per1M | $1.50 / $7.50 |
| 246 | Voxtral Mini Transcribe (`voxtral-mini-latest`) | $0.003 permin | $0.003/min |
| 247 | Voxtral TTS (`voxtral-mini-tts-2603`) | $0.000016 perChar | $0.016/1k chars |

**Billing mechanics:** per-token for chat/vision (in/out separate), Voxtral STT per audio-minute, Voxtral TTS per character. 50% batch discount + 90% cached-input discount exist (we don't use them). Our provider sends no price-changing params. Note: FAQ on the consumer page quotes "Large $2/$6" — that's the OLD Large 2411, not Large 3. Voxtral Transcribe (per-min) now carries `pricing_mode: per_second` and is metered via the shared duration path (#1314 fixed).

### Cloudflare Workers AI (verified 2026-07-13 — all correct, no change)

Official docs table (updated 2026-07-08). Billed in neurons ($0.011/1k neurons); the docs also show the per-token equivalent. Both our entries are embeddings and match:

| BID | Model | Catalog | Official |
| --- | ----- | ------- | -------- |
| 187 | `@cf/baai/bge-m3` | $0.012/1M | $0.012/1M (1075 neurons) |
| 188 | `@cf/qwen/qwen3-embedding-0.6b` | $0.012/1M | $0.012/1M (1075 neurons) |

**Billing mechanics:** everything is metered in **neurons** ($0.011/1k neurons, 10k/day free); the docs publish a per-token equivalent which is what we store. Embeddings bill input tokens only (output $0). No price-changing params sent by our code.

## `app:sync-model-prices` — mode-aware guard (#1318 fixed)

The command classifies every matched model into one of three buckets by comparing the catalog `pricing_mode` against the mode LiteLLM derives — so nothing is silently ignored:

1. **per_token on both sides** → compared and, on drift, **written** (price history + `BMODELS`). The auto-update path.
2. **Same non-per-token mode on both sides** (`per_second`/`per_image`/`per_character`) → both prices are normalised to a single unit via the *same* `CostCalculationService::normaliseToPerUnit()` billing uses, then **compared and reported as drift** — but **never auto-written** (these rows are hand-authored with unit conventions + tier JSON the flat sync can't reproduce). This is what makes whisper / tts / veo / imagen actually checked.
3. **Mode mismatch** (e.g. catalog `per_image` vs LiteLLM `per_token`, because LiteLLM counts the prompt tokens) → structurally not comparable. **Reported for human awareness, never written, and never counted as drift** (the mismatch is permanent — failing CI on it would go red forever).

Models not present in LiteLLM at all (Higgsfield, DeepInfra-pinned Kimi, Cloudflare `@cf/…`, Voxtral, nano-banana) land in **`unmatched`** — no upstream reference exists, verify manually.

Dry-run baseline 2026-07-13: **70 unchanged (per-token + same-mode media, no drift), 5 mode-mismatch, 19 unmatched, 0 drift**. Same-mode media rows now verified against LiteLLM (previously invisible):

| Model | Catalog mode | LiteLLM mode | Bucket |
| ----- | ------------ | ------------ | ------ |
| whisper-v3 / -turbo / whisper-1 | per_second | per_second | **checked** (same-mode) |
| tts-1 / tts-1-hd | per_character | per_character | **checked** (same-mode) |
| veo-3.1 / fast / lite | per_second | per_second | **checked** (same-mode) |
| imagen-4.0 / fast / ultra | per_image | per_image | **checked** (same-mode) |
| gpt-image-1 / 1.5 | per_image | per_token | mode-mismatch (manual) |
| gemini-2.5/3.1-flash-image | per_image | per_token | mode-mismatch (manual) |
| gemini-2.5-flash-preview-tts | per_character | per_token | mode-mismatch (manual) |

**Writes stay conservative:** only per_token rows are auto-written. `--force` overrides admin-set prices but does **not** override the mode guard (reclassification always requires a human editing the catalog). Same-mode media drift is surfaced (and fails `--fail-on-drift`) but left for a human to apply in `ModelCatalog.php`.

### Automated weekly drift check (CI)

`.github/workflows/price-drift.yml` runs every Monday (and on manual dispatch): it seeds the catalog and runs `app:sync-model-prices --dry-run --fail-on-drift`. The flag exits with code **2** when any per-token model **or** any same-mode non-per-token model (whisper/tts/veo/imagen) differs from LiteLLM. Mode-mismatch and unmatched rows never trip it (no false alarms). On drift the workflow opens — or comments on an existing — GitHub issue titled "Price drift detected …" with the dry-run report, so a human verifies against the official page and updates `ModelCatalog.php`. It lives outside the PR CI on purpose: it depends on the external LiteLLM source, which must never turn a code PR red. The report file is written under `backend/` (the check step's `working-directory`), so the issue-opening step must also run with `working-directory: backend` — otherwise `cat drift-report.txt` fails with `No such file or directory` and the step dies under `bash -e` before the issue is created.

You can run the same check locally: `docker compose exec -T backend php bin/console app:sync-model-prices --dry-run --fail-on-drift; echo $?` (0 = no drift, 2 = drift).

> Resolved drift (2026-08-30, #1561): the weekly check flagged `gpt-5.6-sol` (twice — chat + vision, in 5.00 → 4.00, out 30.00 → 20.00). Verified against the [official OpenAI pricing](https://openai.com/api/pricing/): OpenAI cut Sol on 2026-08-21 — input −20% (5 → 4), output −33% (30 → 20), cached input 0.50 → 0.40, long-context (>272k) 10/45 → 8/30. Not a LiteLLM error; keeping the old (higher) rate overcharged every Sol call. Applied to `ModelCatalog.php` (base rows 251/252 + `CONTEXT_PRICING` long-context tier) and rolled out to existing installs by `Version20260830190000`. Terra/Luna and the other GPT-5.x rows did not move.

> No-drift note (2026-08-12): Anthropic made Claude Sonnet 5's $2/$10 rate **permanent** and cancelled the $3/$15 increase that was scheduled for 2026-09-01. The catalog already read $2/$10, so no price change and no migration were needed; we only dropped the stale "revert to $3/$15" TODO (BID 249/250/222). The weekly drift check never surfaced this — it only diffs the current catalog number against LiteLLM (both $2/$10), and is blind to time-boxed manual reverts.

> Resolved drift (2026-08-03): the weekly check flagged `gpt-5.6-terra` and `gpt-5.6-luna` (each twice — chat + vision row). Verified against the [official OpenAI pricing](https://developers.openai.com/api/docs/pricing): OpenAI cut GPT-5.6 prices on 2026-07-30 — Terra 2.50/15 → **2.00/12** (−20%), Luna 1.00/6 → **0.20/1.20** (−80%), Sol unchanged. Not a LiteLLM error; keeping the old (higher) rate overcharged every Terra/Luna call. Applied to `ModelCatalog.php` (base rows + `CONTEXT_PRICING` long-context tiers: Terra 4.00/18, Luna 0.40/1.80) and rolled out to existing installs by `Version20260803120000`.

> History (2026-07-13): an earlier draft claimed whisper's `0.111 perhour` was "the same price" as the sync's per-second value. That was wrong at the time — `perhour` fell through `normaliseToPerUnit()` unchanged and whisper carried no `pricing_mode`. #1314 fixed both: whisper/Voxtral now carry `pricing_mode: per_second` and `normaliseToPerUnit()` converts `perhour`/`permin` down to per-second, and `AiFacade::transcribe()` records the provider-reported audio duration (see below).

## Time-boxed / reminders

- **GPT-5.6 Sol — re-verify on/after 2026-11-22 (#1561).** OpenAI's 2026-08-21 cut to $4/$20 (long-context $8/$30, cached $0.40) is labelled promotional "at least through 2026-11-21"; OpenAI has published no rate for after that, and third-party trackers flag a possible lapse back to $5/$30. On or after 2026-11-22, re-check the [official pricing page](https://openai.com/api/pricing/): if it reverted, roll the old rate back into `ModelCatalog.php` (rows 251/252 + `CONTEXT_PRICING`) + a data migration; if the promo was extended/made permanent, just refresh this note. The weekly drift check is blind to a time-boxed revert (it only diffs against LiteLLM), so this reminder is the only guard.
- _(cancelled)_ — the Claude Sonnet 5 "revert to $3/$15 after 2026-08-31" reminder was **cancelled** on 2026-08-12 (Anthropic made the $2/$10 rate permanent; see the drift-log note below). Do not reintroduce it.

## Anthropic catalog generations (snapshot 2026-07-27)

Source: https://platform.claude.com/docs/en/about-claude/models/overview

| Model | BIDs (chat / vision) | Price in/out per 1M |
| ----- | -------------------- | ------------------- |
| Claude Fable 5 | 240 / 241 | $10 / $50 |
| Claude Opus 5 | 257 / 258 | $5 / $25 |
| Claude Sonnet 5 | 249 / 250 (+ 222 MEM) | $2 / $10 (permanent — see 2026-08-12 note) |
| Claude Opus 4.8 | 238 / 239 | $5 / $25 |
| Claude Haiku 4.5 | 162 / 235 | $1 / $5 |

Retired on 2026-07-27 by `Version20260727120000` (rows deactivated, never deleted — `BMESSAGES` FKs; BIDs must never be reused): Claude Sonnet 4.5 (112/109), Claude Opus 4.6 (160/164), Claude Sonnet 4.6 (161/163), Claude Opus 4.7 (165/166), plus the catalog-orphans Claude Opus 4.1 (69/93, deprecated upstream and retired by Anthropic on 2026-08-05) and Claude Opus 4.5 (121).

The same orphan cleanup continues in `Version20260727180000` for two non-Anthropic rows: **OpenAI `gpt-4.1` (BID 30)** → GPT-5.6 Terra (253) and **Groq `llama-4-maverick-17b-128e-instruct` (BID 49)** → Groq `gpt-oss-120b` (76).

### Orphan sweep 2026-07-28 (`Version20260728120000`)

Comparing the production catalog (`GET /api/v1/admin/models`) against `ModelCatalog::all()` surfaced seven more rows that were still `BACTIVE=1, BSELECTABLE=1` in the database with no catalog entry behind them. **Diffing prod against the catalog is the only way to find these** — the code alone cannot tell you what an old install still offers.

| Retired BID | Row | Successor |
| ----------- | --- | --------- |
| 70 / 106 | OpenAI `gpt-5`, `gpt-5.2-2025-12-11` | GPT-5.6 Terra (253) |
| 150 | OpenAI `gpt-5-mini` | GPT-5.4 mini (232) |
| 125 | HuggingFace `deepseek-ai/DeepSeek-R1` | Kimi K2.6 (202) |
| 128 | HuggingFace `Qwen/Qwen2.5-Coder-32B-Instruct` | Kimi K2.7 Code (242) |
| 126 | HuggingFace `stabilityai/stable-diffusion-xl-base-1.0` | TheHive SDXL (132) |
| 129 | HuggingFace `intfloat/multilingual-e5-large` (vectorize) | none — see below |

> **Never repoint `DEFAULTMODEL.VECTORIZE` from a migration.** Embedding models are not interchangeable: swapping the binding without re-vectorizing leaves every stored vector in the wrong space, which is exactly the failure mode of issue #948 (Qdrant HTTP 400, memory lost). The admin path pairs the switch with a re-vectorize run via `VectorizeBindingService`; a migration cannot. BID 129 is therefore deactivated only where nothing binds to it, guarded by a `NOT EXISTS` on `BCONFIG` — an install still using it keeps working search and switches through the UI.

### A non-zero price under a "free" unit is silently free

`normaliseToPerUnit()` maps `-`, `` and `free` to **0**, so a price stored under one of those units is displayed but never billed. Two Ollama rows shipped exactly that — BID 3 (`deepseek-r1:32b`, out 0.91) and BID 6 (`mistral:7b`, out 0.475) had `BOUTUNIT = '-'` while their input side was `per1M`, so their output tokens were free while input was charged. `Version20260727190000` corrects the unit only; the price values stay as they were, since Ollama rows are an operator-hosted synthetic resale basis and re-pricing them is a decision, not a fix.

`ModelCatalogTest::testNoCatalogPriceIsAuthoredUnderAUnitThatNormalisesToZero()` now fails the build if a catalog row is ever authored this way again. Note that `per_generation` (Higgsfield video, BIDs 302–308) is *not* this bug — unknown units fall through as per-1, which is what a flat per-clip fee needs.

> **Removing a model from the catalog is only half a retirement.** `ModelSeeder` never deletes or deactivates rows, so a model dropped from `ModelCatalog.php` on its own stays `BACTIVE=1, BSELECTABLE=1` in every existing database — still pickable in the UI and still billed at whatever price the stale row holds. That is how Opus 4.1/4.5 survived several releases. Since #1515 the second half is a data entry rather than a migration; see [Retiring a model](#retiring-a-model) below.

## Retiring a model

Every retirement above needed its own hand-written migration, and three of them existed *only* to clean up models an earlier release had dropped from the catalog while leaving them live in every install. #1515 replaced that with a registry: `ModelCatalog::RETIREMENTS`, applied by `ModelRetirementSeeder` on every deploy.

**The whole procedure is now:**

1. Add the entry to `ModelCatalog::RETIREMENTS`, keyed by the retired BID:

```php
321 => [
    'providerId' => 'grok-stt',                  // guard: the row is skipped if the BID now holds something else
    'retiredOn' => '2026-08-20',                 // ships as BMODELS.BRETIREDON
    'successor' => null,                         // catalog key, or null for "no replacement" on purpose
    'reason' => 'Retired by xAI with no replacement speech endpoint (#1514).',
],
```

2. Set `active` and `selectable` to `0` on the catalog row if you are keeping it, or remove the row entirely. Either is fine — the registry is what carries the retirement.
3. Run `make -C backend test`. No migration, no SQL.

**What the seeder does**, idempotently and on every container start, for each entry whose row exists and still matches `providerId`: stamps `BRETIREDON` and `BSUCCESSORID`, and forces `BACTIVE = BSELECTABLE = BISDEFAULT = 0`. A re-run writes nothing. A BID an operator repurposed is skipped with a warning. Rows are never deleted — `BMESSAGES` has FKs into `BMODELS` and **BIDs must never be reused**.

The health monitor (`app:model:health-check`) skips every row that carries `BRETIREDON`. A recorded retirement is expected to be missing, so it is not probed and it must not raise the hourly incident mail. Operator-disabled rows without a date stay in the check.

**Why a separate seeder from `ModelSeeder`:** that one treats `BACTIVE`/`BSELECTABLE`/`BISDEFAULT` as operator-owned and never overwrites them, which is right for a live model and wrong for a dead one. A retirement outranks an operator preference — "please keep offering this" on a model the provider switched off only produces a failing request. Keeping the override in its own seeder makes it explicit instead of punching a hole in `ModelSeeder`'s preservation rules.

**`successor: null` is a statement, not a gap.** It means no substitution may be made — an embedding model (a different model is a different vector space; see the `VECTORIZE` warning above) or a provider that shipped no replacement at all. Do not fill it with another provider's model to avoid the null: that assumes an API key the operator may not hold.

**The guard that makes this stick:** `ModelCatalogRetirementTest` snapshots every BID the catalog has ever shipped (`tests/Unit/Model/__snapshots__/model_bids.json`). Drop a model without a retirement entry and it fails, naming the BID. Adding models is expected — re-record and review that the diff is additions only:

```bash
docker compose exec -T -e UPDATE_MODEL_BID_SNAPSHOT=1 backend \
  ./vendor/bin/phpunit tests/Unit/Model/ModelCatalogRetirementTest.php
git diff backend/tests/Unit/Model/__snapshots__/
```

It also enforces that a recorded successor resolves to exactly one live catalog entry and is not itself retired, so a chain of retirements can never repoint an install at another dead model.

**Still open (follows separately, #1515):** consuming `BSUCCESSORID` at resolution time and surfacing retirement state in the admin UI. Until then, `DEFAULTMODEL` bindings that point at a retired BID are handled as before — repointed or deleted by the migration that accompanied that retirement — and a stale binding degrades through `ModelConfigService`'s logged fallback, since it treats a deactivated row as unusable.

## Related issues

- #1313 — provider name casing standardization (P2)
- #1314 — Whisper per-hour/min unit + duration metering (P2) — **fixed in #1316**
- #1315 — gpt-image-1/1.5 flat rate ignores quality/resolution tiers (P2) — **fixed in #1316**
- #1317 — Higgsfield videos priced per_second but billed per-clip in credits (P2)
- #1318 — app:sync-model-prices clobbers non-per-token models (P2) — **fixed in #1316** (mode guard)
- #1319 — long-context token tier not applied (flat base rate above 200k/272k) (P3) — **fixed in #1316**

## Transcription (STT) metering — #1314

External speech-to-text (OpenAI/Groq Whisper, Mistral Voxtral) is billed on the audio duration the provider returns. `AiFacade::transcribe()` is the single choke point every external call passes through (local whisper.cpp bypasses it and is free), so `TranscriptionUsageRecorder` records the cost there exactly once, under its own `TRANSCRIPTION` action (kept separate from the zero-cost `FILE_ANALYSIS` quota event and from `AUDIOS`, which is TTS). Catalog: whisper/Voxtral carry `pricing_mode: per_second` with their natural `perhour`/`permin` unit; `normaliseToPerUnit()` converts to per-second.

## Image quality/size tiers — #1315

gpt-image bills a different per-image price per quality × size (e.g. gpt-image-1 low 1024² = $0.011, high 1024² = $0.167). The catalog encodes this as `json.quality_prices[quality][size]` with `default_quality`/`default_size` fall-backs; `CostCalculationService::calculateMediaCost()` picks the exact tier from the `quality`/`size` carried in `media_usage`. The generation handlers/services forward the requested quality+size; unknown/`auto` quality falls back to `default_quality`. Models without `quality_prices` keep their flat `priceOut` (no regression). Verified prices (per image):

| Quality | gpt-image-1 1024² / portrait+landscape | gpt-image-1.5 1024² / portrait+landscape |
| ------- | -------------------------------------- | ---------------------------------------- |
| low | $0.011 / $0.016 | $0.009 / $0.013 |
| medium | $0.042 / $0.063 | $0.034 / $0.05 |
| high | $0.167 / $0.25 | $0.133 / $0.20 |

> Rollout caveat: catalog price/JSON changes reach the DB via `ModelSeeder` only for rows still matching their seeded fingerprint. Fresh installs get the correct values; rows an admin edited in the UI are **preserved** and must be updated by a data migration — see §"Production rollout to existing installs".

## Long-context tiers — #1319

Some providers charge a higher per-token rate for the **whole request** once the prompt crosses a token threshold (Gemini 2.5/3.1 Pro above 200k, GPT-5.x above 272k — roughly input ×2, output ×1.5). Billing only the flat base rate under-bills large-context requests. The tiers live in `ModelCatalog::CONTEXT_PRICING` keyed by `providerId` (one place, applies to every BTAG row of a model — the tier is a model property, not a per-row one) and are read via `ModelCatalog::contextPricing()`. `CostCalculationService::calculateCost()` switches both input and output to the above rate when `promptTokens > threshold`; models without a tier are unaffected. Prices are per 1M tokens, same unit as base `priceIn`/`priceOut`, and are read from the current catalog (not the historical snapshot) — acceptable because tiers are stable and rare.

| Model | Threshold | Base in/out (per 1M) | Above in/out (per 1M) |
| ----- | --------- | -------------------- | --------------------- |
| gpt-5.4 | 272k | 2.50 / 15 | 5.00 / 22.50 |
| gpt-5.6-terra | 272k | 2.00 / 12 | 4.00 / 18 |
| gpt-5.5 / gpt-5.6-sol | 272k | 5.00 / 30 | 10.00 / 45 |
| gpt-5.5-pro | 272k | 30 / 180 | 60 / 270 |
| gpt-5.6-luna | 272k | 0.20 / 1.20 | 0.40 / 1.80 |
| gemini-2.5-pro | 200k | 1.25 / 10 | 2.50 / 15 |
| gemini-3.1-pro-preview | 200k | 2.00 / 12 | 4.00 / 18 |

> The `claude-sonnet-4-5` tier was dropped together with that model's catalog rows (retired 2026-07-27, see `Version20260727120000`). No current Claude model has a long-context tier — the 5-series bills one flat rate across its 1M window.

## Production rollout to existing installs

`ModelCatalog` is the source of truth, but a catalog change only reaches an **existing** DB row via `ModelSeeder` when that row still matches its seeded fingerprint. Rows an operator edited in the admin UI are **preserved** and never auto-updated — so a price correction can silently fail to reach production. The repo's convention (see `Version20260712120000/130000/140000`) is to ship a **Doctrine data migration** with idempotent, raw `UPDATE BMODELS ... WHERE BPROVID = :provid` for corrections that must land regardless. Rules:

- Raw `addSql()` only — never touch the `Schema` API (`hasTable()`/`getTable()`); the Galera comparator throws on the shared cluster.
- Idempotent: fixed value UPDATEs / `JSON_SET` re-run to the same result; a `providerId` change guards on the old `BPROVID` so a re-run is a no-op.
- Never touch operator-owned columns (`BSELECTABLE`, `BACTIVE`, `BISDEFAULT`, `BSHOWWHENFREE`).
- Migrations do **not** write `BMODEL_PRICE_HISTORY`; `BMODELS` is the effective price source (history is time-bounded and typically absent), matching every prior price migration.

This PR's corrections are rolled out by `Version20260713190000` (per-token reprices, Kimi DeepInfra pin, TheHive rates, Veo 3.1 Fast, gpt-image quality tiers, Whisper/Voxtral per-second).

## Done in current PR (#1316)

Price updates (Anthropic/Google/OpenAI/Groq) + Anthropic cache-discount case-sensitivity fix + Kimi/HF DeepInfra pinning + TheHive price corrections + `app:sync-model-prices` mode guard & weekly drift CI (#1318) + Whisper/Voxtral duration metering (#1314) + gpt-image quality/size tiers (#1315) + long-context token tiers (#1319) + production rollout migration `Version20260713190000`.

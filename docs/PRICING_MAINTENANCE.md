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

Units (`inUnit`/`outUnit` in the catalog): the tokens `CostCalculationService::normaliseToPerUnit()` actually understands are — `per1m`/`per1mchars`/`per1mtokens` (÷1e6), `per1k`/`per1000`/`per1000chars` (÷1e3), `per1`/`perchar`/`perpic`/`perimage`/`persec`/`persecond`/`permin` (as-is), and `-`/``/`free` (→ 0). **Anything else (incl. `perhour`) falls through unchanged and is billed as per-1** — that is the latent bug behind #1314, so never author a unit that isn't in this list. `pricing_mode` is one of `per_token` (default), `per_image`, `per_character`, `per_second`.

## Verification playbook (do this for EVERY model)

For each provider block in `ModelCatalog.php`, work through all seven steps. Steps 4–6 are the ones people skip — and they are where silent overcharging/undercharging hides.

**Scope — which blocks to touch:**

- **LiteLLM-synced (do NOT hand-edit numbers):** Anthropic, OpenAI, Google, Groq. Their prices come from `app:sync-model-prices`. To verify, run `docker compose exec -T backend php bin/console app:sync-model-prices --dry-run` and eyeball the diff against the official pages; only touch the catalog for something LiteLLM gets wrong (record why).
- **Manual (the full 7-step playbook applies):** Kimi/HuggingFace, TheHive, Higgsfield, Mistral, Cloudflare Workers AI. These are the rows in the status table (§"NOT covered by LiteLLM sync").
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
| Piper / Triton | n/a — free/local | — |

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

**Billing mechanics:** per-token for chat/vision (in/out separate), Voxtral STT per audio-minute, Voxtral TTS per character. 50% batch discount + 90% cached-input discount exist (we don't use them). Our provider sends no price-changing params. Note: FAQ on the consumer page quotes "Large $2/$6" — that's the OLD Large 2411, not Large 3. Voxtral Transcribe per-min metering shares the duration-metering path flagged in #1314.

### Cloudflare Workers AI (verified 2026-07-13 — all correct, no change)

Official docs table (updated 2026-07-08). Billed in neurons ($0.011/1k neurons); the docs also show the per-token equivalent. Both our entries are embeddings and match:

| BID | Model | Catalog | Official |
| --- | ----- | ------- | -------- |
| 187 | `@cf/baai/bge-m3` | $0.012/1M | $0.012/1M (1075 neurons) |
| 188 | `@cf/qwen/qwen3-embedding-0.6b` | $0.012/1M | $0.012/1M (1075 neurons) |

**Billing mechanics:** everything is metered in **neurons** ($0.011/1k neurons, 10k/day free); the docs publish a per-token equivalent which is what we store. Embeddings bill input tokens only (output $0). No price-changing params sent by our code.

## `app:sync-model-prices` — per-token only (guard added, #1318 fixed)

The command now **self-guards**: it only writes a model when the catalog mode AND the LiteLLM-derived mode are both `per_token`. Any model that is (or would become) `per_image` / `per_character` / `per_second` is counted under `skipped (non-per-token)` and never touched. This closes #1318 — earlier the sync would silently reclassify these media/audio models and clobber correct prices.

Dry-run baseline 2026-07-13: **67 text models unchanged (no drift), 19 unmatched (our manual providers — expected), and 8 media/audio rows now reported as `skipped (non-per-token)`** instead of bogus "updates":

| Model | Catalog | LiteLLM mode | Now |
| ----- | ------- | ------------ | --- |
| whisper-large-v3 / -turbo | perhour | per_second | skipped (still needs #1314 metering fix) |
| whisper-1 | permin | per_second | skipped (#1314) |
| gpt-image-1 / 1.5 | perImage | per_token | skipped (#1315) |
| gemini-2.5-flash-image, 3.1-flash-image | perImage | per_token | skipped |
| gemini-2.5-flash-preview-tts | perChar | per_token | skipped |

**Still true:** the sync is safe for per-token chat models only. The guard prevents accidental damage, but it does NOT fix the underlying whisper/gpt-image billing bugs (#1314/#1315) — those models still need proper per-second/per-image metering; the sync simply leaves them alone now. The `--force` flag overrides admin-set prices but does **not** override the mode guard (reclassification always requires a human editing the catalog).

### Automated weekly drift check (CI)

`.github/workflows/price-drift.yml` runs every Monday (and on manual dispatch): it seeds the catalog and runs `app:sync-model-prices --dry-run --fail-on-drift`. The `--fail-on-drift` flag exits with code **2** when any *per-token* model's price differs from LiteLLM (media/audio models are excluded by the guard, so no false alarms). On drift the workflow opens — or comments on an existing — GitHub issue titled "Price drift detected …" with the dry-run report, so a human verifies against the official page and updates `ModelCatalog.php`. It lives outside the PR CI on purpose: it depends on the external LiteLLM source, which must never turn a code PR red.

You can run the same check locally: `docker compose exec -T backend php bin/console app:sync-model-prices --dry-run --fail-on-drift; echo $?` (0 = no drift, 2 = drift).

> Correction (2026-07-13): an earlier draft claimed whisper's `0.111 perhour` was "the same price" as the sync's per-second value. That was wrong — `perhour` falls through `normaliseToPerUnit()` unchanged and whisper carries no `pricing_mode`, so the number equivalence never happens in code. This is the live bug in #1314, not a harmless unit label.

## Time-boxed / reminders

- **Claude Sonnet 5**: introductory $2/$10 → revert to standard $3/$15 after **2026-08-31** (TODO in `ModelCatalog.php` BID 249/250).

## Related issues

- #1313 — provider name casing standardization (P2)
- #1314 — Whisper per-hour/min unit + duration metering (P2)
- #1315 — gpt-image-1/1.5 flat rate ignores quality/resolution tiers (P2)
- #1317 — Higgsfield videos priced per_second but billed per-clip in credits (P2)
- #1318 — app:sync-model-prices clobbers non-per-token models (P2) — **fixed in #1316** (mode guard)

## Done in current PR (#1316)

Price updates (Anthropic/Google/OpenAI/Groq) + Anthropic cache-discount case-sensitivity fix. Kimi/HF provider-pinning fix is separate, pending the routing decision above.

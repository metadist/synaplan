<!-- title: Models: models imported from OpenRouter arrive with price 0 / 0, so they are labelled "Free" and the usage meter shows 0.00 after a day of paid chats -->
<!-- type: Bug -->
<!-- labels: prio:1, area:models, area:billing -->
<!-- issue-type: Bug -->

## Problem
Every OpenRouter model in Edit Models has price in 0 and price out 0, including models imported during the test. The model menu shows a "Free" badge on them, the usage meter showed 0.00 after a day of chats, and the run cost on File work cards shows 0.00. Prices had to be corrected by hand per model.

---

## Expected
Importing from a provider that publishes prices (OpenRouter returns `pricing.prompt` / `pricing.completion` per model in its `/models` listing) stores those prices with the model. A model without a known price shows no "Free" badge and the cost display says "price unknown" instead of 0.00.

## Actual
1. Import models from the OpenRouter endpoint → all rows price 0 / 0.
2. Menu: "Free" badge on gpt luna, sol, terra.
3. Usage: 0.00 € after a day; cost per answer 0.00.

---

## Steps to reproduce
1. Register OpenRouter as an OpenAI-compatible endpoint; Import models; pick one paid model.
2. Open Edit Models → price columns; open the chat model menu.
3. Chat for a while; check the usage meter.

---

## Notes
- Findings: F16 — community test round on 5.2.0 (first noted on 5.0.6 as F5/F16 context).
- Verified in code: `ModelDiscoveryService::discoverOpenAiCompatible()` (`backend/src/AI/Import/ModelDiscoveryService.php`) uses `OpenAiCompatibleEndpointRegistry::listModelIds()`, which keeps only ids, so any `pricing` object in the listing is discarded. `ModelImportApplier` (`backend/src/AI/Import/ModelImportApplier.php` ~line 122) creates imported rows with no price and sets `showWhenFree(1)` with the comment "Imported rows are self-hosted and free by nature (no per-token price)" — true for Ollama, wrong for OpenRouter and other hosted OpenAI-compatible endpoints. `Model::isHiddenBecauseFree()` and the "Free" badge key off `priceIn === 0.0 && priceOut === 0.0`.
- `app:sync-model-prices` (`backend/src/Command/SyncModelPricesCommand.php`) exists for catalog rows; it does not know imported OpenRouter ids.
- Related: F5 (capability probes) and F4/F18 (import dialog) are a separate issue.

Fix direction: `listModelIds()` returns the raw entries (id + optional `pricing`, `context_length`, `name`); `DiscoveredModel` carries `priceIn` / `priceOut` (USD per 1M, OpenRouter gives per-token strings — convert) and the import dialog shows them in the list; `ModelImportApplier` stores them and only sets `showWhenFree` when the price is genuinely 0; a `priceKnown` notion (null vs 0) so "Free" means free and unknown means "price unknown" in the badge, the cost line and the usage meter; `app:sync-model-prices` learns to refresh imported OpenRouter rows from the same listing.

Journey (U10): import a paid OpenRouter model → Edit Models shows its prices → model menu shows the price tier, not Free → one chat → usage meter moves.

Verification:
1. Import dialog lists prices next to each model; imported row has non-zero prices.
2. A model with no pricing in the listing shows "price unknown", not Free, and is still selectable.
3. `make -C backend test` covers the conversion from OpenRouter per-token strings to per-1M floats.

---

## Screenshots/Logs
Edit Models: price in 0, price out 0 for every OpenRouter row (5.2.0).

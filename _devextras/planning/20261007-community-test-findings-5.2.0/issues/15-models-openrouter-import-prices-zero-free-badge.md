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

Fix direction: extend discovery with the price fields (`listModelIds()` stays id-only so existing callers do not break — add a sibling that returns id, name, and optional pricing). OpenRouter `pricing.prompt` / `pricing.completion` are USD **per token** strings; store USD per 1M (`× 1_000_000`) in `BPRICEIN` / `BPRICEOUT`, same unit as the README. Show the price in the import list.

Do not clear `showWhenFree` on imports that have no price. `ModelImportApplier::newModel()` sets it because `isHiddenBecauseFree()` would otherwise drop the row from `/config/models` (#2110). Ollama and any endpoint that does not publish prices must stay selectable. "Free" is only for a published price of 0. Unknown price is a separate label ("price unknown"), and the model stays in the menu.

`touchLastSeen()` must keep ignoring prices. A sync must not overwrite a price an admin typed. Existing rows with hand-corrected prices stay as they are until the admin asks to refresh.

The usage meter showing 0.00 is the symptom. Do not change the EUR formatter here (issue 43). Open-source mode does not bill; the check is the per-answer cost line once the model row has a non-zero price.

Journey (U10): import a paid OpenRouter model → Edit Models shows its prices → model menu shows the price tier, not Free → one chat → the answer's cost line is not 0.00. An Ollama model imported the same day is still in the menu.

Verification:
1. Import dialog lists prices next to each model; imported row has non-zero prices.
2. A model with no pricing in the listing shows "price unknown", not Free, and is still selectable.
3. `make -C backend test` covers the conversion from OpenRouter per-token strings to per-1M floats.

---

## Screenshots/Logs
Edit Models: price in 0, price out 0 for every OpenRouter row (5.2.0).

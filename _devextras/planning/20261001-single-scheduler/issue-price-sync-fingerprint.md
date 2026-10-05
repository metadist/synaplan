Title: Pricing: app:sync-model-prices leaves the catalog fingerprint stale, so the seeder freezes synced rows

**Kind:** Needs planning — do not implement before the open decision below is answered.

## Problem
`app:sync-model-prices` writes new prices into `BMODELS` without refreshing `BJSON.__catalog_fingerprint`. From then on `ModelSeeder` treats the row as hand-edited and preserves it, so later catalog changes for that model are never applied.

---

## Expected
One documented rule decides whether a LiteLLM-synced price or a catalog price wins. Both `app:sync-model-prices` and `app:seed` follow that rule.

## Actual
- `SyncModelPricesCommand::updateModelPrice()` sets `priceIn`, `priceOut`, `inUnit`, `outUnit` and `BJSON` pricing keys. It keeps the old `ModelCatalog::FINGERPRINT_KEY` value.
- `ModelCatalog::fingerprint()` covers `priceIn` and `priceOut`, so the stored fingerprint no longer matches the row.
- `ModelSeeder` compares the stored fingerprint with the current row. It returns `ACTION_PRESERVE` when they differ ("Row was edited via the admin UI after we last seeded it").
- As a result, a catalog price fix shipped in a release never reaches a row the sync has touched.
- A deliberate catalog override is overwritten by LiteLLM on the next sync run.

---

## Steps to reproduce
1. Seed a model (`make -C backend seed`) and note its `BPRICEIN` and `BJSON.__catalog_fingerprint`.
2. Run `docker compose exec -T backend php bin/console app:sync-model-prices` for a model where LiteLLM has a different price.
3. Change that model's price in `ModelCatalog` and run `app:seed` again.
4. The row keeps the LiteLLM price, and the seeder reports it as preserved.

---

## Open decision
Which source owns the price of a catalog model?
- (a) The catalog. The sync only reports drift (`--dry-run` style) and never writes catalog-managed rows.
- (b) LiteLLM. The sync refreshes the fingerprint after writing, so the seeder keeps treating the row as catalog-managed. A catalog override then needs an explicit "pinned" marker the sync respects.
- (c) Per-model choice through a flag in the catalog entry.

## Affected paths
- `backend/src/Command/SyncModelPricesCommand.php` (`updateModelPrice`)
- `backend/src/Seed/ModelSeeder.php` (fingerprint comparison)
- `backend/src/Model/ModelCatalog.php` (`fingerprint`, `FINGERPRINT_KEY`)
- `docs/PRICING_MAINTENANCE.md`

## Out of scope
- `ModelPriceHistory` bookkeeping.
- Price display in the UI.

## Verification
- PHPUnit: after a sync write, `ModelSeeder` takes the action the chosen rule demands for that row (`UPDATE` or `PRESERVE`).
- Run steps 1–4 and confirm the documented winner holds.

## Notes
The scheduler runs `app:sync-model-prices` only with `SYNAPLAN_SCHEDULER_PRICE_SYNC=1` (default off). Production runs it daily.
related: #2302

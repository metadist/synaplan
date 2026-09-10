# Price drift — resolution procedure

Step-by-step procedure for resolving a model price drift reported by the daily `Price Drift Check` workflow (issue "Price drift detected …", or `app:sync-model-prices --fail-on-drift` exiting 2). Written so that a person or an AI agent can execute it without guessing; the background and the per-provider history are in [PRICING_MAINTENANCE.md](PRICING_MAINTENANCE.md). Prices are resold at a 10% margin, so a wrong direction loses money silently — follow it in full, including the STOP conditions.

## Ground rules (read all five before touching anything)

1. **`backend/src/Model/ModelCatalog.php` is the source of truth.** Nothing writes prices
   automatically — not CI, not the sync. Every change is a reviewed catalog edit.
2. **LiteLLM is a signal, not a source.** It has been wrong for weeks at a time (Veo 3.1
   Fast, Jina rerank). Never copy a LiteLLM number into the catalog. The only evidence that
   counts is the provider's own price page or API.
3. **Lowering a price is the money-losing direction.** A price decrease needs explicit
   official evidence quoted in the PR. If you cannot quote it, do not lower it.
4. **Never run `app:sync-model-prices` without `--dry-run`** against any shared database,
   and never edit `BMODELS` by hand. Rollout to existing installs goes through a migration.
5. **This rule contains no price URLs on purpose** — they rot. Sources come, in this order,
   from: (a) the `source:` URL printed on each flagged line of the report (LiteLLM's own
   reference), (b) a machine-readable provider catalog where one exists
   (`docs/PRICING_MAINTENANCE.md` § "Maintenance links" lists them, e.g. Jina `/v1/models`,
   TrustedTokens `/api/billing/models`), (c) the provider pages linked in that same section.

## Step 0 — Reproduce locally

```bash
docker compose exec -T backend php bin/console app:seed --no-interaction
docker compose exec -T -e COLUMNS=120 backend php bin/console app:sync-model-prices --dry-run --fail-on-drift; echo "EXIT=$?"
```

`EXIT=0` no drift (the issue may already be resolved — check `git log` before doing
anything). `EXIT=1` the command broke (fix that, it is not a price problem). `EXIT=2` drift.

## Step 1 — Classify the report

Only two sections require action. Everything else is steady state and reported every day.

| Section in the report | Meaning | Action |
| --- | --- | --- |
| `[DRY-RUN] <model>: in a -> b, out c -> d` | per-token rate differs from LiteLLM | **verify (Step 2)** |
| `Non-per-token price drift` | per_second / per_image / per_character rate or a single resolution tier differs | **verify (Step 2)** |
| `Known LiteLLM deviations` | verified LiteLLM error recorded in `ModelCatalog::LITELLM_DEVIATIONS` | none |
| `Obsolete LiteLLM deviations` | LiteLLM now agrees with the catalog | delete that registry entry (Path B, last bullet) |
| `Pricing-mode mismatch` | structurally not comparable (e.g. gpt-image per_image vs LiteLLM per_token, Cohere rerank per request) | none |
| `Null-price protected` | LiteLLM reports 0 for a priced row | none |
| `Unmatched` | model not in LiteLLM at all | none here (manual re-verification is the doc's 30-day rule, not this procedure) |

Do not "fix" rows in the no-action sections. A PR that touches them because they looked
wrong in passing is out of scope for a drift fix.

## Step 2 — Verify every flagged model against the official source

For each flagged model:

1. **Identify the exact SKU.** Read `providerId` and `json.params.model` in the catalog row.
   The price must be for that id, not for the display name or a newer sibling.
2. **Fetch the official price** using the source order from ground rule 5. Prefer a JSON API
   over a rendered page, and a rendered page over any markdown/plain-text export: exports
   flatten multi-row tier tables and keep only the first row (this produced a 40%
   undercharge once). If the fetched text shows a single number for a product you know has
   resolution/quality tiers, treat it as unverified and fetch differently or STOP.
3. **Compare all three values** — catalog, LiteLLM, official — in the same unit
   (catalog units are listed in `docs/PRICING_MAINTENANCE.md` § "Where prices live"; per-token
   rows are USD per 1M tokens). Then decide:

| Official equals … | Verdict | Do |
| --- | --- | --- |
| LiteLLM, not the catalog | provider moved its price | **Path A** with the official value |
| the catalog, not LiteLLM | LiteLLM is wrong | **Path B** |
| neither | both are stale | **Path A** with the official value **and Path B** (LiteLLM stays wrong afterwards) |
| cannot be determined | — | **STOP** (see below) |

For a tiered row (`json.resolution_prices`, `json.quality_prices`) compare every tier, not
just the headline; the headline must stay equal to one of the tiers (`ModelCatalogTest`
enforces this).

## Path A — the provider changed the price

1. Edit the row in `ModelCatalog.php`: `priceIn`/`priceOut` and their units, every affected
   `json` table (`resolution_prices`, `quality_prices`, `mode_prices`,
   `cache_read_price_per_1M`), and `ModelCatalog::CONTEXT_PRICING` if a long-context tier
   moved. Put the source URL and the date in a comment on the row.
2. **Ship a data migration** so existing installs receive it — copy
   `backend/migrations/Version20260910120000.php` (single row) or `Version20260907120000.php`
   (tiered row) verbatim as the template: full catalog snapshot of the row with the json keys
   in catalog order, fingerprint computed by the frozen local `fingerprint()`, guard on the
   OLD value, operator-owned columns (`BSELECTABLE`, `BACTIVE`, `BISDEFAULT`,
   `BSHOWWHENFREE`) never in the `SET` clause, raw `addSql` only, no `Schema` API.
   A bare `UPDATE BMODELS SET BPRICEIN …` without a fingerprint freezes the row out of every
   future catalog update — that is the trap, not a shortcut.
3. Update `docs/PRICING_MAINTENANCE.md`: the provider's status-table row/date, and a
   `> Resolved drift (YYYY-MM-DD, #issue)` entry in the drift log stating the model, old →
   new values, the official source, the direction (over-/undercharge) and how long it
   was wrong.

## Path B — LiteLLM is wrong

1. Add an entry to `ModelCatalog::LITELLM_DEVIATIONS`, keyed
   `<lowercase service>:<providerId>`, pinning **both** `litellm_in` and `litellm_out`
   exactly as the report prints them (per 1M tokens for per_token rows, per billable unit
   for media rows), plus `source` (the URL you verified against), `verifiedOn` and a
   `reason` naming what LiteLLM got wrong. Tiered rows cannot be pinned.
2. Re-run Step 0: the row must now appear under `Known LiteLLM deviations` and the exit
   code must be 0.
3. File the correction upstream in BerriAI/litellm (`model_prices_and_context_window.json`)
   and put the PR link into `reason`. Every entry is a fork of the source we chose; the
   upstream fix is what lets it retire.
4. Add a drift-log entry to `docs/PRICING_MAINTENANCE.md` as in Path A step 3.
5. **Obsolete entries:** when the report lists one under `Obsolete LiteLLM deviations`,
   delete it from the registry in the same PR — no other change.

## Step 3 — Gate (unfiltered, in this order)

```bash
make -C backend lint
make -C backend phpstan
make -C backend test
make -C backend migrate && make -C backend seed
docker compose exec -T -e COLUMNS=120 backend php bin/console app:sync-model-prices --dry-run --fail-on-drift; echo "EXIT=$?"
```

Required outcome: all green, the seed step reports the corrected row as `skipped`/`updated`
— not `preserved` — and the dry run exits 0. A `--filter` run does not count.

## Step 4 — Deliver

- Branch + conventional commit `fix(pricing): …` (never on `main`).
- The PR description quotes, per model: catalog value → new value, LiteLLM value, official
  value **with its source URL**, direction of the error and since when. A reviewer must be
  able to re-verify from the description alone.
- Reference the drift issue (`Fixes #…`) so the daily check's dedup stops on the next run.

## STOP and ask the user when

- The official price cannot be read (credit-only dashboards, key-gated pricing, JS-only
  pages you cannot render, a table that came back flattened).
- Official and LiteLLM disagree **and** the change would lower a price by more than 20%.
- The billing **unit** or `pricing_mode` is affected, not just the number (a wrong unit
  bills wrong even with the right number).
- The row has `resolution_prices`/`quality_prices` and the official page shows a tier the
  catalog does not have (or vice versa).
- The provider has retired the model (a `404` on the model id) — that is a retirement
  (`docs/PRICING_MAINTENANCE.md` § "Retiring a model"), not a price fix.
- Anything about the report itself looks wrong (a section missing, counts not adding up).
  The check is the safety net; do not paper over it.

## Traps that have bitten before

- Headline vs tier: LiteLLM's base per-second rate is its **cheapest** tier; the catalog's
  headline is the **default render**. The check compares tiers for that reason — do not
  "align" the headline to LiteLLM.
- Only the unit strings `CostCalculationService::normaliseToPerUnit()` knows are billed;
  any other string bills per-1 silently, and `-`/`free` bill zero.
- `CONTEXT_PRICING` is code-only (no migration), but its test assertions in
  `ModelCatalogTest` must be updated with it.
- Time-boxed promotional rates are invisible to the check (both sides agree). They live in
  `docs/PRICING_MAINTENANCE.md` § "Time-boxed / reminders" — check that section whenever you
  touch the same provider.

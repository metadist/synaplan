# S2 — Thinking levels + dynamic per-model config

**Steps `S2.1`–`S2.3`.** Users set the thinking effort per model to the
levels that model supports; the model page only shows controls a model
actually supports. Works for Muse Spark on day one and generalizes the
xAI/OpenAI effort plumbing to every reasoning provider.

**Depends on:** S1.1 (Meta rows exist to configure) + `00_master_plan.md` §0.
**Journeys:** J-MM-2, J-MM-3.

**UX exit (§6) before the sprint closes:**

1. **Journey (U10):** J-MM-2 and J-MM-3 walked (light, dark, V2, 1280 + 320 px).
2. **Findability (U2):** the effort control lives on the model row/section
   itself — no hunting in a second page.
3. **Consequence (U3):** the picker says what changes in plain words
   (“Higher effort answers harder questions but is slower”); five locales.
4. **Empty / flag-off (U5, U11):** a model without levels shows no picker,
   no disabled teaser, no tooltip apology.
5. **Honest outcome (U8):** saving confirms in one sentence; a rejected
   level (disabled mid-flight) says which level is active now.

---

## S2.1 — Effort levels + resolution rule (backend-only)

**Machine instructions**

1. Catalog JSON: `reasoning_efforts: ['low','medium','high']` (subset per
   model) on every reasoning row that supports levels — Muse Spark first
   (per S1.0 spike), then xAI + OpenAI families (their `REASONING_EFFORTS`
   move into the catalog rows; provider constants stay as validators).
   Absent list = toggle-only/unsupported (no behavior change).
2. Shared resolution rule (generalize xAI's order; keep each provider's
   native mapping in its own resolver):
   explicit effort string (validated against the model's list) →
   `reasoning` bool (true = catalog default, false = off) → send nothing.
3. Storage: per-user effort default per model key, next to the default-model
   choices (`ModelConfigService` + the same config API family; OpenAPI
   annotated; migrate nothing — additive keys with safe defaults).
4. `ChatHandler`/stream path passes the resolved effort through (existing
   `reasoning_effort` option slot; bool path untouched).

**Tests**

- Resolution matrix unit test: explicit × bool × missing × unsupported
  model × unknown level (rejected → default + honest note in response).
- Per-user default round-trip (set/get/fallback to catalog default).
- Existing provider tests stay green (bool contract unchanged).

**Commit:** `feat(ai): per-model reasoning effort levels + resolution rule`

## S2.2 — Dynamic per-model config UI (ota-candidate)

**Machine instructions**

1. `AIModelsConfiguration.vue` “Default models” tab: under each reasoning
   model's choice, an effort picker (Low/Medium/High or the model's subset)
   appears **iff** the model row advertises `reasoning_efforts`. Rendered
   from the same generated Zod schemas as the rest of the page — no manual
   response interfaces.
2. Copy (five locales, plain words): label “How hard the AI thinks” +
   one-sentence consequence; option labels Low/Medium/High (+Off where the
   model allows disabling). No `reasoning_effort`, no provider ids.
3. House chains: full button + form-control class chains; light/dark/V2;
   320 px stacked.
4. Chat toggle: keep as-is visually; one plain-words hint where the toggle
   lives that “on” now uses the configured level (J-MM-3).

**Tests**

- Vitest: picker renders iff levels advertised; save calls the config API
  with `{model, effort}`; rejected level shows the honest note.
- `localeParity.spec.ts` green (ledger only shrinks).

**Commit:** `feat(models): dynamic per-model config (effort picker) on the model page`

## S2.3 — Effort matrix + journey specs (ota-candidate)

**Machine instructions**

1. Characterization/E2E matrix: for each seeded reasoning model × each
   advertised level, assert the provider request carries the native
   parameter (recorded at the translator/resolver seam, no live calls).
2. Journey specs J-MM-1…3 (click-type-find-undo) in the nav-journeys style.
3. `redirects.spec.ts` untouched (no routes move); visual baselines only if
   S2.2 changed shared components (reviewed diff by diff).

**Commit:** `test(models): effort-matrix + journey specs J-MM-1…3`

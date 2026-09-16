# Sprint S3 — Gates & lazy registries

**Feature modules, sprint 3 of 5.** Steps `FM12`–`FM17`.

**Goal:** An absent module can answer a uniform 404 on its routes (behind a
per-module flag that ships **off**), providers are instantiated only when
used, and the frontend stops rendering cards for absent modules. Configured
installations see no change.
**Depends on:** S2 (registry), S1 `FM5` (eager-registry audit).
**Unlocks:** S4 (the CI matrix proves the gate and flips it on).
**Repos:** `synaplan/` (`backend/`, `frontend/`).
**Flags:** `MODULES.GATE_<ID>` × 12, `BCONFIG` group `MODULES`, seeded `0`.

---

## 0. Why this sprint exists

This is the sprint with user-visible effect, so it is split in three
independent PR groups: the gate (dark, flag off), the lazy registry (pure
refactor, behaviour-neutral by test), and the frontend hiding (driven by
`configured`, not by the gate — hiding an unconfigured card is safe on its
own). Any of the three can be reverted alone.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/EventSubscriber/` (e.g. `PasswordChangeRequiredSubscriber.php`) | House style for request-phase subscribers and how they skip routes |
| `backend/config/packages/security.yaml`, firewall order | The gate runs on `kernel.controller`, i.e. after authentication; confirm anonymous routes (webhooks) still hit it |
| `backend/src/Controller/StripeWebhookController.php`, WhatsApp webhook controller, `MobilePurchaseController.php` | Public routes to include in `routeNames()`; their current behaviour when unconfigured (must not be *worse* after gating: a 404 with body vs. today's error) |
| `backend/src/Controller/AI/HiggsfieldCredentialController.php` | The GET must stay reachable when the module is *unconfigured but gate off* (the admin uses it to enter the key). Decide: credential routes are **never gated** — they are how a module becomes configured. Same for any `*/credentials`, `*/connect` route. Record in `routeNames()` doc-block |
| `backend/src/AI/Service/ProviderRegistry.php` | Rewrite target; callers: `rg "ProviderRegistry" backend/src -l` |
| `backend/config/services.yaml` provider tags | Add `key: <name>` to each `app.ai.*` tag |
| `backend/src/Controller/ConfigController.php` `runtime` action (~line 503, `features.selfAware`) and its OpenAPI block | Additive `modules` property |
| `frontend/src/stores/config.ts` (`features.selfAware` handling at ~112), `views/ConfigView.vue`, `components/config/HiggsfieldConnection.vue`, admin feature-status view | Where the `v-if` and the module list go |
| `frontend/src/i18n/{en,de,es,fr,tr}.json`, `tests/unit/i18n/localeParity.spec.ts` | New `modules.*` keys in all five locales |
| `backend/src/Seed/BConfigSeeder.php` | `insertIfMissing` for `MODULES.GATE_*` |

---

## 2. Work

### FM12 — `ModuleGateListener` (flag off)

`Module/Http/ModuleGateListener.php` on `kernel.controller`: `_route` →
`ModuleRegistry::forRoute()`; if module absent and `MODULES.GATE_<ID>` on →
`JsonResponse(404, {error:'feature_not_configured', module, docs})`. Never
gates: health, runtime config, `/api/doc`, credential/connect routes, anything
without a module. `ModuleGateListenerTest` covers configured × flag × route
matrix. Seeder adds the 12 flags at `0`.

### FM13 — Lazy `ProviderRegistry`

Tag attribute `key` on every provider registration; `ProviderRegistry` holds
`ServiceLocator`s per type; public API unchanged; `ProviderRegistryKeyTest`
asserts `key === getName()` for every tagged provider by instantiating them
once in the test only. `debug:container --tag=app.ai.chat` output in the PR.
Extend to other eager consumers only where `FM5` found them eager.

### FM14 — Runtime config `modules`

`GET /api/v1/config/runtime` → `modules: {<id>: {configured: bool, gated: bool}}`
with complete OpenAPI annotations; `make -C frontend generate-schemas`;
`vue-tsc`. Older frontends ignore the key (C8).

### FM15 — Frontend: hide absent module cards

`useConfigStore().isModuleConfigured(id)` (default `true` when `modules` is
missing, so an older backend changes nothing); `ConfigView.vue` wraps
`HiggsfieldConnection` and any other module-owned card; admin tabs for
Stripe/IAP likewise. Vitest for the store; `@ci` E2E unchanged because the test
stack is `full`.

### FM16 — Frontend: Modules section on Feature status

Existing Operate → Feature status page renders the module list: label, state
badge (`configured`/`not configured`/`unhealthy`), "How to enable" link to the
docs anchor, `configured by` as key names. i18n in five locales; no new route.

### FM17 — Uniform client handling of `feature_not_configured`

`httpClient` maps the 404 body to a typed error; one shared notice component
("This feature is not configured on this installation") used where a module
route can be hit from a still-visible surface (e.g. deep links). No raw
`alert()`.

---

## 3. Invariants exercised

| # | How |
| - | --- |
| C1 | `full` stack `@ci` green; configured module never gated (`ModuleGateListenerTest`) |
| C2 | OpenAPI changes only by the additive `modules` property; regenerate schemas |
| C4 | Gate matrix test; credential routes exempt test |
| C5 | All 12 flags seeded `0`; `git diff` of seeder shows only inserts |
| C7 | Enter a Higgsfield key in the UI → card appears without restart (cache TTL ≤ 5 min; `ProviderKeyStore` invalidates on save) |
| C8 | Zod schema regenerated; old-frontend behaviour covered by the store default |
| C9 | FM12–FM14 `backend-only`; FM15–FM17 `ota-candidate`; `mobile-impact.mjs` in each PR |

---

## 4. Exit criteria / demo

1. Dev stack, no Higgsfield key: `ConfigView` has no Higgsfield card; Feature
   status lists `higgsfield — not configured — how to enable`.
2. Set `MODULES.GATE_TIKA=1` with `TIKA_URL` empty: Tika diagnostics route
   returns the uniform 404 body; set `TIKA_URL`, clear cache → 200 again.
3. Enter a Higgsfield key via the (never-gated) credentials route → card and
   provider appear without restart.
4. First chat request in dev instantiates only the providers used (log line
   count in `var/log/dev.log` or a debug counter in the test).
5. `make ci-local && make test-e2e` green.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| FM12 | `feat(backend): add ModuleGateListener answering 404 for absent modules behind MODULES.GATE_* flags` | backend-only | FM10 |
| FM13 | `refactor(backend): make ProviderRegistry resolve providers lazily via tag keys` | backend-only | FM5 |
| FM14 | `feat(backend): expose module configured/gated states in runtime config` | backend-only | FM10 |
| FM15 | `feat(frontend): hide configuration cards of unconfigured modules` | ota-candidate | FM14 |
| FM16 | `feat(frontend): list feature modules with enable hints on the Feature status page` | ota-candidate | FM10, FM14 |
| FM17 | `feat(frontend): handle feature_not_configured responses with a shared notice` | ota-candidate | FM12, FM14 |

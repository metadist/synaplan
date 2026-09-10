# Feature modules — optional features declared, gated and provable — master plan

**Status:** Draft 2026-09-10, awaiting the §0 decision checklist (log in
[`STATUS.md`](./STATUS.md)). This is the **Intermezzo** release in
[`../20260910_roadmap_update.md`](../20260910_roadmap_update.md) — after the
two production bugfixes and Wave 4, before Wave 5. Cross-cutting refactor,
not a seventh track. Wave 5 adds optional services (compute client, more
tools); those must be born as modules, so this lands first.
Research that produced this plan:
[`../20260910-wave5-architecture-research/02_conditional_module_loading.md`](../20260910-wave5-architecture-research/02_conditional_module_loading.md).
Sprint files: [`01_sprint_1_inventory_and_dead_weight.md`](./01_sprint_1_inventory_and_dead_weight.md) …
[`05_sprint_5_compile_time_and_plugin_extraction.md`](./05_sprint_5_compile_time_and_plugin_extraction.md).
**Owner surface:** admins only — Operate → Feature status gains a "Modules"
section (no new page); `app:modules:list` on the CLI. End users see fewer
cards, never more.
**Flags:** `MODULES.GATE_<ID>` per module, default off in code and seeder
(S3); flipped on per module in S4 after its E2E path is green. No global
kill-switch is needed because the registry itself changes no behaviour.
**Related:**

- [`../20260907-config-pyramid/00_master_plan.md`](../20260907-config-pyramid/00_master_plan.md)
  row 11 — "Sidecar = one L1 URL, no extra flag; empty URL = capability
  absent" is the rule a module's `isConfigured()` implements; `app:config:doctor`
  prints the module list when it ships
- [`../20260822-open-plugin-platform/README.md`](../20260822-open-plugin-platform/README.md)
  — manifest v2 `provides.*`; a plugin is the *physical* form of a module (S5)
- [`../202609_ai_plugs/06_sprint_6_plugin_adapters.md`](../202609_ai_plugs/06_sprint_6_plugin_adapters.md)
  — `PlugDeclarationCheckPass`: the "declare it or boot fails" pattern reused
  for optional services
- [`../20260902-platform-self-awareness/`](../20260902-platform-self-awareness/)
  — `PlatformCapabilityInventory` / `CapabilityState` become consumers of the
  registry instead of a second hand-written list
- [`../202609_secure_compute/05_phase_b1_client_and_capability.md`](../202609_secure_compute/05_phase_b1_client_and_capability.md)
  — first new feature that should be born as a module

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **No broad compile-time exclusion of PHP.** The container, autoloader and OpenAPI spec are identical in every installation; "lean" is achieved at the request/registry/vendor level. Compile-time exclusion exists only as the opt-in S5 experiment for env-only modules, behind the S4 CI matrix. | Runtime gating first | ☐ |
| 2 | **One `FeatureModule` descriptor per optional feature**, `final readonly`, in `backend/src/Module/` (namespace `App\Module`), tagged `app.feature_module`. Fields: `id`, `label` (i18n key), `configuredBy()` (env keys / `BCONFIG` keys / provider keys), `isConfigured()`, `capabilityIds`, `routeNames` (prefixes), `serviceIds` (for the architecture test), `docsAnchor`, `mobileClass`. | Descriptors in core | ☐ |
| 3 | **Initial module set (12):** `tika`, `docling`, `office_convert` (Collabora), `searxng`, `piper_tts`, `local_ai` (Ollama), `higgsfield`, `google_ai`, `thehive`, `stripe_billing`, `mobile_iap`, `whatsapp`. Widgets, OAuth/OIDC, desktop agent, e-mail channels stay **core** in v1 (they have their own flags or are product-defining). Each later optional feature (compute, plug adapters) is born as a module. | 12 modules | ☐ |
| 4 | **"Configured" has two sources and one answer.** `isConfigured()` reads env (L0/L1) **and** the runtime stores (`ProviderKeyStore`, `PlugKeyStore`, `BCONFIG`) with the same 5-minute cache those stores use; the registry never caches longer than they do. A key entered in the UI makes the module configured without restart. | Env + runtime, cached ≤ 5 min | ☐ |
| 5 | **Absent module ⇒ uniform `404 {"error":"feature_not_configured","module":"<id>","docs":"<anchor>"}`** from one `ModuleGateListener` for the module's `routeNames`, evaluated *after* authentication (no information leak to anonymous callers beyond what a 401 already gives). Public webhooks of absent modules (Stripe, WhatsApp) also 404. | 404 + machine-readable body | ☐ |
| 6 | **Gates ship default-off per module** (`MODULES.GATE_<ID>`), flipped on in S4 after that module's E2E path is green in both CI variants. A gate firing for a configured module is a P1 regression. | Default off, per module | ☐ |
| 7 | **Registries become lazy.** `ProviderRegistry` and every other eager `AutowireIterator` consumer index by tag attribute and resolve through a `ServiceLocator`; a provider object exists only when a request needs it. Behaviour of configured providers is unchanged. | Lazy locators | ☐ |
| 8 | **Feature status and capability inventory derive from the registry.** `ConfigController::featuresStatus()` shrinks to "registry → JSON"; the hand-written blocks move into each module's `status()`; `PlatformCapabilityInventory::KNOWN_ABSENT` rows that correspond to a module are generated. Infrastructure rows (database, redis, centrifugo) stay hand-written — they are not optional. | Registry is the source | ☐ |
| 9 | **Frontend hides what is absent, from one list.** `GET /api/v1/config/runtime` gains `modules: {id: {configured, gated}}` (additive, optional, safe default `{}`); config cards, nav entries and admin tabs for absent modules render nothing; the Feature status page shows every module with "Not configured — how to enable". No new page. | Runtime config carries module states | ☐ |
| 10 | **Dependency slimming (Ask recorded):** remove `league/flysystem-aws-s3-v3` (no reference in the codebase); restrict `google/apiclient-services` to `AndroidPublisher` via `composer.json` `extra`. −~260 MB in the image. | Do in S1 | ☐ |
| 11 | **CI matrix `minimal` / `full`.** The backend job gains a second variant that boots with every optional env empty, runs `lint:container`, PHPUnit, `app:modules:list --assert-none-configured`, dumps OpenAPI and diffs it against the `full` dump (must be byte-identical). A Playwright `@minimal` set (≤ 8 specs) runs on the minimal stack. Change-scope: `thin` runs skip the variant. (Ask recorded: CI change.) | Add variant | ☐ |
| 12 | **Architecture test:** every service in `services.yaml` that defaults an optional env to `''`, and every class under a module's namespace, belongs to exactly one module or is listed as core; a new optional service without a module fails PHPUnit — the `PlugDeclarationCheckPass` idea applied to first-party code, at test time rather than boot time so prod boot never gets slower. | Test-time check | ☐ |
| 13 | **S5 is optional and cut first.** Compile-time exclusion for env-only modules and the Higgsfield plugin extraction are separate PRs with their own go/no-go recorded in `STATUS.md`; the initiative is complete after S4. | S5 optional | ☐ |
| 14 | **Mobile:** `backend/src/Module/**` is `backend-only` in `.github/mobile-impact-policy.json`; runtime-config and Feature-status frontend work is `ota-candidate`; nothing here is `store-required`. | Classified | ☐ |
| 15 | **Schema:** none. `MODULES.*` flags are `BCONFIG` rows via the seeder. | No migration | ☐ |

---

## 1. The concept in three sentences

> Every optional feature says in one place what it is, what configures it and
> what it exposes. If it is not configured, its URLs answer "not configured",
> its cards do not render, its objects are never created, and the admin can see
> exactly why and how to enable it. The code stays in one image so every
> installation runs the same, tested contract.

---

## 2. Why this exists

The research measured that unused PHP is not a runtime cost (compiled
container, classmap, preload, worker mode), but that a lean installation is
still not lean: 470 routes answer regardless of configuration with
per-controller behaviour, `ProviderRegistry` instantiates all 18 providers on
first use, feature status is a 430-line hand-written controller method next to
a second hand-written capability list, the Higgsfield card renders for
installations that will never use it, and 269 MB of `vendor/` belong to a dead
dependency and a 98 %-unused SDK. Tracks 2, 3 and 5 are about to add more
optional services; each would repeat the `isEnabled()` + status block + `v-if`
ritual. One descriptor per feature ends that ritual and gives CI a way to
prove "absent means absent".

---

## 3. What already exists (do not rebuild)

| Exists | Where | Reuse |
| ------ | ----- | ----- |
| Per-client availability checks | `TikaClient::isEnabled()`, `DoclingClient::isEnabled()`, `OfficeConverterClient`, `HiggsfieldProvider::isAvailable()`, `GoogleProvider`, `TheHiveProvider` | Each becomes the body of one module's `isConfigured()`/`status()`; the client keeps its method |
| Runtime key stores | `AI/Credential/ProviderKeyStore` (BCONFIG, env fallback, 5-min cache, `getStatus()` with `source`), `Plug/PlugKeyStore` | Modules delegate; no new secret storage |
| Capability inventory | `Service/SelfAware/PlatformCapabilityInventory`, `CapabilityState {Available, NeedsSetup, Absent}`, `CapabilityReport` | Registry feeds it; states map 1:1 (`configured+healthy` → Available, `configured+unhealthy` → NeedsSetup, `absent` → Absent) |
| Feature status endpoint | `ConfigController::featuresStatus()` (`GET /api/v1/config/features`), frontend Operate → Feature status | Same route, same response shape plus `module` fields; body generated |
| Runtime config | `GET /api/v1/config/runtime` (`features.selfAware` precedent for additive booleans), `useConfigStore()` | Add `modules` map additively |
| Tagged-service discipline | `#[AutowireIterator('app.plug.*')]`, `Kernel::build()` autoconfiguration, `PlugDeclarationCheckPass` | `app.feature_module` tag; test-time declaration check |
| Env defaults | `services.yaml` `env(X): ''` for every optional feature | The list the architecture test walks |
| Config pyramid plan | `LayeredConfigResolver`, row 11 (empty URL = absent), `app:config:doctor` (planned) | `isConfigured()` reads through the resolver where it exists; doctor prints modules |
| Plugin platform | `Kernel` plugin discovery, manifest v1, planned manifest v2 `provides.*` | S5 reference extraction only |
| CI change scope | `scripts/ci-change-scope.mjs` (`heavy`/`thin`/`promote`) | The `minimal` variant runs on `heavy` only |

---

## 4. Target architecture

### 4.1 Descriptor

```php
// backend/src/Module/Contract/FeatureModuleInterface.php
interface FeatureModuleInterface
{
    public function id(): string;                    // 'tika'
    public function labelKey(): string;              // 'modules.tika.label' (frontend i18n key)
    public function configuredBy(): ConfiguredBy;    // env keys, BCONFIG keys, provider keys — for docs, doctor, tests
    public function isConfigured(): bool;            // env + runtime stores; cached ≤ 5 min
    public function status(): ModuleStatus;          // configured, healthy, detail, docsAnchor (replaces hand-written blocks)
    public function capabilityIds(): array;          // ids in PlatformCapabilityInventory
    public function routeNames(): array;             // route-name prefixes gated when absent
    public function serviceIds(): array;             // services owned by the module (architecture test)
    public function mobileClass(): string;           // 'backend-only' | 'ota-candidate'
}
```

`ModuleRegistry` (`#[AutowireLocator('app.feature_module', indexAttribute: 'key')]`)
exposes `all()`, `get(id)`, `configured()`, `absent()`, `forRoute(name)`.

### 4.2 Gate

`ModuleGateListener` on `kernel.controller` (after the firewall): resolves the
module for the matched `_route`; if the module is absent **and**
`MODULES.GATE_<ID>` is on, returns the uniform 404 body. One listener, no
per-controller code. Webhook routes (Stripe, WhatsApp) are included by name;
health and runtime-config routes are never gated.

### 4.3 Lazy registries

`ProviderRegistry` keeps its public API (`getProvider(type, name)`,
`getProviders(type)`, `hasProvider`) but holds `ServiceLocator`s indexed by the
provider's tag `key` attribute (set in `services.yaml`; a PHPUnit test asserts
key == `getName()` for every provider so the index cannot drift). The same
pattern is applied to `ExtractionRegistry`, `WebSearchRegistry`, `RerankRegistry`
only if S1's inventory shows they are eager too.

### 4.4 Consumers

- `featuresStatus()` → `foreach ($registry->all() as $m) $features[$m->id()] = $m->status()->toArray()` + the three infrastructure rows.
- `PlatformCapabilityInventory` → module states for `capabilityIds()`; `KNOWN_ABSENT` keeps only capabilities with no module (e.g. `code_execution` until compute B1 registers one).
- `GET /api/v1/config/runtime` → `modules: {id: {configured: bool, gated: bool}}`.
- Frontend `useConfigStore().isModuleConfigured(id)`; `ConfigView.vue` and the admin tabs wrap module cards in `v-if`; Feature status renders the module list with an "enable" hint.
- `app:modules:list [--json] [--assert-none-configured]` for the CI variant and for admins.

### 4.5 CI

`backend` job matrix `variant: [full, minimal]`; `minimal` sets a
`docker-compose.minimal.yml` overlay (all optional env empty, `TestProvider`
as the only AI provider) and adds the OpenAPI byte-diff step. E2E adds one
`chromium minimal` job running `@minimal`. `thin` change scope skips both.

---

## 5. Compatibility invariants (tested, not promised)

| # | Invariant | Test |
| - | --------- | ---- |
| C1 | Configured features behave exactly as before | Full PHPUnit + `@ci` Playwright on the `full` variant every PR; per-module E2E path recorded in S4 before its gate flips on |
| C2 | The OpenAPI spec is configuration-independent | `minimal` vs `full` dump byte-diff in CI |
| C3 | Characterization snapshots unchanged | `tests/Characterization/` runs in both variants; any drift is reviewed line by line, never silently re-recorded |
| C4 | A gate never fires for a configured module | `ModuleGateListenerTest` matrix (configured × flag on/off × route in/out of prefix); `@minimal` proves the 404, `@ci` proves the absence of 404 |
| C5 | No behaviour change while gates are off | S2/S3 land with all `MODULES.GATE_*` off; `make test-e2e` green before each push |
| C6 | Every optional service is owned | `ModuleOwnershipArchitectureTest` walks `services.yaml` env defaults and `App\Module\*` namespaces |
| C7 | Runtime-entered keys work without restart | `ProviderKeyStoreModuleTest`: save key → `isConfigured()` true within cache TTL, gate opens |
| C8 | Runtime config is additive | `GetRuntimeConfigResponseSchema` regenerated; `modules` optional; older frontends ignore it |
| C9 | Mobile classes | `node scripts/mobile-impact.mjs` in each PR; policy lists `backend/src/Module/**` |

---

## 6. Sprints

| Sprint | Name | Steps | Scope | Depends on |
| ------ | ---- | ----- | ----- | ---------- |
| S1 | Inventory & dead weight | FM1–FM5 | Module map, vendor slimming, ownership test (failing-allowed list), eager-registry audit | — |
| S2 | Registry & descriptors | FM6–FM11 | `App\Module`, 12 descriptors, `featuresStatus` and capability inventory derived, `app:modules:list` | S1 |
| S3 | Gates & lazy registries | FM12–FM17 | `ModuleGateListener` (default-off flags), lazy `ProviderRegistry`, runtime-config `modules`, frontend hides absent cards | S2 |
| S4 | CI matrix & rollout | FM18–FM22 | `minimal` variant, OpenAPI diff, `@minimal` E2E, gates flipped on per module, docs | S3 |
| S5 (optional) | Compile-time & plugin extraction | FM23–FM26 | Env-only compile-time exclusion experiment; Higgsfield as reference plugin | S4, plugin-platform manifest v2 |

Each sprint ends with the full local gate (`make ci-local && make test-e2e`)
and a PR per step (Conventional Commits, `feat`/`refactor`/`chore`/`test`/`docs`
chosen for the release bump it deserves — this initiative is `refactor`/`chore`
until S3's user-visible gating, which is `feat`).

---

## 7. Rollout

1. S1 and S2 are invisible to users (status page content is identical, only
   generated). Ship on the normal cadence.
2. S3 lands with every gate off; `runtime.modules` appears; frontend hiding is
   driven by `configured` only, not by the gate — so cards disappear for
   absent modules immediately (low risk: a hidden card for an unconfigured
   feature is not a regression) while API behaviour is unchanged.
3. S4 flips gates on one module per PR, each after the `minimal` + `full`
   variants are green and the module's E2E path is recorded in `STATUS.md`.
   Order: sidecars first (`tika`, `docling`, `office_convert`, `searxng`,
   `piper_tts`), providers second, billing/IAP/WhatsApp last (webhooks).
4. Synaplan Cloud: gates are `BCONFIG` rows, so the platform repo can hold them
   off until its own smoke test passes; no image difference.

---

## 8. Out of scope

- Removing PHP files or container definitions per installation (S5 experiment
  only, env-only modules, never providers).
- Per-user or per-group module visibility (that is config-pyramid L3/L4 and IAM).
- New admin pages; new schema; changes to plugin manifest v1.
- Widgets, OAuth/OIDC, desktop agent, e-mail channels as modules (v2 candidates
  once the pattern is proven).
- Hot-reloading plugins.

---

## 9. Success criteria

- A fresh install with no optional env set: `app:modules:list` shows 12 ×
  "not configured"; every gated route answers the uniform 404; the Feature
  status page explains how to enable each; `ConfigView` shows no Higgsfield
  card; `docker image` is ≥ 250 MB smaller than before S1.
- A configured install: zero behaviour change (C1–C5), first chat request in
  dev instantiates only the providers the request uses.
- Adding compute B1 requires one `ComputeModule` class and no edit to
  `featuresStatus()`, `PlatformCapabilityInventory` or `ConfigView.vue`.
- `minimal` and `full` CI variants green; OpenAPI byte-identical.

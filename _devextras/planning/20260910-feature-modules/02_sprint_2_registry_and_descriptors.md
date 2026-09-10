# Sprint S2 — Registry & descriptors

**Feature modules, sprint 2 of 5.** Steps `FM6`–`FM11`.

**Goal:** `App\Module` exists, the 12 descriptors are written from the S1 map,
and the two hand-written lists (`featuresStatus()`, `PlatformCapabilityInventory`)
read from the registry. The JSON the admin page receives is **identical** before
and after (snapshot-tested). No gating, no flags, no frontend change.
**Depends on:** S1 (`module_map.md`, ownership test).
**Unlocks:** S3 (gates and runtime-config need the registry).
**Repos:** `synaplan/` (`backend/`).
**Flag:** none — the registry is read-only.

---

## 0. Why this sprint exists

Everything later hangs on one question per feature — "is it configured?" —
answered in one place with the cache discipline the key stores already have.
Writing the descriptors while *only* replacing existing read paths keeps the
sprint risk-free: the output must not change, so the tests are a diff.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Plug/DependencyInjection/PlugDeclarationCheckPass.php`, `Kernel::build()` | Tagging via `registerForAutoconfiguration`; add `FeatureModuleInterface` → `app.feature_module` |
| `backend/src/AI/Credential/ProviderKeyStore.php` (`getStatus()`, `resolveKey()`, cache) | The runtime source for provider modules; the registry must not out-cache it |
| `backend/src/Plug/PlugKeyStore.php`, `Plug/PlugConfigService.php` | Runtime source for search/extraction plug modules |
| `backend/src/Service/Config/LayeredConfigResolver.php` | Read env/BCONFIG through it where a key is layered |
| `backend/src/Service/SelfAware/PlatformCapabilityInventory.php` (`build()`, `fact()`, `flagFact()`, `KNOWN_ABSENT`), `CapabilityState`, `CachedPlatformCapabilityInventory` | Consumer; keep its own per-user cache |
| `backend/src/Controller/ConfigController.php` 2078–2505 | Each `$features['x'] = [...]` block becomes `XModule::status()` |
| `backend/src/Service/File/TikaClient.php`, `Plug/Extraction/Docling/DoclingClient.php`, `Service/…/OfficeConverterClient.php`, `AI/Provider/HiggsfieldProvider.php::isAvailable()` | Existing availability logic reused verbatim |
| `backend/src/Command/` (any `app:*:list` command) | Console output style for `app:modules:list` |

---

## 2. Work

### FM6 — Contract and registry

`backend/src/Module/Contract/{FeatureModuleInterface, ConfiguredBy, ModuleStatus}.php`,
`backend/src/Module/ModuleRegistry.php` (`#[AutowireLocator('app.feature_module', indexAttribute: 'key')]`),
`Kernel::build()` autoconfiguration, `ModuleRegistryTest` (duplicate id fails,
unknown id → `ModuleNotFoundException` with the id in the message).
`ConfiguredBy` is a value object listing env keys, `BCONFIG` group/keys and
provider/plug keys — used by docs, doctor and the ownership test.

### FM7 — Sidecar modules

`Module/Sidecar/{Tika,Docling,OfficeConvert,Searxng,PiperTts,LocalAi}Module.php`:
`isConfigured()` = the one L1 URL non-empty (config-pyramid row 11);
`status()` = the existing health probe of the client, unchanged.

### FM8 — Provider modules

`Module/Provider/{Higgsfield,GoogleAi,TheHive}Module.php`: `isConfigured()`
delegates to `ProviderKeyStore::getStatus()` (`configured` from db or env);
`status()` reuses `isAvailable()`. Unit test: env-only key, db-only key, none.

### FM9 — Commerce and channel modules

`Module/Commerce/{StripeBilling,MobileIap}Module.php`,
`Module/Channel/WhatsappModule.php`: env-only; `status()` reports which env
keys are missing by *name*, never value.

### FM10 — Consumers derive from the registry

- `featuresStatus()` → registry loop + the three infrastructure rows. A
  characterization-style test records the current JSON for a fixed
  configuration **before** the refactor (`tests/Controller/__snapshots__/features_status.json`)
  and must still match after (only `module` fields added under a key the old
  frontend ignores).
- `PlatformCapabilityInventory`: for each module, `capabilityIds()` map to
  `Available`/`NeedsSetup`/`Absent`; matching `KNOWN_ABSENT` rows removed;
  `CapabilityReport` snapshot unchanged for the same configuration.
- `ModuleOwnershipTest` switches its source from `module_map.md` to
  `serviceIds()`/`configuredBy()`; allow-list burned down to zero.

### FM11 — `app:modules:list`

Console table (id · configured · healthy · configured by · docs) and `--json`;
`--assert-none-configured` exits non-zero if any module is configured (used by
S4's `minimal` variant). Documented in `docs/DEVELOPMENT.md`.

---

## 3. Invariants exercised

| # | How |
| - | --- |
| C1 | `features_status.json` and `CapabilityReport` snapshots identical for the `full` fixture configuration |
| C3 | Characterization routing snapshots untouched (no classifier code touched) |
| C6 | Allow-list reaches zero in `FM10`; test fails on any new unowned optional env |
| C7 | `ProviderKeyStoreModuleTest`: save key via store → module configured within TTL |

---

## 4. Exit criteria / demo

1. Operate → Feature status renders identically (compare the e2e-visual
   snapshot) while `ConfigController::featuresStatus()` is under 50 lines.
2. `app:modules:list` on the dev stack prints 12 rows; with `TIKA_URL` removed
   from `.env` and cache cleared, `tika` flips to "not configured" and the
   capability report says so in the self-aware chat.
3. Full gate green.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| FM6 | `refactor(backend): add FeatureModule contract and ModuleRegistry` | backend-only | FM1, FM4 |
| FM7 | `refactor(backend): describe Tika, Docling, Collabora, SearXNG, Piper and Ollama as feature modules` | backend-only | FM6 |
| FM8 | `refactor(backend): describe Higgsfield, Google AI and TheHive as feature modules` | backend-only | FM6 |
| FM9 | `refactor(backend): describe Stripe billing, mobile IAP and WhatsApp as feature modules` | backend-only | FM6 |
| FM10 | `refactor(backend): derive feature status and capability inventory from the module registry` | backend-only | FM7, FM8, FM9 |
| FM11 | `feat(backend): add app:modules:list console command` | backend-only | FM10 |

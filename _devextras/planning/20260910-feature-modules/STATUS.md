# Status — Feature modules

**Intermezzo** release of
[`../20260910_roadmap_update.md`](../20260910_roadmap_update.md)
(after the two bugfixes; Wave 4 is on `main` via #1774; before Wave 5). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked
2026-09-10 by the product owner (all rows at the proposed default). The two
production bugfixes landed first
([#1787](https://github.com/metadist/synaplan/pull/1787)); S1 started the
same day.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Inventory & dead weight (FM1–FM5) | `intermezzo/s1-inventory-and-dead-weight` | in progress | FM2/FM3 Ask recorded in §0 row 10 and approved with the §0 ticks. FM2: `composer remove league/flysystem-aws-s3-v3` also drops `aws/aws-sdk-php`, `aws/aws-crt-php`, `league/flysystem`, `league/flysystem-local`, `league/mime-type-detection`, `mtdowling/jmespath.php` (7 packages, no reference outside `composer.json`). FM3: `extra."google/apiclient-services": ["AndroidPublisher"]` + `scripts."pre-autoload-dump": Google\Task\Composer::cleanup`; the prod Dockerfile now runs that script inside the `composer install` layer (a deletion in the later autoloader layer would only add whiteouts). Measured on local prod-target builds (`main` vs branch): vendor install layer **502 MB → 219 MB (−283 MB)**, autoloader layer 16.4 MB → 3.75 MB, `vendor/` on disk 410 MB → 134 MB; only `AndroidPublisher` remains, `vendor/aws` and `vendor/league` are gone; `lint:container` passes inside the image with the authoritative classmap. FM4: `tests/Architecture/ModuleOwnershipTest.php` classifies all 132 env keys referenced by `services.yaml` (12 modules / 11 core groups, allow-list empty). FM1: [`module_map.md`](./module_map.md) — 12 module tables with cited paths, the core list (79 keys with reasons), never-gate routes per module, and the store-required frontend files (`stripe_billing`, `mobile_iap`, `stores/config.ts`). FM5 (in the map): make lazy in S3 — `ProviderRegistry` (FM13 target), `SkillCatalog`+`RunnerRegistry` (one shared locator), `DocumentToolRegistry` (low priority); leave `WebSearchRegistry`, `RerankRegistry`, `ExtractionRegistry`, `BundleSectionRegistry`, `InferenceRouter`, `ResourceKindRegistry` (cheap or hot path), `MessagesGateway`, `ToolRegistry` (already lazy). `backend/src/Module/**` needs no policy change: `backend/**` is already `backend-only`. |
| S2 Registry & descriptors (FM6–FM11) | `intermezzo/s2-registry-and-descriptors` (stacked on S1, PR #1815) | in progress | FM6: `App\Module\Contract\FeatureModuleInterface` + `ConfiguredBy`/`ModuleStatus`/`MobileClass`; `FeatureModuleTagPass` indexes the `app.feature_module` tag by each class's `public const ID` (snake_case, duplicates refuse to compile); `ModuleRegistry` over a lazy `#[AutowireLocator]` — `ids()/has()` never instantiate, `forRoute()` takes exact names or `prefix*`, `forCapability()` resolves `capabilityIds()`. FM7–FM9: 12 descriptors in `Module/{Sidecar,Provider,Commerce,Channel}`; each reads the SAME env expression as the service it describes and the unit tests assert `isConfigured()` equals the client's own `isEnabled()`/`isConfigured()` (Tika, Docling, Collabora, SearXNG). Probing only in `status()` via `SidecarHealthProbeInterface` (HTTP impl keeps the status page's historic 2 s / basic-auth / 401-403-5xx rules; fake in tests). `WhatsappModule` is env-only (does not pull the 16-collaborator service); `PiperTtsModule` reads the raw `SYNAPLAN_TTS_URL`; Stripe/IAP mirror `BillingService::isEnabled()` / `IapPricingService::isConfigured()`. Never-gate routes per module_map excluded from `routeNames()` and asserted in tests. `ModuleOwnershipTest` now reads ownership from `configuredBy()`; `ModuleDescriptorContractTest` checks ids, real service classes, route patterns, single ownership. FM10 in three commits: (1) verbatim extraction of `getFeaturesStatus()` into `App\Service\Config\FeatureStatusReporter` + snapshot test (`tests/Unit/Service/Config/__snapshots__/features_status.{bare,full}.json`, recorded BEFORE derivation); (2) tika/docling/office-convert rows derived from the descriptors, `features` and `summary` verified byte-identical to the recorded baseline, additive top-level `modules` list (OpenAPI-annotated; the frontend's own Zod object is non-strict so it ignores it — C2 holds); `PlatformCapabilityInventory` takes `pdf_export`/`text_to_speech`/`channel_whatsapp` from `ModuleRegistry::forCapability()` instead of its own env reads; runtime-config `officeConvertEnabled` from the module. FM11: `app:modules:list [--json] [--assert-none-configured]` (`ModuleStatusPresenter` shared with the status page), documented in `docs/DEVELOPMENT.md`. Dev stack readout: 12 modules, 10 configured. |
| S3 Gates & lazy registries (FM12–FM17) | — | planned | All 12 `MODULES.GATE_*` flags seeded off |
| S4 CI matrix & rollout (FM18–FM22) | — | planned | Ask-first CI change (§0 row 11); gates flipped one module per PR — record E2E path + run URLs here |
| S5 Compile-time & plugin extraction (FM23–FM26) | — | planned, optional | Cut first if capacity runs out (§0 row 13) |

## Gate rollout log (S4 FM21)

| Module | Gate on for new installs | E2E path | `minimal` run | `full` run |
| ------ | ------------------------ | -------- | ------------- | ---------- |
| tika | — | | | |
| docling | — | | | |
| office_convert | — | | | |
| searxng | — | | | |
| piper_tts | — | | | |
| local_ai | — | | | |
| higgsfield | — | | | |
| google_ai | — | | | |
| thehive | — | | | |
| stripe_billing | — | | | |
| mobile_iap | — | | | |
| whatsapp | — | | | |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-10 | Initiative proposed by the research in `../20260910-wave5-architecture-research/02_conditional_module_loading.md`: runtime-gated declared modules instead of compile-time exclusion; vendor slimming first; CI `minimal`/`full` matrix as the proof. |
| 2026-09-10 | Named **Intermezzo** in `../20260910_roadmap_update.md`: after the two production bugfixes and Wave 4, before Wave 5. Not a seventh track. |
| 2026-09-10 | Wave 4 merged to `main` as #1774. Intermezzo still waits on the two bugfixes and on §0 ticks. |
| 2026-09-10 | Bugfixes confirmed on `main` (#1787: Parsedown HTML on SMTP + Graph `contentType: html`; rolling summary applied on stream and non-stream, refresh dispatched after persist; characterization snapshots untouched). §0 rows 1–15 ticked at their proposed defaults. **Intermezzo S1 started** on `intermezzo/s1-inventory-and-dead-weight`. |
| 2026-09-10 | **S1 delivered as PR #1815.** **S2 started** on `intermezzo/s2-registry-and-descriptors` (stacked on S1). Two S2 design decisions taken and recorded here: (a) `routeNames()` entries are exact route names or `prefix*` — plain prefix matching would have caught never-gate routes such as `api_webhooks_whatsapp_verify` via `api_webhooks_whatsapp`; (b) the feature-status page keeps its historic row shape and wording (snapshot-locked) and gains an additive `modules` list instead of being rewritten around module ids, because `features`/`summary` are a frontend contract (C2). |

## Review log

**2026-09-10 (first pass):** master plan and five sprint files drafted against
the verified codebase state (container 2 255 definitions, 470 routes, 18
providers eagerly indexed, `vendor/` 500 MB with 269 MB dead/unused,
`featuresStatus()` 430 lines). Next: product-owner ticks on §0, then technical
plan review (roadmap §7 step 3).

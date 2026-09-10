# Research: load optional PHP modules only when configured? (2026-09-10)

**Question (product owner):** Synaplan has many optional features — Tika,
Docling, widgets, optional LLM providers (Higgsfield, Google), Collabora and
more — that some installations never use. Should the code be refactored so
that PHP modules are **only loaded when the function is configured** (Higgsfield
code only when its API key is set, Tika code only when Tika is configured), so
an installation stays lean?

**Verdict:** **Yes — but not in the literal form.** "Loading PHP files only
when configured" buys almost nothing at runtime, because Symfony's compiled
container, classmap-authoritative autoloading, opcache preload and FrankenPHP
worker mode already make an *unused* class close to free per request. The lean
installation the question is after is real, but it lives in five other places:
the **route surface** (every unconfigured feature still answers on its URLs),
**eager registries** (`ProviderRegistry` instantiates all 18 providers on first
use), the **container** (2 255 definitions, one for every optional service),
**`vendor/`** (269 MB of 500 MB belong to two optional or dead dependencies),
and **code ownership** (no single place says "this feature exists iff X").
Those are worth a focused, refactor-first initiative: **declared feature
modules gated at runtime**, dead-weight removal, and a CI matrix that proves an
absent module is absent. Compile-time exclusion of code is possible only for a
small class of env-only features and is deferred behind that matrix.

Implementation plan: [`../20260910-feature-modules/00_master_plan.md`](../20260910-feature-modules/00_master_plan.md).

---

## 1. How the code is loaded today (facts, not assumptions)

| Layer | What happens | Cost of an unconfigured feature |
| ----- | ------------ | -------------------------------- |
| Autoload | Prod image: `composer install --no-dev --no-scripts --no-autoloader` then `dump-autoload --optimize --classmap-authoritative` (`_docker/backend/Dockerfile`). Plugins get their own PSR-4 registration in `Kernel::registerPluginAutoloaders()`. | One classmap entry (a few bytes). No file is read until a class is first referenced. |
| Container | `Kernel::configureContainer()` imports `config/packages/*.yaml` + `services.yaml`; `App\` is resource-loaded, every provider/client is registered unconditionally with `app.ai.*` / `app.plug.*` tags. Compiled once per env (`var/cache/prod`). Measured (dev): **2 255 definitions, 376 aliases, 1 073 `App\` definitions**. Compiled prod container: **1 049 files / 4.8 MB**, `url_generating_routes.php` 194 KB. | One factory method in the compiled container; the service object is created only when injected or fetched. Unused *private* services are inlined or removed by the compiler; tagged services, controllers and public services stay. |
| Preload | `config/preload.php` requires `App_KernelProdContainer.preload.php` — **2 103 lines, ~2 100 classes** compiled into opcache at worker start. | One-time shared memory per process pool, not per request. |
| Runtime | FrankenPHP worker mode in prod (`FrankenPhpRunner`, `Caddyfile`): the kernel and container live across requests. Dev runs the classic SAPI. | Zero per request unless something *instantiates* the service. |
| Routing | 104 controllers, **470 routes** (`debug:router`), all registered whatever is configured. | The URL exists. Behaviour when the feature is absent is decided per controller (`isAvailable()`, "Unauthorized", 400, 500 …) — not uniform. |
| Config | `services.yaml` defaults every optional env to `''` (`HIGGSFIELD_API_KEY`, `DOCLING_BASE_URL`, `OFFICE_CONVERT_URL`, `TIKA_*`, `STRIPE_SECRET_KEY`, `IAP_*`, WhatsApp, OAuth …). Provider keys are **also stored in `BCONFIG` at runtime** by `ProviderKeyStore` (UI-entered, encrypted, 5-minute cache) and by `PlugKeyStore`. | Two truths for "configured": env at boot, DB at runtime. |

Consequence for the literal proposal: a compile-time decision "Higgsfield code
is not in the container because `HIGGSFIELD_API_KEY` is empty" is **wrong by
construction** for providers — an admin can enter the key in the UI at runtime
and expects the provider to work without redeploying. Compile-time gating can
only ever apply to features whose configuration is *env-only* (L0/L1 in the
config-pyramid plan: sidecar URLs, Stripe, IAP, WhatsApp, OAuth clients).

---

## 2. Where the real cost is (measured 2026-09-10)

### 2.1 Eager registries

`ProviderRegistry::__construct()` receives eight `#[AutowireIterator('app.ai.*')]`
iterables and immediately indexes them by `$provider->getName()`. Iterating the
generator **instantiates every provider** (18 today, incl. Higgsfield, Google,
TheHive, Piper …) with their credential resolvers and HTTP clients — once per
worker in prod, once per request in dev/test. Not a correctness problem, but
the one place where "all optional code is loaded" is literally true, and the
reason the first chat request in dev pays for providers nobody configured.
`AutowireIterator` supports `indexAttribute`/`defaultIndexMethod`, and a
`#[AutowireLocator]` yields a lazy `ServiceLocator` — the eager index can go
without changing callers.

### 2.2 Route surface

Every optional feature exposes its routes unconditionally:
`/api/v1/ai-providers/higgsfield/credentials` (3 methods), 14
`*Widget*Controller` files, Stripe webhook, `MobilePurchaseController`,
Docling/Tika/office-convert diagnostics, WhatsApp webhooks, OAuth callbacks.
What a caller gets when the feature is unconfigured varies (`401 Unauthorized`
guard first, then feature-specific errors; some return 200 with
`configured:false`). For a lean install this is (a) attack surface and noise in
scanners, (b) UX debt: the frontend shows `HiggsfieldConnection` in
`ConfigView.vue` unconditionally, so an installation that will never use video
generation still shows a Higgsfield card.

### 2.3 Feature status is hand-written

`ConfigController::featuresStatus()` (lines 2078–2505, **~430 lines in one
controller method**) assembles `web-search`, `image-gen`, per-provider,
`whisper`, `tika`, `docling`, `office-convert`, `memory-service`, `database`,
`redis`, `centrifugo` by hand. `PlatformCapabilityInventory` keeps a second,
separate list (`KNOWN_ABSENT` …). There is no registry either could read from;
every new optional feature adds another hand-written block in two places and a
new `isEnabled()`/`isAvailable()` convention (`TikaClient`, `DoclingClient`,
`OfficeConverterClient`, `HiggsfieldProvider`, `GoogleProvider` each spell it
slightly differently).

### 2.4 `vendor/` dead weight

| Package | Size | Used by | Finding |
| ------- | ---- | ------- | ------- |
| `google/apiclient` (+ `apiclient-services`) | **206 MB** | `Service/Iap/GooglePlayVerifier.php` only — `Google\Service\AndroidPublisher` | `composer.json` has no `extra.google/apiclient-services` allow-list, so **all ~300 Google service bundles** ship. Listing `["AndroidPublisher"]` lets the package's own composer plugin delete the rest (documented feature of `google/apiclient`). Expected: 206 MB → a few MB. |
| `league/flysystem-aws-s3-v3` → `aws/aws-sdk-php` | **63 MB** | **nothing** — `rg -i "flysystem|aws" backend --glob '!vendor'` finds no class reference in `src/`, `config/` or `tests/` | Dead dependency. Remove (Ask-first: dependency change). |
| `stripe/stripe-php` | 4.1 MB | `StripeWebhookController`, `SubscriptionController` | Cloud-only; fine as-is, candidate for module extraction later |
| `hoels/app-store-server-library-php` | small | `AppleStoreKitVerifier` | Mobile-only; fine |
| `phpoffice/*`, `codewithkyrian/whisper.php` | 12 + 12 MB | document generation / local STT | Used; keep |

269 MB of the 500 MB `vendor/` tree (54 %) is either dead or 98 % unused.
That is the single biggest "lean installation" win available, costs one PR,
and shrinks the image, the CVE-scan noise and the `docker-build` job.

### 2.5 Cognitive modularity

Higgsfield touches ~25 files across `AI/Provider`, `AI/Credential`,
`Controller/AI`, `Seed`, frontend component + API client + i18n. Tika ~22,
Collabora/office 19, Docling 11, widgets 96 mentions. None of these has a
manifest, an owner file or a test that says "with `X` unset, none of this is
reachable". The plugin system (`plugins/*/manifest.json`, manifest v2
`provides.*` in the open-plugin-platform plan, `PlugDeclarationCheckPass`)
already has that shape — for third-party code only.

---

## 3. Options

| Option | What it is | Wins | Regression / CI risk | Verdict |
| ------ | ---------- | ---- | -------------------- | ------- |
| **O1 Status quo** | Keep per-class `isEnabled()`, hand-written feature status | none | none | Cost keeps growing with tracks 3–5 (compute, plugs, agents each add optional services) |
| **O2 Compile-time DI exclusion** — `Kernel::configureContainer()` imports `config/modules/<x>.yaml` only when `getenv('X_URL') !== ''` | Literal reading of the question | Smaller container/preload for the excluded set | **High.** Container differs per install → OpenAPI dump (`nelmio:apidoc:dump` in `frontend-build`) and therefore the Zod schemas depend on which env the spec was generated in; `debug:container`/PHPStan/characterization snapshots see one shape, prod another; provider keys entered in the UI are not env → *cannot* gate providers this way; env change needs `cache:clear` + restart (the plugin rule "no hot loading" would spread to every feature) | Only for a small, explicitly **env-only** class, and only after the CI matrix (O5) exists. Deferred to the last sprint as an opt-in row |
| **O3 Runtime feature modules** — one `FeatureModule` descriptor per optional feature (`id`, `isConfigured()` from env/`BCONFIG`, capability id, route name prefixes, provider/plug keys, docs anchor); a `ModuleRegistry`; one `ModuleGate` (request listener / attribute) that answers **404 + `feature_not_configured`** for routes of absent modules; feature status and `PlatformCapabilityInventory` read from the registry | Uniform "absent" behaviour, one place per feature, lean *surface* without changing the container shape; frontend can hide cards from the same list | **Low.** Container identical everywhere; OpenAPI unchanged; each gate is default-off per module until its E2E path is verified | **Recommended core** |
| **O4 Lazy registries** — `ProviderRegistry` and other `AutowireIterator` consumers become locator-based, providers instantiated on demand; `ProviderKeyStore` decides "configured" | Removes the only true eager load; faster first request in dev/test | Medium-low: `getName()` index must come from the tag (`indexAttribute`) or a static; characterization snapshots unaffected (routing, not providers) | **Recommended** |
| **O5 CI matrix "minimal / full"** — compile and boot the container with all optional env empty *and* with everything set (fixture values), run PHPUnit + `lint:container` + OpenAPI dump in both, diff the OpenAPI spec (must be identical), run a small Playwright `@minimal` set against the minimal stack | Makes "absent means absent" a CI fact; prerequisite for O2 | Adds one backend job variant (~3–4 min) | **Recommended** |
| **O6 Vendor slimming** — `extra.google/apiclient-services: ["AndroidPublisher"]`, remove `league/flysystem-aws-s3-v3` | −~260 MB image layer, fewer CVEs | Low: `GooglePlayVerifier` unit tests + IAP E2E cover the only user; the S3 package has no user | **Do first** (Ask-first: dependency change — recorded in the plan's checklist) |
| **O7 Plugin extraction** — move self-contained optional features (Higgsfield adapter first; later IAP, Stripe, WhatsApp) into `plugins/*` with manifest v2 `provides.*` | Physical modularity: code not present unless installed; reuses the platform already planned for third parties | Medium: needs manifest v2 (`PL39`), frontend plugin slots, migration of seeds, mobile-impact reclassification (`plugins/**` is `backend-only`, but Higgsfield has frontend) | Long-term direction; one **reference extraction** in the last sprint, rest as backlog |

---

## 4. Why "yes, narrowly" and what "lean" means afterwards

For an installation with no Higgsfield key, no Tika, no Docling, no Collabora,
no Stripe, no IAP:

| Aspect | Today | After the plan |
| ------ | ----- | -------------- |
| PHP files on disk | all | all (O2 excluded from v1; plugin-extracted features absent when not installed) |
| Classes in opcache preload | ~2 100 | ~2 100 (unchanged; not a per-request cost) |
| Objects created for unconfigured providers | 18 per worker / request | 0 |
| Routes that answer for absent features | all, inconsistent | uniform `404 feature_not_configured`, listed by `app:config:doctor`/Feature status |
| Admin view | hand-written status blocks | one Modules section in Operate → Feature status: configured / not configured / how to enable (docs anchor), no new page |
| Frontend | shows Higgsfield card always | cards and nav entries for absent modules hidden from one list |
| `vendor/` | 500 MB | ~240 MB |
| Proof | none | CI `minimal` variant: container compiles, OpenAPI identical, gated routes 404, E2E `@minimal` green |
| New optional feature (e.g. compute B1) | add `isEnabled()`, status block, capability entry, frontend `v-if` by hand | implement one `FeatureModule`, everything else derives |

The last row is the strategic reason to do it **before Wave 5**: track 5 (B1
"client & capability"), track 3 (plug adapters) and track 2 (agent tools) each
add optional services; a module descriptor per feature is cheaper to introduce
now than to retrofit across three tracks.

---

## 5. Regression and CI considerations (binding for the plan)

- **OpenAPI/Zod contract must not vary by configuration.** Controllers stay
  registered; gating is a request-time 404, so `nelmio:apidoc:dump` output is
  identical in every install. The `minimal` CI job diffs the spec against
  `full` to prove it.
- **Characterization snapshots** (`backend/tests/Characterization/`) lock
  routing/classifier behaviour, not provider instantiation; O4 must not change
  `MessageSorter`/`MessageClassifier` inputs. Re-record only if a diff appears,
  and review every line.
- **`doctrine:schema:validate`, `app:seed`, fixtures** run in both matrix
  variants — no schema change is in this plan.
- **PHPStan over the whole project** stays; `FeatureModule` implementations are
  `final readonly` and covered by an architecture test (every optional service
  in `services.yaml` belongs to exactly one module or is core).
- **Playwright**: `@ci` suite runs on the `full` stack as today; a small
  `@minimal` set (login, chat with the test provider, files, feature status
  page shows "not configured" rows, gated route returns 404) runs on the
  minimal stack. Feature-status and config UI changes are `ota-candidate`;
  everything else `backend-only`; `.github/mobile-impact-policy.json` needs
  the new `backend/src/Module/` (or chosen) path listed.
- **No behaviour change for configured features.** A gate that fires for a
  configured module is a P1 regression; each module's gate ships behind
  `MODULES.GATE_<ID>` default-off in code and seeder, flipped on per module
  after its E2E path is green (roadmap §4 "default-off flags", "regression as
  deliverable").
- **Dependency removal is Ask-first** (AGENTS.md boundaries); the checklist
  row records the ask.

---

## 6. Recommendation

Proceed with a five-sprint, refactor-first initiative — **Feature modules**
([`../20260910-feature-modules/`](../20260910-feature-modules/00_master_plan.md)):

1. S1 — inventory and dead weight (vendor slimming, module map, architecture test).
2. S2 — module registry and descriptors; Feature status and capability
   inventory read from it (no gating yet).
3. S3 — gates and lazy registries (uniform 404, `ProviderRegistry` on demand,
   frontend hides absent modules), each gate default-off.
4. S4 — CI matrix minimal/full, `app:config:doctor` integration, gates
   default-on where proven.
5. S5 — optional: env-only compile-time exclusion behind the matrix, and one
   reference plugin extraction (Higgsfield).

Not recommended: a broad compile-time "only load configured PHP" refactor. It
would trade a non-existent runtime cost for real contract and CI variance.

---

## 7. Evidence (commands run 2026-09-10, dev stack)

- `docker compose exec -T backend php bin/console debug:container --format=json` → 2 255 definitions, 376 aliases, 1 073 `App\`.
- `debug:router` → 470 routes; `ls backend/src/Controller | wc -l` → 104; 14 `*Widget*Controller*` files.
- `APP_ENV=prod php bin/console cache:warmup` inside the container → `App_KernelProdContainer.preload.php` 2 103 lines; container dir 4.8 MB / 1 049 files; `url_generating_routes.php` 194 KB.
- `du -sh backend/vendor` 500 MB; `vendor/google` 206 MB; `vendor/aws` 63 MB; `composer why google/apiclient` → root only; `composer why league/flysystem-aws-s3-v3` → root only; no `Flysystem`/`Aws` reference outside `vendor/` and `composer.json`.
- `rg "Google\\\\Client|AndroidPublisher" backend/src` → `Service/Iap/GooglePlayVerifier.php` only; `composer.json` `extra` has only the Symfony Flex block.
- `backend/src/AI/Service/ProviderRegistry.php` lines 30–77 (eager `getName()` index over eight `AutowireIterator`s).
- `backend/src/AI/Credential/ProviderKeyStore.php` (keys in `BCONFIG`, entered at runtime, env fallback, 5-min cache).
- `backend/src/Controller/ConfigController.php` 2078–2505 (`featuresStatus`), `/api/v1/config/capabilities`, `runtime` `features.selfAware`.
- `frontend/src/views/ConfigView.vue` line 20 (`<HiggsfieldConnection />` unconditional).
- `_docker/backend/Dockerfile` 108–118; `backend/config/preload.php`; `Caddyfile`; `backend/src/Runtime/FrankenPhpRunner.php`.
- `.github/workflows/ci.yml`: `frontend-build` generates the OpenAPI spec from the backend container and Zod schemas from it; backend job runs PHPStan whole project, migrations, `schema:validate`, fixtures, seed, PHPUnit; E2E matrix on `docker-compose.test.yml`.
- Planning: `20260907-config-pyramid/00_master_plan.md` (L0–L4, "empty URL = capability absent", `app:config:doctor`), `20260822-open-plugin-platform/README.md` (manifest v2 `provides.*`), `202609_ai_plugs/06_sprint_6_plugin_adapters.md` (`PlugDeclarationCheckPass` pattern).

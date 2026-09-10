# Sprint S1 — Inventory & dead weight

**Feature modules, sprint 1 of 5.** Steps `FM1`–`FM5`.

**Goal:** Know exactly which code belongs to which optional feature, remove the
two `vendor/` dead weights, and put a (temporarily allow-listed) ownership test
in place so the module map cannot rot before S2 uses it. No behaviour change.
**Depends on:** §0 checklist ticked (rows 10 and 12 in particular).
**Unlocks:** S2 (descriptors are written from the map, not from memory).
**Repos:** `synaplan/` (`backend/`, `_devextras/planning/`, `.github/`).
**Flag:** none.

---

## 0. Why this sprint exists

The research counted files by grep (Higgsfield ~25, Tika ~22, office 19,
Docling 11) but a plan must not be built on grep. This sprint produces the
authoritative map — per module: services, routes, env keys, `BCONFIG` keys,
frontend components, seeds, tests — and turns it into a test. It also takes the
cheapest lean-install win (−~260 MB) so the initiative shows value in its first
PRs.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/config/services.yaml` | Every `env(X): ''` default is an optional-feature marker; provider registrations with `app.ai.*` tags |
| `backend/src/AI/Service/ProviderRegistry.php` 30–77 | The eager index; list every other `AutowireIterator` consumer (`rg AutowireIterator backend/src`) |
| `backend/src/Controller/ConfigController.php` 2078–2505 | `featuresStatus()` — one block per feature, the seed of each module's `status()` |
| `backend/src/Service/SelfAware/PlatformCapabilityInventory.php` | Capability ids and `KNOWN_ABSENT` |
| `backend/src/Service/Iap/GooglePlayVerifier.php` | Only user of `google/apiclient` (`Google\Service\AndroidPublisher`) |
| `backend/composer.json` `extra` | Currently only the Flex block; the `google/apiclient-services` allow-list goes here |
| `_docker/backend/Dockerfile` 108–118 | `composer install --no-dev --no-scripts --no-autoloader` — check that the apiclient cleanup task still runs (it is a Composer plugin hook, so `--no-scripts` must be validated; fall back to a `RUN` step that calls `Google\Task\Composer::cleanup` if it does not) |
| `.github/mobile-impact-policy.json` | Add `backend/src/Module/**` (backend-only) now so S2 PRs pass |
| `frontend/src/views/ConfigView.vue`, `components/config/*Connection.vue` | Frontend surface per module |

---

## 2. Work

### FM1 — Module map (docs)

`_devextras/planning/20260910-feature-modules/module_map.md`: one table per
module (`tika`, `docling`, `office_convert`, `searxng`, `piper_tts`,
`local_ai`, `higgsfield`, `google_ai`, `thehive`, `stripe_billing`,
`mobile_iap`, `whatsapp`) with columns *service ids · route names · env keys ·
BCONFIG keys · provider/plug keys · frontend components · seeds · tests ·
capability ids · mobile class*. Also a "core" list for every optional-looking
env default that is deliberately **not** a module in v1 (widgets, OAuth/OIDC,
desktop, e-mail), with the reason.

### FM2 — Remove `league/flysystem-aws-s3-v3`

`composer remove league/flysystem-aws-s3-v3`; verify `composer.lock` drops
`aws/aws-sdk-php`; grep proves no reference; `docker-build` job size compared in
the PR description. Ask-first is recorded in §0 row 10.

### FM3 — Restrict `google/apiclient-services`

`composer.json` `extra: {"google/apiclient-services": ["AndroidPublisher"]}`;
run the cleanup (`composer update google/apiclient-services` locally; in the
image validate that the cleanup runs under `--no-scripts` or add an explicit
step). `GooglePlayVerifierTest` green; image size delta in the PR.

### FM4 — Ownership architecture test (allow-listed)

`backend/tests/Architecture/ModuleOwnershipTest.php`: parses `services.yaml`
env defaults and `App\` namespaces, asserts each is in `module_map` (S2 replaces
the markdown source with the descriptors' `serviceIds()`/`configuredBy()`), or in
an explicit `CORE` list, or in a dated `ALLOWED_UNOWNED` list that must shrink.
Fails on a new unowned optional env default.

### FM5 — Eager-registry audit

Short section appended to `module_map.md`: for each `AutowireIterator`
consumer — eager or lazy, how many services it instantiates, whether the
providers' `getName()` can be replaced by a tag `key`. Decides S3 `FM13` scope.

---

## 3. Invariants exercised

| # | How |
| - | --- |
| C1 | No PHP behaviour change; full gate green |
| C6 | `ModuleOwnershipTest` lands green with its allow-list; the list is the S2 burn-down |
| C9 | Policy updated in `FM1`'s PR; `node scripts/mobile-impact.mjs` shows `backend-only`/no-app-impact |

---

## 4. Exit criteria / demo

1. `module_map.md` reviewed by the product owner; every row has an owner and a
   capability id or "none".
2. `docker images` before/after S1: backend image ≥ 250 MB smaller; IAP unit
   tests green; `GooglePlayVerifier` still resolves `AndroidPublisher`.
3. `make ci-local` green including the new architecture test.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| FM1 | `docs(planning): add feature module map and classify backend/src/Module as backend-only` | noAppImpact | — |
| FM2 | `chore(backend): remove unused league/flysystem-aws-s3-v3 dependency` | backend-only | — |
| FM3 | `chore(backend): restrict google/apiclient-services to AndroidPublisher` | backend-only | — |
| FM4 | `test(backend): add module ownership architecture test with dated allow-list` | backend-only | FM1 |
| FM5 | `docs(planning): audit eager AutowireIterator consumers for lazy locators` | noAppImpact | FM1 |

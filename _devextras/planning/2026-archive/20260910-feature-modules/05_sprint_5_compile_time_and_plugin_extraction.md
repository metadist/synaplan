# Sprint S5 (optional) — Compile-time exclusion experiment & plugin extraction

**Feature modules, sprint 5 of 5 — optional, cut first.** Steps `FM23`–`FM26`.

**Goal:** Answer the literal version of the original question with data — does
excluding env-only modules from the compiled container measurably help? — and
prove the long-term direction by extracting one first-party optional feature
(Higgsfield) into a plugin with zero core edits. Both halves have their own
go/no-go in `STATUS.md`; neither is required for the initiative to be complete.
**Depends on:** S4 (matrix, byte-diff); plugin-platform manifest v2
(`PL39`/`PL40` in `202609_ai_plugs/06_sprint_6_plugin_adapters.md` or
`20260822-open-plugin-platform` S1.4).
**Unlocks:** the plugin catalog listing first-party optional features; a
documented answer to "why not compile-time" that does not need re-litigating.
**Repos:** `synaplan/` (`backend/`, `plugins/`, `frontend/`), `synaplan-docs/`.
**Flag:** `MODULES.COMPILE_TIME_EXCLUSION` — env-only (`MODULES_COMPILE_TIME=1`),
never a `BCONFIG` row (it changes what the container contains, so it must be
known before the container is built); default unset.

---

## 0. Why this sprint exists

Decision row 1 rejects broad compile-time exclusion on argument. A cheap,
bounded experiment turns the argument into a measurement — memory per worker,
warm-up time, container size — for the only class where it is even possible
(env-only modules: sidecars, Stripe, IAP, WhatsApp). Provider modules are
excluded from the experiment by construction (runtime keys). The plugin
extraction tests the *other* way to make code physically absent — the way the
platform already plans for third parties.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Kernel.php` `configureContainer()` | Conditional `import()` of `config/modules/<id>.yaml` when `getenv()` says configured and `MODULES_COMPILE_TIME=1` |
| `backend/config/preload.php`, `var/cache/prod/App_KernelProdContainer.preload.php` | Measure preload class count and shared memory before/after |
| `_docker/backend/Dockerfile`, `Caddyfile`, `backend/src/Runtime/FrankenPhpRunner.php` | Where warm-up happens; worker memory measurement point |
| `plugins/hello_world/`, `plugins/castingdata/`, `plugins/synafastbill/` | Plugin layout; `frontend/` slot conventions for a plugin-owned config card |
| `backend/src/AI/Provider/HiggsfieldProvider.php`, `AI/Credential/HiggsfieldCredentialResolver.php`, `Controller/AI/HiggsfieldCredentialController.php`, `Seed/` entries for Higgsfield models, `frontend/src/components/config/HiggsfieldConnection.vue`, `services/api/higgsfieldCredentialsApi.ts`, i18n keys | Everything that moves |
| `.github/mobile-impact-policy.json` | `plugins/**` is `backend-only`; a plugin with a `frontend/` slot needs a policy decision (proposal: `plugins/*/frontend/**` → `ota-candidate`) |

---

## 2. Work

### FM23 — Compile-time exclusion behind `MODULES_COMPILE_TIME`

For env-only modules, move their service definitions from `services.yaml` into
`config/modules/<id>.yaml`; `Kernel::configureContainer()` imports each file
unconditionally **unless** `MODULES_COMPILE_TIME=1` and the module's env keys
are empty. Default behaviour (flag unset) is byte-identical to today (the CI
byte-diff proves it). The `minimal` CI variant gains a third run with the flag
set: container must compile, `lint:container` pass, PHPUnit for `tests/Module`
pass, and the *gated routes must still 404* (the route exists via the
controller resource load even when the service file is excluded — decide and
test: either controllers move into the module file too and the gate answers
from a static route list, or controllers stay and fail fast with the same 404
body when their service is missing).

### FM24 — Measurement and go/no-go

On the prod image with and without the flag, minimal configuration: preload
class count, `memory_get_usage()` per worker after warm-up, `cache:warmup`
wall-clock, compiled container size, p50 latency of `GET /api/v1/health` and one
chat request over 500 requests. Record in `STATUS.md`. Proposed threshold to
keep the flag: ≥ 10 % worker RSS **and** no OpenAPI variance; otherwise revert
`FM23` entirely (the module yaml files may stay as pure organisation).

### FM25 — Higgsfield as a reference plugin

`plugins/higgsfield/` with manifest v2 `provides.plugs`/`provides.modules`
(extend the parser with `modules` if S2's descriptor is to be declared like plug
adapters), the provider, credential resolver, credential controller, model
seeds, and the frontend card in the plugin's `frontend/` slot. Core diff
`git diff main --stat -- backend/src frontend/src`: only deletions. `higgsfield`
disappears from the core module list and appears when the plugin is installed;
`ModuleOwnershipTest` accepts plugin-declared modules. Existing installs:
migration note — models already seeded keep working; the plugin ships in the
image by default for one release so nobody loses the feature on upgrade
(decision to record).

### FM26 — Plugin-module authoring docs

`synaplan-docs`: "Ship an optional feature as a plugin" using Higgsfield as the
worked example; `AGENTS.md` mobile section: policy line for `plugins/*/frontend/**`.

---

## 3. Invariants exercised

| # | How |
| - | --- |
| C1 | Flag unset → byte-identical container and OpenAPI (CI diff) |
| C2 | Third `minimal` run with the flag: spec still identical (controllers stay in the spec either way) |
| C4 | Gated routes 404 with the flag set — test decides the mechanism in FM23 |
| C9 | FM25 reclassification recorded in the policy and `tests/mobile-impact.test.mjs` |

---

## 4. Exit criteria / demo

1. `STATUS.md` holds the FM24 numbers and a one-line go/no-go for compile-time exclusion.
2. `plugins/higgsfield` installed: Higgsfield card, credentials route, provider and models present; removed: none of them, `app:modules:list` has 11 rows, chat unaffected.
3. Full gate green in all variants.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| FM23 | `feat(backend): optionally exclude unconfigured env-only modules from the compiled container` | backend-only | FM19, FM21 |
| FM24 | `docs(planning): record compile-time exclusion measurements and go/no-go` | noAppImpact | FM23 |
| FM25 | `refactor(plugins): move Higgsfield provider, credentials and card into plugins/higgsfield` | backend-only + ota-candidate (frontend slot) | FM21, plugin-platform manifest v2 |
| FM26 | `docs(plugins): document shipping an optional feature as a plugin` | noAppImpact | FM25 |

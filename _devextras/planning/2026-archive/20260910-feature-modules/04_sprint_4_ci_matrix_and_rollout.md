# Sprint S4 — CI matrix & rollout

**Feature modules, sprint 4 of 5.** Steps `FM18`–`FM22`.

**Goal:** CI proves, on every heavy run, that a minimal installation compiles,
boots, serves the same OpenAPI contract and answers the uniform 404 on gated
routes — and then the gates are switched on, one module per PR, with the proof
attached. After this sprint the initiative is complete.
**Depends on:** S3.
**Unlocks:** S5 (compile-time experiment needs the matrix); compute B1 and
plug adapters born as modules.
**Repos:** `synaplan/` (`.github/`, `docker-compose*.yml`, `backend/`,
`frontend/tests/e2e/`, `docs/`), `synaplan-docs/` (one section).
**Flag:** flips `MODULES.GATE_<ID>` seeder defaults to `1` module by module
(with a Galera-safe `UPDATE` migration for existing installs only if the
product owner wants gating on for upgrades — default proposal: **new installs
on, existing installs stay off** until the admin enables; see §2 FM21).

---

## 0. Why this sprint exists

AGENTS.md is explicit: "never use GitHub as the first E2E run" and
"`make ci-local` ≠ green CI". A minimal installation is a configuration nobody
runs locally by default, so without a CI variant it is the one configuration
that would only ever be tested by customers. The matrix is also the safety net
that lets S5 even be considered.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `.github/workflows/ci.yml` — `backend` job, `frontend-build` (OpenAPI dump + `openapi-zod-client`), `e2e` matrix, `all-checks-passed` | Where the variant, the diff step and the `@minimal` job go; required-check list must include them |
| `scripts/ci-change-scope.mjs`, `tests/ci-change-scope.test.mjs` | `thin` runs skip the variant; add tests |
| `docker-compose.test.yml`, `backend/.env.test` | Base the minimal overlay on the test stack; empty every optional env; `TestProvider` is the only AI provider (`when@test` in `services.yaml`) |
| `frontend/tests/e2e/playwright.config.ts`, `docs/E2E_TESTING.md` | Tag convention (`@ci`, `@crossbrowser`, `@ollama`); add `@minimal` |
| `backend/src/Seed/BConfigSeeder.php`, `backend/migrations/` (a recent `addSql`-only migration) | Flag default flip; Galera rules (no `Schema` API) |
| `docs/DEVELOPMENT.md`, `docs/DEPLOYMENT*.md` or platform docs pointer | Document the module concept for admins |

---

## 2. Work

### FM18 — `minimal` backend variant

`backend` job matrix `variant: [full, minimal]`. `minimal` uses
`docker-compose.minimal.yml` (overlay: all optional env `''`), runs
`lint:container`, PHPUnit, `app:modules:list --assert-none-configured`, and
dumps `openapi.json`. Artefact uploaded for FM19. Skipped when change scope is
`thin`. Runtime budget ≤ +4 min on heavy runs (measure; if over, run PHPUnit
only for `tests/Module`, `tests/Controller`, `tests/Architecture` in `minimal`
and keep the full suite in `full`).

### FM19 — OpenAPI byte-diff

`frontend-build` downloads both dumps and fails on any difference
(`cmp`), with the diff printed. This is the C2 test.

### FM20 — `@minimal` E2E job

≤ 8 specs on the minimal stack: login, chat with the test provider, upload a
text file, Feature status shows 12 "not configured" rows, gated route returns
the 404 body, `ConfigView` has no Higgsfield card, runtime config `modules`
shape, self-aware chat says Tika is absent. One chromium job, part of
`all-checks-passed`.

### FM21 — Flip gates on, one module per PR

Order: `tika`, `docling`, `office_convert`, `searxng`, `piper_tts`, `local_ai`,
`higgsfield`, `google_ai`, `thehive`, `stripe_billing`, `mobile_iap`,
`whatsapp`. Each PR: seeder default `1` for that flag; `STATUS.md` row records
the E2E path (spec name) and the `minimal`/`full` run URLs. Existing installs:
proposal is **no migration** (admins turn gates on from System config, where
the flag appears with its description); if the owner prefers upgrades to gate
too, one `UPDATE BCONFIG … WHERE BGROUP='MODULES'` migration per batch,
`addSql` only.

### FM22 — Docs

`docs/DEVELOPMENT.md` (modules, `app:modules:list`, how to add a module in one
class — the compute B1 template), admin docs section in `synaplan-docs`
("Not configured — how to enable"), `AGENTS.md` one bullet under Backend Rules:
*"Optional features are `FeatureModule`s — never add a bare `isEnabled()` +
status block again."*

---

## 3. Invariants exercised

| # | How |
| - | --- |
| C1 | `full` variant identical to today's backend job |
| C2 | FM19 byte-diff on every heavy run |
| C3 | Characterization tests run in both variants |
| C4/C5 | FM20 proves 404 on `minimal`; `@ci` on `full` proves none |
| C9 | `.github/**`, `docker-compose*.yml`, `docs/**` are no-app-impact; FM21 seeder changes are `backend-only` |

---

## 4. Exit criteria / demo

1. A heavy CI run shows `Backend (full)`, `Backend (minimal)`, `E2E (chromium minimal)` green and the OpenAPI diff step "identical".
2. A deliberately broken PR (a module gating a configured route) fails `@ci` on `full`; a PR that forgets a module's descriptor fails `ModuleOwnershipTest` in both.
3. All 12 gates on for new installs; `STATUS.md` has 12 rows with proof links.
4. Fresh install without optional env: image ≥ 250 MB smaller than pre-S1; `app:modules:list` 12 × not configured; every gated route 404 with the uniform body.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| FM18 | `ci: add minimal-configuration backend variant that asserts no module is configured` | noAppImpact | FM11, FM12 |
| FM19 | `ci: fail when the OpenAPI spec differs between minimal and full configurations` | noAppImpact | FM18 |
| FM20 | `test(e2e): add @minimal suite against the minimal-configuration stack` | noAppImpact | FM15, FM16, FM18 |
| FM21 | `feat(backend): enable MODULES.GATE_<id> by default for new installs` (× 12) | backend-only | FM19, FM20 |
| FM22 | `docs: document feature modules for developers and admins` | noAppImpact | FM21 |

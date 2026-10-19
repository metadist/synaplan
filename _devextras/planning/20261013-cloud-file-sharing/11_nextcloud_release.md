# P1 — the Nextcloud release, then a break

Plan of record: [`00_master_plan.md`](./00_master_plan.md) (rows 42–49). This
file is the entry point for everyone working in P1: it orders the P1 steps
into four releases, sets the engineering standard, names the test stacks and
the regression guards, and ends P1 with a break. Briefs stay in
[`08`](./08_agent_briefs.md) (CF1.3a–d are in §8 here), tests in
[`09`](./09_functional_tests.md), stacks in [`10`](./10_local_environments.md).
Where an older file disagrees with this one, this one wins and the older file
is a bug.

| | |
| - | - |
| **Scope** | The Synaplan core and the Nextcloud app (`synaplan_integration`) through CF0–CF5: the whole Nextcloud column of `00` §5, with contract v1 frozen (row 44) |
| **Ends with** | A Synaplan release and Nextcloud app **2.0.0** in the App Store, `cloud_files` switched on (row 46) — then the **break** (§1) |
| **Not in P1** | OpenCloud and ownCloud.online app work (P2, P3), CF2.11, CF6 — §7 |
| **Checked** | 2026-10-19 against `synaplan` `297d0cc67` (after 5.5.0), `synaplan-nextcloud` `a80e2d8` (1.6.0), `synaplan-opencloud` `5cc1351`, `synaplan-charts` `7c919d1`: D1–D3 and D18–D20 still hold; D21 is new (`01` §4) |

---

## 1. The release, then the break

P1 ships in four milestones. Each one is a Synaplan release plus a Nextcloud
app release, so every milestone is a safe place to stop and nothing waits
months for one big release. M4 is the P1 exit: **the Nextcloud release**.

After M4 the track **pauses** (row 48):

- **Continues:** Nextcloud patch releases (`fix:` only), Renovate updates, the
  compatibility check after every Synaplan release (§5 item 4), and core
  fixes users report — additive, with a dated STATUS decision and the
  Nextcloud regression set (C22).
- **Stops:** OpenCloud and ownCloud.online app work, any change to contract
  v1, CF6.
- **Ends:** with a dated STATUS decision "P2 go" by the product owner. It
  re-pins OpenCloud (`10` §3.3) and re-reads the CF-S1 and CF-S3 answers
  before the first P2 brief starts. P2 then gets a file like this one.

---

## 2. Milestones

| | Ships | Core (`synaplan`) | Nextcloud app | Before or beside | Exit (`09`) |
| - | ----- | ----------------- | ------------- | ---------------- | ----------- |
| **M1 — works, tested, releasable** | Synaplan release · Nextcloud 1.6.1 (fast lane) and 1.7.0 | CF0.1–CF0.6 | CF1.3a–d, CF1.4-nc | E-2–E-4, E-6 (E-1 done); walks CF0.7 | §1; §2 Nextcloud rows |
| **M2 — one identity** | Synaplan release · Nextcloud 1.8.0 | CF2.1–CF2.5, CF2.9 | CF2.10; CF2.6 if CF-S2 says yes | E-9; spikes CF-S2, CF-S5 (from M1, once E-2 is green) | §3 |
| **M3 — files both ways, searchable state** | Synaplan release · Nextcloud 1.9.0 · chart value `cloudFiles` | CF3.1, CF3.2, CF3.6, CF3.7, CF4.1, CF4.2 | CF3.3, CF3.8, CF4.3 | spikes CF-S1, CF-S3, CF-S4 answered before CF3.1 merges | §4, §5 |
| **M4 — watched folders, contract v1** | Synaplan release · **Nextcloud 2.0.0** | CF5.1, CF5.2, CF5.7, CF5.8, CF5.3, CF5.10 | CF5.4, CF5.9-nc, CF5.10 (vendored fixtures) | — | §6, §8.1 |

Order inside a milestone: stacks → core → app → walks → release (`08` §1).

Two fast lanes in M1, because users are affected today:

- **CF0.1 (D1).** Linked and provisioned Nextcloud users get `403
  insufficient_scope` on summarize, translate, chat and media (re-checked
  2026-10-19: `ApiKeyScope::requiredScopesForPath()` denies
  `/api/v1/summary/*`, `/api/v1/media/*` and the memory check, and `/v1/*`
  needs `desktop:messages`). CF0.1 merges on `ApiKeyScopeTest` plus a `curl`
  of the four paths with a minted `chat files rag` key on the dev stack, and
  goes into the next Synaplan release; `cf0-scopes.sh` and the walks follow
  in CF0.7.
- **CF1.3a (D21).** The app declares Nextcloud 30–34, so the App Store does
  not offer it to Nextcloud 35, which is `nextcloud:latest` since 2026-09-15.
  It ships alone as 1.6.1, before CI and the release workflow exist: its gate
  is the CF1.3a acceptance plus the upgrade from 1.6.0 on Nextcloud 33
  (§5 item 6), and it is packaged with `make appstore` as 1.6.0 was.

**Release gate, every milestone:** (1) the milestone's `09` sections green on
Nextcloud and, from M2, on the reference client in both profiles; (2) the
guards of §5 green; (3) the checklist of §6 done; (4) dated STATUS entries for
the walks and the release. A red item blocks the release, not the work on the
next milestone.

---

## 3. Engineering standard (row 49)

Dominik set it in `synaplan-opencloud` (code) and `synaplan-charts`
(deployment). The Nextcloud app adopts it in CF1.3b and keeps it; the core
keeps its own gate (`AGENTS.md`).

### 3.1 Code — from `synaplan-opencloud`

| Rule | `synaplan-opencloud` | `synaplan-nextcloud` |
| ---- | -------------------- | -------------------- |
| One way in | `Makefile` targets; `AGENTS.md` says to prefer them over raw tools | `make lint`, `test`, `typecheck`, `test-unit-js`, `test-e2e`, `ci-local` (every CI job but e2e), `dev-up`, `dev-reset`, `dev-occ` |
| One workflow, one gate | `.github/workflows/ci.yml`: frontend, backend, docker-build, e2e, docker-push → **All Checks Passed** (`needs` every job, `if: always()`, red on `failure` or `cancelled`) | `ci.yml`: **php** (php-cs-fixer, PHPStan, PHPUnit with `nextcloud/ocp` `dev-stable33` and `dev-stable35`), **frontend** (eslint, prettier, vue-tsc, Vitest, build), **e2e** (`nextcloud:33-apache` and `nextcloud:35-apache`), **package** (unsigned tarball as artifact) → **All Checks Passed**, required on `main` |
| Actions pinned | by commit SHA, version as a comment | same |
| Synaplan pinned in one place | `SYNAPLAN_REF` (git SHA) and `SYNAPLAN_IMAGE_DIGEST` in `ci.yml`; two Renovate custom managers bump both in one "Synaplan" PR | same two lines, same managers |
| Contract from the pinned image | `backend/scripts/generate-synaplan-client.sh` dumps the OpenAPI spec from the pinned image (`nelmio:apidoc:dump`) or a live dev container; `oapi-codegen` generates the client; neither is committed | `scripts/synaplan-openapi.sh` with the same two modes. The PHP client stays hand-written: Nextcloud apps call out through `IClientService`, and a generated client would add runtime dependencies to the tarball. Instead `tests/Contract/SynaplanContractTest.php` runs every public method of `SynaplanClient`, `PlatformLinkService` and `UserAccountService` against a recording `IClientService`, takes the URL and body `ChatController` hands to `SseProxyResponse` (raw curl, needed for streaming), and asserts that each method and path exists in the dumped spec and that each call sends its required body fields. A call the pinned Synaplan lacks fails the build, not a user |
| E2E on the real stack | the Synaplan test stack from the pinned ref (`docker-compose.test.yml` + `docker-compose.test.ci.yml`, `--profile oidc --profile integration`), the app stack with `docker-compose.ci.yml`, Playwright | the same Synaplan test stack (API `:8001`, Keycloak `:8444`); Nextcloud and MariaDB from `docker-compose.ci.yml` with `NC_IMAGE`; Playwright in `tests/e2e/` |
| Renovate | `renovate.json5`: `config:best-practices` (digests pinned), groups, majors separate and never automerged | same shape; groups Nextcloud (`@nextcloud/**`, `nextcloud/ocp`), QA tools, Docker images, Synaplan |
| Short `AGENTS.md` | dev workflow, E2E, prerequisites, tool usage, commits | the same five headings for this app; the house rules of `synaplan/AGENTS.md` by reference |
| Release from a tag, built by CI | `v*` → image to GHCR after e2e | `v*` on a `main` commit with green CI → signed tarball → GitHub release → App Store (CF1.3d) |

### 3.2 Deployment — from `synaplan-charts`

There, every version is pinned and bumped by Renovate, environments are code
(`helmfile/environments/{default,standalone,opendesk}`), CI renders every
environment and deploys it into a throwaway k3d cluster with a Playwright
suite, generated docs are never edited by hand, and secrets derive from
`MASTER_PASSWORD` instead of living in values. For P1:

1. **A core switch is a chart value in the same milestone.**
   `features.platformLinks` (`FEATURE_PLATFORM_LINKS_ENABLED`) exists. M3 adds
   `features.cloudFiles` → `CLOUD_FILES_ENABLED` with CF4.1: an entry in
   `synaplan.featurePinMap`, the `values.yaml` comment, `make docs`, a case in
   `charts/synaplan/tests/env_test.yaml`, `make all` green.
2. **Organisation login in openDesk** (only if CF-S2 says yes): the
   token-exchange permission for openDesk's Nextcloud client goes into
   `helmfile/opendesk/` beside `synaplan-client.yaml.gotmpl`, rendered by
   `make check`. No secret in a values file.
3. **Each release names its chart.** The chart's `appVersion` (5.4.1 today)
   follows the milestone's Synaplan release; the STATUS release entry names
   the chart version.

### 3.3 Rules that keep the code small

- **One brief, one PR.** Above about 600 changed lines, not counting
  generated files, locales and fixtures, the PR is split.
- **A fix starts with a failing test.** D1's PR starts with the scope test
  that fails without the fix.
- **No code ahead of its use.** No listener, field or flag before the step
  that uses it: `UserChangedListener` moves from CF1.3 to CF2.10, where it
  calls `lifecycle`.
- **Split before you grow.** A brief that changes a file over 800 lines first
  splits it in a separate commit with no behaviour change, the journeys of §5
  green before and after: `AdminSettings.vue` (1 547 lines) in CF2.10,
  `ResearchChat.vue` (1 311) in the first brief that touches it. New files stay
  under 400 lines.
- **Ratchets only move up.** The PHPStan level in `phpstan.neon` (CF1.3b) is
  the highest that passes after fixing real findings, without a baseline
  file; no later PR lowers it.
- **Nextcloud idioms.** PHP attributes instead of docblock annotations;
  `IClientService`, `ICrypto`, `IJobList`, `IL10N`; `declare(strict_types=1)`,
  `final`, typed properties; `<script setup lang="ts">` with `@nextcloud/vue`;
  every string through `t()` / `n()` in five locales.
- **Nothing generated is committed**: not the spec, not `js/`, not a tarball.

---

## 4. Test stacks

| Stack | Start | Version | Ready when | Used in P1 for |
| ----- | ----- | ------- | ---------- | -------------- |
| Synaplan + Keycloak | `docker compose --profile oidc up -d` in `synaplan` | `main` | `env-check.sh` groups core and oidc | everything |
| Nextcloud (E-2) | `make dev-up` in `synaplan-nextcloud`: MariaDB, `cron`, `user_oidc`, `ada` / `bob` / `carol` | `nextcloud:35-apache`; the floor with `NC_IMAGE=nextcloud:33-apache make dev-reset dev-up` | `10` §3.2 | every journey; CF-S2, CF-S5 |
| OpenCloud (E-4) | `make docker-up` in `synaplan-opencloud` | `opencloud-rolling:8.1.0`, the current rolling release (2026-10-05) | `10` §3.3 | CF-S1, CF-S3; the compatibility check |
| Reference client (E-9) | `_devextras/testing/cloud-apps/fixture-app/compose.yml` | — | `env-check.sh` group fixture | core briefs from CF2.1 |

Ready means `env-check.sh --cloud nextcloud --oidc` and
`env-check.sh --cloud opencloud` print no `FAIL`.

**State on 2026-10-19:** E-1 to E-4 are done and `env-check.sh` exists (the
rest of E-6 follows). `env-check.sh --cloud nextcloud,opencloud --oidc`: 45
passed, one warning (Nextcloud 35 is above the app's `max-version` until
CF1.3a), nothing failed. Keycloak's `ada` signs in to Nextcloud 35 and 33 as the
local `ada`; OpenCloud's own Playwright suite passes 10 of 10 against the local
Synaplan. Details and findings: STATUS review log, 2026-10-19 (environments).

---

## 5. Regression guards

The full set per sprint is `09` §7. These seven are the ones no release skips:

1. **Pin today's behaviour first.** CF1.3b adds Playwright journeys for what
   1.6.0 does — connect (J-NC-1), provision, summarize, translate, chat about
   a file, an image saved to `Synaplan/Images`, knowledge add, status and
   remove — on Nextcloud 33 and 35, before any app feature PR. They stay in
   CI.
2. **Pin access rules.** `ControllerAccessTest` (CF1.3a) lists every routed
   controller method with its attributes (`NoAdminRequired`,
   `NoCSRFRequired`, `PublicPage`, rate limits) and checks them by
   reflection; `ApiKeyScopeTest` does the same for the core's scope map (C3).
3. **Core changes are additive.** Optional fields; new routes behind
   `PLATFORM_LINKS.ENABLED` or `cloud_files`; capabilities both ways (R5,
   R11, C21); contract fixtures and the reference client changed in the same
   PR (DoD-11).
4. **Old app, new core.** After every Synaplan release in P1, the previous
   Nextcloud release (from the App Store), OpenCloud v1.0.0 and
   ownCloud.online 1.0.0 pass their rows of `09` §1 (1–3, 8, 9).
5. **New app, old core.** Before a Nextcloud release, its e2e suite runs once
   against the previous Synaplan release image: no new surface, no error
   (J-CF-12, C1).
6. **Upgrade, not only install.** Before a Nextcloud release: install the
   previous release on Nextcloud 35, link Ada, provision Carol, upgrade, run
   the journeys. Encrypted keys (CF1.3c), adoption (CF2.10) and settings
   survive.
7. **Neighbours untouched.** Synamail sign-in after every change to platform
   links (C2); the `cf*-flag-off.sh` scripts with the flags off (U11).

---

## 6. Release checklist

**Synaplan:** `make ci-local && make test-e2e` on `main`; `Cut Release Tag`
(a `feat` commit since the last tag raises the minor version); release CI
green; chart values for new switches merged and `appVersion` bumped.

**Nextcloud app:**

1. `appinfo/info.xml` `<version>`, `package.json` `version` and a
   `CHANGELOG.md` section agree; the range is `min-version="33"
   max-version="35"` (row 47).
2. CI green on the release commit on `main`; tag `vX.Y.Z`; the release
   workflow (CF1.3d) builds, signs and publishes; the App Store offers the
   version on Nextcloud 33 and 35.
3. §5 items 4–6 done on the release candidate.
4. The Nextcloud page in `synaplan-docs` and `docs/HOSTING_GUIDE.md` say what
   changed.
5. STATUS release entry: versions, chart version, links to the walk entries.

**M4 adds:** the STATUS decision "contract v1 frozen at <commit>" (CF5.10);
`cloud_files` on for new installs and web.synaplan.com with the activation
migration (row 46); the chart documents the new `features.cloudFiles` default;
then the break entry (§1).

---

## 7. Not in P1

CF1.1, CF1.2, CF1.4-oc, CF1.4-oco, CF2.7, CF2.8, CF2.11, CF3.4, CF3.5, CF3.9,
CF3.10, CF4.4, CF4.5, CF5.5, CF5.6, CF5.9-oc, CF5.9-oco, E-5 and CF6. CF1.5
only as row 34 allows. The spikes CF-S1, CF-S3 and CF-S4 run in P1 because
their answers can change the core before the freeze; they end in STATUS
entries, never in merged code.

---

## 8. Briefs CF1.3a–d (replace CF1.3 of `08` §4)

CF1.3 mixed CI, a security change and a listener nothing used yet. Split, each
PR has one purpose. CF1.4-nc follows CF1.3c.

### CF1.3a — Nextcloud 33 to 35 (D21)

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` (P1, M1 fast lane) · app release 1.6.1 · `fix(app)` |
| Branch | `fix/cf1-3a-nextcloud-35` |
| Depends on | E-2 on both images; row 47 agreed |
| Build | 1. `appinfo/info.xml`: `<nextcloud min-version="33" max-version="35"/>`. 2. The six controllers that rely only on docblock annotations (`@NoAdminRequired`, `@NoCSRFRequired`, …) get the same rules as PHP attributes, method by method; `LinkController` drops its duplicate docblocks. 3. `composer.json`: `nextcloud/ocp` from `dev-master` to `dev-stable33`. 4. `CHANGELOG.md` section 1.6.1 |
| Tests | `ControllerAccessTest` (§5 item 2) with the table taken from today's annotations, asserting attributes — red before step 2, green after; the existing suite |
| Acceptance | J-NC-1…3 and Summarize walked on `nextcloud:35-apache` and `nextcloud:33-apache`; dated STATUS entries |
| Don't | change behaviour; change the PHP floor (8.2) |

### CF1.3b — Engineering baseline (no behaviour change)

| | |
| - | - |
| Repo · type | `synaplan-nextcloud` (P1, M1) · `chore(ci)` and `test(e2e)`; no release of its own |
| Branch | `chore/cf1-3b-engineering-baseline` |
| Depends on | CF1.3a; **ask first (A2):** the dev dependencies `phpstan/phpstan`, `vitest`, `@vue/test-utils`, `happy-dom`, `vue-tsc` and `@playwright/test` (at the version `synaplan/frontend` pins) |
| Touches | `.github/workflows/ci.yml`, `renovate.json5`, `AGENTS.md`, `Makefile`, `scripts/synaplan-openapi.sh`, `tests/Contract/`, `phpstan.neon`, `package.json` (scripts `check:types`, `test:unit`, `test:e2e`), `vitest.config.ts`, `tests/unit/`, `playwright.config.ts`, `tests/e2e/`, `docker-compose.ci.yml`, `README.md` (Development) |
| Build | §3.1 row by row; the journeys of §5 item 1; Vitest specs for the pure helpers in `src/` (MIME filters, kind folders) — later briefs add specs for what they touch |
| Acceptance | CI green on the PR, e2e on both images; `make ci-local` green; after the merge the owner makes **All Checks Passed** required on `main` and Renovate opens its onboarding PR |

### CF1.3c — Per-user keys encrypted (D4)

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` (P1, M1) · app release 1.7.0 · `feat(app)` |
| Branch | `feat/cf1-3c-encrypted-keys` |
| Depends on | CF1.3b |
| Build | The user preference `synaplan_user_api_key` is stored as `enc:` + `ICrypto::encrypt()`. A value without the prefix is read once as plaintext and saved encrypted. A value that does not decrypt counts as no key — the person sees the Connect card — and writes one log line without the value |
| Tests | `KeyStorageTest` (encrypt, plaintext migration, undecryptable value); `cf1-keys.sh` |
| Acceptance | `09` §2 L3 row 6 on Nextcloud 35: a link made with 1.6.x keeps working after the upgrade without connecting again (C2) |

### CF1.3d — Release from CI

| | |
| - | - |
| Repo · type | `synaplan-nextcloud` (P1, M1) · `ci(release)` |
| Branch | `ci/cf1-3d-release-workflow` |
| Depends on | CF1.3b; the owner stores two repository secrets (A6): the App Store signing key (today in `.local/`, never committed) and an App Store API token |
| Build | `.github/workflows/release.yml` on tag `v*`: refuse unless the tagged commit is on `main` with a green CI run, `info.xml` `<version>` equals the tag and `CHANGELOG.md` has its section; `make package`; sign as `make sign` does; attach tarball and signature to the GitHub release; publish through the App Store releases API. A pre-release tag (`v*-rc.*`) stops before the App Store |
| Acceptance | a release-candidate tag produces a draft GitHub release whose signature verifies against the app certificate; 1.7.0 is the first release published this way |

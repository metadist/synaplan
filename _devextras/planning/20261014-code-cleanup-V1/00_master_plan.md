# Code cleanup V1 — enforce the layers, empty the Service root

**Status:** Draft 2026-10-14. Tick §0 before the first PR.
**Goal:** A developer who opens `backend/src` sees an order that holds, and
CI keeps it that way. Two levers, both finishable in digestible PRs:
(1) an architecture gate with a baseline, (4) the 73 loose files in
`src/Service/` sorted into their domain folders.
**Class:** `backend/**` is `backend-only`; `_devextras/**`, `.github/**`
and `Makefile` are `no-app-impact`. Nothing here ships to the apps.
**Out of scope here:** splitting the god classes, `docs/ARCHITECTURE.md`
and `make metrics` are **V2**
([`../20261014-code-cleanup-V2/`](../20261014-code-cleanup-V2/00_master_plan.md));
domain-first folder layout, the `AI` ↔ `Service` split, domain-to-domain
rules, `Plugin\Sdk`, PHPStan 6+ and the test-tree mirror are **V3
candidates** (§8). "V3" below always means that unscheduled list.

Files in this track:

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Measured baseline, decisions, layer model, step table, gates |
| `01_sprints.md` | Per-step goal, exact procedure, exit criteria |
| `02_service_root_map.md` | All 73 loose files → destination, reason, risk note |
| `STATUS.md` | Step log |

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | Architecture gate tool: **deptrac** (`deptrac/deptrac` ^4.7, PHP ^8.2, PHPStan ^2.0 — matches our PHPStan 2.3.1). New dev dependency + new CI step in the Backend job. Alternative with zero CI change: `phpat/phpat` (rules run inside the existing `composer phpstan`, violations land in `phpstan-baseline.neon`; no dependency graph). | deptrac | ✅ 2026-10-14 — deptrac. C1 starts after the next merge to `main`, on explicit go. |
| 2 | Baseline policy: the first run writes `backend/deptrac.baseline.yaml`; CI fails only on **new** violations. The baseline can only shrink (same rule as `localeParityBaseline.json` and the dated allow-lists in `tests/Architecture/`). | Locked | |
| 3 | V1 layer rules are the ones already (nearly) true today — see §3. Domain-to-domain rules inside `Service/` are V3 (§8), after the folders are sorted. | Locked | |
| 4 | The four classes external plugins import (`PluginDataService`, `ModelConfigService`, `RateLimitService`, `OidcUserService`) **stay in `src/Service/` root** in V1. They are the de-facto plugin SDK; moving them breaks Synaform, synafastbill, Synamail and synaScriber. End state of the root: *only* plugin-facing classes, guarded by a test. | Locked | |
| 5 | Every move PR keeps the class name, only the namespace changes (`App\Service\X` → `App\Service\<Domain>\X`). No behaviour change, no method rename, no file split in the same PR. | Locked | |
| 6 | Tests stay where they are (`tests/Unit/Service/`, `tests/Service/`, `tests/Unit/` do not mirror `src/` today); only their `use` lines change. Mirroring the test tree is V3 (§8). | Locked | |
| 7 | `AccountLanguage` (used by `Entity\User` and `DTO\RegisterRequest`) moves to `App\Model\`, not to a Service subfolder, so the Entity and Shared layers stop depending on Service. | Approved | |
| 8 | New Service folders created in V1: `Billing/`, `Feedback/`, `Notification/`. Nothing else new. | Approved | |

---

## 1. Measured baseline (2026-10-14, `main`)

| Metric | Value |
| ------ | ----- |
| PHP files / LOC in `backend/src` | 1,615 / ~319k |
| Test files | 963 |
| PHPStan | level 5, `phpstan-baseline.neon` with 96 entries |
| `src/Service/` | 856 files, 68 subfolders, **73 loose files in the root** |
| Largest classes | `ChatHandler` 4,607 lines / 39 ctor deps · `StreamController` 4,430 / 37 · `ConfigController` 2,572 / **51** |
| Controllers over 800 lines | 19 |
| Architecture tooling | none (two hand-written tests in `tests/Architecture/`) |

Layer dependencies measured with `rg` on `use App\…` imports:

| Edge | Files | Verdict |
| ---- | ----- | ------- |
| anything outside `Controller/` → `Controller\` | **0** | rule already true |
| `Service/`, `AI/` → `Controller\` | 0 | rule already true |
| `Repository/` → `Service\` | 4 (`Iam\Permission`, `Iam\SharedResourceIds`, `SmartSearch\Index\*`) | baseline; these are value objects that belong in a shared layer (V3) |
| `Repository/` → `AI\` | 1 (`ModelHealthRepository`) | baseline |
| `Entity/` → `Service\` | 2 (`User` → `AccountLanguage`, `McpServerConfig` → `EncryptionService`, `Mcp\McpOAuthState`) | `AccountLanguage` fixed by C5; the other baselined |
| `Entity/` → `AI\` | 1 (`ModelHealth` → `AI\Health\ModelHealthState`, an enum used as a column type) | baseline; moving the enum to `App\Model\` is a V3 candidate |
| `Entity/` → `Repository\` | 58 | allowed — Doctrine `repositoryClass:` attribute |
| `DTO/` → `Service\` | 1 (`RegisterRequest` → `AccountLanguage`) | fixed by C5 |
| `AI/` ↔ `Service/` | 32 / 71 | cycle; both in one *Application* layer in V1, split in V3 |

Expected baseline after C1: **9 files** (4 + 1 + 2 + 1 + 1); C5 removes
the two `AccountLanguage` entries, leaving 7.
| `Controller/` → `Repository\` | 53 | allowed in V1 (reads through repositories are house style) |

Blast radius of the 69 moves: 806 `use`/FQCN occurrences in 167 `src/`
files, 223 `tests/` files and 24 lines of `config/services.yaml`. No quoted
string FQCNs, no persisted class names (Messenger messages live in
`src/Message/`, none of the loose classes is serialized).

---

## 2. What "chaotic" meant, in one sentence each

1. `src/Service/` has no legend: 856 files, folder sizes from 60 to 1, and 73
   files in the root with no visible reason for being there.
2. The biggest classes carry 37–51 constructor dependencies.
3. Nothing enforces the layering, so the order that exists is convention.

V1 fixes 1 and 3. Item 2 is V2 (the god-class track, §8).

---

## 3. Layer model V1

```
Entrypoint   App\Controller\*  App\Command\*
    │ may use ↓
Application  App\Service\*  App\AI\*  App\Mcp\*  App\Realtime\*  App\Plug\*
             App\Prompt\*  App\Module\*  App\Runtime\*  App\Message\*
             App\MessageHandler\*  App\EventSubscriber\*  App\EventListener\*
             App\Security\*  App\Seed\*  App\Observability\*  App\Monolog\*
             App\Doctrine\*  App\Bundle\*  App\DataFixtures\*
    │ may use ↓
Repository   App\Repository\*
    │ may use ↓
Entity       App\Entity\*            (+ Repository, for repositoryClass:)
    │ may use ↓
Shared       App\DTO\*  App\Model\*  App\Attribute\*
```

Rules (everything not listed is a violation):

| Layer | May depend on |
| ----- | ------------- |
| Entrypoint | Application, Repository, Entity, Shared |
| Application | Application, Repository, Entity, Shared |
| Repository | Repository, Entity, Shared |
| Entity | Entity, Repository, Shared |
| Shared | Shared |

Three facts make this cheap: Entrypoint is a leaf (0 violations today),
Repository, Entity and DTO have 9 known violating files (baselined, 2 of
them removed by C5), and the `AI` ↔ `Service` cycle is hidden inside one
layer until V3.

**Service root rule** (`tests/Architecture/ServiceRootTest.php`, step C2):
a file directly in `src/Service/` is part of the plugin contract. The test
holds an allow-list; the four SDK classes are permanent, the other 69 are
listed with the PR that removes them. The list only shrinks.

---

## 4. Step table

Each step is one PR, mergeable alone, green on `make ci-local`.

| Step | Commit title | Files | Class | Depends |
| ---- | ------------ | ----- | ----- | ------- |
| C0 | `docs(planning): code cleanup V1 master plan` | this folder | no-app-impact | — |
| C1 | `chore(backend): add deptrac layer rules with baseline` | `composer.json`, `deptrac.yaml`, `deptrac.baseline.yaml`, `backend/Makefile`, root `Makefile`, `ci.yml` | backend-only + no-app-impact | C0, §0 #1 approved |
| C2 | `test(backend): guard the Service root as plugin contract` | `tests/Architecture/ServiceRootTest.php` | backend-only | C0 |
| C3 | `refactor(backend): move widget services into Service/Widget` | 10 | backend-only | C2 |
| C4 | `refactor(backend): move email, WhatsApp and notification services` | 10 | backend-only | C2 |
| C5 | `refactor(backend): move auth and identity services` | 13 | backend-only | C2 |
| C6 | `refactor(backend): move billing, usage and IAP services` | 9 | backend-only | C2 |
| C7 | `refactor(backend): move chat, message, memory and feedback services` | 16 | backend-only | C2 |
| C8 | `refactor(backend): move media, speech, prompt and infrastructure services` | 11 | backend-only | C2 |
| C9 | `chore(backend): shrink deptrac baseline after service moves` | `deptrac.baseline.yaml` | backend-only | C3–C8 |

C3–C8 are independent of each other and can run in any order or in
parallel branches; the table order is smallest-risk first. Full map with
the reason for each destination: [`02_service_root_map.md`](./02_service_root_map.md).

---

## 5. First ordering sprint (today)

Half a day, four PRs, everything measurable afterwards:

1. **C0** — this plan (10 min).
2. **C1** — deptrac + baseline + `make -C backend deptrac` in `ci-local`
   and CI (60–90 min including the approval in §0 #1).
3. **C2** — `ServiceRootTest` with the 73-name allow-list (30 min).
4. **C3** — Widget move, the warm-up: 10 files, one domain, two
   `services.yaml` entries, the richest E2E coverage of all groups (60–90
   min with the gate).

After that the backend has a gate that fails on a new layer violation, a
root that can only get emptier, and one domain folder as the worked
example. C4–C8 are then one PR per half-day, in any order; C9 closes.

---

## 6. Gates

Per PR (backend-only, no HTTP contract change):

```bash
make -C backend lint && make -C backend phpstan && make -C backend test
make -C backend deptrac                       # from C1 on
docker compose exec -T backend php bin/console lint:container
docker compose exec -T backend php bin/console cache:clear
```

- `lint:container` is the proof that every renamed FQCN in
  `services.yaml` (explicit definitions, aliases, factories) still resolves.
  A typo there is a prod boot failure, not a test failure.
- `make test-e2e` for **C3** and **C7** (widget, stream and chat paths moved)
  and once more after C9. The others may skip Playwright per AGENTS.md.
- `node scripts/mobile-impact.mjs --base main --head HEAD` must print
  `backend-only` (or `no-app-impact` for C0).
- Filtered runs are diagnostics only. The unfiltered `make` targets are
  the gate.

---

## 7. Non-goals

- No behaviour change, no method or class rename, no file split in any V1
  PR. A reviewer must be able to read every diff as "namespace changed,
  nothing else".
- No new top-level namespace. `App\Model\AccountLanguage` reuses an
  existing one.
- No move of the four plugin-SDK classes. A `Plugin\Sdk` namespace with
  `class_alias` shims and a deprecation note to plugin authors is V3 (§8).
- No test-tree reorganisation.
- No Go, no sidecar extraction. Candidates (stateless file conversion,
  STT, bulk ingestion) are already behind `Module/Sidecar` /
  `Service/Compute` and stay there.

---

## 8. After V1

**V2** — the god classes — has its own track, queued behind this one:
[`../20261014-code-cleanup-V2/00_master_plan.md`](../20261014-code-cleanup-V2/00_master_plan.md)
(size ratchet, characterization snapshots, `ConfigController` →
`ChatHandler` → `StreamController`, `docs/ARCHITECTURE.md`, `make metrics`).

**V3 candidates** — everything below is unscheduled and has no plan file.
Every "V3" reference in this track and in the V2 plan points here.

| Item | Why it waits |
| ---- | ------------ |
| Domain-first layout (`src/Widget/{Controller,Service,Repository}`), one PR per domain | Needs the V1 folders as the unit of move |
| Split `AI` ↔ `Service` into two layers | 32 / 71 cross imports; needs a facade decision |
| Domain-to-domain rules inside `Service/` (X reaches Y only through a named facade) | Needs sorted folders first |
| `Plugin\Sdk` namespace + `class_alias` shims for the four SDK classes | Needs a deprecation window for four external repos |
| PHPStan 5 → 6 → 8 with baseline | One level per sprint |
| Mirror `tests/` to `src/` | Mechanical, after the moves settle |
| Move `Iam\Permission`, `Iam\SharedResourceIds`, `SmartSearch\Index\*` value objects and the `AI\Health\ModelHealthState` enum to Shared so Repository → Service/AI and Entity → AI hit zero | Removes the last baseline entries |

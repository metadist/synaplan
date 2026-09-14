# Regression protection, CI, docs and Confluence re-sync

**Steps `NV22`–`NV23`** plus the test matrix every step in Sprints A–C obeys.
This file is the answer to "how do we know nothing else moved".

---

## 1. Test layers and who owns what

| Layer | Runs in | Locks | Touched by |
| ----- | ------- | ----- | ---------- |
| Vitest `tests/unit/composables/useNavItems.spec.ts` | `make -C frontend test` (CI "Frontend") | Rail shape, Manage groups and order, flag-gated children, Operate children, People path | NV01, NV04, NV06, NV07 |
| Vitest `tests/unit/router/*.spec.ts` (`iamGuards`, `navContext`, new `redirects.spec.ts`) | same | Guards, context classes, in-app redirect targets | NV01, NV02, NV04, NV07, NV15, NV18 |
| Vitest component specs (`ChatsView`, `AiAccountsView`, `MemoriesView`, `PeopleView`, `AdminConfigView`, `operate/*`) | same | Section lists per flag, `?section=` / `?tab=` handling, empty + error states, grid columns | Sprint A + C |
| Vitest i18n (`localeParity`, new `unusedNavKeys`, new `navWording`) | same | Five-locale parity, no dead nav keys, sentence case, banned words, nav = page title | Sprint B |
| PHPUnit (`SystemConfigServiceTest`, `ProviderKeyCatalogTest`) | `make -C backend test` | Managed-by refusal, catalog ↔ config coverage | NV05 |
| Playwright `@ci` desktop (`navigation`, `redirects`, `admin-panel`, `feature-status`, `memories`, new `chats-archive`, `summarize-tool`, `provider-keys`, `nav-journeys`) | `make test-e2e` (CI E2E chromium 1/3–3/3) | Journeys J-NV-1…6, redirects, visible menu entries | every step |
| Playwright `@layout` mobile (`layout.spec.ts`) | `make -C frontend test-e2e-layout` (CI chromium Mobile) | 320 px, 44 px targets, drawer accordion groups, Operate compact | NV02, NV06, NV07, NV08, NV14–NV21 |
| Playwright `visual.spec.ts` | same job | Pixel baselines of key pages | NV21 only (single re-baseline commit) |
| `node scripts/mobile-impact.mjs` | CI "mobile impact" | Every PR classified `ota-candidate` (NV05a, NV22 `backend-only`) | every step |

Rule from AGENTS.md restated: a filtered run is never the gate. Each PR ends
with `make ci-local && make test-e2e` (+ `test-e2e-layout` where the table
says so) — unfiltered.

---

## 2. Selector alias policy

- Test ids are **stable handles**, like the Confluence row ids. A moved
  surface keeps its inner ids (`tab-users`, `item-feature`, `page-chats-incoming`
  → stays on the incoming tab body) so the E2E that exercised the body keeps
  passing.
- New chrome gets new ids from the A6 scheme
  (`operate-page-<page>`, `section-<page>-<id>`, `btn-section-toggle-<id>`,
  `grid-<id>`) and the nav scheme
  (`btn-sidebar-v2-group-<group>`, `link-sidebar-v2-<childKey>`).
- Never assert on translated text where an id exists. Where text *is* the
  assertion (wording pass), assert the new string and the test lives next to
  the i18n key it checks.

---

## 3. Redirect matrix (the `X012` rule, ≥ 2 releases)

| From | To | Introduced |
| ---- | -- | ---------- |
| `/admin?tab=users` | `/admin/people` | NV01 |
| `/statistics#chats` | `/chats` | NV02 |
| `/ai/providers/higgsfield` | `/ai/providers?section=higgsfield` | NV04 |
| `/ai/summarizer` | `/?tool=summarize` | NV07 |
| `/tools/doc-summary` | `/?tool=summarize` (row replaced) | NV07 |
| `/admin?tab=<overview\|prompts\|usage\|subscriptions\|moderation\|appServer>` | `/admin?section=<same>` | NV15 |
| `/admin/setup?tab=<models\|extraction\|web-search\|rerank>` | `/admin/setup?section=<same>` | NV18 |

`frontend/tests/e2e/tests/redirects.spec.ts` holds exactly this table; the
unit `tests/unit/router/redirects.spec.ts` (new in NV02) resolves the same
rows through `router.resolve` so a broken redirect fails in Vitest before
Playwright.

Removal of a row is a conscious commit two releases later, with the
Confluence page updated in the same change.

---

## 4. Characterization guard for the menu

NV09 adds `tests/unit/composables/__snapshots__/navTree.json`: the rail and
every child (key, path, groupKey) for four personas — guest, user with all
flags off, user with all flags on, admin with all flags on. A change to
`useNavItems.ts` that alters the tree fails the test until the snapshot is
re-recorded and the diff reviewed (same discipline as
`backend/tests/Characterization/`). Re-record only with
`UPDATE_NAV_SNAPSHOT=1 npx vitest tests/unit/composables/useNavItems.spec.ts`
and paste the diff into the PR.

---

## 5. NV22 — Documentation

**Files:** `docs/FRONTEND_CONVENTIONS.md` ("Operate pages" from Sprint C §1,
"Navigation: one home per concept" paragraph, redirect rule),
`docs/E2E_TESTING.md` (journey specs, redirect matrix, nav snapshot),
`synaplan-docs/docs/administration.md`, `ai-infrastructure.md`,
`people-and-groups.md`, `platform-links.md`, `using-synaplan.md`
(menu paths and screenshots that name moved surfaces: Users, Summarizer,
Memories, Linked platforms vs Platform instances, provider keys).

**Machine instructions**

1. `rg -n "Feature Status|Model Status|Summarizer|Configure Connections|List Of All|Linked platforms|statistics#chats|\?tab=users"` across both repos' `docs/`; update every hit to the new label / path.
2. Screenshots: only replace those that show a changed Operate page; name the source route in the alt text.
3. `synaplan-docs` PR references the synaplan PR numbers of NV01–NV21.

**Commit:** `docs: navigation and administration pages follow the new tree`

---

## 6. NV23 — Confluence re-sync

After NV22 is merged, publish a **new version** of
*20260914 - Navigation Tree (Status)* (page id `3079143428`, space `syna`):

1. Regenerate the table and the YAML block from the merged `main` (same
   generator approach as version 2; record the commit hash in the header).
2. Apply the handle map from the master plan §2: remove `O004`, `M020`;
   add `W029`, `M021`, `M066`; update `sub` for the Developer & devices
   children; update labels from Sprint B.
3. Replace §6 "Observations" with a short "Resolved on <date>" list that
   names the PRs, and a new observations list for whatever the regeneration
   still shows.
4. Version message: `nav consolidation NV01–NV22 applied`.

Use the Atlassian MCP (`updateConfluencePage`, `contentFormat: html`) — the
same tooling that read version 2. No product code in this step.

---

## 7. Definition of done for the whole plan

1. Every row in the master plan §2 matches the running app.
2. J-NV-1 … J-NV-6 are Playwright tests in `nav-journeys.spec.ts` and pass
   in CI on chromium 1/3–3/3; the Operate rows also pass in chromium Mobile.
3. `redirects.spec.ts` = §3 of this file.
4. `navTree.json` snapshot committed and reviewed.
5. `localeParity` ledger smaller than before Sprint B.
6. `docs/FRONTEND_CONVENTIONS.md` carries the Operate page rule.
7. Confluence page version 3 published.

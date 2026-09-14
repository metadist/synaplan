# Navigation consolidation — duplicates, reachability, wording, Operate stacked UI

**Status:** Plan, 2026-09-14. Not started. Coding begins only after the product
owner confirms the decisions in §3.
**Input:** Confluence *20260914 - Navigation Tree (Status)* (115 rows, handles
`W001`…`X013`, generated from `synaplan@af15fdc89`). Its §6 "Observations" is
the cherry-pick list for this plan.
**Binding contracts:** [`../202609_ux_user_flows.md`](../202609_ux_user_flows.md)
(U1–U12, §6 sprint-file contract) and AGENTS.md "Perfect UX & Stability".
**Owner of the surface:** frontend shell (`useNavItems.ts`, `router/index.ts`,
`SidebarV2.vue`, `MobileNav.vue`) plus the Operate pages.
**Class:** every step is `ota-candidate` unless marked `backend-only`.

Files in this folder:

| File | Content |
| ---- | ------- |
| `00_master_plan.md` (this) | Goal, decisions, handle map, step table, gates |
| [`01_sprint_a_duplicates_and_reachability.md`](./01_sprint_a_duplicates_and_reachability.md) | NV01–NV09: one home per concept, Summarizer into chat, Higgsfield reachable, Memories one technique |
| [`02_sprint_b_wording.md`](./02_sprint_b_wording.md) | NV10–NV12: label glitches, sentence case, stale keys, five locales |
| [`03_sprint_c_operate_stacked_ui.md`](./03_sprint_c_operate_stacked_ui.md) | NV13–NV21: the Operate page rule (accordion sections, 2/4-card grids, compact density) applied to every admin page |
| [`04_regression_protection.md`](./04_regression_protection.md) | Test matrix, selector aliases, snapshot policy, Confluence re-sync (NV22–NV23) |

---

## 0. Why

The tree is complete but nobody can *manage* Synaplan from it: the same thing
has two homes (Users, chat archive, usage, linked platforms), provider keys
live in three places, a transitional page (Summarizer) still sits in a menu,
a real page (Higgsfield) sits in no menu, Memories behaves differently per
device, and the Operate pages are long single-column lists that hide the
overview an administrator needs. This plan attacks the shell from the ground
up **without** a visual rebrand and **without** new backend domain models —
the same stance as the 2026-08-28 streamlining sprint that produced the
Work / Manage / Operate rail.

Not in scope (say so, do not drift): assistant-centred widget editor, hoster
console, moving the admin-only Models tabs (`M013`, `M014`) to Operate, new
rail items, any new API except the small `managedBy` hint in NV05.

---

## 1. Principles for this plan

1. **One concept, one home.** A second entry is a redirect or a cross-link
   sentence, never a second page.
2. **Redirect, never 404, for ≥ 2 releases.** Every retired path lands on the
   new home with query/hash preserved (`X012` rule).
3. **Nav from one source.** Only `useNavItems.ts` changes menu shape; the rail
   and the drawer follow. Test ids stay stable (`btn-sidebar-v2-nav-<key>`,
   `link-sidebar-v2-<childKey>`, `btn-sidebar-v2-group-<group>`).
4. **Flag off ⇒ absent** (U11). No greyed entries, no teasers.
5. **Wording is product.** Sentence case, one canonical noun per concept, no
   implementation words in primary copy, all five locales in the same PR.
6. **Operate is dense, stacked, foldable.** Rule set in
   [`03_sprint_c_operate_stacked_ui.md`](./03_sprint_c_operate_stacked_ui.md) §1.
7. **Every step ships with its test** and is green on the full local gate
   (`make ci-local && make test-e2e`) before the PR opens.

---

## 2. Handle map — what happens to every affected row

| Handle | Today | After this plan | Step |
| ------ | ----- | --------------- | ---- |
| `O004` Operate › Users tab | Second home for users, redirects only when IAM groups on | **Removed.** `/admin?tab=users` → `/admin/people` always | NV01 |
| `O032` People › Users | Only when `features.iamGroups` | **Always available**; Groups/Policies/Audit tabs stay flag-gated | NV01 |
| `W002` History sheet "Show all" | → `/statistics#chats` | → `/chats` (new page **All chats**, tab *Incoming* inside) | NV02 |
| `A003` Incoming chats | Own page `/chats/incoming` | Same URL, now a tab of `/chats`; Account entry + red dot unchanged | NV02 |
| `A006` Usage + All chats | Title "Welcome to synaplan!", chat browser below | **My usage** only; `#chats` redirects to `/chats` | NV02, NV10 |
| `O006` Operate › Usage tab | "Usage" | **Usage (all users)** — wording only | NV10 |
| `O035` People › Linked platforms | Same label as `M063` | **Platform instances** + one-sentence cross-link to `M063` | NV03 |
| `M063` Connections › Linked platforms | — | Unchanged label; cross-link sentence to `O035` for admins | NV03 |
| `O019` System configuration › AI Services | Raw key fields for 10+ providers | Key fields **hidden**; status card: helm/env vs UI override + pointer → `O013`; non-key fields stay | NV05 |
| `O013` AI infrastructure › Models & keys | Provider key cards | **The** web editor for instance keys. Helm/env still load with zero UI clicks | NV05 |
| `M015` Higgsfield (no menu entry) | Reachable by URL only | Section of new **`M021` Your AI accounts** (`/ai/providers`); old URL redirects | NV04 |
| `M033` Coding clients › BYO Anthropic key | Inline block | Moves to `M021`; Coding clients keeps a pointer sentence | NV04 |
| `M060` Configure Connections | Under Connections, gated by Saved tasks flag | Renamed **Connected apps**, stays in Connections; **ungated** for every signed-in user (D5) | NV06, NV10 |
| `M061` MCP servers, `M063` Linked platforms | Connections | Stay in **Connections** | NV06 |
| `M062` Desktop, `M064` API keys, `M065` API docs, `M033` Coding clients | Connections / Automations | New group **Developer & devices** | NV06 |
| `M020` Summarizer page | Manage › Assistants child + Tools link | **Retired.** Tools › *Summarize a document* runs in the chat; `/ai/summarizer`, `/tools/doc-summary` → `/?tool=summarize` | NV07 |
| `W007` Tools pill | "Summarizer → opens the tool" | "Summarize a document" (in-chat) | NV07 |
| `A005` Memories | Dialog on desktop, page on mobile | **Page** everywhere; chat badge deep-links `/memories?highlight=<id>`; dialog deleted | NV08 |
| `M011`–`M014` Models tabs | "Model Choice · List Of All · Vector Runs · Edit Models" | "Default models · All models · Embedding runs · Model catalog" | NV10 |
| `M040` Inbound | "Inbound" (DE "Eingehend") | "Channels overview" | NV10 |
| `O010` Feature Status / System Status | Two names for one page | "System status" everywhere | NV10 |
| `O011` Model Status | — | "Model health" everywhere | NV10 |
| `O002`–`O009` Operate dashboard tabs | Tabs | Stacked accordion sections; `?tab=` → `?section=` | NV15 |
| `O010`, `O011` | One card per row | 4-card info grids with fold-out details | NV16, NV17 |
| `O012`–`O016` AI infrastructure | 4 tabs | 4 accordion sections; provider cards 2-up | NV18 |
| `O017`–`O030` System configuration | Tab groups, long field lists | Tab groups kept; sections foldable; fields 2-up | NV19 |
| `O031`–`O036` People | Tabs | Tabs kept (lists of different kinds); compact density | NV20 |
| `X013` `/testv` | Dev route, no auth | Unchanged (out of scope; note only) | — |

New handles for the Confluence re-sync (NV23): `W029` All chats page,
`M021` Your AI accounts, `M066` group Developer & devices.

---

## 3. Decisions — locked 2026-09-14 (interview)

| # | Locked choice | What we will build |
| - | ------------- | ------------------ |
| D1 | New `/chats` page | **All chats** with tabs *All* / *Incoming*. Incoming keeps `/chats/incoming` and the Account badge. Usage is usage only. |
| D2 | Cards + env (Helm-first) | **Models & keys** is the only *web* editor. Helm / env / chart secrets stay the source of truth and work with **zero UI clicks**. System configuration hides duplicate password fields and shows a status card: set from the environment / Helm, change it in the chart, or override under Models & keys. |
| D3 | Page everywhere | `/memories`; badge → `/memories?highlight=`; `MemoriesDialog.vue` deleted. |
| D4 | Your AI accounts | `/ai/providers` under Manage › Assistants: Higgsfield + BYO Anthropic. Old Higgsfield URL redirects. |
| D5 | **Ungate** (flipped from the plan default) | **Connected apps** is visible to every signed-in user, even when Saved tasks is off. |
| D6 | Stacked sections | Operate dashboard (`/admin`) becomes foldable sections; `?tab=` → `?section=`. |
| D7 | In-chat summarize | Tools › *Summarize a document* (attach + length/language). Page retired. `/api/v1/summary/generate` stays. |

Interview notes: D2 was re-asked after the Helm/K8s turn-key constraint. D5 is the only flip vs the written recommendation.

---

## 4. Journeys this plan walks (U10)

| Id | Journey | Sprint |
| -- | ------- | ------ |
| J-NV-1 | **One place for people.** Admin (IAM groups off) opens Operate › People, finds a user, edits the level. Bookmark `/admin?tab=users` lands on the same page. Operate dashboard shows no Users tab. | A |
| J-NV-2 | **Find an old chat.** User opens History › Show all → All chats, searches, opens it. A colleague's shared chat appears under the *Incoming* tab and via Account › Incoming chats. Usage page shows usage only. Bookmark `/statistics#chats` lands on All chats. | A |
| J-NV-3 | **One key, one place.** A Helm install with keys only in secrets never needs the UI. An admin who *does* open Operate sees Models & keys as the editor; System configuration › AI services shows helm/env status and no password fields. Same admin, as a user, opens Manage › Your AI accounts, saves a Higgsfield key, clicks Test, sees a human result; bookmark `/ai/providers/higgsfield` lands on that section. | A |
| J-NV-4 | **Summarize in the chat.** User opens Tools › Summarize a document, attaches a PDF, sends; the summary is an answer in the thread. Bookmark `/ai/summarizer` opens the chat with the tool ready. No Summarizer menu entry anywhere. | A |
| J-NV-5 | **Memories one way.** Desktop and mobile: Account › Memories opens the same page. In a chat, a memory badge opens the page with that memory highlighted; browser Back returns to the same chat. | A |
| J-NV-6 | **Operator overview in one scroll.** Admin opens Operate: stacked sections (Overview open, others folded), 4 status cards on top; `?section=usage` opens and scrolls to Usage; System status shows 4 cards per row with fold-out details; 320 px has no overflow; dark and V2 pass. | C |

Each sprint file lists the §6 exit bullets for its journeys.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| NV01 | `refactor(nav): People is the only home for users; drop the Operate Users tab` | ota-candidate | D1–D7 locked |
| NV02 | `feat(chats): All chats page with Incoming tab; Usage keeps usage only` | ota-candidate | NV01 |
| NV03 | `fix(people): rename platform approvals to "Platform instances" and cross-link both homes` | ota-candidate | — |
| NV04 | `feat(ai): "Your AI accounts" page for Higgsfield and BYO Anthropic keys` | ota-candidate | — |
| NV05 | `feat(admin): Models & keys is the one editor for instance provider keys` | backend-only + ota-candidate (two commits, one PR) | NV04 |
| NV06 | `refactor(nav): split Connections into Connections and Developer & devices` | ota-candidate | NV04 |
| NV07 | `feat(chat): Summarize a document runs in the chat; retire the Summarizer page` | ota-candidate | — |
| NV08 | `refactor(memories): one Memories page on every device; retire the dialog` | ota-candidate | — |
| NV09 | `test(nav): journey specs J-NV-1…5 and redirect matrix` | ota-candidate | NV01–NV08 |
| NV10 | `fix(i18n): navigation and page-title wording pass (five locales)` | ota-candidate | NV01–NV08 |
| NV11 | `chore(i18n): remove unused nav keys and hardcoded strings on nav-adjacent pages` | ota-candidate | NV10 |
| NV12 | `test(i18n): wording guard for banned nav words and sentence case` | ota-candidate | NV10 |
| NV13 | `feat(ui): AccordionSection, CardGrid, InfoCard and OperatePage shell` | ota-candidate | — |
| NV14 | `feat(style): compact density scope for Operate pages (light, dark, V2)` | ota-candidate | NV13 |
| NV15 | `refactor(admin): Operate dashboard as stacked foldable sections` | ota-candidate | NV13, NV14, NV01 |
| NV16 | `refactor(admin): System status as 4-card grids with fold-out details` | ota-candidate | NV13, NV14 |
| NV17 | `refactor(admin): Model health as 4-card grid` | ota-candidate | NV13, NV14 |
| NV18 | `refactor(admin): AI infrastructure as four foldable sections` | ota-candidate | NV13, NV14, NV05 |
| NV19 | `refactor(admin): System configuration sections foldable, fields two-up` | ota-candidate | NV13, NV14, NV05 |
| NV20 | `refactor(admin): People on the OperatePage shell` | ota-candidate | NV13, NV14, NV01, NV03 |
| NV21 | `test(admin): layout, axe and visual baselines for the Operate pages` | ota-candidate | NV15–NV20 |
| NV22 | `docs: navigation and administration pages follow the new tree` (synaplan + synaplan-docs) | backend-only (docs) | NV21 |
| NV23 | Confluence: new version of *Navigation Tree (Status)* with the handle map applied | n.a. | NV22 |

One PR per step. Branch names `feat/nav-nv01-people-home`, … Never on `main`.

---

## 6. Gates (every step, no exceptions)

```bash
make ci-local                       # lint, phpstan, phpunit, eslint, vue-tsc, vitest
make test-e2e                       # @ci Playwright against the dev stack
make -C frontend test-e2e-layout    # when a layout / nav / viewport surface changed (all of sprint C, NV02, NV06, NV07, NV08)
node scripts/mobile-impact.mjs --base main --head HEAD   # classification must read ota-candidate (or backend-only for NV05a / NV22)
```

- `tests/unit/i18n/localeParity.spec.ts` must stay green — new keys go into
  all five locales; a translated key removes its ledger row.
- `redirects.spec.ts` gains a row for every retired path in the same PR that
  retires it.
- Snapshot re-baseline (`visual.spec.ts`) only in NV21, reviewed diff by diff.
- A step is **done** when its journey (§4) is walked in the browser in light,
  dark and V2 at 1280 px and 320 px, and the screenshots are attached to the PR.

---

## 7. Rollback

Every step is a frontend refactor with redirects; rollback is a revert of the
single PR. NV05a (backend `managedBy` hint) is additive and ignored by an
older frontend. No migrations, no seed changes, no flag changes.

# Execution sprints — menu, wording, and shell structure

**Status:** Ready to schedule  
**Date:** 2026-08-28  
**Parent:** [README.md](./README.md)  
**Binding:** [02_compatibility_contract.md](./02_compatibility_contract.md) — external widget,
plugin, add-in, shared-chat, and mobile contracts; frozen files; per-sprint regression matrix.  
**Test plan:** [03_playwright_test_plan.md](./03_playwright_test_plan.md) — the full E2E spec
inventory (41 specs, 16 nav-dependent), which suites are rewritten/updated/guarded per sprint,
selector-alias process, visual re-baseline policy, and new specs to add.  
**Classification:** `ota-candidate` (frontend shell, copy, tests). No store-required native change.

## Reality check: is this “just HTML”?

Mostly yes for **product behavior**. These four sprints do **not** add APIs, roles,
tenants, or a new assistant data model. Existing pages keep their URLs and
controllers. Authorization stays where it is (`isAdmin`, feature gates, guest
gates).

What actually changes:

| Kind of work | Share | Examples |
| --- | --- | --- |
| Visible HTML / Vue templates | Large | Rail, flyouts, mobile More, empty states, account menu, page headers |
| Wording | Large | `en/de/es/tr.json`, page titles, aria labels, empty states |
| Navigation model | Medium | `useNavItems.ts`, selected-state, breadcrumbs — still one source of truth |
| Tests | Large | `navigation.spec.ts` and siblings assert today’s flyouts and labels |
| Backend / APIs | None intended | Keep routes; add redirects only if a path is renamed |

So the risk is **regression of navigation and copy**, not broken chat or billing.
Budget as much time for rewriting E2E as for moving the menu — the per-spec
breakdown and effort estimate live in
[03_playwright_test_plan.md](./03_playwright_test_plan.md); tests are adjusted
in the **same PR** as the shell change they verify.

One exception deserves respect: the embedded chat widget bundles the **same
i18n files and the same `style.css`** as the app shell into the self-contained
`widget.js` running on customer websites (`widget.ts` imports `./i18n` and
`./style.css?inline`; `vite.config.widget.ts` inlines everything). Wording
work is therefore also a widget release. The rules — values only, never key
renames; `widget.*` namespace treated as customer copy; widget build + E2E on
every locale change — are in the
[compatibility contract](./02_compatibility_contract.md) §2 and are binding.

Do **not** restore Easy Mode. Do **not** invent a company-admin or hoster role.
Do **not** start the assistant-as-resource editor in these sprints.

## Out of scope (later epics)

- Assistant-centered widget editor (README “Follow-up sprint”).
- Hoster console, tenants, token pools, delegated RBAC.
- Splitting `ChatView.vue` / `AdvancedWidgetConfig.vue` for maintainability.
- Visual rebrand or retiring the V1/V2 design tokens.
- New command palette as a substitute for a clear menu.

## Target after four sprints

Everyday signed-in user (Plugins only when installed):

```
[New]  [History]  [Sources]  [Plugins*]  [Manage ▾]  [Account]
```

Guest:

```
[Chat]  [Sign in]
```

Administrator, same Work rail plus:

```
[Operate ▾]
```

`Manage` is **one** entry that groups today’s Channels + AI Setup. `Operate` is
today’s Admin cluster, labeled for instance work. Every child still opens the
**same URL** it opens today.

**Plugins stay a top-level rail entry** (when plugins are installed), exactly as
today. Plugin users work in their plugin daily; folding it into Manage would
add a hop to their most frequent destination for zero disclosure gain — the
entry only exists for users who installed a plugin. So the daily-user rail is:

```
[New]  [History]  [Sources]  [Plugins*]  [Manage ▾]  [Account]      *if installed
```

## Locked glossary (all four locales in Sprint 1)

| Concept | Use this | Do not use in primary UI |
| --- | --- | --- |
| Knowledge library | **Sources** | File Manager, knowledge base (as a nav label) |
| One uploaded item | **file** | — |
| RAG scope | **knowledge folder** | knowledge group (user-facing) |
| Embeddable product | **chat widget** | web widget, Your Widgets (as the only name) |
| Configured answering personality | **AI assistant** | agent (except the gateway product) |
| Guided widget setup chat | **AI Setup Assistant** | Widget AI Wizard |
| User-facing prompt library | **Instructions** | Preconfigured Instructions, Task Prompts |
| Classifier | **Routing** | Sorting, Classification |
| Website / WhatsApp / email entry | **Inbound** | Standard Inbound |
| IMAP automation | **Email handler** | IMAP Email Handler |
| Repeatable jobs | **Automations** / **Saved tasks** | bury under AI Setup & Tools |
| Action-oriented gateway | **AI Agents** | Messages Gateway (primary label) |
| Installation administration | **Operate** | Admin Panel (keep “Admin” only if space is tight) |
| Assistant / channel authoring | **Manage** | AI Setup & Tools (as a top-level name) |

Internal route prefixes (`/files`, `/ai`, `/admin`) may stay. Users should not
need them.

---

## Sprint 1 — Lock the words

**Goal:** One name per concept in all four locales. Menu **placement** stays the
same so this sprint is a safe copy drop.

**Duration:** ~3–5 days  
**Shippable alone:** Yes. Users see clearer labels on the current rail.

### Work

1. Add a short glossary comment block at the top of the `nav` / `pageTitles`
   sections (or a planning-only table here — do not invent a runtime glossary
   UI).
2. Update **all four** locale files together:
   - `nav.aiSetup` → keep the key, change the string only if Sprint 2 has not
     landed; prefer **not** renaming the top-level item yet if it would fight
     Sprint 2. Focus Sprint 1 on **child** labels and page titles.
   - `Your Widgets` → Chat widgets
   - `Standard Inbound` → Inbound
   - `IMAP Email Handler` → Email handler
   - `Preconfigured Instructions` → Instructions
   - `Routing Config` / `AI Model Config` → Routing / Models
   - `Saved Recurring Tasks` → Saved tasks
   - `File Manager` and leftover Easy Mode strings (`settings.appMode.*`)
   - Chat / widget / files body copy that still says “Files” as a product name
3. Replace hardcoded English in profile, chat loading, aria labels, and weak
   empty states (see README finding 15 and the UX-pattern audit).
4. Align `docs/FEATURES.md` if it still advertises Easy/Advanced modes.
5. Keep `data-testid` / `NavItem.key` values unchanged.
6. **i18n safety (contract §2):** change values only — no key renames, moves,
   or deletions in this sprint. Do not touch the `widget.*` namespace or any
   key referenced by `ChatWidget.vue`: that is end-customer copy inside the
   embedded widget on customer websites. State “widget-bundled keys touched:
   no” (or list them with justification) in the PR description.

### Primary files

- `frontend/src/i18n/{en,de,es,tr}.json`
- Scattered hardcoded strings in `ProfileView.vue`, `ChatView.vue`,
  `ChatInput.vue`, leftover widget list copy
- `docs/FEATURES.md` (copy only)

### Tests at the end of Sprint 1

Automated:

```bash
make -C frontend lint
docker compose exec -T frontend npm run check:types
make -C frontend test
# i18n key parity is part of frontend lint / existing i18n checks
make -C frontend build-widget   # locale files are bundled into widget.js
```

Widget guard (because i18n is bundled into the customer-site widget):

- Widget E2E `frontend/tests/e2e/tests/widget.spec.ts` passes.
- Diff review confirms no `widget.*` value changed unintentionally.

Then E2E that **assert visible names**:

- Update `frontend/tests/e2e/tests/navigation.spec.ts` only where it uses
  visible text (prefer testids; they should still pass).
- `layout.spec.ts` (labeled nav, 44px targets).
- Any test that `getByRole('button', { name: '…' })` on old labels.

Manual (30 minutes):

- EN + DE: open rail flyouts and account menu; confirm no leftover
  “File Manager”, “IMAP Email Handler”, “Preconfigured Instructions”,
  “Easy Mode”.
- Guest and signed-in: no mixed-language leftovers on the same screen.

**Done when:** A grep for the retired primary labels in `frontend/src` (except
comments and redirects) is empty, four locales share the same keys, and CI
frontend jobs are green.

---

## Sprint 2 — Rebuild the menu (same pages)

**Goal:** Everyday work is three items. Everything else sits under **Manage** or
**Operate**. Same routes, same pages, new shell HTML.

**Duration:** ~5–7 days  
**Shippable alone:** Yes, if Sprint 1 labels are in. Can follow Sprint 1
immediately.

### Target information architecture (existing URLs)

#### Work (always)

| Rail | Opens | URL |
| --- | --- | --- |
| New | New chat | `/` |
| History | Chat manager (unchanged modal) | not a route |
| Sources | Sources | `/files` |

#### Manage (signed-in, not guests)

One rail item. Children grouped — **no** second top-level “AI Setup & Tools”.

| Group | Label | URL (unchanged) |
| --- | --- | --- |
| Assistants | Models | `/ai/models` |
| Assistants | Instructions | `/ai/instructions` |
| Assistants | Routing | `/ai/routing` |
| Channels | Inbound | `/channels` |
| Channels | Chat widgets | `/channels/widgets` |
| Channels | Email handler | `/channels/email` |
| Channels | Connections | `/channels/connections` |
| Channels | MCP servers | `/channels/mcp` |
| Channels | API keys | `/channels/api` |
| Channels | API docs | `/channels/api/docs` |
| Channels | Live support | `/channels/widgets/live-support` |
| Automations | Saved tasks | `/channels/tasks` |
| Automations | AI Agents | `/channels/agents` |
| Tools | Summarizer *(transitional)* | `/ai/summarizer` |

This fixes the current bug that tasks / agents / email live in the AI Setup
**menu** while their URLs are `/channels/*`. It also gives **Live support** a
menu home for the first time — today it is reachable only through secondary
flows although takeover operators use it daily. Plugins stay on the rail (see
above), not in this panel.

Guest-gate note: `/channels` and `/ai/*` map to the backend feature key
`settings` via `mapPathToFeatureKey()`. The menu regrouping must not change any
returned feature key — those are a backend contract, not labels.

#### Operate (`isAdmin` only)

Rename the Admin rail item to **Operate** (key can stay `admin` for testids).

| Label | URL |
| --- | --- |
| Overview | `/admin` |
| Feature status | `/admin/features` |
| Model status | `/admin/model-status` |
| AI providers | `/admin/setup` |
| System configuration | `/admin/config` |

Dashboard **tabs** (users, prompts, usage, subscriptions, moderation) stay on
`/admin` for now. Do not add a Hoster tab.

#### Account (avatar / More)

Sprint 2 only **groups** the existing items. Sprint 3 trims them.

- Profile
- Preferences
- Memories
- Usage (today: Statistics)
- Plan / Upgrade (when billing applies)
- Sign out

### Interaction rules

- Desktop: Work icons stay on the rail. Manage and Operate open a **click**
  panel (not hover-only). Last destination inside Manage/Operate is remembered.
- Mobile: Chat / History / Sources / More. More contains Manage, Operate
  (admin), Account. Two levels, not three nested accordions if it can be
  avoided.
- Guests: do **not** show dimmed Channels / AI Setup teasers. Chat + Sign in.
- Do not hide Manage from ordinary signed-in users in this sprint. There is
  still no workspace-admin role; collapsing two flyouts into one is the
  disclosure win we can ship without new permissions.
- **Workspace seam (contract §6):** each context is shown/hidden by exactly one
  named predicate — Work: signed-in; Manage: signed-in; Operate: `isAdmin`.
  No inline plan-level (`isPro`/`isTeam`) conditions in nav items. When a real
  workspace capability exists later, only the Manage predicate changes.
- Add `meta.context: 'work' | 'manage' | 'operate' | 'personal' | 'public'` to
  routes while touching them — additive metadata only, no guard behavior
  change in this sprint.

### Primary files

- `frontend/src/composables/useNavItems.ts` — groups, labels, guest children
- `frontend/src/components/SidebarV2.vue`
- `frontend/src/components/MobileNav.vue`
- `frontend/src/composables/useNavItems` unit tests if present; add them if not
- `frontend/src/router/index.ts` — only if breadcrumbs / `meta.context` are
  added. **Do not delete routes.**
- `frontend/tests/e2e/helpers/selectors.ts` — add Manage/Operate selectors;
  keep old testids as aliases during the sprint if needed
- `frontend/tests/e2e/tests/navigation.spec.ts` — rewrite flyout describes
- `frontend/tests/e2e/tests/admin-panel.spec.ts` and impersonation specs that
  click the Admin rail item

### Tests at the end of Sprint 2

Automated (must rewrite, not skip):

1. **Unit:** `useNavItems` for guest / user / admin:
   - guest rail has no Manage children and no Operate;
   - user has Manage groups Assistants / Channels / Automations;
   - email handler and saved tasks are under Manage, not a leftover AI Setup
     item;
   - admin has Operate; non-admin does not.
2. **E2E `navigation.spec.ts`:**
   - user rail = New, History, Sources, (Plugins when installed), Manage — no
     Channels + AI Setup pair;
   - Manage opens widgets, models, email handler, saved tasks, live support;
   - History still opens the chat manager;
   - Sources still opens `/files`;
   - admin sees Operate; non-admin does not;
   - `/admin` still redirects non-admins.
3. **E2E** `layout.spec.ts`, `files-tabs.spec.ts`, `admin-panel.spec.ts`,
   `guest-registration.spec.ts` — update selectors, keep assertions.
4. **External-consumer checks (contract §5):**
   - plugin smoke: with a seeded/installed plugin the rail entry is visible,
     `/plugins/:name` mounts, and the direct URL works after re-login;
   - deep links still resolve: `/channels/widgets/:id`,
     `/channels/widgets/live-support`, `/channels/api/docs`,
     `/tools/chat-widget` (redirect), `/shared/:token` (logged out),
     `/addin/connect?redirect=…` (Synamail round-trip);
   - guest gate `restricted=` query keys unchanged.
5. Full frontend gate:

```bash
make -C frontend lint
docker compose exec -T frontend npm run check:types
make -C frontend test
```

Manual (45 minutes, desktop **and** 320px):

- User: start chat, open a past chat, open a source, create/open a widget via
  Manage — three hops or fewer.
- Admin: open provider setup and system config from Operate.
- Keyboard: Tab to Manage, Enter, move to Chat widgets, Enter. No hover-only
  path.
- Browser Back from `/channels/widgets` returns to the previous Work page and
  does not lose the rail.

**Done when:** A chat user sees at most Work + Manage + Account. An admin sees
Operate. Every previous destination is still one click from Manage or Operate.
CI navigation jobs are green.

---

## Sprint 3 — Quiet the first screens

**Goal:** The pages people land on look like the new mental model. Still no new
APIs: hide, move, or relabel existing chrome.

**Duration:** ~5 days  
**Depends on:** Sprint 2 (so setup links can point at Operate / Manage).

### Work

1. **Chat empty state (`ChatView.vue` and related banners)**
   - Primary: composer + example prompts.
   - One blocking message only if chat cannot run.
   - Move installation / provider-setup cards to Operate (link for admins;
     hide for non-admins).
   - Keep a compact model control only when more than one approved model is
     selectable.
   - Jobs tray: show after the first background job, not on every empty land.
   - At most one tip/banner above the composer.

2. **Sources (`FilesTabs.vue`, `FilesView.vue`)**
   - Default tab remains Browse.
   - Hide **Vectors** for non-admins (operators still reach it).
   - Incoming / Generated stay if they have data or an integration; otherwise
     they may sit behind a “More” filter rather than five equal tabs.
   - Copy says Sources / files / knowledge folder per the glossary.

3. **Account**
   - One **Preferences** destination for language, theme, timezone.
   - Profile keeps identity + billing company fields (still not an org).
   - Remove duplicate language/theme if both Profile and Settings show them.
   - Relabel Statistics → Usage in the account menu.
   - Feedback stays reachable; do not promote it to the rail.
   - Fix tools that send people to `/settings?tab=features` (no such tab).

4. **Page titles and breadcrumbs**
   - Every retained Manage/Operate child has a document title and a crumb:
     `Manage / Chat widgets`, `Operate / AI providers`.

### Primary files

- `frontend/src/views/ChatView.vue` (empty-state / banner composition only)
- `frontend/src/components/config` banners such as `ProviderSetupBanner.vue`
- `frontend/src/components/files/FilesTabs.vue`, `FilesView.vue`
- `frontend/src/views/SettingsView.vue`, `ProfileView.vue`
- `frontend/src/components/SidebarV2.vue` account menu
- `frontend/src/components/ToolsDropdown.vue` (broken features-tab link)
- Page header / title helpers if they already exist

Resist editing `AdvancedWidgetConfig.vue` here. That is the later assistant
epic.

### Tests at the end of Sprint 3

Automated:

- Chat empty-state E2E: signed-in user sees composer + examples; admin-only
  setup card is absent for a non-admin and present or linked for an admin.
- Guest: no dimmed Manage; Sign in remains.
- Sources: non-admin has no Vectors tab; admin does; Browse is default.
- Preferences: language switch still works (`navigation.spec.ts` Preferences
  describe).
- Profile still saves (existing profile tests).
- Deep links `/files`, `/files/search`, `/settings`, `/profile`, `/admin/setup`
  still resolve.
- Tools / disabled-feature links never land on a missing settings tab.
- If any locale or `style.css` value changed: `make -C frontend build-widget`
  plus widget E2E (contract §2 — these files are bundled into the customer
  widget).

Manual:

- New user: “ask a question” without opening Manage.
- Admin with no provider: blocking message **or** Operate link — not a wall of
  model pickers.
- Light, dark, and V2: empty chat, Sources, Preferences contrast.

**Done when:** A chat-only user can start a conversation without seeing provider
keys, vector jobs, or system config. Preferences has one home. Broken
`?tab=features` links are gone.

---

## Sprint 4 — Prove the shell

**Goal:** The new HTML is safe to ship: routes classified, keyboard path
complete, tests blocking, leftover copy gone.

**Duration:** ~3–5 days  
**Depends on:** Sprints 1–3.

### Work

1. **Route inventory** in this folder (`04_route_inventory.md` or a table in
   the PR): every registered path is `canonical` | `redirect` | `detail` |
   `utility` | `remove-candidate`. No orphan provider page without a Manage or
   Operate parent (live support gained its menu home in Sprint 2). Public
   contract routes (`/shared/*`, `/addin/connect`, `/account-deletion`,
   `/setup`) are marked `public-contract` and are never remove-candidates.
   Deferred i18n **key** cleanup (e.g. `settings.appMode.*`) happens here,
   each deletion backed by a zero-reference grep that includes the widget
   entry and `ChatWidget.vue`.
2. Shared `Dialog.vue`: focus trap + restore (pattern already exists on
   `SubscriptionPaywallModal.vue`).
3. Skip-to-content on `MainLayout.vue`; verify landmarks.
4. Make axe **blocking** on login, empty chat, and the Manage panel only —
   not the whole app yet (`layout.spec.ts` phase 0.5 → 1 for those three).
5. Guest, user, admin screenshots or Playwright traces attached to the PR for
   desktop and 320px.
6. Product-doc pass: README of this folder, `docs/FEATURES.md`, any help-tour
   strings that still say “AI Setup & Tools” or “Admin Panel” as the rail name.

### Tests at the end of Sprint 4 (release gate)

This is the gate for the **whole** streamlining effort. Run the full frontend
suite, then the persona script.

```bash
make -C frontend lint
docker compose exec -T frontend npm run check:types
make -C frontend test
```

Playwright (CI tags plus a local desktop/mobile pass):

| Journey | Guest | User | Admin |
| --- | --- | --- | --- |
| Land on chat and send / see composer | x | x | x |
| Open History | | x | x |
| Open Sources → Browse | | x | x |
| Open Manage → Chat widgets | | x | x |
| Open Manage → Models | | x | x |
| Open Manage → Email handler | | x | x |
| Open Manage → Live support | | x | x |
| Open Plugins → plugin mounts | | x | x |
| Open Operate → Providers | | | x |
| Open Operate → System configuration | | | x |
| Direct `/admin` as non-admin redirects | | x | |
| Account → Preferences language | | x | x |
| Keyboard through Manage | | x | x |
| 320px More menu | x | x | x |
| Embedded widget: load, open, send, privacy notice (widget.spec.ts) | x | | |
| Shared chat `/shared/:token` renders | x | | |
| `/addin/connect` relay round-trip (Synamail) | | x | |

Plus the full regression matrix in
[02_compatibility_contract.md](./02_compatibility_contract.md) §5 — the S4
column is the release gate.

Manual persona script (use real tasks, not “does it look simple?”):

1. Chat-only: start a new chat in one primary action.
2. Chat-only: find a known source without help.
3. Assistant owner: open an existing chat widget from Manage.
4. Operator: find provider credentials from Operate in two decisions or fewer.
5. Nobody confuses Profile “Company” with a managed organization.
6. Nobody looks for language under Operate.

**Done when:** The README acceptance criteria that apply to **shell, wording,
and structure** are all true. Failed E2E is a blocker, not a follow-up.

README criteria **not** claimed by these four sprints:

- Assistant detail IA (Overview / Instructions / Knowledge / …).
- Operator “data processing / sovereignty” summary as a new product surface
  (optional extra if existing status APIs already render on `/admin`; do not
  invent a new health backend).
- Hoster console.

---

## Suggested calendar

```
Week 1     Sprint 1  wording          → merge
Week 2     Sprint 2  menu             → merge
Week 3     Sprint 3  first screens    → merge
Week 3–4   Sprint 4  prove + ship
```

Each sprint is a PR (or a short stack). Do not combine Sprint 2 and Sprint 3
in one review: the menu rewrite already touches every navigation test.

## Implementation notes (so the HTML stays honest)

1. **Stable keys.** `NavItem.key` and `data-testid` stay (`files`, `admin`,
   `chat-widget`, …). Visible labels change; selectors should not.
2. **One nav source.** Desktop and mobile still read `useNavItems` only.
3. **Redirects, not deletes.** `/tools/*` and `/config/*` aliases stay until
   Sprint 4 classifies them.
4. **Capabilities, not a mode toggle.** Guest vs signed-in vs `isAdmin` is
   enough. No `localStorage` “simple shell” flag. One named predicate per
   context (contract §6) so a future workspace scope plugs in without a third
   IA rewrite.
5. **Company field stays billing copy.** Do not add a Workspace item.
6. **Frozen files.** The widget runtime (`widget.ts`, `widget-utils.ts`,
   `ChatWidget.vue`, shadow-style adapter), `PluginView.vue`,
   `AddinConnectView.vue`, `SharedChatView.vue`, `OnboardingView.vue`, and all
   `MOBILE-APP SEAM` blocks are off-limits — full list in contract §3.
7. **Mobile impact.** Record the path class in
   `.github/mobile-impact-policy.json` if new files appear; verify each PR
   with `node scripts/mobile-impact.mjs` — this work must classify as
   `ota-candidate`.
8. **No AI attribution** in commits. Conventional Commits, e.g.
   `feat(frontend): group channels and AI setup under Manage`.

## If a sprint starts to grow

Cut in this order:

1. Do not redesign the widget advanced modal.
2. Do not regroup `/admin` dashboard tabs.
3. Do not hide Incoming/Generated — only Vectors.
4. Do not add breadcrumbs if page titles already match the glossary.
5. Never skip the Sprint 2 navigation E2E rewrite.

## Mapping to the research README

| README deliverable | Sprint |
| --- | --- |
| 5 Terminology and destination audit | 1 (+ titles in 3) |
| 1 Capability-aware shell | 2 |
| 3 Simplified chat start | 3 |
| 4 Settings consolidation | 3 |
| 7 Route / copy / a11y hygiene | 1 (copy) + 4 (routes, a11y) |
| 2 Operator overview (new health hub) | **Not in these sprints** unless `/admin` already has the data |
| 6 Usability validation | 4 (persona script) |
| Follow-up assistant editor | Later epic |
| Hoster console | Later epic |

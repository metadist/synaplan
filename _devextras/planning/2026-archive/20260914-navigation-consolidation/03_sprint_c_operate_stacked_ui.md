# Sprint C — The Operate page rule: stacked, foldable, 2/4-card, compact

**Steps `NV13`–`NV21`.** One shell for every administrator page; content is a
stack of foldable sections with real headlines; inside a section, cards sit
two per row when they take input and four per row when they only inform;
the whole Operate context renders one density step smaller so an
administrator sees more at once.

**Reference today:** `/admin/setup` (`ModelsAndKeysTab.vue`, provider cards
`md:grid-cols-2`) is the 2-up model; `/admin/features` is the page that
should be 4-up and is one card per row today.

**User-flow:** journey **J-NV-6** ([`00_master_plan.md`](./00_master_plan.md) §4).

**UX exit (§6) before the sprint closes:**

1. **Journey (U10):** J-NV-6 walked on `/admin`, `/admin/features`,
   `/admin/model-status`, `/admin/setup`, `/admin/config`, `/admin/people`.
2. **Findability (U2):** every former tab is reachable by its old URL
   (`?tab=` → `?section=` redirect on `/admin`) and by scrolling; the section
   headline is the tab label the admin knew.
3. **Consequence (U3):** section descriptions say what the section changes
   ("Keys take effect immediately, no restart"); every destructive control
   keeps its sentence. Five locales.
4. **Empty / error / flag-off (U5, U8, U11):** a section whose data fails
   shows one sentence + **Retry** inside the section, never a blank fold; a
   module-gated section is absent, not disabled.
5. **Theme (U9):** compact density defined for light, dark and V2; WCAG AA
   re-measured on the smaller type; 320 px: sections stack, grids become one
   column, headlines wrap, no horizontal scroll.

---

## 1. The rule (binding for every route under `/admin*`)

| # | Rule | Not done if… |
| - | ---- | ------------ |
| **A1** | Every Operate page renders inside `OperatePage.vue`: breadcrumb "Operate › {page}", `PageHeader`, compact density class, `max-w-7xl`. | A page still uses `MainLayout` + its own container |
| **A2** | Content is a vertical stack of `AccordionSection`s. Each has a headline (noun phrase), one sentence of description, an optional status pill, and a body. The first section is open by default; open state per section is remembered (`localStorage` `synaplan.operate.<page>.<section>`); `?section=<id>` opens and scrolls to it. | A long page with `<h2>`s and no folds; state lost on reload |
| **A3** | Inside a section, cards live in a `CardGrid`: `cols="2"` when the cards take input or actions (keys, toggles, forms); `cols="4"` when they only inform (`InfoCard`: label, value, pill, hint, optional fold-out **Details**). A data list (users, audit rows, models table) is one full-width card. | A form 4-up; a status card 2-up; a bare table |
| **A4** | `TabNav` is allowed only to switch between lists of **different kinds** (People: Users / Groups / Policies / Platform instances / Audit) or when a page has more than six top-level sections (System configuration keeps its tab groups; inside a tab, sections fold). | Tabs used to hide two forms of the same kind |
| **A5** | Compact density changes spacing and type size only, scoped to `.operate-compact`; it never changes colour, and it never reaches overlays (dropdowns, dialogs, toasts are teleported and unaffected). | An ancestor-scoped colour rule; a dialog rendered smaller |
| **A6** | Test ids: `operate-page-<page>`, `section-<page>-<id>`, `btn-section-toggle-<id>`, `grid-<id>`, `card-<id>-<n>`. Existing ids inside bodies stay. | New ids invented per page |
| **A7** | Headline copy: sentence case, no verbs, no jargon; description answers "what does this change / show". Five locales. | "Config", "Misc", "Advanced stuff" |

The rule text is copied into `docs/FRONTEND_CONVENTIONS.md` in NV13 under
"Operate pages" so it outlives this plan.

---

## 2. Shared components (NV13)

All in `frontend/src/components/operate/` (new folder; classify in
`.github/mobile-impact-policy.json` as `ota-candidate` if the folder is not
already covered by an existing `frontend/src/components/**` pattern —
check with `node scripts/mobile-impact.mjs`).

### `AccordionSection.vue`

```text
props:  id: string · title: string · description?: string · pill?: { label: string; tone: 'success'|'warning'|'error'|'neutral'|'info' }
        defaultOpen?: boolean · storageKey?: string · count?: number
model:  v-model:open (optional; uncontrolled when absent)
slots:  default (body) · actions (right side of the header, e.g. Refresh)
emits:  update:open
a11y:   header is a <button aria-expanded aria-controls>; body is a <div role="region" aria-labelledby>;
        chevron rotates; Enter/Space toggle; prefers-reduced-motion respected
markup: <section :id="`section-${page}-${id}`" class="surface-card">
          <button class="w-full flex items-center gap-3 px-4 py-3 text-left" data-testid="btn-section-toggle-<id>">
            <h2 class="text-base font-semibold txt-primary">title</h2> <pill/> <span class="ml-auto"><slot name="actions"/> <chevron/></span>
          </button>
          <p v-if="description" class="px-4 pb-2 text-sm txt-secondary">description</p>
          <div v-show="open" role="region" class="px-4 pb-4"><slot/></div>
        </section>
state:  open = query.section === id ? true : storage ?? defaultOpen; on toggle → storage; on open via query → scrollIntoView({ block: 'start' }) after nextTick
```

No `<details>` element (Safari animation and testability), no `setTimeout`.

### `CardGrid.vue`

```text
props: cols: 2 | 4 · id: string
class: cols 2 → "grid grid-cols-1 md:grid-cols-2 gap-3"
       cols 4 → "grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3"
testid: grid-<id>
```

### `InfoCard.vue`

```text
props: label: string · value?: string | number · pill?: { label; tone } · hint?: string · icon?: string · testid?: string
slot:  details (renders a "Details" disclosure inside the card; closed by default)
markup: surface-card p-4 (compact) · label text-xs txt-secondary · value text-2xl font-semibold txt-primary · pill uses existing status tokens
        (--status-success-muted/--status-success-text etc., already used in FeatureStatusView)
```

### `OperatePage.vue`

```text
props: page: string (testid + storage key) · title: string · subtitle?: string · icon?: string
slots: default · header (extra chrome, e.g. TabNav) · actions
markup: <MainLayout :data-testid="`operate-page-${page}`">
          <div class="operate-compact container mx-auto px-4 md:px-6 py-6 max-w-7xl overflow-x-hidden">
            <nav aria-label="Breadcrumb" class="text-xs txt-secondary mb-2">Operate › {title}</nav>   (link to /admin unless page === 'overview')
            <PageHeader :title :subtitle :icon><slot name="header"/></PageHeader>
            <div class="space-y-3"><slot/></div>
```

`useOperateSection(page)` composable: reads `route.query.section`, exposes
`isOpen(id)`, `toggle(id)`, `open(id)` and writes `router.replace({ query: { ...route.query, section } })` when a section is opened by click so the URL is shareable.

**Tests (Vitest, `tests/unit/components/operate/`):** toggle + aria state;
`?section=` opens and calls `scrollIntoView` (spy); storage round-trip;
`CardGrid` class per `cols`; `InfoCard` renders pill tone classes; `OperatePage`
breadcrumb link present except on overview.

**Commit:** `feat(ui): AccordionSection, CardGrid, InfoCard and OperatePage shell`

---

## 3. Compact density (NV14)

**Files:** `frontend/src/style.css`, `frontend/src/style-v2.css`,
`docs/FRONTEND_CONVENTIONS.md`.

**Machine instructions**

1. `style.css`, new block "Operate compact density" — spacing and size only:

   ```css
   .operate-compact { --op-card-pad: 1rem; --op-gap: 0.75rem; }
   .operate-compact .surface-card { padding: var(--op-card-pad); }
   .operate-compact .surface-card.p-6, .operate-compact .surface-card.p-8 { padding: var(--op-card-pad); }
   .operate-compact h1 { font-size: 1.375rem; }
   .operate-compact h2 { font-size: 1rem; }
   .operate-compact h3 { font-size: 0.9375rem; }
   .operate-compact .txt-secondary { font-size: 0.8125rem; }
   .operate-compact .tab-nav-item { padding-top: 0.375rem; padding-bottom: 0.375rem; }
   ```

   Do **not** touch colour tokens. Overlays are teleported to `body`, so the
   scope cannot reach them; assert this in the test below.
2. `style-v2.css`: the V2 `.surface-card` override keeps its border/blur; add
   `.design-v2 .operate-compact .surface-card { padding: var(--op-card-pad); }`
   only if the V2 rule sets padding (check §… of the file; today it does not).
3. Buttons keep the house chain (`px-4 py-2.5 rounded-lg`); do not shrink
   buttons below 44 px tap height on touch (`@media (pointer: coarse)` keeps
   `py-2.5`).
4. Contrast: `.txt-secondary` at 13 px must still be ≥ 4.5:1 on `--bg-card`
   in light and dark — measure once, record the values in the PR.

**Tests**

- Vitest `operateDensity.spec.ts`: mount `OperatePage` with a teleported
  dialog child (`useDialog` stub) and assert the dialog root is not inside
  `.operate-compact`.
- E2E `layout.spec.ts` (`@ci @layout`): "Operate pages — compact, no overflow
  at 320 px" for the six routes; "primary controls ≥ 44 px on mobile" reused.

**Commit:** `feat(style): compact density scope for Operate pages (light, dark, V2)`

---

## 4. Page by page

### NV15 — Operate dashboard `/admin` (`O002`–`O009`)

**Machine instructions**

1. `AdminView.vue` → `OperatePage page="overview"`. Sections in this order,
   each an `AccordionSection`:
   - `overview` **Installation** (open) — `CardGrid cols=4` of `InfoCard`s: Version, Total users, users by level (one card each), then the existing `AdminSystemInfoPanel` and `RegistrationChart` below the grid (they are already cards).
   - `prompts` **System prompts** — existing prompt editor (one full-width card; A3 list rule).
   - `usage` **Usage (all users)** — period buttons + `UsageChart`.
   - `subscriptions` **Subscriptions** — only when `billing.enabled` (absent otherwise, U11).
   - `moderation` **Moderation**.
   - `appServer` **App server** — only when the native gate applies (same condition as the tab today).
2. `?tab=<x>` → `router.replace` to `?section=<x>` in `beforeEnter`
   (keeps `adminUsersTabRedirect` for `users` → People). Old E2E
   `admin-panel.spec.ts` targets `tab-*`; rewrite to `btn-section-toggle-*`.
3. Description sentences (en): Installation "Version, people and sign-ups on
   this server."; System prompts "The instructions the AI follows for routing
   and internal tasks. Changes apply to the next message."; Usage (all users)
   "Tokens and requests across everyone on this server."; Subscriptions
   "Plans, trials and payments."; Moderation "Flagged content and blocks.";
   App server "The address the mobile apps use for this server."

**Tests:** Vitest `AdminView.spec.ts` (sections list per flags, `?section=usage`
opens usage); E2E `admin-panel.spec.ts` rewritten; `redirects.spec.ts` row
`['/admin?tab=usage', '/admin?section=usage']`.

**Commit:** `refactor(admin): Operate dashboard as stacked foldable sections`

### NV16 — System status `/admin/features` (`O010`)

1. `FeatureStatusView.vue` → `OperatePage page="status"`. Sections:
   - `summary` **Overall** (open): `CardGrid cols=4` — Healthy, Needs setup, Disabled, Total (`InfoCard` with pills).
   - `modules` **Feature modules** — the existing `FeatureModulesSection` cards inside a `CardGrid cols=4` (module name, state pill, decisive env in **Details**).
   - one section per backend category (`AI Features`, `AI Providers`, `Processing Services`, `Infrastructure`, `Other`), headline via i18n key `settings.features.category.<slug>` (slug from the backend string; unknown → the raw string), body `CardGrid cols=4` of `InfoCard`: name, status pill, version badge as hint, message as hint; setup instructions (env vars, set / not set) in the **Details** fold.
2. Empty category ⇒ section absent. Load error ⇒ one card + Retry inside the first section.
3. The `nav` badge (disabled count) is unchanged.

**Tests:** Vitest `FeatureStatusView.spec.ts` (grid count = features; details
fold shows env var rows); E2E `feature-status.spec.ts` (`item-feature` ids
stay on the `InfoCard` root; 4 columns ≥ 1280 px asserted via bounding boxes
of the first four cards sharing a top edge).

**Commit:** `refactor(admin): System status as 4-card grids with fold-out details`

### NV17 — Model health `/admin/model-status` (`O011`)

1. `OperatePage page="model-health"`. Sections: `summary` **Overall** (4 InfoCards from `summary`), `filters` folded into the header **actions** slot of the next section (checkbox + capability select stay), one section per provider (`section-provider` today) with `CardGrid cols=4` of model `InfoCard`s (name, capability pills, status, last check in Details; per-provider Refresh in `actions`).
2. `state-empty` stays as one sentence inside the first provider section.

**Tests:** Vitest for section-per-provider; E2E: existing ids kept.

**Commit:** `refactor(admin): Model health as 4-card grid`

### NV18 — AI infrastructure `/admin/setup` (`O012`–`O016`)

1. `ProviderSetupView.vue` → `OperatePage page="ai-infrastructure"`, tabs replaced by four `AccordionSection`s: `models` **Provider keys** (open; the `ModelsAndKeysTab` body: status banner, `CardGrid cols=2` of `ProviderKeyCard`, own-service card, local AI card), `extraction` **Document reading**, `web-search` **Web search**, `rerank` **Reranking**. Descriptions = today's tab hints.
2. `?tab=<x>` → `?section=<x>` (keeps deep links from NV05's pointer card and from `SetupWizard`).
3. The three plug tabs already contain their own "Test" flows (J-PL-1/2); do not change their bodies beyond the wrapper.

**Tests:** Vitest sections + query redirect; E2E `admin-setup-tab-*` ids → `btn-section-toggle-*`; `redirects.spec.ts` rows for the four `?tab=` values.

**Commit:** `refactor(admin): AI infrastructure as four foldable sections`

### NV19 — System configuration `/admin/config` (`O017`–`O030`)

1. `AdminConfigView.vue` → `OperatePage page="config"`; tab groups + tabs stay (A4: 13 tabs > 6). Inside the active tab each `section` becomes an `AccordionSection` (id = section id; `?section=` already exists — reuse it; first open).
2. Fields inside a section render in `CardGrid cols=2` — each `ConfigField` wrapped as a card (label, control, hint, live/restart pill). Long text / JSON fields set `class="md:col-span-2"`.
3. NV05's pointer card is the only content of an all-managed section.
4. Live vs restart-required sentence moves to the section description.

**Tests:** Vitest `AdminConfigView.spec.ts` (sections fold, `?section=` opens, 2-up grid, col-span for textareas); E2E existing config tests keep passing (`config-section-<id>` ids kept on the `AccordionSection` root).

**Commit:** `refactor(admin): System configuration sections foldable, fields two-up`

### NV20 — People `/admin/people` (`O031`–`O036`)

1. `PeopleView.vue` → `OperatePage page="people"` with `TabNav` in the `header` slot (A4 allowed). Each tab body is one full-width card; filters sit in a compact row above the table.
2. Breadcrumb replaces the hand-made "Back to Operate" button (`link-people-back-operate` id moves to the breadcrumb link).
3. `?tab=` read on mount (from NV03).

**Tests:** Vitest updated; E2E ids unchanged.

**Commit:** `refactor(admin): People on the OperatePage shell`

### NV21 — Layout, axe and visual baselines

1. `layout.spec.ts`: six Operate routes at 320 px and 1280 px, light/dark/V2, no horizontal overflow, accordion header ≥ 44 px on mobile, first section open, `?section=` opens the right one.
2. `visual.spec.ts`: re-baseline the Operate screenshots **after** NV15–NV20 are merged; review every changed PNG; per the streamlining plan's policy, one re-baseline commit, no mixed changes.
3. Axe (report-only today): add the six Operate routes to the scan list; promote `/admin` and `/admin/features` to blocking if they pass clean.

**Commit:** `test(admin): layout, axe and visual baselines for the Operate pages`

---

## 5. Order and parallelism

NV13 → NV14 first (shared). Then NV16 and NV17 (pure re-layout, no data
change) as the pilot pair to validate the rule; then NV15, NV18, NV19, NV20
in any order; NV21 last.

## 6. Exit / demo

1. J-NV-6 walked; six pages on the shell; screenshots at 1280 px and 320 px,
   light, dark, V2, in the PRs.
2. `docs/FRONTEND_CONVENTIONS.md` "Operate pages" section = §1 of this file.
3. `rg "MainLayout" frontend/src/views/Admin*.vue frontend/src/views/{FeatureStatus,ModelStatus,ProviderSetup,People}View.vue` returns nothing — every Operate page uses `OperatePage`.
4. Full gate green including `make -C frontend test-e2e-layout`.

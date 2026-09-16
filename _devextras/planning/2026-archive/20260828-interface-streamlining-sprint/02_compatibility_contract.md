# Compatibility contract — what the streamlining sprints must not break

**Status:** Binding for all four execution sprints  
**Date:** 2026-08-28  
**Parent:** [README.md](./README.md) · [01_execution_sprints.md](./01_execution_sprints.md)

The shell rework is low-risk for product behavior but touches two files that are
**shared with external consumers**: the i18n bundle and `style.css`. Both are
compiled into the embedded chat widget that runs on customer websites. This
document lists every external and internal contract, the file-level freeze
list, and the regression matrix each sprint PR must run.

## 1. External consumers of this frontend

| Consumer | Entry contract | Breaks if we… |
| --- | --- | --- |
| **Embedded chat widget** on customer sites | `widget.js` (self-contained ES module from `frontend/src/widget.ts`; edge-cached; loads config from `/api/v1/widget/{id}/config`; API URL via `detectApiUrl()`) | rename/delete i18n keys it bundles, change `style.css` tokens/utilities it inlines, alter `widget-utils.ts`, or change the public widget config endpoint shape |
| **Plugin users** (daily) | Rail entry from `configStore.plugins` → `/plugins/:name` → `PluginView.vue` mounts per-user ES module from `/api/v1/user/{id}/plugins/{name}/assets/index.js` with `{ userId, apiBaseUrl, pluginBaseUrl, config }` | remove/deepen the Plugins nav entry, change the `mount()` contract, or gate `/plugins/:name` behind a new context guard |
| **Support / live takeover operators** (daily) | `/channels/widgets/live-support`, `/channels/widgets/:id/chats`, widget realtime channels | orphan these routes in the new menu or regress their reachability |
| **Outlook add-in (Synamail)** | `/addin/connect` public route with `redirect` (relay) param round-trip | touch `AddinConnectView.vue` or its route meta (see `Synamail/docs/AUTH_FLOW.md`) |
| **Shared chat links** in the wild | `/shared/:token`, `/shared/:lang/:token` public routes | rename or guard these paths |
| **Mobile apps (OTA)** | `MobileNav.vue`, router `MOBILE-APP SEAM` blocks (onboarding gate, `branding.defaultRoute` / `landingPage` resolution), `/account-deletion`, `/onboarding` | move or rewrite seam-marked code, or let shell changes drift into `store-required` territory |
| **Backend guest gating** | `mapPathToFeatureKey()` in `router/index.ts` maps `/channels`, `/ai/*`, `/settings` → feature key `settings`; `/files` → `files`; etc. | change the returned keys — they are a backend contract, not labels |
| **External docs / bookmarks / support articles** | Every `/channels/*`, `/ai/*`, `/admin/*`, `/files/*` URL plus the `/tools/*`, `/config/*` legacy redirects | delete a route or drop a redirect before the Sprint 4 inventory approves it |

## 2. The i18n and style coupling (the one real landmine)

`frontend/src/widget.ts` dynamically imports **`./i18n`** (all four locale
JSONs) and **`./style.css?inline`**, and `vite.config.widget.ts` inlines them
into one self-contained `widget.js`. Consequences:

1. **Every Sprint 1 wording commit is also a widget release.** The bundle on
   customer sites is edge-cached and rebuilt on deploy; the next deploy after
   the i18n change ships the new strings to end customers.
2. **Renaming or deleting an i18n key can render a raw key string inside a
   customer's support widget.** Values may change; keys may not.
3. **`style.css` edits leak into widget shadow DOM** via
   `adaptStylesForShadowDom`. The sprints do not restyle, but incidental
   utility/token edits must be treated as widget changes.

### Binding i18n rules for all sprints

- **Change values, never keys.** No key rename, move, or deletion in
  `en/de/es/tr.json` during Sprints 1–3. Key cleanup (e.g. retiring
  `settings.appMode.*`) happens only in Sprint 4 after a grep proves zero
  references — including from `ChatWidget.vue` and the widget entry.
- The **`widget.*` namespace is end-customer copy**, not app copy. It is out of
  scope for the glossary pass unless a change is deliberately intended for
  customer widgets, reviewed as such, and checked in all four locales.
- Namespaces bundled into the widget (at minimum `widget.*` and anything
  referenced by `ChatWidget.vue` and its children) get a dedicated review line
  in every Sprint 1/3 PR description: *“widget-bundled keys touched: yes/no,
  which, why.”*
- After any locale or `style.css` change: `make -C frontend build-widget` must
  succeed and the widget E2E (`frontend/tests/e2e/tests/widget.spec.ts`) must
  pass before merge.

## 3. Frozen files (no edits in Sprints 1–4)

Do not modify these during the streamlining work. If a sprint seems to need to,
stop and split that into its own reviewed change:

- `frontend/src/widget.ts`, `frontend/src/widget-utils.ts`
- `frontend/src/utils/widgetShadowStyles.ts`
- `frontend/src/components/widgets/ChatWidget.vue` (embedded runtime; the
  *management* views around it are fair game)
- `frontend/src/views/AddinConnectView.vue` and its route entry
- `frontend/src/views/SharedChatView.vue` and its route entries
- `frontend/src/views/PluginView.vue` (the plugin `mount()` seam)
- `frontend/src/views/OnboardingView.vue` and all `MOBILE-APP SEAM` blocks
- `mapPathToFeatureKey()` return values (the function may gain paths only if
  the backend key set is unchanged)
- Backend: everything (this effort is frontend shell + copy only)

## 4. Self-compatibility (the app must keep agreeing with itself)

Renaming a surface means renaming every reference to it in the same PR
(house rule: “Copy must be CORRECT”). Checklist per renaming PR:

- [ ] Grep all four locales for the old visible name (e.g. “AI Setup & Tools”,
      “Your Widgets”, “Admin Panel”) in breadcrumbs, tips, help tours, error
      hints, and “go to X” instructions (`chatInput.reasoningNotSupported`
      points at “AI Config → Models” today, guest gate copy lists old names,
      `guest.featureGate.*`, `help.*`).
- [ ] `pageTitles.*` match the new nav labels.
- [ ] `ToolsDropdown.vue` and any disabled-feature link point at destinations
      that exist (kill `/settings?tab=features`).
- [ ] Command palette / destination search (if enabled) reads from
      `useNavItems`, not its own list.
- [ ] `helpId`-based tours still anchor to elements that exist after the shell
      change.
- [ ] `data-testid` and `NavItem.key` values unchanged; E2E selector aliases
      added rather than renamed mid-sprint.
- [ ] Legacy `/tools/*`, `/config/*`, `/rag` redirects still registered and
      still land on a 200 page.

## 5. Regression matrix (run per sprint PR)

Spec-by-spec Playwright detail (which suites are rewritten, updated, or act as
guards per sprint) lives in [03_playwright_test_plan.md](./03_playwright_test_plan.md).

| Check | S1 wording | S2 menu | S3 first screens | S4 gate |
| --- | :-: | :-: | :-: | :-: |
| `make -C frontend lint` + `check:types` + `make -C frontend test` | x | x | x | x |
| `make -C frontend build-widget` succeeds | x | — | x (if i18n/style touched) | x |
| Widget E2E `widget.spec.ts` (embed, open, send, theme, privacy notice) | x | — | x | x |
| Widget copy diff review (`widget.*` keys) in PR description | x | — | x | x |
| Plugin smoke: rail entry visible with a seeded plugin; `/plugins/:name` mounts; direct URL works after re-login | — | x | — | x |
| Live support: `/channels/widgets/live-support` reachable from the new menu; widget sessions open | — | x | — | x |
| Navigation E2E suite (rewritten in S2) | label-only updates | x | x | x |
| Deep links: `/channels/widgets/:id`, `/channels/api/docs`, `/files/search`, `/admin/setup`, `/tools/chat-widget` (redirect) | — | x | x | x |
| Guest gate: `restricted=` query keys unchanged (`settings`, `files`, `memories`, `statistics`) | — | x | — | x |
| `/addin/connect` with `redirect` param round-trips (Synamail flow, manual or E2E) | — | x | — | x |
| Shared chat `/shared/:token` renders logged-out | — | x | — | x |
| Mobile 320px: Chat/History/Sources/More + Manage/Operate/Account reachable | — | x | x | x |
| `node scripts/mobile-impact.mjs --base <base> --head <head>` classifies as `ota-candidate` | x | x | x | x |
| Light / dark / V2 contrast on changed surfaces | x | x | x | x |

The widget checks are cheap and prevent the only class of bug in this effort
that a customer would see before we do.

## 6. Workspace scope: deferred, with a reserved seam

Deferring the workspace/organization scope is the right call for these sprints
— there is no backend tenant/role model, and a menu cannot honestly offer
“People & access” that no API enforces. But “leave it out” must not mean
“design it out.” The sprints keep the seam open:

1. **Manage is the future workspace home.** When a workspace model lands,
   Manage becomes “Manage *(workspace name)*” and gains People & access and
   Usage & billing children. No third IA rewrite.
2. **Navigation visibility goes through named capability predicates** in
   `useNavItems` (documentation-level now, one place in code later):

   | Context | Predicate today | Predicate later |
   | --- | --- | --- |
   | Work | signed-in (guest sees Chat + Sign in) | unchanged |
   | Manage | signed-in | `canManageWorkspace` (owner/editor capability) |
   | Operate | `isAdmin` | `canOperateInstance` |
   | Hoster console | — (absent) | reseller capability, separate shell |

   Implementation rule: nav items check **one predicate**, never inline
   `isAdmin`/plan-level combinations, so swapping in real capabilities is a
   one-line change per context.
3. **Route metadata carries ownership now** (`meta.context: work | manage |
   operate | personal | public`), added in Sprint 2 while every route is being
   touched anyway. The future workspace model reuses it.
4. **Explicit non-claims stay:** Profile’s company fields remain billing copy;
   no “Workspace”, “Team”, or “Organization” label appears anywhere in the new
   menu until the backend model exists. TEAM/BUSINESS plan copy must not
   promise people management these sprints do not build.

Risk accepted by deferring: TEAM/BUSINESS buyers keep managing users through
the operator area (Operate → Users) even when the “operator” is really a
company admin. That is today’s behavior, unchanged. The mitigation is the seam
above plus the open decision list in the README, not a rushed role model.

## 7. Rollback and sequencing safety

- Each sprint is an independently revertable PR. Sprint 2 (menu) must be
  revertable without reverting Sprint 1 (wording): labels read from i18n, so
  the old shell renders new labels harmlessly.
- No `localStorage` schema changes except the additive “last destination per
  context” key (Sprint 2), which must tolerate being absent or stale.
- Feature-flag nothing: the shell swap ships whole per sprint. If Sprint 2
  slips mid-sprint, `main` keeps the old shell — do not merge a half-migrated
  rail.
- Deploys rebuild `widget.js`; if a widget regression escapes, reverting the
  frontend deploy restores the previous bundle (self-contained, no chunk
  dependencies).

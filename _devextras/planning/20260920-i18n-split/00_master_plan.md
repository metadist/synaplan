# i18n split — per-locale directories + lazy-loaded namespaces

**Status:** 2026-09-20 — **planned, postponed**. Not started. No code changed.
**Trigger:** all five locale files were truncated to invalid JSON by a failed
Cursor write on 2026-09-20 15:45 (20,559 lines lost, restored from git). Files
at 6k+ lines each are no longer safe to edit with AI tooling.
**Binding contracts:** [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md)
(U1–U12, §6 sprint-file contract) and AGENTS.md "Perfect UX & Stability".
**Class:** every step is `ota-candidate` (`frontend/**` only, no backend change).

Files in this folder:

| File | Content |
| ---- | ------- |
| `00_master_plan.md` (this) | Goal, decisions, namespace map, step table, gates |

---

## 0. Why

`frontend/src/i18n/` holds five monoliths, statically imported by `index.ts`:

| File | Lines | Bytes | Keys |
| ---- | ----- | ----- | ---- |
| `en.json` | 6,810 | 326,685 | 5,661 |
| `de.json` | 6,744 | 357,990 | 5,593 |
| `es.json` | 5,824 | 297,701 | 4,799 |
| `fr.json` | 6,810 | 377,620 | 5,661 |
| `tr.json` | 5,825 | 294,524 | 4,800 |

Three problems, one fix:

1. **Editability.** A 6,800-line JSON file cannot be safely rewritten by an AI
   edit — proven by the 2026-09-20 truncation. Smaller files are reviewable,
   diffable, and cheap to re-check.
2. **Bundle cost.** All five locales (~1.65 MB raw JSON) ship in the initial JS
   for every visitor, whatever their language. The embeddable widget
   (`widget.ts` imports the same `i18n` instance) drags admin/config strings
   onto third-party pages.
3. **Load cost.** Strings for pages the user never visits (admin, widgets,
   onboarding) are parsed on first paint.

Not in scope: renaming any key, adding/removing any copy, changing the
supported-language set, touching the backend.

---

## 1. Decisions (locked)

- **D1 — keys never move.** `$t('config.savedTasks.saveAsTask')` keeps working
  unchanged. A namespace file holds a subset of *top-level* keys; the loader
  merges them back into one message tree. Zero component changes.
- **D2 — `en` is authoritative for the split map.** The migration script splits
  `en.json` by the map in §2, then splits the other four locales with the same
  map and asserts a key-for-key round-trip before the old files are deleted.
- **D3 — scripted migration, verified merge.** No hand-splitting. The script
  fails the run if merged output differs from the deleted original by even one
  key (value comparison included).
- **D4 — public API of `@/i18n` is unchanged.** `i18n`, `supportedLanguages`,
  `languageOptions`, `SupportedLanguage` keep their names and shapes; all
  existing imports keep working. New exports: `setLocale`, `loadNamespaces`,
  `useI18nNamespaces`.
- **D5 — fallback twin rule.** `fallbackLocale: 'en'` only works if the English
  chunk is loaded too. Every load of locale L + namespaces N also loads
  `en` + N (cheap — same chunks, one extra fetch at most, usually cached).
- **D6 — navigation waits for strings.** `core` + route namespaces load in
  `router.beforeEach` *before* `next()` resolves. No key-flash, no
  missing-key warnings on deep links. `meta.titleKey` (read during navigation)
  lives in `core`, so titles always resolve.
- **D7 — tests stay eager and deterministic.** Vitest uses an eager glob of all
  namespaces (no async, no network). The parity gate keeps gating the same
  dotted keys; only its import source changes.
- **D8 — widget gets a minimal loader.** The embed loads `core` + `chat` +
  `widget(s)` for one locale only — never admin/config/tools.

---

## 2. Namespace map — where every top-level key goes

11 files per locale (101 top-level keys total). Sizes are `en` JSON bytes,
rounded.

| File | Top-level keys | ≈ Size |
| ---- | -------------- | ------ |
| `core.json` | announcements, branding, common, cookies, error, forceUpdate, header, iap, loading, models, native, nav, network, notFound, pageTitles, realtime, search, shared, sidebar, system, unsavedChanges, updates, welcome, welcomeUser | 9 KB |
| `auth.json` | accountDeletion, adminSetup, auth, biometricLock, forcedPasswordChange, guest, localAiDownload, nativeServer, onboarding, setup, setupBanner | 22 KB |
| `chat.json` | approvals, chat, chatError, chatInput, chatMessage, chatShare, chats, commands, companionLinks, incognito, message, messageRefs, modelMix, moderation, processing, promoTips, selfAware, summary, taskPlan | 34 KB |
| `files.json` | fileMention, fileSelection, files, rag, storage, vectorStorage | 21 KB |
| `knowledge.json` | feedback, memories | 14 KB |
| `assistants.json` | assistants, bundle | 11 KB |
| `widgets.json` | liveSupport, widget, widgetSessions, widgets | 41 KB |
| `admin.json` | admin, adminModelStatus, aiInfra, iam, modules, people, platformConnect, providerHelp, statistics | 37 KB |
| `config.json` | config (48 KB alone — the biggest file; may split into `config/core\|savedTasks\|connections\|…` as a follow-up) | 49 KB |
| `settings.json` | export, externalLink, limitReached, marketingNews, paywall, profile, settings, subscription, usageTaximeter | 16 KB |
| `tools.json` | aiAccounts, aiProvider, channels, compute, customTools, help, jobs, linkedPlatforms, mail, mcpServers, messagesGateway, plugins, tools, workflows | 34 KB |

Layout after the split:

```
frontend/src/i18n/
  index.ts                    # loader + setLocale + useI18nNamespaces (D4)
  locales/
    en/{core,auth,chat,files,knowledge,assistants,widgets,admin,config,settings,tools}.json
    de/{…same 11 files}
    es/{…same 11 files}
    fr/{…same 11 files}
    tr/{…same 11 files}
```

Initial load drops from ~1.65 MB of JSON (all locales, everything) to one
locale × (`core` + route chunk), typically 20–60 KB.

---

## 3. Design sketch (for the implementer, not a spec)

**Loader** — `import.meta.glob('./locales/*/*.json')` without `eager` gives
Vite one code-split chunk per file for free. Merge via
`i18n.global.mergeLocaleMessage(locale, chunk)`; skip already-loaded
(locale, namespace) pairs.

**Route wiring** — routes declare needs in meta; the existing async
`beforeEach` awaits the load:

```typescript
{ path: '/files', component: () => import('@/views/FilesView.vue'),
  meta: { i18n: ['files'] } }
```

Suggested route → namespace map (refine during implementation):

| Routes | Namespaces (plus `core` + `en` twin, always) |
| ------ | -------------------------------------------- |
| `/login`, `/register`, `/forgot-password`, `/setup`, `/onboarding`, … | `auth` |
| `/`, `/chats`, `/shared` | `chat`, `files` (attachment UI renders file strings) |
| `/files`, `/rag` | `files` |
| `/memories`, `/feedback` | `knowledge` |
| `/assistants` | `assistants` |
| `/widgets/*`, `/live-support` | `widgets` |
| `/admin/*`, `/people`, `/model-status` | `admin`, `config` |
| `/settings`, `/profile`, `/subscription`, `/config` | `settings`, `config`, `tools` |
| everything else | `core` only |

**Locale switch** — `setLocale(lang)` loads `core` + current-route namespaces
for the new locale (+ `en` twin), then flips `i18n.global.locale`. The language
dropdown awaits it; on failure the locale does not change and a toast explains
(U8 — never a half-translated UI).

**Component-level (optional follow-up)** — heavy tabbed views (`SettingsView`,
`AdminConfigView`) can lazy-load per tab via `useI18nNamespaces(['tools'])`
instead of loading all three namespaces for the route. Not required for v1.

**Widget** — `widget.ts` uses a dedicated `loadWidgetLocale(lang)` resolving
`core` + `chat` + `widgets` for one locale. Assert in CI that the widget bundle
contains no `config.taskPrompts` / `admin.` strings (dist grep).

**Parity test** — `tests/unit/i18n/localeParity.spec.ts` imports a synchronous
`loadAllMessages(locale)` helper (eager glob, test-only) instead of the five
deleted files. `localeParityBaseline.json` is untouched: dotted keys don't move,
so the ledger stays valid.

---

## 4. User-flow block (§6 contract)

This track ships **zero intended user-visible change** — same strings, same
screens, same five locales. The "journey" is therefore the verification walk
that proves invisibility (U10):

**J-I18N-1 — language switch + deep-link walk.** Fresh profile → `/login`
renders fully translated with no key-flash → log in → switch language in
Settings → every visible string flips, no half-translated screen → deep-link
(direct URL, cold load) into `/files`, `/memories`, `/assistants`,
`/widgets`, `/admin`, `/settings` in each of the five locales → each page
renders complete on first paint → widget embed on a third-party page renders
translated with no console error.

Exit criteria (the §6 five, adapted to an invisible-infra track):

1. J-I18N-1 walked in the browser, all five locales (U10).
2. Findability unchanged (U2) — nothing moves; the walk asserts no string
   regressed to a raw key or to English-behind-fallback unexpectedly.
3. No copy changed, so no new consequence copy; all touched files keep
   five-locale parity via the existing gate (U3).
4. Empty + error + flag-off states: locale-load failure shows a toast and keeps
   the old locale (U8); no new flags, no new empty states (U5, U11).
5. No styling touched; the walk covers dark + V2 + 320 px to prove nothing
   regressed (U9).

---

## 5. Steps

| Step | Content | Class |
| ---- | ------- | ----- |
| `I18N-01` | Migration script (`scripts/split-i18n.py` or node): splits per §2 map from `en`, round-trip-asserts all five locales, deletes old files. Run once, keep the script for review, then remove it in the same PR. | ota-candidate |
| `I18N-02` | New `frontend/src/i18n/index.ts`: lazy loader + `setLocale` + `useI18nNamespaces`, resilient compiler preserved, D4 API kept. `core` preloaded at boot. | ota-candidate |
| `I18N-03` | Route meta (`meta.i18n`) for every route per §3 table + `beforeEach` await + `en`-twin rule (D5, D6). | ota-candidate |
| `I18N-04` | Widget minimal loader in `widget.ts` path (D8) + dist-grep assertion. | ota-candidate |
| `I18N-05` | Test updates: parity spec on `loadAllMessages`, unit tests for loader (twin rule, failure keeps old locale, already-loaded skip), E2E language-switch + cold-deep-link coverage, bundle-size assertion (main chunk contains no `config.taskPrompts`). | ota-candidate |
| `I18N-06` | Mobile-impact allow-list: `frontend/src/i18n/locales/**` in `.github/mobile-impact-policy.json` + `tests/mobile-impact.test.mjs` case, verified with `node scripts/mobile-impact.mjs --base <base> --head <head>`. Docs touch-up (`docs/FRONTEND_CONVENTIONS.md` i18n section: where new keys go). | ota-candidate |

Order: `I18N-01` → `I18N-02` → (`I18N-03`, `I18N-04` in parallel) →
`I18N-05` → `I18N-06`. One PR for all six (atomic — a half-split tree is worse
than none), or two PRs max (`01–02` eager-merge first, `03–06` lazy after).

---

## 6. Gates

- `make ci-local && make test-e2e` green before the PR opens (AGENTS.md gate).
- `localeParity.spec.ts` green with an **unchanged** baseline file — any ledger
  diff means the split moved or dropped a key (D2/D3 violation).
- J-I18N-1 walked per §4, all five locales, dark + V2 + 320 px.
- Bundle check: `dist/assets` main chunk contains no `config.taskPrompts`;
  widget bundle contains no `admin.` namespace strings.
- `vue-tsc` green (JSON module resolution for the new paths).
- Mobile-impact script green on the PR diff.

---

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| Deep link renders before chunk arrives (key-flash) | D6 — `beforeEach` awaits; E2E cold-load coverage per namespace group |
| Translator adds a key under the wrong namespace file | Parity test still gates dotted keys; document the map in `FRONTEND_CONVENTIONS.md`; map violations are harmless at runtime (merge is key-based, not file-based) |
| `es`/`tr` drift resumes per-file | Baseline ledger is file-agnostic; unchanged |
| Widget bundle regresses by importing full loader | D8 + dist-grep assertion in CI |
| `config.json` still 49 KB and growing | Explicit follow-up: split into `config/*.json` once this track is green |

---

## 8. Handoff

**Next action when resumed:** start at `I18N-01` (migration script). The
namespace map in §2 is approved as-is; re-measure sizes before implementing
(the counts above are from `main@2cb62627d`, 2026-09-20 — key sets drift).
No branch exists yet; create `feat/i18n-split-namespaces` from `main`.

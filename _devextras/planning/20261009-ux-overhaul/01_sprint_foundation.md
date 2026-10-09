# Sprint 1 — Foundation (UX01–UX02)

**Goal:** every page can say what it is, every empty state can offer one
action, and every area can explain itself with a guided tour.

**User-flow:** J-UX-1 (first visit of each rail section) — see
[`00_master_plan.md`](./00_master_plan.md) §6.

## UX01 — EmptyState, PageHeader help, wording guard

- `frontend/src/components/common/EmptyState.vue`: icon, one sentence,
  optional secondary sentence, one primary action (`to` or `@action`).
  Test id `empty-state` + `btn-empty-state-action`.
- `PageHeader.vue`: optional `tourId` prop renders a `?` button
  (`btn-page-help`, `QuestionMarkCircleIcon`) that starts the tour for the page.
- `tests/unit/i18n/wordingGuard.spec.ts`: page titles, nav labels and
  page descriptions in `en` must not contain the banned words of the glossary.

## UX02 — Guided tours

- Dependency `driver.js` (MIT).
- `frontend/src/composables/useTour.ts`: `startTour(id)`, `maybeAutoStart(id)`,
  `hasSeen(id)`; tour state from `GET /api/v1/profile` (`toursSeen`), saved via
  `PUT /api/v1/profile`. Guests use localStorage.
- `frontend/src/tours/*.ts`: one definition per area; steps target
  `[data-tour="…"]` and skip a step whose element is absent.
- Namespace `tours` in all five locales.
- Popover theme in `style.css` from tokens (`--bg-card`, `--txt-primary`,
  `--brand`) with dark and V2 variants.
- Backend (`backend-only` commit): `ProfileController` reads / writes
  `toursSeen` (string list, max 64 ids, `[a-z0-9._-]`) in `BUSERDETAILS`;
  OpenAPI updated; `make -C frontend generate-schemas`.
- Removed: `HelpTour.vue`, `HelpDialog.vue`, `HelpButton.vue`, `HelpHost.vue`,
  `useHelp.ts`, `helpContent.ts`, `meta.helpId`, `features.help`.

## Exit criteria

1. J-UX-1 tour walked in the browser on Chats, Library, Assistants, Apps, Admin.
2. A finished or skipped tour does not reappear on another device (U2).
3. Tour copy in all five locales (U3).
4. Tour skips missing targets; profile save failure keeps the tour usable (U5, U8).
5. Popover readable in light, dark, V2 and 320 px (U9).

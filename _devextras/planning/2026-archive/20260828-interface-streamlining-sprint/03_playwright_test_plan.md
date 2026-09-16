# Playwright adjustment plan — which specs change, when, and how

**Status:** Binding for all four execution sprints  
**Date:** 2026-08-28  
**Parent:** [01_execution_sprints.md](./01_execution_sprints.md) ·
[02_compatibility_contract.md](./02_compatibility_contract.md)  
**House guidelines:** `docs/E2E_TESTING.md`

The shell rework invalidates assumptions baked into the E2E suite (two rail
flyouts named Channels and AI Setup, an Admin rail item, dimmed guest teasers,
specific visible labels). This document is the complete inventory of affected
specs so no suite is discovered broken after merge.

## Process rules

1. **Tests change in the same PR as the shell change.** A sprint PR that turns
   navigation E2E red and defers the fix is not mergeable. Green CI on the PR
   is the definition of "sprint done" — the persona script in Sprint 4 comes on
   top, not instead.
2. **A filtered run is not the gate** (house rule). `npx playwright test
   navigation` while iterating is fine; the sprint gate is the full suite:

   ```bash
   make -C frontend test-e2e            # full E2E suite
   make -C frontend test-e2e-layout     # mobile-viewport layout guard
   make -C frontend test-e2e-plugin-castingdata   # plugin E2E (CastApp + Synaplan running)
   ```

3. **Selectors over labels.** All navigation assertions go through
   `tests/e2e/helpers/selectors.ts`. `NavItem.key` / `data-testid` values are
   stable across the rework (contract §4), so specs that already use testids
   survive label changes untouched. Specs matching visible text
   (`getByRole(... { name })`, `getByText`) are the ones that break in
   Sprint 1 — convert them to selectors while updating, don't chase strings.
4. **Selector aliases, not renames.** When Sprint 2 introduces Manage/Operate,
   add new entries (`sidebarV2Manage`, `manageFlyout*`, `sidebarV2Operate`) to
   `selectors.ts`; keep `sidebarV2Channels` / `sidebarV2AiSetup` as deprecated
   aliases until every spec is migrated inside the same PR, then delete them
   in that PR. No half-migrated selector file on `main`.
5. **`@ci`-tagged tests keep their tags.** The rework must not quietly drop
   suites from the CI subset. If a test's premise disappears (e.g. "AI Setup
   flyout exists"), replace the test with its Manage equivalent — do not
   delete without replacement.
6. **Visual snapshots are re-baselined intentionally.** `visual.spec.ts` will
   diff on every shell change. Re-record baselines once per sprint PR, review
   every changed screenshot in the PR (the reviewer confirms the diff shows
   the intended shell change and nothing else), and never mix a re-baseline
   with unrelated fixes.

## Spec inventory

Legend — **Rewrite:** structure/premise changes. **Update:** selector or label
touch-ups. **Guard:** must stay green unchanged; failure means we broke a
contract. **Re-baseline:** snapshot refresh.

| Spec | Depends on | S1 | S2 | S3 | S4 |
| --- | --- | --- | --- | --- | --- |
| `navigation.spec.ts` | rail items, both flyouts, user menu, Preferences | Update (labels) | **Rewrite** (Manage/Operate structure) | Update (account menu trim, Usage label) | Guard |
| `layout.spec.ts` | labeled nav, 44px targets, overflow, axe report-only | Update | **Rewrite** (new rail set, More menu) | Update | **Rewrite** (axe → blocking for login/chat/Manage) |
| `admin-panel.spec.ts` | Admin rail item + Dashboard flyout link | — | **Update** (Operate selectors) | — | Guard |
| `admin-impersonation-chat.spec.ts` | Admin rail item + flyout | — | **Update** (Operate selectors) | — | Guard |
| `files-tabs.spec.ts` | Files rail item, five tabs | — | Update (rail selector) | **Rewrite** (Vectors admin-only, tab set per role) | Guard |
| `guest-registration.spec.ts` | guest sidebar contents | — | **Rewrite** (no dimmed teasers; Chat + Sign in) | — | Guard |
| `guest-chat.spec.ts` | guest chat entry, gate hints | — | Update | Update (empty-state changes) | Guard |
| `chat-manage.spec.ts` | History → chat manager modal | — | Update (rail selector only; modal unchanged) | — | Guard |
| `chat-share.spec.ts` | History modal, share flow | — | Update | — | Guard |
| `chat.spec.ts`, `chat-input.spec.ts`, `chat-again.spec.ts`, `chat-bubble-monotonic.spec.ts`, `multitask.spec.ts`, `incognito.spec.ts` | composer, banners, empty state | Update (aria/label i18n) | — | **Update** (empty-state chrome: one banner, jobs tray deferred, setup cards gone) | Guard |
| `auth.spec.ts`, `oidc-*.spec.ts` (5 specs), `registration.spec.ts` | login/register pages, post-login landing | Update (labels if touched) | Update (post-login rail assertions) | — | Guard |
| `profile-password.spec.ts` | profile sections | Update (i18n hardcodes) | — | Update (Preferences consolidation) | Guard |
| `memories.spec.ts` | account menu → memories | — | Update | Update | Guard |
| `subscription.spec.ts`, `subscription-lifecycle.spec.ts` | upgrade rail button, account menu Plan | — | Update | Update | Guard |
| `task-prompts.spec.ts` | `/ai/instructions` page | Update (new "Instructions" labels) | Update (reached via Manage) | — | Guard |
| `mcp-config.spec.ts` | `/channels/mcp` page | — | Update (reached via Manage) | — | Guard |
| `inbound-email-handler*.spec.ts` (2), `email.spec.ts`, `whatsapp.spec.ts` | channel config pages | Update (Inbound/Email handler labels) | Update (menu path) | — | Guard |
| `rag-search.spec.ts`, `file-manage.spec.ts` | Sources pages | Update (glossary copy) | Update | Update (tab changes) | Guard |
| `saved-task-roundtrip.spec.ts` | `/channels/tasks` via nav | — | Update (Automations group) | — | Guard |
| `redirects.spec.ts` | all `/tools/*`, `/config/*` legacy redirects | — | **Guard** | Guard | Guard — plus extend with any alias Sprint 4 adds |
| `widget.spec.ts` | embedded widget runtime (customer contract) | **Guard** (i18n is bundled into widget.js) | Guard | **Guard** (if i18n/style touched) | Guard |
| `castingdata-plugin.spec.ts` | plugin rail entry, `/plugins/:name` mount | — | **Guard** (plugins stay top-level) | — | Guard |
| `account-deletion.spec.ts` | public compliance route | — | Guard | — | Guard |
| `ollama-integration.spec.ts` | chat back-end behavior | — | — | — | Guard |
| `visual.spec.ts` | screenshot baselines | Re-baseline | Re-baseline | Re-baseline | Re-baseline + review |

The four **Guard** rows in bold — `redirects`, `widget`, `castingdata-plugin`,
and `guest-registration` after its rewrite — are the external-compatibility
watchdogs from the [contract](./02_compatibility_contract.md). If one goes red,
stop and treat it as a broken contract, not a flaky test.

## New specs to add

| Sprint | New coverage |
| --- | --- |
| S2 | Manage flyout: groups Assistants / Channels / Automations render; each child navigates; last-destination memory works; keyboard traversal (Tab → Enter → child → Enter) |
| S2 | Operate flyout: admin-only visibility; non-admin never renders it (assert absence, not just redirect) |
| S2 | Live support reachable from Manage (`/channels/widgets/live-support`) |
| S2 | Plugins rail entry: visible with seeded plugin, absent without |
| S3 | Chat empty state per role: non-admin sees no setup cards; admin sees one Operate link; max one banner above composer |
| S3 | Sources tabs per role: non-admin has no Vectors tab, admin does |
| S4 | Axe blocking on login, empty chat, Manage panel (promote from report-only in `layout.spec.ts`) |
| S4 | Skip-to-content and dialog focus trap/restore assertions |

Unit tests (Vitest, not Playwright) for `useNavItems` — guest/user/admin item
sets, one-predicate-per-context — are listed in Sprint 2 of the
[execution plan](./01_execution_sprints.md) and complement, not replace, the
E2E above.

## Estimated E2E effort per sprint

| Sprint | Test work |
| --- | --- |
| S1 | ~0.5–1 day: label/selector touch-ups + widget guard run + visual re-baseline |
| S2 | **~2–3 days: the navigation/layout rewrite + new Manage/Operate specs.** This is the largest single test effort of the whole project — plan it as real sprint scope, not overhead |
| S3 | ~1 day: empty-state, files-tabs, preferences updates |
| S4 | ~1 day: axe promotion, a11y specs, full-suite gate + persona script |

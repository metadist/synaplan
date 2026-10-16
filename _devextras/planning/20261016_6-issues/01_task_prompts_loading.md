# Sprint 01 — Task prompts loading state

**Issue:** [#2459](https://github.com/metadist/synaplan/issues/2459)
**Kind:** Confirm. Fix only a gap this sprint's checks still show.
**Class:** `no-app-impact` when the checks pass. `ota-candidate` when a
fix changes `frontend/src/**`.
**Parent:** `dev/20261016-6-issues` once that pull request has merged;
until then, run the checks on that branch. A fix branch is
`dev/20261016-s1-task-prompts`, cut from the latest code branch (§2 of
the master plan).

## Goal

Opening Task prompts never shows "0 Total" or "No topics match your
search." while `GET /api/v1/prompts` is in flight. An empty library with
no search says "No topics yet." and offers one action. A failed load
shows the error, with a retry.

## User-flow

Pointer: UX contract `docs/UX_CONTRACT.md` §5 and §6. Journey **J-TP-1**.

1. The person opens Task prompts on a slow connection.
2. The list area says the topics are loading. The three counts are not
   `0`.
3. When the list arrives empty and nothing is typed in the search, one
   sentence ("No topics yet.") and one button (create) are on screen.
4. A search with no match says "No topics match your search." and offers
   Clear filters.
5. A failed load says the topics could not be loaded and offers Try again.
   It does not look like an empty library.

## What `main` already does

`frontend/src/components/config/TaskPromptsConfiguration.vue`

- `loading` starts `true`. The count spans render only when `!loading`.
- `data-testid="task-prompts-loading"` while loading.
- `data-testid="task-prompts-load-error"` plus `btn-task-prompts-retry` on failure.
- `text-no-prompts` ("No topics yet." / `config.taskPrompts.noPrompts`) when
  the loaded list is empty and no search or filter is active, with
  `btn-no-prompts-create`.
- `text-no-prompts-match` only in the other empty branch.

`frontend/tests/unit/components/config/TaskPromptsConfiguration.spec.ts`
already asserts the in-flight state, the empty state, the search state,
and the error state.

## The one step

1. Run the existing unit test, unfiltered as part of the frontend suite
   if you are about to commit, and filtered first to see this file:

   ```bash
   docker compose exec -T frontend npm run test -- tests/unit/components/config/TaskPromptsConfiguration.spec.ts
   ```

2. Walk J-TP-1 in the browser once, throttled (Slow 3G) and then normal,
   light and dark, at 320 px. The five locales can be the unit test's
   English strings plus a spot check that `noPrompts` and `noPromptsMatch`
   exist in `de`, `es`, `fr`, `tr`. Record the walk in `STATUS.md`.

3. **Pass:** comment on #2459 with the test name, the SHA, and the walk.
   Close the issue. No branch, no pull request. Stop.

4. **Fail:** write a unit test that fails the way the walk failed. Fix
   only that gap in `TaskPromptsConfiguration.vue`. Do not split the
   component. Do not restyle the create button in the page header (it is
   outside this issue). Then run the loop in the master plan §3.

## Tests that lock the bug

Already present. A new test is required only for a gap they miss. The
in-flight test must keep these three assertions together: loading marker
present, `text-no-prompts-match` absent, `stat-total` text does not
contain `0`.

## Quality gate

Confirmation: the Vitest file above, then the walk. No commit.

Fix: `make -C frontend lint`, `docker compose exec -T frontend npm run check:types`,
`make -C frontend test`, then `make test-e2e` (the spec is
`frontend/tests/e2e/tests/task-prompts.spec.ts`, which must keep waiting
on `task-prompts-loading` rather than on a fixed delay). Master plan §3
for Copilot and CI.

## Exit, if a fix is required

1. J-TP-1 walked in the browser, throttled and normal (U10).
2. The empty library is findable: one sentence, one create button (U2, U5).
3. Retry copy is the existing `common.retry` in all five locales (U3).
4. Loading, empty, no-match, and error are four different states (U5, U8).
   Flag-off does not apply.
5. Light, dark, and 320 px checked (U9). Glass (`.design-v2`) uses the
   same tokens; no new colours.

## Out of scope

Splitting the 2,400-line component. Changing how topics are fetched.
The header create button's classes.

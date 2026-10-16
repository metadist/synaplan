# Sprint 02 — Saved shortcut appears after save

**Issue:** [#2460](https://github.com/metadist/synaplan/issues/2460)
**Kind:** Confirm the cause, then fix only what a failing test shows.
**Class:** `no-app-impact` when the checks pass. `ota-candidate` when a
fix changes `frontend/src/**`.
**Branch, only if a fix is required:** `dev/20261016-s2-shortcuts`, cut
from the latest code branch (sprint 01's branch if it exists, otherwise
`dev/20261016-6-issues` or `main` after that plan has merged).

## Goal

After a successful save on Shortcuts, the new row is in the list in the
same turn the form closes, sorted by name. A list response that started
before the save must not wipe the row. A refetch, a retry, or a longer
wait is not the fix.

## User-flow

Journey **J-SC-1**. The person is on Shortcuts, which already shows one
shortcut.

1. They click New shortcut, fill name, command, and text, and save.
2. The form closes. A success toast names the save. The new row is
   visible, in name order, without a second load of the list.
3. They can delete that row from the same list. The row is gone and the
   slash menu no longer offers the command.

## What `main` already does

`frontend/src/views/PromptsView.vue` `save()` writes, records the row at
a new `listRevision`, calls `showPrompts(withSavedRow(saved))`, then
closes the form. `load()` applies an older response only through
`mergeList`, which lays later writes back on top.

`frontend/tests/unit/views/PromptsView.spec.ts`

- `lists a new prompt in name order without reloading the list` — the
  CI shape where the list request has already finished.
- `keeps a shortcut that was saved while the list request was still open`
  — the race the CI trace ruled out, locked anyway.

The CI failure (run 38215138004, job 114698906615, shard 3/3) is not
reproduced locally. The trace showed one GET, a 201, the form hidden,
and the row missing ten seconds later, plus a console warning
`ChatInput ref not available for auto-focus` while Shortcuts was open.

## The one step

1. Re-read `save()`, `mergeList`, and the two unit tests. Do not change
   them yet.
2. Run:

   ```bash
   docker compose exec -T frontend npm run test -- tests/unit/views/PromptsView.spec.ts
   cd frontend && npx playwright test tests/saved-prompts.spec.ts --repeat-each=20
   ```

   Headless. The Playwright run uses the dev stack (`make test-e2e`
   conventions in `docs/E2E_TESTING.md`). If the full `@ci` suite is
   cheaper in one shot than twenty repeats plus a later full run, run
   `make test-e2e` once after a green repeat of this file.

3. **Both green:** comment on #2460 with the SHA, the two unit-test
   names, and the repeat count. Close the issue. The existing tests are
   the regression lock. No branch. Stop.

4. **Red:** build the timeline before guessing.

   ```bash
   python3 _devextras/e2e/playwright-trace-timeline.py path/to/trace.zip
   ```

   Write a unit test that follows that timeline (the clicks, the one
   GET, the POST, who clears the rows). See it fail. Fix the cause the
   timeline names. The `ChatInput` warning is a lead only if the
   timeline shows chat code resetting this list; otherwise leave it.

5. Run the loop in the master plan §3. Commit type `fix`.

## Tests

The two unit tests above are the lock when step 3 passes.

When step 4 runs, the new test asserts all three: the form is gone, a
`row-saved-prompt` contains the new command, and no second GET was
issued. Deleting the row in the same test is the undo.

## Quality gate

Confirmation: the Vitest file and twenty headless repeats of
`saved-prompts.spec.ts`.

Fix: frontend lint, `check:types`, `make -C frontend test`, then
`make test-e2e`. Copilot on the pull request. CI green.

## Exit, if a fix is required

1. J-SC-1 walked, including the delete (U10, U3).
2. The new shortcut is on the Shortcuts page in the same turn (U2).
3. The toast and the delete confirmation stay the existing
   `savedPrompts.*` strings in all five locales (U3). No new copy unless
   the fix needs a sentence; then all five locales in the same commit.
4. A rejected save keeps the form open with the server's sentence. A
   failed delete keeps the row and says so (U8). Already tested; do not
   drop those tests.
5. Light, dark, 320 px, on the Shortcuts page (U9).

## Out of scope

A post-save refetch. Raising a Playwright timeout. Rewriting the slash
menu. The `ChatInput` autofocus warning, unless the timeline shows it
clears this list.

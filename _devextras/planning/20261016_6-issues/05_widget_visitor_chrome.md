# Sprint 05 — Playwright for the visitor chat chrome

**Issue:** [#2504](https://github.com/metadist/synaplan/issues/2504)
**Kind:** Task. Tests only. Assigned to FExB17; leave the assignee.
**Class:** `no-app-impact` while the diff stays under `frontend/tests/**`
and docs. The moment a product file changes, reclassify. Do not change
product code to make a selector pass: the `data-testid`s already exist.
If one is missing, stop and record it. Adding a test id is a one-line
product change and makes the pull request `ota-candidate`; do that only
when the issue's selector is genuinely absent.
**Branch:** `dev/20261016-s5-widget-chrome`
**Cut from:** `dev/20261016-s4-widget-sources`. Pull request targets that
branch. Sprint 04 lands first so these tests run against the widget that
no longer paints internal sources.

## Goal

A visitor on `widget-test.html` can close the chat and open it again,
switch the widget theme, enter and leave fullscreen, hit the configured
message limit, and remove a staged file. A Playwright test fails if any
of that breaks. The new tests run in the `@ci` chromium job and use the
TestProvider path the existing `@ci` widget reply already uses.

## User-flow

The five tests are the journey **J-WG-2**. Each one is Arrange / Act /
Assert in `test.step`. Together they are the U10 walk for this sprint.
A headed browser is not required.

| Step | What the visitor does | What the test sees |
| ---- | --------------------- | ------------------ |
| Close and reopen | Click `btn-close`, then `#synaplan-widget-button` | The window goes away and comes back with the earlier messages. Tag `@ci` and `@crossbrowser`. The button is outside the shadow root; the window is inside it. |
| Theme | Click `btn-theme` twice | The widget surface switches, then switches back. The host page background does not change. |
| Fullscreen | Click `btn-fullscreen` twice | `fullscreen-backdrop` is visible, then the host page is visible around the widget, same as the existing "keeps the host page visible while open" test. |
| Message limit | Widget created with `messageLimit` 5. Send until 4 replies have finished, then one more. | At 4, `warning-message-limit` is visible and names 4 of 5. After the fifth, `error-message-limit-reached` is visible and `input-message` cannot send. Wait for `message-done` before the next send. The count is the session's. |
| Remove a file | File upload on. Attach `frontend/tests/e2e/test_data/most_important_thing.txt`. Click `btn-remove-file-0`. | The file name shows, then it is gone. Do not send. Do not wait for a model. |

## The change

Edit `frontend/tests/e2e/tests/widget.spec.ts`. A sibling spec is allowed
if this file becomes hard to read; selectors still come from
`frontend/tests/e2e/helpers/selectors.ts` (`selectors.widget`), setup from
`frontend/tests/e2e/helpers/widget.ts`.

Rules from `docs/E2E_TESTING.md`:

- `test.step` for Arrange / Act / Assert.
- `afterEach` deletes every widget the test creates.
- No `networkidle` on the widget page.
- Do not retag the existing `@noci` file-upload, overlay, or task-prompt
  tests as `@ci`.
- Do not cover `btn-human-handoff`. It renders only when a Slack webhook
  is saved. The Conversations redirect stays `J-UX-3` in
  `ux-journeys.spec.ts`.

Message limit uses `input-message-limit` / `updateWidgetSettings`. Follow
the helper the current widget tests use to create a widget. Do not invent
a second setup path.

## Tests

These tests are the product of the sprint. Run them before the pull
request:

```bash
cd frontend && npx playwright test tests/widget.spec.ts
```

Headless. Then the unfiltered gate below, because a green file is not
the `@ci` job.

## Quality gate

```bash
make -C frontend lint
make -C frontend test
make test-e2e
node scripts/mobile-impact.mjs --base <parent> --head HEAD
```

`make test-e2e` is the `@ci` chromium suite and includes this file.
Firefox (`test:e2e:firefox`) is required for the new `@crossbrowser`
close-and-reopen test. Run that test file, not the whole Firefox matrix,
unless the helper change is shared:

```bash
cd frontend && npm run test:e2e:firefox -- tests/widget.spec.ts
```

Commit `test(widget): cover visitor chrome in Playwright`. Push. Pull
request into the sprint 04 branch. Copilot. CI green, including the
Playwright shards. STATUS line with the five test titles.

## Exit

1. J-WG-2 is the five tests, green headless (U10).
2. A broken close, theme, fullscreen, limit, or file chip fails CI, so
   the next person finds it in the spec rather than on a customer site (U2).
3. Limit copy is the copy already in the widget. The test matches the
   visible sentence in English, the suite's locale. Do not add strings.
4. The limit warning and the hard stop are different states. Removing a
   file leaves the composer usable (U5, U8).
5. The close/reopen test is `@crossbrowser`. Fullscreen asserts the host
   page is still visible around the widget (U9).

## Out of scope

Slack handoff. Retagging `@noci` tests. Changing widget behaviour.
Sprint 04's sources list (already gone on the parent branch).

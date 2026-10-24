# Open issues from #2617 — 2026-10-24

**Status:** Live. Sprint 01 is the first code change.
**Base:** `main` at the commit this folder lands on.
**Source:** every open issue on
[metadist/synaplan/issues](https://github.com/metadist/synaplan/issues)
numbered 2617 or higher, as of 2026-10-24.
**Goal:** Close each issue with one reviewable change, a regression test,
and a green pull request. Bugs before features. bilogic's reports first.

| File | Issue | Kind |
| ---- | ----- | ---- |
| [`01_import_button.md`](./01_import_button.md) | [#2678](https://github.com/metadist/synaplan/issues/2678) | bug |
| [`02_delete_default_model.md`](./02_delete_default_model.md) | [#2679](https://github.com/metadist/synaplan/issues/2679) | bug |
| [`03_owncloud_copy_label.md`](./03_owncloud_copy_label.md) | [#2671](https://github.com/metadist/synaplan/issues/2671) | bug |
| [`04_folder_read_honesty.md`](./04_folder_read_honesty.md) | [#2667](https://github.com/metadist/synaplan/issues/2667) | bug |
| [`05_completion_incognito.md`](./05_completion_incognito.md) | [#2617](https://github.com/metadist/synaplan/issues/2617) | bug |
| [`06_model_housekeeping.md`](./06_model_housekeeping.md) | [#2680](https://github.com/metadist/synaplan/issues/2680) | feature |
| [`STATUS.md`](./STATUS.md) | step log | — |

Three enhancements in the same number range stay on their existing track
and are not re-planned here:

| Issue | Track |
| ----- | ----- |
| [#2636](https://github.com/metadist/synaplan/issues/2636) static checks for `plugins/` | row 24, PQ1 |
| [#2638](https://github.com/metadist/synaplan/issues/2638) transcriber image publish | row 24, PQ2 |
| [#2635](https://github.com/metadist/synaplan/issues/2635) plugin i18n | row 24, PQ3 |

---

## 0. Decisions

| # | Decision | Why |
| - | -------- | --- |
| 1 | **Bugs before the feature.** [#2678](https://github.com/metadist/synaplan/issues/2678) and [#2679](https://github.com/metadist/synaplan/issues/2679) (bilogic) go first, then the other bugs, then [#2680](https://github.com/metadist/synaplan/issues/2680). | The request was to start with bilogic and fix the bugs first. |
| 2 | **One sprint, one pull request, one concern.** A sprint that finds a second bug records it and stops. | Reviewable diffs. `feat` vs `fix` stays honest for the release bump. |
| 3 | **The test fails on today's code, then the fix makes it pass.** A longer timeout is not a fix. | The issue comes back the week the wait is no longer long enough. |
| 4 | **Branches are cut from current `main`, not stacked.** Each pull request targets `main`. The next sprint starts only after **All Checks Passed** is green and every Copilot thread has a reply. | [`AGENTS.md`](../AGENTS.md). Do not merge; the owner merges. |
| 5 | **#2635, #2636 and #2638 stay on row 24.** This folder points at them. It does not open a second plan. | That track already has the decisions, the file list and the image-job shape. |
| 6 | **Copy is written in the sprint file before the Vue file.** New strings ship in `en`, `de`, `es`, `fr`, `tr` in the same pull request. | A missing key falls back to English and fails locale parity. |
| 7 | **#2680 does not delete rows by itself.** It marks a model the provider no longer offers, and it labels duplicates. Removal stays the admin's click, and that click is what sprint 02 makes possible. | Automatic deletion of a default embedding model rebuilds the search index. The issue asks to stop the one-by-one chore, not to drop models with no sentence. |

---

## 1. Order

```
main
 ├── docs/20261024-open-issues          this plan
 ├── fix/20261024-import-button         #2678
 ├── fix/20261024-delete-default-model  #2679
 ├── fix/20261024-owncloud-copy-label   #2671
 ├── fix/20261024-folder-read-honesty   #2667
 ├── fix/20261024-completion-incognito  #2617
 └── feat/20261024-model-housekeeping   #2680
```

Sprint 06 is the only `feat`. Everything else that changes product code
is `fix`. This plan is `docs`.

Commit subjects are the lines in each sprint file.

---

## 2. Loop

Each sprint file is the change. This section is how it becomes a pull
request. Follow [`AGENTS.md`](../AGENTS.md).

1. Read the sprint file. Stay inside the files it names.
2. Write the test. For a bug, run it and see it fail for the reason in
   the issue.
3. Make the smallest change that turns that test green.
4. Run the sprint's gate unfiltered. A filtered run finds the failure.
   It is not the gate.
5. Walk the journey when the sprint names one. Record it in `STATUS.md`.
6. Commit. No AI attribution. No secrets.
7. Push and open the pull request against `main`. The body contains
   `Fixes #NNNN`, the test, and the journey.
8. Request Copilot:

   ```bash
   gh api -X POST repos/metadist/synaplan/pulls/<number>/requested_reviewers \
     -f 'reviewers[]=copilot-pull-request-reviewer[bot]'
   ```

9. Reply on every Copilot thread. Fix a real defect on the same branch.
10. Wait until **All Checks Passed** is green. Then write the STATUS line
    and start the next sprint.

Classify before pushing:

```bash
node scripts/mobile-impact.mjs --base origin/main --head HEAD
```

This track must not be `store-required`. New paths go into
`.github/mobile-impact-policy.json` in the same pull request.

---

## 3. Gates

| Sprint | Local gate |
| ------ | ---------- |
| Plan | none (markdown only) |
| 01, 03 | `make ci-local` and `make test-e2e` |
| 02, 04 | `make ci-local` and `make test-e2e` (the answer is on screen) |
| 05 | `make ci-local`. No Playwright: the contract is HTTP and the worker, and no screen changes. |
| 06 | `make ci-local` and `make test-e2e` |

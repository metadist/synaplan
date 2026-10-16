# Six open issues — 2026-10-16

**Status:** Plan only. No product code in this pull request.
**Base:** `main` at `9ca56ccb12747c901b0bed491d8ae7a04ceb7a60`.
**Source:** the six newest product issues on
[metadist/synaplan/issues](https://github.com/metadist/synaplan/issues)
as of 2026-10-16. Left out on purpose: [#1915](https://github.com/metadist/synaplan/issues/1915)
(older, a typing programme of its own) and [#30](https://github.com/metadist/synaplan/issues/30)
(Renovate dashboard).
**Goal:** Close or fix each issue with one reviewable change, a regression
test, and a green pull request. The next session starts at sprint 01 and
stops when that sprint's STATUS line is written.

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Decisions, what is already on `main`, the branch stack, the vibe-coding loop |
| `01` … `06` | One sprint per issue. Implement in this order. |
| `STATUS.md` | The step log. A walk or a confirmation is a STATUS line, not a chat message. |

---

## 0. Decisions

| # | Decision | Why |
| - | -------- | --- |
| 1 | **Confirm before rewriting.** On this `main`, [#2459](https://github.com/metadist/synaplan/issues/2459), [#2460](https://github.com/metadist/synaplan/issues/2460) and [#2461](https://github.com/metadist/synaplan/issues/2461) already have the behaviour and the tests the issues ask for (§1). Sprints 01–03 prove that. A green proof closes the issue. It does not open a second implementation. | Rewriting a fixed path is how a regression lands. |
| 2 | **A branch exists only when the sprint has a diff.** A confirmation that passes has no pull request. A confirmation that fails becomes a fix branch, cut from the latest sprint branch that contains a commit. | Empty pull requests waste Copilot and CI. |
| 3 | **Stack the code branches.** Each code branch is cut from the previous code branch. Its pull request targets that parent. Merge parent first, then retarget the child onto `main` and rebase. Never merge a child while its parent is open. | The user asked for a chain that merges one after the other. |
| 4 | **One sprint, one pull request, one concern.** A sprint that discovers a second bug records it and stops. It does not fix the neighbour. | Reviewable diffs. `feat` vs `fix` stays honest for the release bump. |
| 5 | **The test is the first product commit of a bug sprint.** It fails on the current code, then the fix makes it pass. A longer timeout, a retry, or a refetch after save is not a fix ([#2460](https://github.com/metadist/synaplan/issues/2460) says this outright). | The issue comes back the week the wait is no longer long enough. |
| 6 | **Widget visitors do not see internal retrieval.** [#2519](https://github.com/metadist/synaplan/issues/2519) removes the sources list from the embed. Retrieval stays on the server. Signed-in chat and shared links keep their list. | The screenshot is a customer-site bug, priority 2. |
| 7 | **Widget turns do not search the public web.** An explicit "search the web" sentence is included. Link reading stays. | The embed has no search control. Hiding the list and leaving search on is the wrong fix. |
| 8 | **The prompt transcript is the owner's own reply, for this visit.** [#2515](https://github.com/metadist/synaplan/issues/2515) is a panel on the assistant message, not a new page and not a new rail item. No database migration in this track. The widget, a shared link, and the logs do not receive it. | The issue has no acceptance criteria. A stored prompt is a schema change and a data leak until those rules exist. |
| 9 | **Copy is reviewed in the sprint file before the Vue file.** New strings ship in `en`, `de`, `es`, `fr`, `tr` in the same pull request. | UX contract U1. A missing key falls back to English and fails locale parity. |
| 10 | **This folder name is the owner's.** House style is `YYYYMMDD-topic`. The owner named `20261016_6-issues`. Keep it. | The request was explicit. |

---

## 1. What is already on `main`

Measured at `9ca56ccb1`. These are the facts the confirmation sprints re-check.
They are not a claim that GitHub is already closed.

| Issue | On `main` today | Still open in the product |
| ----- | --------------- | ------------------------- |
| [#2459](https://github.com/metadist/synaplan/issues/2459) Task prompts | `TaskPromptsConfiguration.vue` starts `loading` true, hides the counts while loading, shows `task-prompts-loading`, and splits "No topics yet." from "No topics match your search." `TaskPromptsConfiguration.spec.ts` asserts the three states the issue names. `task-prompts.spec.ts` waits until `task-prompts-loading` is hidden. | Confirm, then close. Fix only a gap the tests miss. |
| [#2460](https://github.com/metadist/synaplan/issues/2460) Shortcuts | `PromptsView.vue` lays the saved row onto the list in `save()` and keeps it if a list response from before the save returns later (`listRevision`, `listWrites`). `PromptsView.spec.ts` covers name order without a second GET, and a save that happens while the list request is still open. | The CI trace (list already finished, POST 201, form closed, row missing) is not the in-flight race. Confirm with `--repeat-each=20`. Fix only what a new red test shows. |
| [#2461](https://github.com/metadist/synaplan/issues/2461) Checklist | `GettingStartedChecklist.vue` calls `GET /api/v1/getting-started` once. `GettingStartedController` and `GettingStartedStatusService` answer file / assistant / app / provider. The unit test asserts that single URL. `AppsView` still uses `loadConnectedAppIds` for per-app badges. | The issue's three open decisions are answered in §0 of sprint 03. Confirm, then close. Do not add a second endpoint. |
| [#2519](https://github.com/metadist/synaplan/issues/2519) Widget sources | `ChatWidget.vue` still mounts `KnowledgeSources` when `ragSources.length > 0`, copies them on the live `complete` event, and parses them from history. `TurnWebSearch::run` still treats an explicit search sentence as a search, with no widget opt-out. | Sprint 04. Real fix. |
| [#2504](https://github.com/metadist/synaplan/issues/2504) Widget chrome tests | `widget.spec.ts` has no test for close, theme, fullscreen, the message limit, or removing a staged file. The `data-testid`s exist. Assigned to FExB17; this plan does not reassign. | Sprint 05. Tests only. |
| [#2515](https://github.com/metadist/synaplan/issues/2515) Final prompts | No owner-facing transcript. The message array is built in `ChatMessageAssembler`. The info popover on `ChatMessage.vue` shows topic and model, not the text that was sent. | Sprint 06. Real feature, scoped by decision 8. |

---

## 2. Branch stack

```
main
 └── dev/20261016-6-issues          this plan (PR → main)
      └── dev/20261016-s4-widget-sources     #2519   (only if 01–03 add no commit)
           └── dev/20261016-s5-widget-chrome #2504
                └── dev/20261016-s6-prompt-transcript   #2515
```

Sprints 01–03 insert a branch only when the confirmation fails. The name is
`dev/20261016-sN-<slug>` and it becomes the new parent of everything after it.

| Sprint | Branch, if it has a diff | Cut from | Pull request base |
| ------ | ------------------------ | -------- | ----------------- |
| Plan | `dev/20261016-6-issues` | `main` | `main` |
| 01 #2459 | `dev/20261016-s1-task-prompts` | latest code branch, else `main` | that parent |
| 02 #2460 | `dev/20261016-s2-shortcuts` | latest code branch | that parent |
| 03 #2461 | `dev/20261016-s3-checklist` | latest code branch | that parent |
| 04 #2519 | `dev/20261016-s4-widget-sources` | latest code branch | that parent |
| 05 #2504 | `dev/20261016-s5-widget-chrome` | the sprint 04 branch | sprint 04 |
| 06 #2515 | `dev/20261016-s6-prompt-transcript` | the sprint 05 branch | sprint 05 |

After a parent merges: `gh pr edit <child> --base main`, rebase the child
onto `main`, push. Do not force-push `main`. A force-push of a feature
branch is only for a rebase the owner asked for; prefer a merge from the
updated parent when the branch is already under review.

Commit subjects follow Conventional Commits. A `feat` subject bumps the
minor release. Sprint 06 is the only `feat`. Sprints 04 and any real fix
in 01–03 are `fix`. Sprint 05 is `test`. This plan is `docs`.

---

## 3. The vibe-coding loop

Every sprint file runs this loop. The sprint file says what to change.
This section says how the change becomes a merged pull request.

1. **Read the sprint file and `AGENTS.md`.** Stay inside the files it names.
2. **Write the test the sprint names.** For a bug, run it and see it fail
   for the reason in the issue. A test that passes before the fix is the
   wrong test.
3. **Make the smallest change that turns that test green.** Match the
   surrounding code. English in code and comments. No new dependency.
4. **Run the sprint's gate unfiltered.** A filtered PHPUnit, PHPStan, or
   Vitest run is how you find the failure. It is not the gate. The gate is
   in §4.
5. **Walk the journey** when the sprint has a user-visible surface. Click,
   type, find, undo. Record the walk in `STATUS.md`. A screenshot is not
   the walk.
6. **Commit** on the sprint branch. No AI attribution. No secrets.
7. **Push and open the pull request** against the parent in §2. The body
   links the issue, names the test, and names the journey.
8. **Request Copilot.** From the repo root:

   ```bash
   gh pr create --base <parent> --title "<type>(<scope>): <why>" --body-file <body>
   gh pr edit <number> --add-reviewer copilot
   ```

   If the reviewer login differs on this repo, request the Copilot code
   reviewer the repository already uses. The request has to succeed. A
   pull request Copilot never saw is not done.
9. **Read every Copilot comment.** Fix what is a real defect, a broken
   contract, or a missed test. Reply on the thread when you decline one,
   with the reason (out of scope, already covered, or a wrong reading of
   the sprint file). Push the fixes to the same branch.
10. **Wait until the required checks are green.** `All Checks Passed` is
    the merge gate. A red job is the next edit, not a follow-up issue.
    Re-request Copilot only when the new diff changes the design Copilot
    reviewed. A comment-only fix does not need a second review cycle
    beyond CI.
11. **Write the STATUS line** on the same branch: date, SHA, PR URL,
    Copilot disposition (fixed / declined with reason), CI green. Stop.
    The next sprint starts in a new session from that line.

Rules that sit on top of the loop:

- Do not start sprint N+1 in the same session as sprint N.
- Do not retarget, rebase, or merge a child to get CI green on the parent.
- Do not weaken a test, a ratchet, a deptrac baseline, or a snapshot to
  go green. Characterization snapshots move only with `UPDATE_*_SNAPSHOTS=1`
  and a reviewed diff, and only when the sprint says the behaviour changes.
- Classify the diff with
  `node scripts/mobile-impact.mjs --base <parent> --head HEAD`
  before pushing. New paths go into `.github/mobile-impact-policy.json`
  in the same pull request. This track must not be `store-required`.

---

## 4. Gates

| Sprint touches | Before commit | Before push |
| -------------- | ------------- | ----------- |
| Planning docs only (this PR) | `docs` commit, English | Pull request + Copilot. `no-app-impact` (`_devextras/**`). |
| Backend PHP | `make -C backend lint`, `phpstan`, `deptrac`, `test` | The same unfiltered. Playwright only when the HTTP contract the UI uses changes. |
| Frontend Vue / TS | `make -C frontend lint`, `npm run check:types` in the frontend container, `make -C frontend test` | `make ci-local` and `make test-e2e` when the change is user-visible or OpenAPI-facing. Headless. Never `HEADED=1`. |
| OpenAPI annotations | `make -C frontend generate-schemas`, then `vue-tsc` | The schema diff is committed. A hand-written response interface is a reject. |
| Widget behaviour | Frontend gate plus the widget unit test | `make test-e2e` including `tests/widget.spec.ts`. Sprint 05's own tests are part of that run. |
| i18n | All five locales, placeholder names identical to English | `tests/unit/i18n/localeParity.spec.ts` inside `make -C frontend test`. |

`make ci-local` does not run Playwright. A green unit run with a red
`saved-prompts` or `widget` spec is a red sprint.

---

## 5. Step table

| Sprint | Issue | Class | Outcome on a pass | Outcome on a fail |
| ------ | ----- | ----- | ----------------- | ----------------- |
| 01 | [#2459](https://github.com/metadist/synaplan/issues/2459) | `no-app-impact` if already green; `ota-candidate` if a fix lands | Close the issue. STATUS line. | Fix branch, loop §3. |
| 02 | [#2460](https://github.com/metadist/synaplan/issues/2460) | same | Close the issue. STATUS line. | Fix branch, loop §3. |
| 03 | [#2461](https://github.com/metadist/synaplan/issues/2461) | same | Close the issue. STATUS line. | Fix branch, loop §3. |
| 04 | [#2519](https://github.com/metadist/synaplan/issues/2519) | `ota-candidate` (Vue) plus backend in the same PR | PR, Copilot, green CI. | Stay on the branch until CI is green. |
| 05 | [#2504](https://github.com/metadist/synaplan/issues/2504) | `no-app-impact` (`frontend/tests/**` only) | PR, Copilot, green CI. | Stay on the branch. Do not retag `@noci` tests. |
| 06 | [#2515](https://github.com/metadist/synaplan/issues/2515) | `ota-candidate` | PR, Copilot, green CI. | Stay on the branch. No migration. |

---

## 6. Out of scope for the whole track

- [#1915](https://github.com/metadist/synaplan/issues/1915) and [#30](https://github.com/metadist/synaplan/issues/30).
- Splitting `TaskPromptsConfiguration.vue` (~2,400 lines) or `ChatWidget.vue`.
- Qdrant `file_name` on payloads, the crawl, `ragResultLimit`, `ragMinScore`.
- A database table for prompts. That is a later decision and needs an
  explicit schema review (`docs/MIGRATIONS.md`, Galera-safe SQL).
- A new navigation item, a public debug page, or a widget citation redesign.
- Code cleanup V4, the prompting flow, and anything in `ROADMAP.md` rows
  4–17.

---

## 7. Start here

The next session does **sprint 01 only**, in
[`01_task_prompts_loading.md`](./01_task_prompts_loading.md). It ends with
a STATUS line. It does not open sprint 02.

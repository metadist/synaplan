# The vibe coding loop — how every V4 sprint runs

Professional vibe coding means the agent writes the code, and nothing
reaches `main` that a test has not proven, a reviewer has not read and CI
has not passed. This file is the procedure. The sprint files say *what*
changes; this file says *how*, and it is the same for all twelve.

Binding underneath: [`AGENTS.md`](../../../AGENTS.md) (gates, Git rules,
commit types, red flags), [`docs/ARCHITECTURE.md`](../../../docs/ARCHITECTURE.md),
[`docs/PHP_CONVENTIONS.md`](../../../docs/PHP_CONVENTIONS.md),
[`docs/FRONTEND_CONVENTIONS.md`](../../../docs/FRONTEND_CONVENTIONS.md),
[`docs/E2E_TESTING.md`](../../../docs/E2E_TESTING.md), and for S1
[`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md).

---

## 1. Roles

| Role | Does | Never |
| ---- | ---- | ----- |
| **Agent** | Reads the plan, writes the tests first, implements in small commits, runs every gate, writes the pull request, answers every review thread, keeps CI green, writes STATUS | Merges; uses `--admin`; pushes to `main`; re-records a snapshot without reading the diff; resolves a thread without an answer; adds AI attribution |
| **Copilot** | First review, automatically when a non-draft pull request opens (ruleset `ReviewCodeSyn`); a re-review on request | — |
| **Owner** | Ticks the §0 decisions D1–D3; answers stop-rule questions (§12); approves and merges in order; walks S1b's journeys with the agent's walk record | — |

---

## 2. The branch chain

```bash
git fetch origin --prune
git switch -c <sprint-branch> <parent-branch>   # the previous sprint's branch, at its head
```

- The parent of S0 is `docs/cleanup-v4-plan`, or `origin/main` once the
  plan has merged. The parent of S(n) is S(n−1)'s branch, or `origin/main`
  once S(n−1) has merged.
- **WIP limit: two.** S(n+2) does not start before S(n) is merged.
- **Keep the child on the parent's head.** When the parent gets a review
  fix, rebase the child the same day (`git rebase <parent-branch>`) and run
  the child's sprint test again.
- Force-push is allowed only on your own sprint branch after a rebase, and
  only as `git push --force-with-lease`. Never on `main`.
- Branch names are in the master plan §2. They follow the pull request's
  commit type (`fix/…`, `refactor/…`).
- **One checkout, one session.** The dev stack's containers mount
  `/Users/puzzler/wwwroot/synaplan`, so every `docker compose exec` gate
  tests what is checked out *there*. A sprint is implemented in that
  checkout, and no other session switches its branch meanwhile. Work that
  needs no gate run (planning, review answers) uses its own worktree:
  `git worktree add ../synaplan-<topic> <branch>`. On 2026-10-16 two
  sessions switched branches in one checkout and carried each other's
  uncommitted files. This rule exists because of that.

---

## 3. The loop

| # | Step | Done when |
| - | ---- | --------- |
| 1 | **Read.** AGENTS.md, this file, the sprint file, the `docs/ARCHITECTURE.md` section of the code you touch. Check the sprint's owner decision is ticked in master plan §0. | You can name the files, the proof test and the exit before writing code |
| 2 | **Record the predecessor.** From S1 on, the sprint's first commit adds the parent's merge row to STATUS (pull request, merge SHA, ledger after). | STATUS shows where this sprint starts |
| 3 | **Measure.** `make -C backend metrics` (S1: the frontend scan), and the sprint file's own numbers. | Start row in STATUS |
| 4 | **Tests first, every commit green.** Refactor: the characterization of the untouched code, committed on its own as `test(…)`. Fix: write the regression test, run it on the parent, quote the failure in STATUS, and commit the test **together with its fix**, so no commit is red (§5). Where today's behaviour can be pinned (a shrink-only gap list, a table of current answers), commit that pin first and let each fix commit change its expectation: the expectation diff is the behaviour change. | The characterization is on the branch; for a fix, its failure on the parent is quoted in STATUS |
| 5 | **Implement** in small commits, one logical change each. Each commit is green on the per-commit gate (§5). | The sprint file's steps are all committed |
| 6 | **Sprint test** (§4). | Proof, regression and journey are recorded in STATUS |
| 7 | **Document.** The `docs/ARCHITECTURE.md` section for every new seam; a docblock on every new class; the sprint file's "As delivered" note when the result differs from the plan. | Docs are in the same pull request, not a follow-up |
| 8 | **Full gate** (§5, before push), unfiltered. | Every command green, or each failure matched by name to the [known local-only list](../../../docs/E2E_TESTING.md#known-local-only-failures) |
| 9 | **Push and open the pull request** (§6), non-draft. | Pull-request link in STATUS |
| 10 | **Copilot review** (§8): every thread answered, a re-review after the fixes. | No unanswered thread; the re-review has no new actionable finding |
| 11 | **CI** (§9): All Checks Passed on the head commit. | Green, or the pull request waits for its parent (stacked) |
| 12 | **Chore** (§7) and **hand-off** (§10). | Merge-ready; the owner merges; the child is rebased |

---

## 4. The sprint test

Every sprint ends with all three layers. Each one answers a question the
next sprint must not have to ask.

| Layer | Question | What counts |
| ----- | -------- | ----------- |
| **Proof** | Does the change do what the sprint says? | For a `fix:`, a regression test that **fails on the parent branch**: run it there before the fix, paste the failing line into STATUS, and commit it with the fix (loop step 4). For a `refactor:`, the characterization snapshot recorded on the untouched code in the sprint's first commit, plus a unit test for every new class. **Mutation check:** break one new class on purpose (or revert one moved branch), see a test fail, restore. A test that cannot fail proves nothing. |
| **Regression** | Did anything else move? | Every characterization snapshot unchanged for a refactor; for a fix, the snapshot diff reviewed line by line and quoted in the pull request. The unfiltered gates. `make test-e2e`, plus the sprint file's named specs. |
| **Journey** | Does a person still get the result? | S1: the named journeys walked in the browser, light and dark, 320 px, recorded in STATUS (who, what was clicked and typed, what was seen). Backend sprints: the named `@ci` E2E specs on the path, and for S0 an account deleted through the UI. |

The STATUS row of the sprint test names the command, the test counts and
every E2E failure, each matched to the known-local list or explained.

---

## 5. Quality gates

```bash
# Every commit — backend
make -C backend lint
make -C backend phpstan            # src/ and tests/, never a single path
make -C backend deptrac
make -C backend test               # size ratchet and every characterization snapshot included

# Every commit — frontend (S1)
make -C frontend lint
docker compose exec -T frontend npm run check:types
make -C frontend test

# Before every push
make ci-local
make test-e2e                      # Playwright on the host, headless
docker compose exec -T backend php bin/console lint:container
make -C frontend generate-schemas && git diff --exit-code frontend/src/generated/
node scripts/mobile-impact.mjs --base <parent-branch> --head HEAD   # = the sprint's class

# S1 in addition
make -C frontend test-e2e-layout
cd frontend && npm run test:e2e:firefox    # the chat specs are @crossbrowser
```

- Snapshots are re-recorded only with their `UPDATE_*` variable, and only
  in a `fix:` sprint. Then `git diff backend/tests/Characterization/__snapshots__/`
  is read line by line.
- Ledgers only shrink: `UPDATE_SIZE_RATCHET=1 php bin/phpunit tests/Architecture/SizeRatchetTest.php`
  and `make -C backend deptrac-baseline` produce removals and smaller
  numbers only. A grown entry is a design problem, not a ledger update.
- `Unable to write in the "cache" directory`: `docker compose exec -T backend rm -rf var/cache/test`.

---

## 6. The pull request

Title: the master plan §2 title, verbatim. It becomes the commit on `main`.

```bash
gh pr create --base <parent-branch-or-main> --head <sprint-branch> \
  --title "<title from master plan §2>" --body-file <body.md>
```

Never `--draft`: Copilot does not review drafts. The body follows the
repository template:

```markdown
## Summary
<One sentence: what changed and why.> Sprint S<n> of code cleanup V4
(_devextras/planning/20261016-code-cleanup-V4/S<nn>_….md).

## Changes
- <one line per commit that matters>

## Verification
- [x] Tests added/updated — proof: `<TestClass>` (fails on the parent: "<one line>");
      regression: snapshots <unchanged | diff below>; backend <N> tests, frontend <N>;
      E2E <passed>/<total>, failures matched to the known local-only list: <names>
- [ ] Manual — <S1: journeys walked, light / dark / 320 px>

## Notes
- Release class: <backend-only | ota-candidate> (`scripts/mobile-impact.mjs`)
- Security impact: <S0 and S9: what changes for whom; otherwise "none">
- Snapshot diff (fix sprints): <quoted lines and why each moved>
- Reviewer focus: <the two or three places where a mistake would hide>
- Not in this pull request: <what the sprint file excludes>
- Stacked on #<parent> — merge after it.

## Screenshots/Logs
<`make -C backend metrics` before → after; S1: screenshots light / dark / 320 px>
```

"Reviewer focus" is the most useful line for Copilot and the owner. It
names the code where the sprint could be wrong, not where it is easy.

---

## 7. The chore (C0–C11)

Each sprint S(n) closes with its chore C(n). It is the last commit of the
sprint, `chore(planning): close V4 S<n> — sprint test, status, ledgers`,
plus the review and CI work that follows.

1. **STATUS:** the sprint row (three ratchet numbers, class worked on,
   lines and parameters before → after), the sprint-test row, the review
   log after §8.
2. **Ledgers:** the size ledger, the deptrac baseline, from S1 the
   frontend ledger, in S0 `NOT_ERASED_YET`. Each one only shrinks.
3. **Docs:** `docs/ARCHITECTURE.md`, docblocks, and the sprint file's
   "As delivered" note when the result differs from the plan.
4. **Roadmap:** row 19's state cell names the sprint and its pull
   request.
5. **Push, pull request, Copilot, CI:** §6, §8 and §9.
6. **Hand-off:** §10.

---

## 8. Copilot review

Copilot reviews once, when the pull request opens. It does not review on
push (`review_on_push: false`), so every later round is a request.
`gh pr edit --add-reviewer copilot` does not resolve a user on this
repository. The `requested_reviewers` call below does, and it leaves the
`review_requested` event that V3 shows on #2512–#2514. Confirm that event
after every request: a round Copilot never saw does not count.

```bash
# Threads, with their state
gh api graphql -F n=<PR> -f query='
  query($n: Int!) { repository(owner: "metadist", name: "synaplan") {
    pullRequest(number: $n) { reviewThreads(first: 100) { nodes {
      id isResolved path line
      comments(first: 10) { nodes { databaseId author { login } body } } } } } } }'

# Answer a thread, then resolve it
gh api repos/metadist/synaplan/pulls/<PR>/comments/<comment-id>/replies -f body='<answer>'
gh api graphql -f id=<thread-id> -f query='
  mutation($id: ID!) { resolveReviewThread(input: { threadId: $id }) { thread { isResolved } } }'

# Ask for a round (if the automatic one did not come) and after the fixes are pushed
gh api -X POST repos/metadist/synaplan/pulls/<PR>/requested_reviewers \
  -f 'reviewers[]=copilot-pull-request-reviewer[bot]'
gh api "repos/metadist/synaplan/issues/<PR>/timeline?per_page=100" \
  --jq '.[] | select(.event == "review_requested") | {reviewer: .requested_reviewer.login, created_at}'
```

Triage every finding into one of four answers:

| Finding | Answer |
| ------- | ------ |
| **Defect** | Reproduce it with a failing test first, fix it, reply with the commit and the test name, resolve. Then **audit the whole diff for the same class of mistake.** In V3 that audit found two more gaps the reviewer had not named. |
| **Improvement within scope** (name, docblock, missing edge case) | Apply it if it belongs to this sprint, reply, resolve. |
| **Out of scope or wrong** | Reply with the reason and a reference (plan decision, code line, doc), resolve. A valid out-of-scope point goes into STATUS as "carried". |
| **Product question** | Ask the owner. The thread stays open until answered. |

Copilot's overview ("🟢 Approval recommended" / "🔵 Needs a closer look")
is not a thread. Copy its named risks into the review log and answer them
in the pull-request description. **At most three rounds;** a fourth means
the sprint is unclear, and the owner decides.

---

## 9. CI

CI runs for pull requests into `main` only. A stacked pull request gets
its CI after the parent merges (§10). Until then the local gate is the
proof.

```bash
gh pr checks <PR> --watch --interval 60          # a run takes about 10–15 minutes
gh run view <run-id> --log-failed                 # the failing step
gh run download <run-id> --dir /tmp/ci-<PR>       # Playwright results, backend-logs/
python3 _devextras/e2e/playwright-trace-timeline.py <trace.zip>
```

- **Red because of the sprint:** reproduce locally, fix it with a test,
  push a new commit. Do not amend reviewed commits.
- **Red before any test ran** (`Unable to download artifact`): Actions
  infrastructure; `gh run rerun <run-id> --failed`, record it in STATUS.
- **A flake:** first the timeline, then the cause. One rerun only with the
  timeline in STATUS. A second failure is a bug.
- **Never:** raise a timeout as a fix, add a retry loop, tag a test
  `@noci`, skip a test, merge with `--admin`.

---

## 10. Merge-ready and hand-off

A pull request is merge-ready when all of these hold:

- [ ] Base is `main`; **All Checks Passed** is green on the head commit.
- [ ] Every Copilot thread is answered and resolved; the last re-review
      came after the last fix and found nothing new.
- [ ] The sprint test is in STATUS; the ledgers shrank or stayed the same.
- [ ] The description carries the final numbers, the release class and,
      for S0 and S9, the security impact.

The owner squash-merges with the title unchanged. GitHub deletes the
branch and retargets the child pull request to `main`. The agent then
moves the child onto the squash commit and pushes. That push starts the
child's CI:

```bash
git fetch origin --prune
git rebase --onto origin/main <parent-branch> <child-branch>   # local parent ref = what was merged
git push --force-with-lease origin <child-branch>
```

The child's next commit records the merge in STATUS (§3, step 2).

---

## 11. The code bar

"Elegant" here means a reader understands the change without the plan
open next to it.

- **Names say the domain:** `TurnHistoryLoader`, `CredentialEraser`;
  never `…Helper`, `…Util`, `…Manager`.
- **One reason to change per class**, said in the class docblock. `final
  readonly`, constructor injection, ≤ 10 parameters, strict types.
- **Short methods.** Aim for ≤ 50 lines; the gate is 100. Early returns, no
  nesting deeper than three levels.
- **No flag parameters** that switch behaviour. Use two named methods or a
  value object. A new seam passes typed values (`final readonly` classes
  in `App\Model\` or the domain folder); `array<string, mixed>` stays only
  at boundaries that already have it.
- **A second copy is a bug.** Extract when the second copy appears, not the
  third.
- **Errors say what failed and for which id.** Use specific exceptions. A
  user sees one honest sentence, never a stack trace (UX contract U8).
- **No dead code, no commented-out code, no TODO without a plan
  reference.** Logs use structured context and never contain secrets or
  message text.
- **Tests read as sentences** (`testDeletedAccountLeavesNoStoredCredential`),
  one behaviour each, minimal fixtures, no `sleep`.
- **Frontend:** `<script setup lang="ts">`, logic in composables and pure
  functions that have unit tests, Zod for every API response, every string
  through i18n in five locales, no `any`, no `setTimeout` race fixes.
- **Docs travel with the code.** A new seam that is not in
  `docs/ARCHITECTURE.md` is unfinished.

---

## 12. Stop rules

Stop and ask the owner, with a WIP note in STATUS, when:

- the same fix failed twice;
- a snapshot diff appears that you cannot explain line by line;
- a Copilot finding contradicts the plan and you cannot refute it with a
  reference;
- an E2E failure is not on the known-local list and not reproducible on
  `main`;
- the sprint would exceed its size budget and the split is not obvious;
- a step needs a schema migration, a new dependency, or a Docker / CI
  change (AGENTS.md "Ask first").

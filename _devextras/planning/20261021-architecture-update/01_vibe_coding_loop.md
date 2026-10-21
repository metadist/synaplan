# The vibe coding loop — how every step of this track runs

The agent writes the code. Nothing reaches `main` that a test has not proven,
a reviewer has not read and CI has not passed. This file is the procedure; the
sprint files say *what* changes, this file says *how*, and it is the same for
every step from AU1 to AU35. It is the loop of code cleanup V4
(`2026-archive/20261016-code-cleanup-V4/01_vibe_coding_loop.md`), which shipped
fifteen pull requests in two days without a regression, plus three things this
track needs: flag ramps, a performance layer, and dual-mode equality.

Binding underneath: [`AGENTS.md`](../../../AGENTS.md) (gates, Git rules, commit
types, red flags), [`docs/ARCHITECTURE.md`](../../../docs/ARCHITECTURE.md),
[`docs/PHP_CONVENTIONS.md`](../../../docs/PHP_CONVENTIONS.md),
[`docs/FRONTEND_CONVENTIONS.md`](../../../docs/FRONTEND_CONVENTIONS.md),
[`docs/E2E_TESTING.md`](../../../docs/E2E_TESTING.md), and for the five
`ota-candidate` steps [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md).

---

## 1. Roles

| Role | Does | Never |
| ---- | ---- | ----- |
| **Agent** | Reads the plan, writes the tests first, implements in small commits, runs every gate, writes the pull request, answers every review thread, keeps CI green, writes STATUS | Merges; uses `--admin`; pushes to `main`; re-records a snapshot without reading the diff; resolves a thread without an answer; adds AI attribution; switches a flag on in production |
| **Copilot** | First review when a non-draft pull request opens; a re-review on request | — |
| **Owner** | Ticks §0; answers stop-rule questions (§12); approves and merges in order; walks the journeys with the agent's walk record; flips production flags | — |
| **Ops** | Rolls out S0 and S5 one web node at a time in `synaplan-platform`; reads the capacity page before and after | Changes two nodes at once |

---

## 2. Branches

```bash
git fetch origin --prune
git switch -c <step-branch> origin/main          # or the previous step's branch inside one sprint
```

- Branch names follow the commit type: `fix/au3-stream-pool`,
  `refactor/au10-call-pipeline`, `test/au9-ai-paths`, `feat/au6-watchdog`.
- Inside a sprint the steps may stack on each other's branches. **WIP limit:
  two unmerged step branches above `main`.** Across sprints, start from
  `origin/main`.
- Keep a child on its parent's head: when the parent gets a review fix,
  `git rebase <parent>` the same day and run the child's sprint test again.
- Force-push only your own step branch after a rebase, only with
  `--force-with-lease`. Never `main`.
- **One checkout, one session.** The dev stack mounts
  `/Users/puzzler/wwwroot/synaplan`; every `docker compose exec` gate tests
  what is checked out *there*. Implement in that checkout and let no other
  session switch its branch meanwhile. Work that needs no gate (planning,
  review answers) uses a worktree: `git worktree add ../synaplan-<topic> <branch>`.
- Cross-repo steps (AU8, AU34) branch in `synaplan-charts` and follow its
  `AGENTS.md` (`make docs`, `make lint`, `make template`, `make all`).

---

## 3. The loop

| # | Step | Done when |
| - | ---- | --------- |
| 1 | **Read.** AGENTS.md, this file, the sprint file, the contract section in `03` it names, the `docs/ARCHITECTURE.md` section of the code you touch. Check the step's §0 rows are ticked. | You can name the files, the proof test, the flag and the exit before writing code |
| 2 | **Record the start.** STATUS: the parent's merge (pull request, SHA), `make -C backend metrics` (files / constructors / methods), and for S0, S1, S2, S5 a `make perf-runtime` run. | STATUS shows where this step starts |
| 3 | **Characterize.** For a `refactor:` the snapshot of the untouched code, committed alone as `test(…)`. For a `fix:` the regression test, run on the parent with its failure quoted in STATUS, committed **with** its fix so no commit is red. | The safety net is on the branch |
| 4 | **Flag.** Every new behaviour lands behind its kill switch, default off (§0 #24). Where the step has a shadow or report mode, it lands first (§5). | Flags off ⇒ every snapshot byte-identical |
| 5 | **Implement** in small commits, one logical change each, each green on the per-commit gate (§6). New code in new classes; ledgers only shrink. | The sprint file's steps are committed |
| 6 | **Sprint test** (§4): proof, regression, journey, performance. | Recorded in STATUS with commands and counts |
| 7 | **Document.** `docs/ARCHITECTURE.md` for every new seam; the doc the step names (`docs/EGRESS.md`, `docs/CAPACITY.md`, `docs/PROVIDERS.md`, `docs/FEATURE_FLAGS.md`); a docblock on every new class; the sprint file's "As delivered" note when the result differs. | Docs are in the same pull request |
| 8 | **Full gate** before push (§6), unfiltered. | Every command green, or each failure matched by name to the [known local-only list](../../../docs/E2E_TESTING.md#known-local-only-failures) |
| 9 | **Push and open the pull request** (§7), non-draft. | Link in STATUS |
| 10 | **Copilot** (§9): every thread answered, a re-review after the fixes. | No unanswered thread; the re-review has no new actionable finding |
| 11 | **CI** (§10): All Checks Passed on the head commit. | Green |
| 12 | **Chore and hand-off** (§8, §11). | Merge-ready; the owner merges; ops rolls out where the step says |

---

## 4. The sprint test

Every step ends with all four layers. Each answers a question the next step
must not have to ask.

| Layer | Question | What counts |
| ----- | -------- | ----------- |
| **Proof** | Does the change do what the step says? | For a `fix:` a regression test that **fails on the parent** (failure line in STATUS). For a `refactor:` the characterization recorded first plus a unit test per new class. For a `feat:` a test per behaviour of the contract section. **Mutation check:** break one new class on purpose (or revert one moved branch), see a test fail, restore. A test that cannot fail proves nothing. |
| **Regression** | Did anything else move? | Every characterization snapshot unchanged for a refactor; for a fix the diff reviewed line by line and quoted in the pull request. Flags off ⇒ byte-identical. The unfiltered gates. `make test-e2e` plus the step's named specs. |
| **Journey** | Does a person still get the result? | UI steps: the named journey walked in the browser, light and dark, 320 px, five locales spot-checked, entered in STATUS (who, what was clicked and typed, what was seen). Backend steps: the `@ci` specs on the path (chat, widget, Saved Tasks, gateways), named in the sprint file. |
| **Performance** | Is it as fast as before, and does it hold its budget? | `make perf-runtime` before and after, both in STATUS. A budget of master plan §6 breached ⇒ the step is not done. A drift over 20 % ⇒ explained in the pull request. Runtime steps add the sprint file's own load case. |

S5 adds a fifth check, **dual-mode equality**: the same scripted turns run
with the pump off and on, and `StreamTranscriptCharacterizationTest` (and its
gateway counterparts) must produce the same frames, byte for byte except ids
and timestamps.

---

## 5. Flag ramps

A behaviour change that people or providers would notice is never switched on
in one step.

| Stage | Meaning | Who |
| ----- | ------- | --- |
| **off** | Code on `main`, flag off, snapshots unchanged | Agent, in the step's pull request |
| **shadow / report** | The new path computes its result next to the old one and logs differences (metering: the row it would write; egress: the request it would block; pump: n/a — it uses dual-mode tests instead) | Agent, same or next pull request |
| **one installation** | The owner's test installation or one production web node, for the soak the sprint file names (usually seven days), watched on the capacity page | Owner, ops |
| **default for new installs** | The seeder sets it on | Agent, a `chore(config)` or the step's follow-up |
| **existing installs** | An explicit migration `UPDATE` of the BCONFIG row, because seeder defaults are bootstrap-only (AGENTS.md) | Agent, after the owner's go |

The kill switch stays after the ramp. Removing a flag is its own pull request,
after one release with the flag on everywhere.

---

## 6. Quality gates

```bash
# Every commit — backend
make -C backend lint
make -C backend phpstan            # src/ and tests/, never a single path
make -C backend deptrac
make -C backend test               # size ratchet, thin-controller gate and every characterization snapshot included

# Every commit — frontend (AU4, AU7, AU21, AU25b, AU33)
make -C frontend lint
docker compose exec -T frontend npm run check:types
make -C frontend test

# Every commit — watchdog (AU6, AU28, AU31)
make -C sidecars/synaplan-watchdog test     # go vet, go test -race, the size budget

# Before every push
make ci-local
make test-e2e                      # Playwright on the host, headless
docker compose exec -T backend php bin/console lint:container
make -C frontend generate-schemas && git diff --exit-code frontend/src/generated/
node scripts/mobile-impact.mjs --base <parent-branch> --head HEAD   # = the step's class
make perf-runtime                  # S0, S1, S2, S5 steps

# UI steps in addition
make -C frontend test-e2e-layout

# Docker / compose / image steps (AU3, AU6, AU18, AU30, AU31) in addition
make test-e2e-full                 # the production test image on :8001
```

- Snapshots are recorded only with their `UPDATE_*` variable, and the diff
  (`git diff backend/tests/Characterization/__snapshots__/`) is read line by
  line. Additive recordings (new cases, new files) on untouched code, in the
  step's `test(…)` commit; changed expectations only in a `fix(…)` commit,
  quoted in the pull request.
- Ledgers only shrink: the size ratchet, the deptrac baseline, the
  thin-controller baseline, and this track's own `EgressInventoryTest` and
  `LongWaitGateTest` baselines.
- `Unable to write in the "cache" directory`: `docker compose exec -T backend rm -rf var/cache/test`.

---

## 7. The pull request

Title: the §5 title of the master plan, verbatim. It becomes the commit on
`main` and decides the version bump.

```bash
gh pr create --base <parent-or-main> --head <step-branch> \
  --title "<title from master plan §5>" --body-file <body.md>
```

Never `--draft`: Copilot does not review drafts. Body:

```markdown
## Summary
<One sentence: what changed and why.> Step AU<n> of the architecture update
(_devextras/planning/20261021-architecture-update/S<k>_….md).

## Changes
- <one line per commit that matters>

## Verification
- [x] Proof: `<TestClass>` (fails on the parent: "<one line>"); mutation: <what was broken, which test failed>
- [x] Regression: snapshots <unchanged | diff below>; backend <N> tests, frontend <N>; E2E <passed>/<total>, failures matched to the known local-only list: <names>
- [x] Performance: `make perf-runtime` before → after: <the budget rows>
- [ ] Journey: <J-AU-n walked, light / dark / 320 px> (UI steps)

## Notes
- Release class: <class> (`scripts/mobile-impact.mjs`)
- Flag: <name>, default <off>; kill switch: <how>
- Security impact: <egress, tools, tokens: what changes for whom; otherwise "none">
- Snapshot diff (fix steps): <quoted lines and why each moved>
- Reviewer focus: <the two or three places where a mistake would hide>
- Not in this pull request: <what the sprint file excludes>
- Rollout: <none | one web node, then the others, after <soak>>
```

"Reviewer focus" names the code where the step could be wrong, not where it
is easy. It is the most useful line for Copilot and the owner.

---

## 8. The chore

Each step closes with `chore(planning): close AU<n> — sprint test, status`
as its last commit, plus the review and CI work that follows.

1. **STATUS:** the step row (metrics before → after, perf before → after),
   the sprint-test row, the review log after §9.
2. **Ledgers:** each one only shrinks.
3. **Docs:** as loop step 7.
4. **Roadmap:** row 22's state cell names the step and its pull request.
5. **Push, pull request, Copilot, CI:** §7, §9, §10.
6. **Hand-off:** §11.

---

## 9. Copilot review

Copilot reviews once when the pull request opens; every later round is a
request. Confirm the `review_requested` event after every request: a round
Copilot never saw does not count.

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

# Ask for a round after the fixes are pushed
gh api -X POST repos/metadist/synaplan/pulls/<PR>/requested_reviewers \
  -f 'reviewers[]=copilot-pull-request-reviewer[bot]'
gh api "repos/metadist/synaplan/issues/<PR>/timeline?per_page=100" \
  --jq '.[] | select(.event == "review_requested") | {reviewer: .requested_reviewer.login, created_at}'
```

| Finding | Answer |
| ------- | ------ |
| **Defect** | Reproduce with a failing test, fix, reply with the commit and the test name, resolve. Then **audit the whole diff for the same class of mistake**. |
| **Improvement within scope** | Apply, reply, resolve. |
| **Out of scope or wrong** | Reply with the reason and a reference (plan row, code line, doc), resolve. A valid out-of-scope point goes into STATUS as "carried". |
| **Product question** | Ask the owner; the thread stays open until answered. |

Copilot's overview is not a thread: copy its named risks into the review log
and answer them in the description. **At most three rounds**; a fourth means
the step is unclear and the owner decides.

---

## 10. CI

```bash
gh pr checks <PR> --watch --interval 60          # about 10–15 minutes
gh run view <run-id> --log-failed
gh run download <run-id> --dir /tmp/ci-<PR>       # Playwright results, backend-logs/
python3 _devextras/e2e/playwright-trace-timeline.py <trace.zip>
```

- **Red because of the step:** reproduce locally, fix it with a test, push a
  new commit. Do not amend reviewed commits.
- **Red before any test ran** (`Unable to download artifact`): Actions
  infrastructure; `gh run rerun <run-id> --failed`, note it in STATUS.
- **A flake:** timeline first, then the cause. One rerun with the timeline in
  STATUS; a second failure is a bug.
- **Never:** raise a timeout as a fix, add a retry loop, tag a test `@noci`,
  skip a test, merge with `--admin`.

---

## 11. Merge-ready and hand-off

- [ ] Base is `main`; **All Checks Passed** green on the head commit.
- [ ] Every Copilot thread answered and resolved; the last re-review came
      after the last fix and found nothing new.
- [ ] Sprint test (four layers) in STATUS; ledgers shrank or stayed.
- [ ] The description carries the final numbers, the class, the flag and,
      for egress, tools and runtime steps, the security impact.

The owner merges with the title unchanged. A stacked child is moved onto the
merge and pushed, which starts its CI:

```bash
git fetch origin --prune
git rebase --onto origin/main <parent-branch> <child-branch>
git push --force-with-lease origin <child-branch>
```

Runtime steps (AU3, AU5, AU6, AU31, AU32) are then rolled out one web node at
a time by ops, with the capacity page (or, before AU7,
`scripts/php-runtime-check.sh` in `synaplan-platform`) read before and after.
The rollout is a STATUS entry, not a chat message.

---

## 12. The code bar

"Elegant" means a reader understands the change without the plan open.

- **Names say the domain:** `AiCallPipeline`, `EgressPolicy`,
  `ToolInvoker`, `StreamPump`; never `…Helper`, `…Util`, `…Manager`.
- **One reason to change per class**, in its docblock. `final readonly`,
  constructor injection, ≤ 10 parameters, `declare(strict_types=1)`.
- **Short methods**, ≤ 50 lines aimed, 100 gated. Early returns.
- **No flag parameters** that switch behaviour; two named methods or a value
  object. New seams pass typed values (`App\Model\…` or the domain folder).
- **A second copy is a bug.** This track exists because of copies; do not
  add one.
- **Errors say what failed and for which id.** A person sees one honest
  sentence (U8), never a status code.
- **Logs and metrics never carry content or secrets.** Allow-listed fields
  only; label values bounded (provider, model, route class — never a URL path
  with ids, never a user id as a label).
- **Go:** standard library first; `context` on every blocking call; no
  goroutine without an owner that ends it; `go test -race` green.
- **Tests read as sentences**, one behaviour each, no `sleep` (inject a clock).

---

## 13. Stop rules

Stop and ask the owner, with a WIP note in STATUS, when:

- the same fix failed twice;
- a snapshot diff appears that you cannot explain line by line;
- a performance budget is breached and the cause is not obvious;
- a Copilot finding contradicts the plan and you cannot refute it with a
  reference;
- an E2E failure is not on the known-local list and not reproducible on `main`;
- the watchdog would need to understand an AI format;
- a step needs a schema migration, a new dependency, or a Docker / compose /
  CI change whose §0 row is not ticked.

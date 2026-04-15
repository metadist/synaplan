# Renovate PR Review Guide

How to review, merge, and close Renovate dependency update PRs.

**Core rule: if any check is uncertain or information is missing, mark the PR as blocked. Never guess.**

## Quick Reference

For each open Renovate PR, work through these steps **in order**. Stop at the first "no".

1. **Superseded?** → close (§1)
2. **Read diff + changelog + PR comments** → understand what changed and what reviewers/bots flagged (Review Checklist)
3. **Dashboard (entire):** superseded by pending/rate-limited PR? → close. Partner needed? → blocked (§2)
4. **Majors — peer deps OK against `main`?** → if unclear or not, blocked (§4)
5. **Majors — peers OK against each other?** → if not, coordinate (§4)
6. **Conflict analysis:** shared files? lockfile? overlapping hunks? → decide parallel vs. sequential (§3)
7. **CI green?** → if not, don't merge
8. **Security fix?** → prioritize (§5)
9. **What does it affect?** → CI-infra / test-infra → merge directly. Build / runtime → test locally (§6)
10. **Merge order:** security first, then independent, then sequential hotspots, blocked last (§5)

---

## Review Checklist

For **every** open PR, do all four:

1. **Read the diff:** `gh pr diff <NR> --repo metadist/synaplan` (incl. `--name-only`). Understand what changes — including lockfile collateral.
2. **Check changelogs / release notes** for affected versions: breaking changes, deprecations, migration steps. Compare against the diff. If release notes are unavailable, state it explicitly and assess risk from diff + peer dependencies.
3. **Read PR comments and review threads:** `gh pr view <NR> --repo metadist/synaplan --comments`. Look for:
   - Reviewer concerns, blockers, or required follow-ups not yet resolved.
   - Renovate bot notes about conflicts, rebases, dependency-dashboard links, or "depends on" markers.
   - Cross-references to issues or other PRs (e.g. "blocked by #123", "supersedes #456").
   - Migration hints or workarounds mentioned by maintainers that aren't in the changelog.
   - For closed/superseded sibling PRs, skim their close reason — it often explains current ecosystem constraints.
4. **CI** is additional plausibility, not a substitute for diff + changelog + comments review.

## 1. Closing Without Merge

Close PRs that are:

- **Superseded** — older major line when a newer PR for the same library exists.
- **Already on `main`** — changes landed via another merge.
- **Empty after a group PR** — a batch PR already covers the same bump.

Always add a short reason when closing.

## 2. Dependency Dashboard

Read the **entire** dashboard at <https://github.com/metadist/synaplan/issues/30> — not just the "Open" section. Check all of these:

- **Rate-limited updates:** if a coordinated partner upgrade is rate-limited (e.g. Vitest 4 while Vite 8 is open), the open PR is **blocked** until its partner is also available. Don't merge one half of a coordinated upgrade alone.
- **Pending status checks:** a newer group PR may be waiting that already includes bumps from an open PR → the open PR is **superseded**, close it.
- **Blocked / closed PRs:** understand why they were blocked. If an open PR depends on a blocked one, it is blocked too.
- **Deprecations & abandoned packages:** note them — they may need replacement rather than updating.

## 3. Conflict Analysis

Group changed paths per PR. When multiple PRs touch the **same file**, decide:

**Rebase between merges** (sequential) when:

- The shared file is a **lockfile** (`package-lock.json`, `composer.lock`) — integrity hashes make text conflicts inevitable.
- The changes touch **overlapping or adjacent lines** (check hunk headers with `gh pr diff <NR> | grep "^@@"`).
- The changes are **semantically coupled** — e.g. one PR changes a function signature, another calls it.

**Parallel merge is safe** when all of these are true:

- The shared file is **not a lockfile**.
- Each PR changes a **single, isolated line** in a **different region** of the file (hunks don't overlap, ≥10 lines apart).
- The changes are **semantically independent** — e.g. different Docker action versions, different service digests, different CI job configs.

PRs that share **no files at all** can always be merged independently.

## 4. Peer Dependency & Ecosystem Compatibility

**Every major update** must pass this check before it can be merged or even recommended for local testing. No PR moves forward without it. **If peer dependency information cannot be found or is ambiguous, mark the PR as blocked.**

For each major PR:

1. **Check the new version against `main`:** look up peer dependencies in the npm registry, packagist, or the package's own `package.json` / `composer.json`. Verify every peer is satisfied by the versions currently on `main`.
2. **Walk the ecosystem chains** — one major often requires others. Common chains:
   - Frontend: **Vite ↔ Vitest ↔ @vitejs/plugin-vue ↔ vue-tsc ↔ TypeScript**
   - Backend: **PHPUnit ↔ PHP version ↔ Symfony ↔ Doctrine**
3. **Cross-check the dashboard:** if a required ecosystem partner is not on `main` **and** not available as an open or rate-limited PR → mark as **blocked**, don't merge.
4. If the partner exists as a rate-limited PR → mark the open PR as **blocked, waiting for coordinated upgrade**. Recommend unlimiting the partner and upgrading both together on a single branch.
5. If CI is green despite a known incompatibility (e.g. tests pass by luck), **still treat as blocked** — a green CI does not override a documented peer dependency mismatch.
6. **Check compatibility between open PRs:** if multiple majors are being reviewed in the same session, verify they are compatible with each other, not just with `main`. Merging one major changes the baseline for the next.

**Runtime versions:**

- **Node.js:** Only **LTS** lines (even numbers). Odd-numbered releases → close. Current: **Node 22 LTS**.
- **PHP:** Check tool majors against `composer.json` minimum PHP version (currently `>=8.3`).

## 5. Merge Order

Goal: minimize rebase cycles.

1. **Security fixes first** — regardless of major/minor/patch, regardless of hotspot group.
2. **Independent PRs and safe parallel groups:** PRs with no shared files, plus PRs that share a file but qualify for parallel merge (see section 3).
3. **Sequential hotspot groups:** lockfile PRs or PRs with overlapping changes. Merge one at a time, rebase the next, wait for CI. Within a group, smallest / safest first, large majors last.
4. **Blocked PRs last:** coordinated upgrades, CI-red PRs — these need work before merging.

**Always rebase** when updating a branch, not merge — keeps history linear, especially for single-commit Renovate PRs.

## 6. Local Testing vs. Direct Merge

Decide based on **what the update affects**, not just whether it's a major.

### Merge directly (CI is sufficient)

- **Patch/minor bumps:** lockfile-only, no constraint changes, no changelog risk.
- **CI-infrastructure majors** (GitHub Actions, Docker digests): CI has already validated itself by running green. Local testing is not possible or useful.
- **Test-infrastructure majors** (happy-dom, Vitest, PHPUnit) when CI is fully green incl. E2E: the CI run *is* the test — it ran the entire test suite with the new version.

All of the above still require: CI fully green (incl. E2E), no peer dependency conflicts (verified in step 4), no known risk from changelog.

### Test locally

- **Build-affecting majors** (Vite, TypeScript, Webpack): changes how production output is generated. CI covers build + E2E but may miss subtle runtime differences. Check out the branch, build, and manually verify.
- **Runtime/framework majors** (Symfony, Doctrine, Vue, vue-router): affects application behavior. Test locally beyond what CI covers.
- **Coordinated upgrades:** check out a fresh branch, apply all related PRs, install dependencies, run full test suite.
- **Any PR with source code changes** or version constraint changes in `package.json` / `composer.json`.

### Local test commands

- **Backend only:** `make -C backend lint && make -C backend phpstan && make -C backend test`
- **Frontend lockfile:** `cd frontend && rm -rf node_modules && npm ci` → `make -C frontend lint && docker compose exec -T frontend npm run check:types && make -C frontend test`
- **Cloudflare lockfile:** `cd cloudflare && rm -rf node_modules && npm ci`
- **Playwright changes:** `npx playwright install`

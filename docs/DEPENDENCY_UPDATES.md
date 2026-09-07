# Renovate PR Review Guide

How to review, merge, and close Renovate dependency update PRs.

**Core rule: if any check is uncertain or information is missing, mark the PR as blocked. Never guess.**

## Quick Reference

For each open Renovate PR, work through these steps **in order**. Stop at the first "no".

1. **Which update type?** `digest`, `pin` and `lockFileMaintenance` bumps have no changelog to read — they follow the CI-infra path, not the changelog review below (§7)
2. **Superseded?** → close (§1)
3. **Read diff + changelog + PR comments** → understand what changed and what reviewers/bots flagged (Review Checklist)
4. **Dashboard (entire):** superseded by pending/rate-limited PR? → close. Partner needed? → blocked (§2)
5. **Majors — peer deps OK against `main`?** → if unclear or not, blocked (§4)
6. **Majors — peers OK against each other?** → if not, coordinate (§4)
7. **Conflict analysis:** shared files? lockfile? overlapping hunks? → decide parallel vs. sequential (§3)
8. **CI green?** → if not, don't merge. If one package is blocking a whole group, don't just close it (§8)
9. **Security fix?** → prioritize (§6)
10. **What does it affect?** → CI-infra / test-infra → merge directly. Build / runtime → test locally (§7)
11. **Major?** → check manual steps beyond Renovate (§5)
12. **Merge order:** security first, then independent, then sequential hotspots, blocked last (§6)

---

## Review Checklist

For **every** open PR, do all four:

1. **Read the diff:** `gh pr diff <NR> --repo metadist/synaplan` (incl. `--name-only`). Understand what changes — including lockfile collateral.
2. **Check changelogs / release notes** for affected versions: breaking changes, deprecations, migration steps. Compare against the diff. If release notes are unavailable, state it explicitly and assess risk from diff + peer dependencies. Digest bumps have no release notes *by construction* — classify them by their tag (§7) instead of blocking them for missing notes.
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

Read the **entire** dashboard at <https://github.com/metadist/synaplan/issues/30> — not just the "Open" section. These are the headings it actually carries; walk all of them:

- **Repository Problems:** Renovate's own config and extraction errors. If this section is not empty, everything below it may be incomplete — deal with it before trusting the rest of the page.
- **Rate-Limited:** if a coordinated partner upgrade is rate-limited (e.g. Vitest 4 while Vite 8 is open), the open PR is **blocked** until its partner is also available. Don't merge one half of a coordinated upgrade alone.
- **Open:** a newer group PR listed here may already include bumps from an older open PR → the older one is **superseded**, close it.
- **PR Closed (Blocked):** understand why they were blocked. If an open PR depends on a blocked one, it is blocked too.
- **Deprecations / Replacements** and **Abandoned Dependencies:** note them — they may need replacement rather than updating.
- **Detected Dependencies:** ground truth for what Renovate manages. Use it to check whether a file or manager is covered at all — several update types and managers fall outside every group rule in `renovate.json5`.

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

**Runtime versions — read them from the repo, never from this file:**

- **Node.js:** `.nvmrc` and `frontend/package.json` (`engines.node`) are the source of truth.
- **PHP:** `backend/composer.json` (`require.php`) is the source of truth. Check tool majors against it.
- **Policy:** Node follows **LTS lines only**. "Even-numbered" is not the test — an even major is not LTS on release day. Node 24 was correctly closed as not-yet-LTS in #873 and only landed months later in #1210.

## 5. Major Upgrades: Manual Steps Beyond Renovate

Renovate only bumps version numbers — it does not fix code, configuration, CI pipelines, or Docker images. For every major upgrade, check and fix these manually:

- **Deprecated/removed APIs:** Search for symbols the new version removes (read the UPGRADE guide). Create a backward-compatible compatibility PR first if possible.
- **Removed config keys:** Framework config files may reference options that no longer exist in the new major.
- **CI runtime version:** Workflows may pin a PHP/Node version that the new major no longer supports.
- **Dockerfile base image:** If the upgrade requires a newer runtime, the base image must be rebuilt, retagged, and the Dockerfile updated (tag + digest).
- **Framework meta-constraints:** Some tools use extra config fields to restrict versions globally (e.g. Symfony Flex's `extra.symfony.require`). Renovate does not update these.
- **Lockfile consistency:** After rebasing across other merges, the lockfile can become inconsistent. Regenerate with `composer update --with-all-dependencies` or `npm install` in the correct runtime version.
- **Security advisories:** Composer/npm may block packages with known CVEs. Check audit config if dependency resolution fails unexpectedly.
- **Polyfill replace section:** When bumping the minimum PHP/Node version, add the corresponding polyfill to the `replace` section.

**Strategy** — three destinations, and mixing them up is the usual failure:

1. **Compatibility PR first:** only changes that also work on the version currently on `main`. Replace a deprecated API with a successor that already exists there, and verify from the changelog *when* the successor was introduced — don't assume it was always available.
2. **The Renovate PR itself:** changes that require the new version (removed config keys, new-only APIs). These must never go into the compatibility PR — they would break `main`.
3. **The Renovate branch, pushed directly:** infrastructure fixes (CI runtime, Dockerfile, framework meta-constraints, lockfile regeneration).

Nothing of the above may carry local testing artifacts — removed Dockerfile digests, `docker-compose.yml` env tweaks, temporary dependency swaps.

## 6. Merge Order

Goal: minimize rebase cycles. This is the expensive part of the process, not a stylistic preference: `ci.yml` has no path filters, so every force-push replays the full pipeline (~7 min, ~7 concurrent jobs, 8-way E2E matrix) on runners shared across the org. Grouped PRs have averaged roughly a dozen CI runs each — median 11 force-pushes, 54 in #1615 — because Renovate rewrites the branch whenever *any* package in the group gets a new release. An idling group PR therefore keeps costing pipelines: merge it or mark it blocked, but don't leave it open to accumulate.

1. **Security fixes first** — regardless of major/minor/patch, regardless of hotspot group.
2. **Independent PRs and safe parallel groups:** PRs with no shared files, plus PRs that share a file but qualify for parallel merge (see section 3).
3. **Sequential hotspot groups:** lockfile PRs or PRs with overlapping changes. Merge one at a time, rebase the next, wait for CI. Within a group, smallest / safest first, large majors last.
4. **Blocked PRs last:** coordinated upgrades, CI-red PRs — these need work before merging.

**Always rebase** when updating a branch, not merge — keeps history linear, especially for single-commit Renovate PRs.

## 7. Local Testing vs. Direct Merge

Decide based on **what the update affects**, not just whether it's a major.

### Merge directly (CI is sufficient)

- **Patch/minor bumps:** lockfile-only, no constraint changes, no changelog risk.
- **CI-infrastructure majors** (GitHub Actions): CI has already validated itself by running green. Local testing is not possible or useful.
- **Docker digests:** depends on the tag — see "Digest bumps" below.
- **Test-infrastructure majors** (happy-dom, Vitest, PHPUnit) when CI is fully green incl. E2E: the CI run *is* the test — it ran the entire test suite with the new version.

All of the above still require: CI fully green (incl. E2E), no peer dependency conflicts (verified in step 4), no known risk from changelog.

### Digest bumps

Digest updates are the single largest stream of dependency PRs and there is no changelog to read. What a digest actually carries depends on the tag it sits on, so check the tag first:

- **Exact tag** (`mariadb:12.3.3@sha256:…`, `qdrant/qdrant:v1.19.0@sha256:…`, `apache/tika:3.3.1.0@sha256:…`): a rebuild of the same declared version, i.e. base-OS patches. Green CI is sufficient evidence — merge.
- **Floating tag** (`node:24@sha256:…`, `redis:7.4-alpine@sha256:…`, `quay.io/keycloak/keycloak:26.7@sha256:…`): the digest can hide a real version move inside that line. Establish what changed (the image's own version label, or the upstream release notes for that line) and review it as a minor bump.
- **`:latest` tag** (`ollama/ollama`, `mailhog/mailhog`, `phpmyadmin/phpmyadmin`, `ghcr.io/metadist/synaplan-tts`): genuinely opaque — the tag guarantees nothing. All of these are dev tooling or optional companions, so green CI plus a working local stack is the only evidence available, and that is enough. Never extend this exemption to an image on the production path.

### Test locally

- **Build-affecting majors** (Vite, TypeScript, Webpack): changes how production output is generated. CI covers build + E2E but may miss subtle runtime differences. Check out the branch, build, and manually verify.
- **Runtime/framework majors** (Symfony, Doctrine, Vue, vue-router): affects application behavior. Test locally beyond what CI covers.
- **Coordinated upgrades:** check out a fresh branch, apply all related PRs, install dependencies, run full test suite.
- **Any PR with source code changes** or version constraint changes in `package.json` / `composer.json`.

### Local test commands

- **Backend only:** `make -C backend lint && make -C backend phpstan && make -C backend test`
- **Frontend lockfile:** reinstall **inside the container** — `docker compose exec -T frontend npm ci` (or `docker compose restart frontend`, whose entrypoint runs `npm ci`). A host-side `npm ci` proves nothing here: `docker-compose.yml` mounts an anonymous volume over `/app/node_modules` on purpose, so the container never sees host `node_modules`. Then `make -C frontend lint && docker compose exec -T frontend npm run check:types && make -C frontend test`.
- **Cloudflare lockfile:** `cd cloudflare && rm -rf node_modules && npm ci` — there is no container for `cloudflare/`, so the host install is the right one.
- **Playwright changes:** `npx playwright install`

## 8. When One Package Blocks a Group

A group PR is all-or-nothing — Renovate cannot drop a single failing member from it. Two different causes, two different remedies. Neither of them is "close the PR": closing only defers the work to the next group PR.

### The bumped tool got stricter

A static-analysis or formatter bump (PHPStan, php-cs-fixer, ESLint) can turn CI red while the dependency itself is perfectly fine — the new version simply reports issues in code that was already there. #580 (PHPStan 2.1.40, `Call to an undefined method ::expects()` in `ProcessMailHandlersCommandTest.php`) was autoclosed within half an hour, that version never landed, and the work reappeared later. #1662 is the open instance (`function.alreadyNarrowedType` in `BootstrapAdminConfiguration.php:74`).

**Push the code fix onto the Renovate branch.** That is the established practice here: #680 carried the mock-typing fix together with the PHPStan bump it needed, and #551 pushed `phpstan.neon` plus lint fixes onto `renovate/api-platform`. Do **not** widen the baseline — new ignores, a lowered level, a broader exclude — to make the bump pass; that hides the finding instead of fixing it.

### One member version is genuinely broken

Rebasing cannot save the group here, and a Dependency Dashboard checkbox does not help either: it recreates the branch with the same bad version still in it.

**Land the rest by hand.** Precedent: #942 (Frontend Core) was blocked by vite 8.0.13 (Rolldown minification bug). The remedy was a human branch `chore/frontend-core-safe` that applied the safe bumps with vite held at 8.0.11, merged as #967, after which #942 was closed pointing at it. Reuse that shape — human branch, a `chore(deps):` subject naming the skipped package and the reason, then close the group PR with that reason. Renovate reopens the skipped package by itself once a fixed version exists.

If the block will outlast a single PR, the cleaner option is a scoped `allowedVersions` rule in `renovate.json5` so Renovate stays in charge instead of you hand-building branches repeatedly. It must be its own rule — Renovate rejects `allowedVersions` and `matchUpdateTypes` in the same rule, and every group rule here has `matchUpdateTypes`. That has not been needed yet, and it is a deliberate config change, so agree it explicitly before adding one.

## 9. Temporary Pins (revert when upstream releases)

Track dependencies pinned to an untagged/dev branch as a stopgap. Revert each to a
proper tagged constraint as soon as the upstream release lands (Renovate will open
the PR — when reviewing it, check this list).

| Package | Current pin | Why | Revert to |
| --- | --- | --- | --- |
| `phpoffice/phppresentation` | `dev-master as 1.3.0` | The phpspreadsheet **v5** security upgrade (clears CVE-2025-54370 + 6 others) is blocked by phppresentation 1.2.0, which caps phpspreadsheet at `^4.0`. The fix (allow `^5.0`) is merged on phppresentation `master` but not yet tagged (no 1.3.0 release on Packagist). | `^1.3` once **1.3.0** is released. |

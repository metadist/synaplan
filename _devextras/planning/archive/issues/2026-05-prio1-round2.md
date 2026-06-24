# `prio:1` Hunt — Round 2 (2026-05-13)

> Source: <https://github.com/metadist/synaplan/issues> filtered by `is:issue state:open label:"prio:1"`
> Snapshot taken **2026-05-13 09:40 UTC+2**, 7 open `prio:1` issues remain after Round 1.
> Companion to [`20250511-issues-plan.md`](./20250511-issues-plan.md).

## Inventory — 7 open `prio:1` issues

| # | Title | Round-2 status |
|---|---|---|
| #439 | Memories: "Create with AI" fails with 503 | **deferred** — environment-dependent (Qdrant / AI provider availability), not safely closeable from static analysis |
| #626 | Mail: Generated video not displayed on platform | **deferred** — needs cross-channel runtime verification |
| #839 | Statistics: Inconsistent request counts and misleading subscription status | **target #3** |
| #856 | Subscription: payment_failed has no UI surface and the past_due status badge is broken | **target #2** |
| #886 | Billing: cost calculation gaps + rate-limiting still uses request count | **target #4 / #5** (split into sub-PRs) |
| #887 | Billing: FILE_ANALYSIS counted on upload (not analysis) and double-counted on RAG retry | **target #1** |
| #891 | Models: per-prompt AI model bypass inconsistent with Model Config restrictions | **blocked** — awaits product decision (architectural comment posted on GH) |

## The next 5 to attack

Order chosen for: smallest blast radius first → testable in PHPUnit alone → progressively wider reach.

| Order | Issue | Branch | Scope |
|---|---|---|---|
| **1** | **#887** | `fix/887-file-analysis-lifecycle` | Move chat `FILE_ANALYSIS` recording out of `MessageController::uploadFileForChat` into the actual analysis point in `MessagePreProcessor`. Add idempotency guard to `FileUploadService::processFile`. Backend-only, fully PHPUnit-testable. |
| **2** | **#856** | `fix/856-subscription-past-due-ui` | Backend: clear `payment_failed` flag in `handlePaymentSucceeded` and `handleSubscriptionUpdated` when status returns to `active/trialing`. Frontend: dedicated warning section above current-plan; status-text resolver maps snake_case → camelCase i18n keys. E2E spec asserts the new behaviour. |
| **3** | **#839** | `fix/839-statistics-counters-and-status` | Sub-fixes only (the broader cost-budget gate is in #886): label the three counters distinctly in i18n, fix "Inactive" → "Free" for active free-plan users, fix `phone_verified` arg in `StreamController`. NO request-gate wiring here. |
| **4** | **#886** sub-PR (a) | `fix/886a-image-pricing-mode` | Set `pricing_mode: per_image` on every image model in `ModelCatalog.php`; unit-test cost calculation flow in `RateLimitService::recordUsage`. |
| **5** | **#886** sub-PR (b) | `fix/886b-tts-pricing-mode` | Set `pricing_mode: per_character` on every TTS model in `ModelCatalog.php`; unit-test the `media_usage['characters']` path. |

Each lands as its own branch + PR; titles include `Closes #<n>` (or `Refs #886` for the sub-PRs since the umbrella isn't done until every sub-task ships).

## Per-PR loop (mirrors Round-1 protocol)

1. `git checkout main && git pull && git checkout -b fix/<issue#>-<slug>` — branch off latest `main`.
2. Write a failing test first (PHPUnit / Vitest / Playwright as appropriate).
3. Implement minimal change, no scope creep.
4. Run full local pre-commit gate **and** the build step the CI runs:
   ```bash
   make -C backend lint
   make -C backend phpstan
   make -C backend test
   make -C frontend lint
   docker compose exec -T frontend npm run format:check
   docker compose exec -T frontend npm run check:types
   docker compose exec -T frontend npx vitest run
   docker compose exec -T frontend npm run build
   ```
5. Commit using a conventional message; reference the issue number in the body.
6. Push branch, open PR using `.github/PULL_REQUEST_TEMPLATE.md` (Summary / Changes / Verification / Notes / Screenshots/Logs), with the verification table that mirrors `.github/workflows/ci.yml` jobs.
7. Watch CI; if a check fails — fix on the **same branch** (no cross-branch contamination), push again. Re-run only on flakes (e.g. firefox `EmptyDatabaseError` / `PathUtils.join` we saw on 2026-05-11).
8. After merge, audit Copilot review; apply only the points that are demonstrably correct (skip false positives like the PHP "extra args throws ArgumentCountError" myth).

## Out-of-scope decisions (locked in for this round)

- **#891**: stays open pending product decision. Not safely actionable as a bug-fix in current data model — see the architectural comment on the issue.
- **#886c–g** (cache tokens, video duration, legacy `MessageController` metadata, cost-budget gate, non-zero plan budgets): defer to Round 3 once #886a/b ship and the catalog defaults are correct.
- **#439, #626**: kept open; will be addressed once a real-device / live-mail E2E test plan exists.

## Tracking checklist

- [ ] #887 — `fix/887-file-analysis-lifecycle`
- [ ] #856 — `fix/856-subscription-past-due-ui`
- [ ] #839 — `fix/839-statistics-counters-and-status`
- [ ] #886a — `fix/886a-image-pricing-mode`
- [ ] #886b — `fix/886b-tts-pricing-mode`

---

Round-2 is **green-on-CI = success criterion** for each PR. No premature merges; reviewer / maintainer approves and merges.

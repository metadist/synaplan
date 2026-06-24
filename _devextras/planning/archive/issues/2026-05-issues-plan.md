# Master Plan — `prio:1` Issue Cleanup (2026-05-11)

> Source: <https://github.com/metadist/synaplan/issues> filtered by `is:issue state:open label:"prio:1"`
> Snapshot taken **2026-05-11 11:21 UTC+2**, 18 open `prio:1` issues.

## Goals

1. **Burn down every open `prio:1` issue.** They block users or core functionality.
2. **One fix per branch / PR.** Branch name `fix/<issue#>-<slug>`. Only combine issues when a single, tiny change fixes both (see *Combined PRs* table below).
3. **Test before each PR.** Run the full local pre-commit gate (see [`AGENTS.md`](../../AGENTS.md) → "MANDATORY Pre-Commit Gate") and let `.github/workflows/ci.yml` go green before merging.
4. **Reference the issue in the PR body** with `Closes #<n>` (or `Refs #<n>` for partial fixes), and link back to this plan.

## Ground rules per branch

For every entry below, the loop is:

1. `git checkout main && git pull && git checkout -b fix/<issue#>-<slug>` (branch off `main`, never off `fix/issues-list-and-fixes`).
2. Write a failing test first when the area is testable (PHPUnit / Vitest / Playwright). Skip only when the fix is config / docker-only.
3. Implement minimal change, no scope creep.
4. Run the **mandatory pre-commit gate**:
   ```bash
   make -C backend lint
   make -C backend phpstan
   make -C backend test
   make -C frontend lint
   docker compose exec -T frontend npm run check:types
   make -C frontend test
   ```
   For PRs that touch backend OpenAPI annotations: `make -C frontend generate-schemas` before `check:types`.
5. Commit with a conventional message, e.g. `fix(rag): normalize query embedding to 1024 dims (#755)`.
6. Push branch, open PR with body:
   - **Summary** (1–3 bullets)
   - **Acceptance criteria** copied from the issue
   - **Test plan** (commands run, screenshots, before/after)
   - `Closes #<n>` (or `Refs #<n>` for #886 sub-tasks)
7. Watch CI (`lint`, `backend`, `frontend`, `docker-build`, `e2e (chromium/firefox/oidc/oidc-redirect)`). Fix red checks. Do **not** force-merge.
8. Merge via squash, delete branch.

> **Combined PRs are the exception, not the rule.** Allowed only when:
> - The two issues share a *single* root cause (e.g. #346 / #755 — both are the same dimension-normalization bug), or
> - One is the documented duplicate / dependent of the other and the fix file set fully overlaps (e.g. #883 frontend toast + #891 backend guard once we introduce a shared `ModelEligibilityGuard` service).

---

## Inventory — 18 open `prio:1` issues

| # | Title | Area | Cluster | PR plan |
|---|---|---|---|---|
| #900 | Mobile: Provider avatars consume too much horizontal space | mobile / chat | C | Solo |
| #898 | Mobile: Speech-to-text input duplicates text on Android | mobile / chat | C | Solo |
| #897 | Mobile: Full-screen error on file upload send and after background resume | mobile / chat | C | Solo |
| #891 | Models: per-prompt AI model bypass inconsistent with Model Config restrictions | models | D | Combined w/ #883 |
| #887 | Billing: FILE_ANALYSIS counted on upload and double-counted on RAG retry | billing / rag | E | Solo |
| #886 | Billing: cost calculation gaps + rate-limiting still uses request count | billing | E | **Multi-PR umbrella** |
| #883 | AI Models Config: 403 on save shows generic error | models | D | Combined w/ #891 |
| #882 | Worker: stale prod cache deadlocks startup after DBAL upgrade | setup | A | Solo |
| #856 | Subscription: payment_failed has no UI surface; past_due badge broken | billing | E | Solo |
| #839 | Statistics: Inconsistent request counts and misleading subscription status | statistics | E | Solo (paired with #886 outcome) |
| #755 | RAG: Semantic search returns 0 results for >1024 embedding dims | rag | B | Combined w/ #346 |
| #626 | Mail: Generated video not displayed on platform | chat / mail-channel | I | Solo |
| #501 | Widget: Support takeover / handback only visible after page refresh | widget | H | Solo |
| #457 | Nav: Sidebar icons do not respond when collapsed | nav | C | Solo |
| #449 | Chat: Mic input stays active after send; Stop then puts transcript back | chat | C | Solo |
| #439 | Memories: "Create with AI" fails with 503 | memories | A | Solo |
| #346 | RAG: Semantic search fails when OpenAI large is selected | rag / models | B | Combined w/ #755 |
| #331 | docker compose stack fails without nvidia graphics card | setup | A | Solo |

### Combined PR table (only allowed exceptions)

| Combined branch | Issues | Reason |
|---|---|---|
| `fix/755-346-rag-embedding-dim-normalize` | #755 + #346 | Same root cause: `VectorSearchService::semanticSearch` does not normalize the query embedding to 1024 dims. One change in `VectorSearchService.php` closes both. |
| `fix/883-891-model-eligibility-guard` | #883 + #891 | #891's recommended fix is a shared `ModelEligibilityGuard` used by *both* `ConfigController` and `PromptController`. #883 (frontend toast that surfaces the 403 reason) is the natural UX completion of the same change set. Splitting risks an inconsistent intermediate state. |

Everything else is its **own** PR.

---

## Execution order — small steps, lowest risk first

Phases run roughly sequentially, but PRs **inside** a phase can be opened in parallel by different working sessions. Do **not** start phase N+1 until phase N's PRs are at least open and green in CI (merging can lag).

### Phase 0 — Setup unblockers (Day 1)

Goal: stop bleeding, unblock contributors who can't even start the dev stack.

1. **#331 — `fix/331-optional-nvidia-gpu`**
   - Files: `docker-compose.yml` (and `docker-compose.override.yml.example` if present).
   - Approach: move the `deploy.resources.reservations.devices` GPU block out of the default service definition into an opt-in profile (e.g. `--profile gpu`) or a `docker-compose.gpu.yml` overlay; document in `_devextras/SYSADMIN-help.md` and the README quickstart.
   - Tests: manual `docker compose up -d` on a CPU-only host; CI is unaffected (CI doesn't use compose for build).
   - Risk: low; no PHP / TS code paths changed.

2. **#882 — `fix/882-worker-cache-clear-before-db-check`**
   - Files: `docker-compose.yml` worker command, possibly `_docker/backend/entrypoint*.sh`.
   - Approach: in the `worker` service `command:`, run `rm -rf var/cache/prod && bin/console cache:warmup --env=prod` **before** the `dbal:run-sql 'SELECT 1'` readiness loop. Apply the same pattern to any service that shares the cache volume.
   - Tests:
     - Add a regression line to `_docker/backend/tests/test-migrations-bootstrap.sh` that creates a fake stale `var/cache/prod/App_KernelProdContainer.php` referencing a removed class and asserts the worker still boots.
     - Manual: `docker compose down -v && docker compose build --no-cache backend && docker compose up -d` → `docker compose logs -f worker` should reach "ready, consuming queues".
   - Risk: low; tests catch regressions.

3. **#439 — `fix/439-memories-parse-503`**
   - Files: `backend/src/Controller/UserMemoryController.php` (or wherever `/api/v1/user/memories/parse` lives), `backend/src/Service/UserMemoryService.php`, frontend memory dialog.
   - Approach: identify why the parse endpoint returns 503. Likely root causes:
     - Required model (chat / structured-output) not configured for user → fall back to user's default chat model with a structured-output prompt instead of 503-ing.
     - Provider transient error → return 502 with retry guidance, not 503.
   - Tests: PHPUnit integration test that calls the controller with a user who has only Ollama configured (no OpenAI key) and asserts a successful structured response or a graceful fallback to "saved as note" with HTTP 200 + a `aiStructuringSkipped: true` flag the frontend can show as a soft warning.
   - Risk: medium; touches AI provider routing.

### Phase 1 — Frontend quick wins (Day 1–2)

Each is small, self-contained, ships independently. Run the full FE pre-commit gate every time.

4. **#457 — `fix/457-sidebar-collapsed-nav-clickable`**
   - File: likely `frontend/src/components/layout/Sidebar.vue` (or wherever the collapsed/expanded toggle lives).
   - Approach: inspect with browser dev tools (use `cursor-ide-browser` MCP) to find the overlay / `pointer-events:none` / wrong-`z-index` element covering the icons in collapsed mode. Likely a tooltip wrapper or the toggle button hit area.
   - Tests: Playwright spec that toggles the sidebar collapsed and clicks every nav icon, asserting the route changes (`@ci` tag).
   - Risk: low.

5. **#449 — `fix/449-chat-mic-stop-on-send`**
   - Files: `frontend/src/components/ChatInput.vue` (~line 981), `frontend/src/services/webSpeechService.ts`.
   - Approach: on `handleSendMessage`, call `webSpeechService.stop()` and clear `speechFinalTranscript` / `speechInterimTranscript` so a later "Stop" click cannot re-inject the previous transcript. Suspend recognition while a streaming AI response is rendering when voice-reply is on (existing flag).
   - Tests: Vitest unit test on the speech state machine + Playwright happy path with a stubbed Web Speech mock. Manual verification on a real Android device is part of the PR review.
   - Risk: low.

6. **#900 — `fix/900-mobile-chat-avatars-compact`** *(also folds in the iOS-keyboard-padding tweak below)*
   - File: `frontend/src/components/ChatMessage.vue` (lines 3–19, 842–847) and `frontend/src/components/ChatInput.vue` (line ~3 + inner `py-4` on line ~7).
   - Approach (avatars, per the issue's own recommendation): `gap-2 md:gap-4 p-2 md:p-4`, `w-6 h-6 md:w-10 md:h-10` for avatars, smaller icon size on mobile, and ensure tables / `<pre>` blocks get `min-w-0 overflow-x-auto`.
   - **Bonus — iPhone keyboard input-bar gap (not on the issue tracker, reported 2026-05-11):** the chat input bar is already `position: sticky; bottom: 0` and follows the iOS soft keyboard, which is the desired behaviour. But the bottom padding stacks ~34 px (`pb-[env(safe-area-inset-bottom)]` for the iPhone home indicator) on top of the inner `py-4` (16 px), giving ~50 px of dead space below the textarea even when the keyboard is open. Fix:
     - In `frontend/src/composables/useKeyboardOpen.ts` (new, ~25 lines), use `window.visualViewport` to set `document.documentElement.style.setProperty('--keyboard-open', '0' | '1')` based on a small height-delta threshold; clean up on unmount. Initialize once in `App.vue` (or the chat layout).
     - Change `ChatInput.vue` line 3 from `pb-[env(safe-area-inset-bottom)]` to `pb-[calc(env(safe-area-inset-bottom)*var(--keyboard-open,1))]`.
     - Change the inner wrapper from `py-4` to `py-2 md:py-4` (saves ~16 px on mobile) and let the outer `pb-` carry the home-indicator inset.
     - Net effect: ~40–50 px less dead space when the keyboard is up; desktop and non-iOS unchanged.
   - Tests:
     - Playwright at 375×812 viewport asserting `.chat-message` content width ≥ 320 px and that a wide table doesn't overflow the body.
     - Vitest unit test for `useKeyboardOpen` with a stubbed `visualViewport` confirming the CSS variable flips on resize.
     - Manual verification on a real iPhone (iOS 17+ Safari) that the bar still floats with the keyboard *and* the gap shrinks to the documented target.
   - Risk: low. The CSS-variable fallback (`var(--keyboard-open, 1)`) keeps the current behaviour on browsers without `visualViewport`.

7. **#898 — `fix/898-android-stt-duplicate`**
   - Files: `frontend/src/services/webSpeechService.ts` (~line 192–201), `frontend/src/components/ChatInput.vue` (~line 981–993).
   - Approach: in `WebSpeechService.onresult`, iterate `event.results` from `event.resultIndex` and emit only the *new* `final` segment plus the *latest* `interim` segment, keyed by `result.isFinal`. In `ChatInput.vue`, replace `+=` accumulation with a "set-final" + "set-interim" model so duplicates are impossible. Keep desktop / iOS behaviour green via existing tests.
   - Tests: Vitest spec that drives the service with a synthetic Android-style event sequence (multiple `resultIndex=0` interims followed by a final).
   - Risk: low.

8. **#883 + #891 — `fix/883-891-model-eligibility-guard`** *(combined per rules)*
   - Backend:
     - New `App\Service\Model\ModelEligibilityGuard` service with one method `assertUserCanUse(User $user, string $modelId): void` throwing `ModelNotEligibleException` (HTTP 403, machine-readable code `MODEL_PREMIUM_REQUIRED`, human-readable reason).
     - Wire into `ConfigController::saveDefaults` (existing call site) and `PromptController::saveMetadataForPrompt` (new — closes #891).
   - Frontend:
     - In the API client / `useToast` consumer for the models config save path, parse the 403 body and surface the `reason` text in the toast (closes #883).
     - Disable / grey-out premium options for non-premium users with an `Upgrade` link (defensive; backend remains source of truth).
   - Tests:
     - PHPUnit: `ModelEligibilityGuardTest` (premium user OK, free user throws). Integration: PUT `/api/v1/prompts/{id}` with a premium model as a free user → 403 + correct error code.
     - Vitest: model-config view shows the reason from a mocked 403 response.
   - Risk: medium (touches a security boundary). Mitigated by tests that cover both controllers.

### Phase 2 — RAG correctness (Day 2)

9. **#755 + #346 — `fix/755-346-rag-embedding-dim-normalize`** *(combined per rules)*
   - Files: `backend/src/Service/Vector/VectorSearchService.php`, `backend/src/Service/Vector/VectorizationService.php` (extract shared helper).
   - Approach: extract the existing 1024-dim truncate/pad logic from `VectorizationService::vectorizeAndStore` into a small static helper (`VectorDimensionNormalizer::normalizeTo(array $vec, int $dim = 1024): array`), and call it on the *query* embedding inside `VectorSearchService::semanticSearch` before passing to `VectorStorageFacade::search`.
   - Tests:
     - Unit test on the normalizer (shorter, equal, longer inputs).
     - Integration test in `tests/Integration/Rag/SemanticSearchTest.php`: store a 1024-dim chunk, then query with a 1536-dim provider stub, assert ≥ 1 result and `distance` is a finite float.
   - Risk: low and well-bounded.

### Phase 3 — Mobile reliability (Day 3)

10. **#897 — `fix/897-mobile-resilient-error-handling`**
    - Files: `frontend/src/utils/installGlobalErrorHandlers.ts`, `frontend/src/views/ChatView.vue` (~line 1196), `frontend/src/views/ErrorView.vue`, `frontend/public/sw.js`.
    - Approach:
      - Extend `IGNORED_PATTERNS` for `ChunkLoadError`, `Loading chunk \d+ failed`, `Failed to fetch dynamically imported module`, SSE reconnect timeouts.
      - Wrap `handleSendMessage` / `streamAIResponse` in a try/catch that surfaces a non-fatal toast and offers a retry, instead of escalating to the global handler.
      - Add chunk-load retry-once-then-reload behaviour around the `import('@/services/filesService')` dynamic imports.
      - Make `sw.js` skip cache-nuke when the user is online and the version hasn't changed; add a `fetch` handler with a network-first strategy for navigations.
    - Tests:
      - Vitest unit tests for the new `IGNORED_PATTERNS` + chunk-load retry helper.
      - Playwright mobile-emulation test that triggers a dynamic-import failure and asserts the chat view stays mounted, with a non-fatal toast visible.
    - Risk: medium; touches PWA + global error path. Ship behind a feature flag if the diff balloons.

### Phase 4 — Cross-channel media (Day 4)

11. **#626 — `fix/626-mail-channel-media-display`**
    - Files: `backend/src/Service/Mail/*`, `backend/src/Service/Channel/MessageRenderer.php` (or equivalent), media renderers in `frontend/src/components/ChatMessage.vue`.
    - Approach:
      - Audit the message-creation pipeline for inbound mail and ensure the generated media blobs (video / audio / image) are persisted to `BMESSAGEMETA` (or the file table) with the correct media type, then linked in `BMESSAGES.BMETAFILE` so the chat renderer can embed them.
      - Confirm the same path works for image + audio (per the issue's "must be verified for all media types").
    - Tests:
      - PHPUnit feature test: simulate an inbound mail asking for video, mock the Veo provider to return a known media URL, assert the persisted message contains a `video` part the frontend renderer recognises.
      - Frontend snapshot test: `ChatMessage` with a `video` attachment renders a `<video>` element.
    - Risk: medium-high — multi-service path, but well-scoped.

### Phase 5 — Widget realtime (Day 4–5)

12. **#501 — `fix/501-widget-realtime-takeover`**
    - Files: `frontend/widget/*` (chat polling / SSE), `backend/src/Controller/Widget/*` (events endpoint).
    - Approach:
      - Re-use the existing SSE infrastructure (already used for streaming AI responses) to push *takeover* and *handback* notifications to the widget's open session. Backend emits `event: agent.takeover` / `event: agent.handback` / `event: message.appended`.
      - On the widget side, subscribe whenever the conversation is open; reflect state changes (banner + new message) without reload.
    - Tests:
      - Backend: integration test that opens an SSE consumer for a guest session and asserts the events are sent on takeover.
      - Playwright (widget e2e): start guest convo → trigger takeover via API → assert the widget shows the agent message and the "you're chatting with a human" banner *without* a reload.
    - Risk: medium-high — widget cross-origin + SSE.

### Phase 6 — Billing, in **strict** order (Day 5–8)

This is the largest cluster. Do them in this order so each PR builds on the previous one's tests and data shape.

13. **#887 — `fix/887-file-analysis-lifecycle`**
    - Files: `MessageController::uploadFileForChat`, `MessagePreProcessor`, `FileUploadService::{processSingleUpload,processFile}`, `WidgetPublicController::upload` (no change, regression test only).
    - Approach:
      - Move the chat `recordUsage($user, 'FILE_ANALYSIS')` call out of `MessageController::uploadFileForChat()` and into the *consumption* point in `MessagePreProcessor` (when text is actually extracted/used in a streamed message).
      - Add a guard inside `FileUploadService::processFile()` that no-ops the `recordUsage` call when `BFILES.BVECTORIZED` already indicates `processSingleUpload()` recorded for the same file id.
    - Tests:
      - Cover all 5 rows in the issue's "Screenshots/Logs" table with PHPUnit feature tests so each scenario asserts the exact `BUSELOG` row count.
    - Risk: medium; billing tests must catch regressions.

14. **#856 — `fix/856-subscription-past-due-ui`**
    - Files: `frontend/src/views/SubscriptionView.vue`, `backend/src/Controller/StripeWebhookController.php`, `frontend/src/i18n/{en,de}.json`, `frontend/tests/e2e/tests/subscription.spec.ts`.
    - Approach: implement every checkbox in the issue's "Acceptance criteria" sections (UI surface, recovery path, status text fix, E2E).
    - Tests: turn the existing negative-path test from "encodes the gap" into "asserts the fix" — list those flips in the PR description.
    - Risk: medium; webhook handling is sensitive.

15. **#839 — `fix/839-statistics-counters-and-status`**
    - Files: `RateLimitService.php`, `UsageStatsService.php`, `LimitReachedModal.vue`, `UsageStatistics.vue`, `StreamController.php`.
    - Approach (smallest correct subset):
      - Make the three counters (50/50, 59, 80) consistent by labelling them in the UI ("Messages", "Billable actions", "All events") and unifying the data source.
      - Fix the misleading "Inactive" status for active free-plan users → render "Free".
      - Fix the `phone_verified` value passed by `StreamController` (use the actual phone-verification status, not email).
      - Do **not** wire `checkCostBudget()` into the request gate here — that is part of #886.
    - Tests: Vitest snapshot for the dashboard with mocked stats; PHPUnit assertion that `StreamController` passes `phone_verified` correctly.
    - Risk: medium.

16. **#886 — multi-PR umbrella, branched off #887 + #856 once those merge.**
    Each sub-task gets its own branch. Track them under one tracking issue / project board column "Billing accuracy".
    - **`fix/886a-image-pricing-mode`** — set `pricing_mode: per_image` on every image model in `ModelCatalog.php`; unit-test cost calculation.
    - **`fix/886b-tts-pricing-mode`** — set `pricing_mode: per_character` on every TTS model in `ModelCatalog.php`; unit-test.
    - **`fix/886c-cache-creation-tokens-openai`** — map `input_tokens_details` cache fields in `normalizeResponsesUsage()` for OpenAI; cover Google + Groq if cheap.
    - **`fix/886d-video-duration-warn`** — when the provider omits `duration_seconds`, log a warning and fall back to a sensible default (or 0 with a clearly logged anomaly), surfaced in `BUSELOG.BNOTES`.
    - **`fix/886e-legacy-message-controller-meta`** — populate provider/model/token metadata in the legacy `MessageController::send()` `recordUsage` call.
    - **`fix/886f-cost-budget-gate`** — wire `checkCostBudget()` into `StreamController` and `MessageController` request entry, returning HTTP 429 with reason + portal link when the monthly cost budget is exceeded. Behind a feature flag (`COST_BUDGET_GATE_ENABLED=false`) by default; enable per-environment after dashboard verification.
    - **`fix/886g-subscription-cost-budgets`** — non-zero `BCOST_BUDGET_MONTHLY` for PRO / TEAM / BUSINESS via a new idempotent seeder in `backend/src/Seed/`. *No data fixture* — production seed.
    - Each sub-PR runs the full pre-commit gate independently. The umbrella issue is **closed only when every sub-PR is merged**, so use `Refs #886` (not `Closes #886`) until the last one.

---

## Tracking checklist

Use this table to update as PRs land. Tick items only after the PR is merged on `main` and CI is green.

- [ ] #331 — `fix/331-optional-nvidia-gpu` *(Phase 0)*
- [ ] #882 — `fix/882-worker-cache-clear-before-db-check` *(Phase 0)*
- [ ] #439 — `fix/439-memories-parse-503` *(Phase 0)*
- [ ] #457 — `fix/457-sidebar-collapsed-nav-clickable` *(Phase 1)*
- [ ] #449 — `fix/449-chat-mic-stop-on-send` *(Phase 1)*
- [ ] #900 — `fix/900-mobile-chat-avatars-compact` *(Phase 1)*
- [ ] #898 — `fix/898-android-stt-duplicate` *(Phase 1)*
- [ ] #883 + #891 — `fix/883-891-model-eligibility-guard` *(Phase 1, combined)*
- [ ] #755 + #346 — `fix/755-346-rag-embedding-dim-normalize` *(Phase 2, combined)*
- [ ] #897 — `fix/897-mobile-resilient-error-handling` *(Phase 3)*
- [ ] #626 — `fix/626-mail-channel-media-display` *(Phase 4)*
- [ ] #501 — `fix/501-widget-realtime-takeover` *(Phase 5)*
- [ ] #887 — `fix/887-file-analysis-lifecycle` *(Phase 6)*
- [ ] #856 — `fix/856-subscription-past-due-ui` *(Phase 6)*
- [ ] #839 — `fix/839-statistics-counters-and-status` *(Phase 6)*
- [ ] #886a — image pricing_mode *(Phase 6)*
- [ ] #886b — TTS pricing_mode *(Phase 6)*
- [ ] #886c — cache_creation_tokens for OpenAI *(Phase 6)*
- [ ] #886d — video duration warn *(Phase 6)*
- [ ] #886e — legacy `MessageController::send` metadata *(Phase 6)*
- [ ] #886f — cost-budget request gate (feature-flagged) *(Phase 6)*
- [ ] #886g — non-zero `BCOST_BUDGET_MONTHLY` seeder *(Phase 6)*

---

## Risks and watch-outs

- **CI flakiness on the `e2e` matrix** (chromium / firefox / oidc / oidc-redirect) blocks merges. If a flake is unrelated to the PR, retry the job once; otherwise file a separate issue and drop the test from `@ci` only with reviewer approval.
- **Schema drift gate** (`doctrine:schema:validate`) in `backend` job: never use `doctrine:schema:update --force`; always ship a migration alongside any entity change. None of the PRs above are *expected* to touch entities — if one does, follow `docs/MIGRATIONS.md`.
- **Frontend type-check is mandatory** and `vue-tsc -b` catches errors that ESLint misses. Always run `docker compose exec -T frontend npm run check:types` before committing FE PRs (per `AGENTS.md`).
- **Billing PRs (#887 / #886.*) are user-money sensitive.** Every change must come with a PHPUnit test asserting the *exact* `BUSELOG` row count for the scenario it claims to fix.
- **Don't `git checkout --ours` / `--theirs`** when rebasing on `main`; merge manually (per `AGENTS.md`).
- **No `Generated with …` / `Co-Authored-By: Claude` lines in commits or PRs** (per `AGENTS.md`).

---

## Done = everything below is true

- All 18 issues in the inventory are closed (or linked to a closing PR awaiting CI).
- `gh issue list --label "prio:1" --state open` returns zero results.
- The `Tracking checklist` above is fully ticked.
- No new `prio:1` regressions introduced (verified by running the full pre-commit gate + CI on each PR).

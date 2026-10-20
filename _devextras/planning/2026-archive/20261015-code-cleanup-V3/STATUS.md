# Code cleanup V3 — status

> **Archived 2026-10-16.** K0–K10 merged on `main` in order:
> [#2512](https://github.com/metadist/synaplan/pull/2512) (`f6a60ab71`),
> [#2513](https://github.com/metadist/synaplan/pull/2513) (`bf7f1b059`),
> [#2514](https://github.com/metadist/synaplan/pull/2514) (`6e7bff308`).
> The ratchet stands at 57 / 69 / 147; `MessageProcessor` 303 lines / 6
> deps, `FileAnalysisHandler` 161 / 6, `SeedAllCommand` and
> `UserDeletionService` out of the constructor ledger. The twelve findings
> of §7 are the plan of code cleanup V4,
> [`../20261016-code-cleanup-V4/`](../20261016-code-cleanup-V4/00_master_plan.md),
> led by the 12 user-owned tables account deletion never erases. Code
> comments that cite `_devextras/planning/20261015-code-cleanup-V3/` mean
> this folder.

Three numbers in every row (from the V2 size ratchet): files > 800 lines,
constructors > 10 params, methods > 100 lines. Plus the lines / params of
the class the step works on. Start values measured 2026-10-15: 59 / 73 / 151.

Built on one branch and split by fix into [#2512](https://github.com/metadist/synaplan/pull/2512) → [#2513](https://github.com/metadist/synaplan/pull/2513) → [#2514](https://github.com/metadist/synaplan/pull/2514); one commit per step, each green on
`make -C backend lint phpstan deptrac test` (8,727 → 8,733 tests). Rows are in
commit order: Fix 3 was done before the processor half of Fix 2 so the two
cheaper fixes could not be blocked by the largest one.

| Date | Step | > 800 lines | ctor > 10 | method > 100 | Class worked on | Note |
| ---- | ---- | ----------- | --------- | ------------ | --------------- | ---- |
| 2026-10-15 | K0 | 59 | 73 | 151 | — | Plan from the 2026-10-15 audit: three fixes (copied helpers, twin pipelines, hand-wired lists); ten further findings ordered for V4 (§7) |
| 2026-10-15 | K1 | 59 | 73 | 151 | 4 handlers / router / pre-processor | Four private `notify()` copies → `ProgressCallback`. Wire: the status `timestamp` becomes fractional seconds in those four, as everywhere else; every client reads it as seconds |
| 2026-10-15 | K2 | 59 | 73 | 151 | `MediaGenerationHandler` 2,351 → 2,245, ctor 23 → 22 | Media memory job through `PendingMemoryExtraction` (its docblock said "kept in sync but duplicated"); classifier model tag through `ModelConfigService`; four locale lists → `AccountLanguage::SUPPORTED` |
| 2026-10-15 | K3 | 59 | 73 | 151 | `MessageClassifier` 2,082 → 2,025 | Override readers and the four thread probes folded; processor Confluence / Jira merge folded. The ratchet caught the first attempt growing `MessageProcessor` by 6 lines (docblocks) — trimmed before the commit. Routing snapshot unchanged |
| 2026-10-15 | K4 | 59 | 73 | 151 | — | `FileAnalysisCharacterizationTest`: 25 attachment mixes × buffered / streamed, recorded on the untouched handler; built through `FileAnalysisHandlerFactory` |
| 2026-10-15 | K5 | **58** | **72** | **149** | `FileAnalysisHandler` 2,046 → 161 lines, ctor 11 → 6 | One path + `AnalysisReply`; `FileAnalysis\{AnalysisFileResolver, FileAnalysisRoute, DocumentAnalysis, VoiceMessageReply, ImageAnalysis, FileAnalysisFailureCopy, AnalysisContext, AnalysisModel}`. Snapshot unchanged; `$uploadDir` wiring moved to `ImageAnalysis` |
| 2026-10-15 | K8 | 58 | **71** | 149 | `SeedAllCommand` ctor 36 → 2 | Tagged `SeederInterface`; `--help` generated (was 26 of 35 steps); `SeedAllCommandOrderTest` pins the order; full `app:seed` run on dev identical |
| 2026-10-15 | K9 | 58 | **70** | 149 | `UserDeletionService` 643 → 82 lines, ctor 36 → 3 | 15 tagged erasers in today's order; dead `cleanupUserData()` removed. **`UserErasureCoverageTest` found 12 user-owned tables that account deletion never erases** (no eraser, no cascade, no listener): Approval, ChatSummary, ComputeRun, ComputeWorkspace, Connection, Credential, CustomTool, DesktopDevice, DesktopJob, DocumentRevision, UrlWatch, VoiceSession — frozen as a shrink-only list, V4 #0 |
| 2026-10-15 | K6 | 58 | 70 | 149 | — | `MessageTurnCharacterizationTest`: 17 turn kinds × `process()` / `processStream()`, recorded on the untouched processor. Pins the streamed widget turn that runs the classifier twice |
| 2026-10-15 | K7 | **57** | **69** | **147** | `MessageProcessor` 2,109 → 303 lines, ctor 21 → 6 | Shared stages in `Message\Processing\`; the nine differences named in `TurnClassificationResolver`. Snapshot unchanged. First run hit a `TypeError`: the old file had no `strict_types` and relied on int → string coercion — the moved code keeps weak typing (V4 #11). Three stale PHPStan baseline entries removed |
| 2026-10-15 | K10 | 57 | 69 | 147 | — | Exit met: both chat-path classes out of the file and constructor ledgers, both list classes out of the constructor ledger, no private `notify()` under `Service/Message/`. `docs/ARCHITECTURE.md` gains the message turn, file analysis and tagged-list sections. `make test-e2e` on the branch head: **139 passed**, 14 failed — 12 match the documented local-only list (mail specs on MailHog `:8026`, WhatsApp / Telegram stubs, Stripe test secrets, compute on in dev), 2 passed when rerun in isolation (`multitask` TTS-in-DAG, known from V1 C9; `live-voice-activity-filter`, new, a parallel-load flake). The E2E teardown deletes every test user through the new erasers |
| 2026-10-15 | Review | 57 | 69 | 147 | `PendingMemoryExtraction`, `DocumentAnalysis`, `VoiceMessageReply`, `UserDeletionService` | Copilot on #2513: K2 moved the media memory job outside the handler's old `try/catch`, so a message that was never stored (live voice, transcript row failed) would now abort the media turn — the chat path already aborted that way on `main`. Fixed at the owner in #2512: `PendingMemoryExtraction` gives no job to a message without an id (like incognito); tests reproduce the `TypeError` first. A strict audit for the same class of change found two exactness gaps, both restored with tests: the analysis `complete` status back inside its logging guard (#2513), the erasure built inside the deletion transaction (#2514). Gate on each branch head (8,737 / 8,744 tests); `make test-e2e` on #2514's head: 136 passed, 17 failed — the 12 documented local-only cases, the three guest UI specs (screenshot: "Too many sessions", the documented per-IP cap), `live-voice-activity-filter` (passes in isolation), and the `layout` axe scan of the AI models page (active tab `#3264cf` on `#cbd7ed`, 3.75:1) — that one fails identically on `main` after a full local run and passed on the first run of the day: dev-database state, cause not isolated, frontend untouched by V3 |

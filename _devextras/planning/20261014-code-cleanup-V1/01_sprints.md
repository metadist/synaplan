# Code cleanup V1 — steps

Every step is one PR on its own branch, green on the gate in
`00_master_plan.md` §6 before push. Commit types matter for the release
bump: all steps here are `chore`, `test`, `refactor` or `docs` — none is a
`feat`, so none raises the minor version.

---

## C0 — `docs(planning): code cleanup V1 master plan`

**Goal:** this folder exists and is listed in `_devextras/planning/README.md`.
**Exit:** the README "Live" table has the row; nothing else changes.

---

## C1 — `chore(backend): add deptrac layer rules with baseline`

**Goal:** `make -C backend deptrac` exists, runs in `make ci-local` and in
the CI Backend job, and fails only on violations that are not in
`deptrac.baseline.yaml`.

**Needs approval first** (`AGENTS.md` → Ask first: dependencies, CI): §0 #1.

### Procedure

1. Dependency, dev only:

   ```bash
   docker compose exec -T backend composer require --dev deptrac/deptrac:^4.7
   ```

2. `backend/deptrac.yaml` (starting point, adjust the regexes if deptrac
   reports an uncovered namespace you care about):

   ```yaml
   imports:
     - deptrac.baseline.yaml

   deptrac:
     paths:
       - ./src
     exclude_files:
       - '#src/Kernel\.php#'
     analyser:
       types: [class, class_superglobal, use, file, function]
     ignore_uncovered_internal_classes: true

     layers:
       - name: Entrypoint
         collectors:
           - { type: classLike, value: '^App\\(Controller|Command)\\' }
       - name: Application
         collectors:
           - { type: classLike, value: '^App\\(Service|AI|Mcp|Realtime|Plug|Prompt|Module|Runtime|Message|MessageHandler|EventSubscriber|EventListener|Security|Seed|Observability|Monolog|Doctrine|Bundle|DataFixtures|DependencyInjection)\\' }
       - name: Repository
         collectors:
           - { type: classLike, value: '^App\\Repository\\' }
       - name: Entity
         collectors:
           - { type: classLike, value: '^App\\Entity\\' }
       - name: Shared
         collectors:
           - { type: classLike, value: '^App\\(DTO|Model|Attribute)\\' }

     ruleset:
       Entrypoint:  [Application, Repository, Entity, Shared]
       Application: [Application, Repository, Entity, Shared]
       Repository:  [Repository, Entity, Shared]
       Entity:      [Entity, Repository, Shared]
       Shared:      [Shared]
   ```

3. First run, then freeze what exists:

   ```bash
   docker compose exec -T backend vendor/bin/deptrac analyse --no-progress --report-uncovered
   docker compose exec -T backend vendor/bin/deptrac analyse --formatter=baseline --output=deptrac.baseline.yaml --no-progress
   ```

   Read the baseline before committing it. Expected content: the nine
   files from `00_master_plan.md` §1 — `Repository` → `Service\Iam\*`,
   `Service\SmartSearch\Index\*`, `AI\*` (5 files); `Entity\User` and
   `Entity\McpServerConfig` → `Service\*`; `Entity\ModelHealth` →
   `AI\Health\ModelHealthState`; `DTO\RegisterRequest` →
   `Service\AccountLanguage`. Anything else is a finding — note it in
   `STATUS.md`, do not silently freeze it.

4. Wiring, mirroring the PHPStan entries exactly:

   - `backend/composer.json` → `"deptrac": "deptrac analyse --no-progress"`
   - `backend/Makefile` → `deptrac` (runs `composer deptrac`) and
     `deptrac-baseline` (regenerates the file); add both to `.PHONY` and the
     `## Code Quality` block.
   - root `Makefile` → `ci-local` runs `$(MAKE) -C backend deptrac` after
     `phpstan`; update the `##` help line of `ci-local`.
   - `.github/workflows/ci.yml` → in the `Backend (PHP/Symfony)` job, a
     step `Run deptrac` (`working-directory: backend`, `run: composer
     deptrac`) directly after `Run PHPStan`.
   - `AGENTS.md` → add the row `make -C backend deptrac` to the "Unit /
     static" table and one bullet under "Common pre-commit traps": the
     baseline only shrinks; a new violation is fixed, not baselined.

### Exit

- `make -C backend deptrac` is green on `main` + this branch.
- Introducing one deliberate violation locally (e.g. `use
  App\Service\WidgetService;` in `src/Entity/Widget.php`) turns it red;
  reverted before commit.
- `make ci-local` passes and includes the new step.
- `deptrac.baseline.yaml` is reviewed line by line and listed in
  `STATUS.md` with its entry count.

---

## C2 — `test(backend): guard the Service root as plugin contract`

**Goal:** a PHPUnit test that fails when a file appears in `src/Service/`
root that is not on its allow-list, and fails when the allow-list names a
file that no longer exists (so the list cannot rot).

### Procedure

`backend/tests/Architecture/ServiceRootTest.php`, same shape as
`ModuleOwnershipTest` (dated allow-list that only shrinks):

- `PERMANENT = ['ModelConfigService', 'OidcUserService', 'PluginDataService', 'RateLimitService']`
  with the docblock: *imported by external plugins (Synaform, synafastbill,
  Synamail, synaScriber); moving one is a breaking change for plugin
  authors and needs a `class_alias` window (V3, master plan §8).*
- `PENDING_MOVE = [...]` the other 69 names, grouped by the step that
  removes them (C3 … C8) as comments, taken from `02_service_root_map.md`.
- Test 1: `glob('src/Service/*.php')` basenames ⊆ `PERMANENT ∪ PENDING_MOVE`.
  Failure message names the file and says: *put it in a domain folder, or
  add it to PERMANENT with the external importer that needs it.*
- Test 2: every name in `PENDING_MOVE` still exists. Failure message: *it
  moved — delete it from PENDING_MOVE in the same PR.*

### Exit

- `make -C backend test` green with the full 73-name list.
- Adding `src/Service/Foo.php` locally fails Test 1; deleting a listed
  file fails Test 2; both reverted before commit.

---

## C3–C8 — the move PRs

One procedure for all six, applied to the file list of the step
(`02_service_root_map.md` has the destination and reason per file).

### Procedure per PR

1. For each file in the step:

   ```bash
   git mv backend/src/Service/X.php backend/src/Service/<Domain>/X.php
   ```

   and change the first namespace line to `namespace App\Service\<Domain>;`.

2. Repo-wide, exact-token replace `App\Service\X` → `App\Service\<Domain>\X`
   in `backend/src`, `backend/tests`, `backend/config`. Verify nothing is
   left:

   ```bash
   rg -n "App\\\\Service\\\\X\b" backend plugins
   ```

   The in-repo plugin (`plugins/castingdata`) only imports
   `PluginDataService`, which does not move; the grep is the proof.

3. Moved classes that referenced a root sibling **without** a `use` line
   (same namespace until now) and whose sibling goes to a *different*
   folder now need an explicit `use`. PHPStan and `lint:container` find
   every such case; fix them, do not reorder anything else.

4. Remove the moved names from `PENDING_MOVE` in `ServiceRootTest`.

5. `make -C backend lint` (php-cs-fixer sorts the changed `use` blocks),
   then the full gate from `00_master_plan.md` §6.

6. `git diff --stat` sanity: every `src/Service/X.php` shows as a rename
   (`R`), not delete + add. If git shows a rename below ~90 % similarity,
   something other than the namespace changed — find it before pushing.

### Step contents

| Step | Destination ← files |
| ---- | ------------------- |
| **C3** Widget (10) | `Widget/` ← `DatabaseWidgetEventStore`, `HumanTakeoverService`, `WidgetEventStoreInterface`, `WidgetExportService`, `WidgetRealtimeBroadcaster`, `WidgetService`, `WidgetSessionService`, `WidgetSetupService`, `WidgetSummaryService`, `WordPressIntegrationService` |
| **C4** Email, WhatsApp, Notification (10) | `Email/` ← `EmailChatService`, `EmailWebhookIdempotencyService`, `InboundEmailHandlerService`, `InboundEmailService`, `InternalEmailService`, `MailHandlerLogService`, `MailerConfig` · `WhatsApp/` ← `WhatsAppService` · `Notification/` (new) ← `DiscordNotificationService`, `SlackNotificationService` |
| **C5** Auth & identity (13) | `Auth/` ← `ImpersonationService`, `JwtValidator`, `NativeAuthHandoffService`, `OAuthLoginResponder`, `OidcScopeResolver`, `OidcTokenService`, `RecaptchaService`, `RegistrationConfig`, `TokenService` · `OAuth/` ← `OAuthStateService` · `Iam/` ← `UserDeletionService`, `UserLifecycleService` · `App\Model\` ← `AccountLanguage` |
| **C6** Billing, Usage, IAP (9) | `Usage/` ← `CachePriceResolver`, `CostCalculationService`, `StorageQuotaService`, `UsageStatsService`, `UsageTaximeterConfig` · `Billing/` (new) ← `BillingService`, `PremiumFeatureGate` · `Iap/` ← `IapPricingService`, `MobilePurchaseService` |
| **C7** Chat, Message, Memory, Feedback (16) | `Message/` ← `AiResponseSanitizer`, `ContentModerationService`, `MessageEnqueueService`, `PerfPipelineFlag` · `Chat/` ← `ConversationSummaryRefreshDispatcher`, `GuestChatConfig`, `GuestSessionService`, `PastedContentText` · `Memory/` ← `MemoryExtractionDispatcher`, `MemoryExtractionService`, `UserMemoryService` · `Feedback/` (new) ← `FeedbackConfigService`, `FeedbackConstants`, `FeedbackContradictionService`, `FeedbackExampleService` · `Research/` ← `UrlContentService` |
| **C8** Media, Speech, Prompt, Security, Infrastructure (11) | `Media/` ← `MediaGenerationService`, `MediaGenerationServiceInterface`, `TtsTextSanitizer` · `Stt/` ← `SpeechFailure`, `WebSpeechConfig`, `WhisperService` · `Prompt/` ← `PromptService` · `Security/` ← `EncryptionService` · `Infrastructure/` ← `CircuitBreaker`, `PerfTimer` · `App\Monolog\` ← `MonologFormatterFactory` |

Step-specific notes (`services.yaml` line numbers as of 2026-10-14; grep
the class name, the numbers will drift):

- **C3** touches `services.yaml` lines 1517–1518 (`WidgetEventStoreInterface`
  alias). Run `make test-e2e` — the widget suite is the best regression net
  for this group.
- **C4** touches `services.yaml` 711, 722, 848, 851 (Discord, WhatsApp,
  InternalEmail, InboundEmail explicit definitions).
- **C5** touches `services.yaml` 979, 987, 1091, 1096, 1107, 1129, 1138 and
  `src/DTO/RegisterRequest.php` + `src/Entity/User.php` (`AccountLanguage`).
  After this PR, two baseline entries are dead — remove them in C9, or
  here if the deptrac run already reports them as obsolete.
- **C6** files carry `MOBILE-APP SEAM` markers; the markers stay verbatim.
  Still `backend-only` (`backend/**`), confirm with
  `node scripts/mobile-impact.mjs`.
- **C7** `MessageEnqueueService` has a German docblock ("Service zum
  Enqueuen …"); translate it in this PR — the only non-namespace line
  change allowed in the series, called out in the PR description. Run
  `make test-e2e` (stream and chat paths).
- **C8** touches `services.yaml` 687, 795, 798, 802–803, 824 (Whisper,
  CircuitBreaker, MediaGeneration + alias, ModelConfig stays) and
  1445–1452 (`MonologFormatterFactory` as a factory — the `factory:` line
  must be renamed too; `lint:container` catches a miss).

### Exit (every move PR)

- `rg "App\\\\Service\\\\X\b"` is empty for every moved `X`.
- `ServiceRootTest::PENDING_MOVE` shrank by exactly the step's file count.
- Full gate green; `lint:container` green; git shows renames only.
- `STATUS.md` row with the PR link and the new root count.

---

## C9 — `chore(backend): shrink deptrac baseline after service moves`

**Goal:** `deptrac.baseline.yaml` contains no entry that no longer fires.

```bash
make -C backend deptrac-baseline
git diff backend/deptrac.baseline.yaml   # removals only; an addition is a bug in C3–C8
```

**Exit:** the diff removes the `AccountLanguage` entries (Entity and DTO)
and nothing is added. `make test-e2e` once for the whole series.
`STATUS.md` closes with: root count 4, baseline entry count, deptrac green
on `main`.

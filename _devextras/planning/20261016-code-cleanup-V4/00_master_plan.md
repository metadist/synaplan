# Code cleanup V4 — twelve sprints, one branch chain

**Status:** Plan, 2026-10-16. Drafted from a re-measurement of `main` at
`9ca56ccb1`, the morning V3 merged ([#2512](https://github.com/metadist/synaplan/pull/2512) →
[#2513](https://github.com/metadist/synaplan/pull/2513) → [#2514](https://github.com/metadist/synaplan/pull/2514)).
Nothing is implemented. The §0 rows marked *proposed* need the owner's
tick before the sprint that depends on them starts.
**Priority:** Row 19 of [`../ROADMAP.md`](../ROADMAP.md). S0 is a security /
privacy fix: account deletion leaves 12 user-owned tables behind. It starts
first. S1 lands before row 8, the prompting flow, grows `ChatView.vue`
further.
**Goal:** Work the twelve findings V3 deliberately left
([V3 §7](../2026-archive/20261015-code-cleanup-V3/00_master_plan.md)), one
sprint each, in that order. Every sprint ends with a test that proves the
change works and that nothing else moved, and with a reviewed pull request
whose CI is green.
**Class:** per pull request (§2): S1b `ota-candidate`, S1a and this plan
`no-app-impact`, every other sprint `backend-only`. No sprint changes the OpenAPI
contract: `make -C frontend generate-schemas` produces a zero diff in all
twelve.
**Prerequisite:** V3 on `main` (done 2026-10-16).

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Decisions, baseline, the sprint chain, gates, exit, non-goals |
| [`01_vibe_coding_loop.md`](./01_vibe_coding_loop.md) | The loop every sprint runs: branch, tests first, gate, pull request, Copilot, CI, merge hand-off, and the chore that closes it |
| `S00_…` – `S11_…` | One file per sprint: measured scope, steps, sprint test, exit, chore |
| [`STATUS.md`](./STATUS.md) | Step log with the three ratchet numbers, and the review log |

---

## 0. Decisions

| # | Decision | Agreed |
| - | -------- | ------ |
| 1 | **Twelve sprints, S0–S11**, numbered like V3 §7 #0–#11 and run in that order. One sprint is one branch and one pull request. Lettered pull requests (S1a, S4a, …) only when the size budget (#6) is exceeded or the release classes differ. | 2026-10-16 |
| 2 | **One branch chain.** Each sprint branch is created from the head of the previous sprint branch, not from `main`; this plan's branch is the root. Pull requests merge strictly in order. **At most two unmerged sprint branches above `main`:** S(n+2) does not start before S(n) is merged, so a review finding never ripples through more than one child. | 2026-10-16 |
| 3 | **Every sprint ends with a sprint test** ([loop §4](./01_vibe_coding_loop.md#4-the-sprint-test)): proof tests that fail on the parent branch or with the change reverted, the characterization snapshots, the unfiltered gates and the E2E specs on the path. A sprint without a sprint test is not done, however green it looks. | 2026-10-16 |
| 4 | **Every sprint ends with its chore, C0–C11** ([loop §7](./01_vibe_coding_loop.md#7-the-chore-c0c11)): STATUS row, ledgers, docs, push, pull request, Copilot review answered, re-review, CI green, merge hand-off. The chore is part of the sprint, not polish after it. | 2026-10-16 |
| 5 | **Squash merge; the pull-request title is the commit on `main`** and drives the release version. Behaviour fixes are `fix(…)`, structure `refactor(…)`, gates `test(…)`. **No `feat` in V4:** no sprint adds a user capability. | 2026-10-16 |
| 6 | **Size budget:** a pull request carries at most ~1,500 lines of new or changed logic. Snapshots, ledgers, generated files and code moved verbatim (`git diff --color-moved`) do not count. Above that it splits into lettered pull requests on the same chain (S1a, S1b, …), each with its own sprint test. S1 and S4 are planned that way. | 2026-10-16 |
| 7 | **Safety net before a change** (V2/V3 rule, locked): characterization first. A `refactor:` commit with a snapshot diff is wrong. A `fix:` commit's snapshot diff is reviewed line by line and quoted in the pull request. | Locked |
| 8 | **Extraction rules** (V2, locked): one reason to change, ≤ 10 constructor parameters, domain folder, `final readonly`, `declare(strict_types=1)` in every new file, no "Helper" / "Util" / "Manager" names. Each new class opens with a docblock that says what it owns in one or two sentences. Comments only for constraints the code cannot show. | Locked |
| 9 | **Erasure lives in code, not in foreign keys.** S0 closes the 12 gaps with erasers. It does not add `ON DELETE CASCADE`: several gaps carry state outside their row that a cascade cannot reach (sidecar workspaces, Redis copies, open live sessions), secrets must go through the credential vault, and FK migrations on the production Galera cluster are the riskiest kind (AGENTS.md, "Production Platform Specifics"). | 2026-10-16 |
| 10 | **The owner merges.** The agent brings a pull request to merge-ready (loop §10). It never merges with `--admin`, never bypasses a red check, never resolves a review thread without an answer. | 2026-10-16 |
| 11 | V3 moves to `2026-archive/` in this plan's pull request (V3 decision #8); `docs/ARCHITECTURE.md` points at the archived path. | 2026-10-16 |
| D1 | **S2 — which processor differences align.** Proposed: align #1–#6 and the double classification; keep #7–#9 and name the reason in the stage (S02 §2). | *proposed* |
| D2 | **S3 — one topic / intent rule.** Proposed: the classifier's semantics win, with one policy: a `chat` tag keeps the topic it is given (S03 §2). | *proposed* |
| D3 | **S8 — one MIME-to-extension map.** Proposed: the union of both maps in `FileHelper`, parameters stripped, the caller keeps its fallback (S08 §2). | *proposed* |

---

## 1. Measured baseline (2026-10-16, `main` at `9ca56ccb1`)

V2 ratchet ledger (`make -C backend metrics`): **57** files > 800 lines,
**69** constructors > 10 params, **147** methods > 100 lines. 1,598 of
1,757 PHP files under `src/` declare strict types.

| Sprint | Target | Measured today |
| ------ | ------ | -------------- |
| S0 | Account deletion | `UserErasureCoverageTest::NOT_ERASED_YET` = 12 entities: Approval, ChatSummary, ComputeRun, ComputeWorkspace, Connection, Credential, CustomTool, DesktopDevice, DesktopJob, DocumentRevision, UrlWatch, VoiceSession |
| S1 | `frontend/src/views/ChatView.vue` | 6,481 lines; `streamAIResponse()` from line 3,499, ~1,873 lines; three `onUpdate` handlers (lines 2,991, 3,626, 4,210). 29 frontend files > 800 lines; **no frontend size gate** |
| S2 | `MessageProcessor` / `Message\Processing\` | 303 + 1,575 lines; nine named differences; the streamed widget turn runs the AI sorter twice (`MessageProcessor.php` lines 68–74 and 89–93) |
| S3 | Topic / intent | `MessageClassifier::mapModelTagToTopic` / `mapTopicToIntent` vs. `TurnClassificationResolver::topicForModelTag` / `intentForAgainTopic` disagree on 5 of 5 inputs (V3 §3) |
| S4 | Channel turns | `WidgetPublicController::message()` 735 lines (class 2,234 / 27 deps, own `sendSse()` at line 2,014); `WhatsAppService::handleIncomingMessage()` 601 (class 2,563 / 25); `WebhookController::email()` 606 (class 1,085 / 19) |
| S5 | `MediaGenerationHandler` | 2,245 lines, 22 deps; `handleStream()` 1,010, `detachMediaToAsyncJob()` 166; `handle()` is a buffering wrapper over `handleStream()` |
| S6 | `MessageClassifier` | 2,025 lines, 21 deps; `classify()` 575, `canFastPathClassify()` 208 |
| S7 | `SystemConfigService` | 2,961 lines, 13 deps; `buildSchema()` 1,534, `getSchema()` 182, `getValues()` 138, `setValue()` 103 |
| S8 | MIME → extension | `WhatsAppService::getExtensionFromMimeType()` (24 types, strips parameters, `unknown`) vs. `FileHelper::getExtensionFromMimeType()` (12 types, no stripping, caller fallback, `bin`) |
| S9 | Controllers | 115 of 809 controller methods > 50 lines; 25 of 131 controller files use `EntityManagerInterface`, 9 use `AiFacade` |
| S10 | Providers | `GoogleProvider` 2,764 lines, `OpenAIProvider` 2,079 (also `XaiProvider` 1,791, `AnthropicProvider` 1,744 — see §6) |
| S11 | Strict types | 5 of 6 files in `Message\Processing\` and `MessageProcessor.php` declare no strict types |

---

## 2. The sprint chain

Every branch is created from the one above it. "Base when opened" is the
pull-request base. CI runs only for pull requests into `main` (`ci.yml`,
`pull_request: branches: [main, develop]`). A pull request opened on its
parent branch is therefore reviewed by Copilot at once, and its CI runs
when the parent merges, GitHub retargets it to `main` and the rebased
branch is pushed (loop §10).

| Sprint | Chore | Branch | Base when opened | Pull-request title (= commit on `main`) | Class | Owner decision |
| ------ | ----- | ------ | ---------------- | ---------------------------------------- | ----- | -------------- |
| — | — | `docs/cleanup-v4-plan` | `main` | `docs(planning): code cleanup V4 — twelve sprints, one branch chain` | no-app-impact | — |
| [S0](./S00_erase-user-owned-tables.md) | C0 | `fix/cleanup-v4-s0-erasure` | plan branch or `main` | `fix(iam): erase every user-owned table with the account` | backend-only | — |
| [S1a](./S01_frontend-ratchet-chat-stream.md) | C1 | `test/cleanup-v4-s1a-frontend-ratchet` | S0 or `main` | `test(frontend): size ratchet for the frontend` | no-app-impact | — |
| [S1b](./S01_frontend-ratchet-chat-stream.md) | C1 | `refactor/cleanup-v4-s1b-chat-stream` | S1a or `main` | `refactor(frontend): one stream handler for the chat view` | ota-candidate | — |
| [S2](./S02_align-buffered-streamed-turn.md) | C2 | `fix/cleanup-v4-s2-turn-alignment` | S1b or `main` | `fix(message): one buffered and streamed turn, widget turns classified once` | backend-only | D1 |
| [S3](./S03_one-topic-intent-rule.md) | C3 | `fix/cleanup-v4-s3-topic-intent` | S2 or `main` | `fix(message): one topic and intent rule for model overrides and Again` | backend-only | D2 |
| [S4a](./S04_channel-turns.md) | C4 | `refactor/cleanup-v4-s4a-widget-turn` | S3 or `main` | `refactor(widget): the widget turn as named stages` | backend-only | — |
| [S4b](./S04_channel-turns.md) | C4 | `refactor/cleanup-v4-s4b-email-turn` | S4a or `main` | `refactor(email): the inbound email turn as named stages` | backend-only | — |
| [S4c](./S04_channel-turns.md) | C4 | `refactor/cleanup-v4-s4c-whatsapp-turn` | S4b or `main` | `refactor(whatsapp): the WhatsApp turn as named stages` | backend-only | — |
| [S5](./S05_media-generation-turn.md) | C5 | `refactor/cleanup-v4-s5-media-turn` | S4c or `main` | `refactor(media): media generation turn as stages` | backend-only | — |
| [S6](./S06_message-classifier-split.md) | C6 | `refactor/cleanup-v4-s6-classifier` | S5 or `main` | `refactor(message): split MessageClassifier along its seams` | backend-only | — |
| [S7](./S07_system-config-sections.md) | C7 | `refactor/cleanup-v4-s7-system-config` | S6 or `main` | `refactor(admin): system settings schema as per-tab sections` | backend-only | — |
| [S8](./S08_one-mime-extension-map.md) | C8 | `fix/cleanup-v4-s8-mime-map` | S7 or `main` | `fix(files): one MIME-to-extension map` | backend-only | D3 |
| [S9](./S09_controller-rules.md) | C9 | `refactor/cleanup-v4-s9-controller-rules` | S8 or `main` | `refactor(api): thin-controller gate and first pay-down` | backend-only | — |
| [S10](./S10_provider-adapters.md) | C10 | `refactor/cleanup-v4-s10-providers` | S9 or `main` | `refactor(ai): Google and OpenAI providers split per capability` | backend-only | — |
| [S11](./S11_strict-types-message-turn.md) | C11 | `refactor/cleanup-v4-s11-strict-types` | S10 or `main` | `refactor(message): strict types on the message turn` | backend-only | — |

Why this order (V3 §7, re-checked today): S0 is privacy. S1 is the
largest file in the repository and the one row 8 opens next. S2 changes
the stages that S3, S6 and S11 build on. S3 fixes the rule before S6 moves
it. S4–S7 are splits with a snapshot each. S8 is a small `fix:` best done
after S4 has moved the WhatsApp media code. S9 gates controllers once S4
has thinned the three worst. S10 is independent. S11 types the files
S2/S3 finished changing.

---

## 3. Gates

The commands and the order are in the [loop §5](./01_vibe_coding_loop.md#5-quality-gates);
each sprint file adds its own sprint test.

| When | What must be green |
| ---- | ------------------ |
| Every commit | Backend: `make -C backend lint`, `phpstan`, `deptrac`, `test`. Frontend (S1): `make -C frontend lint`, `docker compose exec -T frontend npm run check:types`, `make -C frontend test` |
| End of sprint | The sprint test of the sprint file: proof (fails without the change), regression (snapshots, unfiltered gates), journey (E2E, browser walk for S1) |
| Before push | `make ci-local && make test-e2e`; `lint:container`; `generate-schemas` zero diff; `node scripts/mobile-impact.mjs --base <parent> --head HEAD` gives the sprint's class |
| Before merge | **All Checks Passed** green on the head commit; every Copilot thread answered and resolved; a re-review after the last fix with no new actionable finding; STATUS row and review log written |

A green filtered run is a diagnosis, never the gate (AGENTS.md).

---

## 4. Exit

V4 is done when all twelve sprints are merged (fifteen pull requests,
counting S1a–b and S4a–c) and:

- `UserErasureCoverageTest::NOT_ERASED_YET` is empty and deleted; an
  integration test proves a deleted account leaves no row with its id in
  any user-owned table.
- A frontend size ratchet runs in Vitest on `main`; `ChatView.vue`'s
  guest and signed-in streams share one tested stream handler.
- `message_turn.json` shows no buffered / streamed difference except
  the ones D1 keeps, each named in its stage; a streamed widget turn
  classifies once.
- One topic / intent rule, used by the classifier and by Again.
- `WebhookController`, `MediaGenerationHandler`, `MessageClassifier`,
  `SystemConfigService`, `GoogleProvider` and `OpenAIProvider` leave the
  file ledger. Expected ledger: **≤ 51 / ≤ 66 / ≤ 132** (today 57 / 69 /
  147): six files (S4b, S5, S6, S7, S10 ×2), three constructors (S5, S6,
  S7), fifteen methods (S4 ×3, S5 ×2, S6 ×2, S7 ×4, S10 ×4). The binding
  numbers are in each sprint file; these follow from them.
- One MIME-to-extension map; a controller gate with a shrink-only
  baseline; strict types across the message turn.
- `docs/ARCHITECTURE.md` describes every new seam; V4 is archived with a
  dated note.

---

## 5. Non-goals

- No new feature, no new UI surface, no OpenAPI change, no new SSE event.
- No routing change outside S2 (double classification, D1) and S3 (D2),
  each with its reviewed routing / turn snapshot diff.
- No schema migration, with one exception: if S0 finds a table whose rows
  cannot be deleted without one, the migration follows `docs/MIGRATIONS.md`
  and the Galera rules, and is called out in the pull request.
- No new dependency (npm or composer). S1's frontend ratchet uses the
  TypeScript and Vue compilers already installed.

---

## 6. Carried to V5

Not in V4, so that V4 can finish: the `XaiProvider` / `AnthropicProvider`
split (S10's recipe, documented in `docs/ARCHITECTURE.md`); classification
and prompt metadata as value objects end to end (S11 types the boundary
only); the rest of `WidgetPublicController` and `WhatsAppService` after
S4; the other 28 frontend files over 800 lines (`AdvancedWidgetConfig.vue`,
`ChatWidget.vue`, `FilesView.vue`, …), frozen by S1's ratchet. Carried
from V2 §7 unchanged: domain-first layout, the `AI` ↔ `Service` split,
domain-to-domain deptrac rules, the `Plugin\Sdk` namespace, PHPStan 5 → 8,
`tests/` mirroring `src/`, aligning `ChatHandler`'s two turns.

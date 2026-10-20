# Code cleanup V3 — three bad ideas, three fixes

**Status:** Implemented 2026-10-15 (K0–K10, see `STATUS.md`); exit met;
merged 2026-10-16 as one pull request per fix, in order: [#2512](https://github.com/metadist/synaplan/pull/2512) → [#2513](https://github.com/metadist/synaplan/pull/2513) → [#2514](https://github.com/metadist/synaplan/pull/2514).
**Archived 2026-10-16.** §7 became code cleanup V4
([`../20261016-code-cleanup-V4/`](../20261016-code-cleanup-V4/00_master_plan.md)).
Drafted the same day from a measurement of `main` at `eef29b5ac`; §0 agreed
in chat ("fix 3 things in V3, not perfection in one go").
**Priority:** Row 17 of [`../../ROADMAP.md`](../../ROADMAP.md): the cleanup follow-up
that lands before row 8, the prompting flow. V1 and V2 closed the cleanup
window and were archived in #2510; V3 does not reopen it.
**Goal:** Remove three ideas that keep producing bloat, each where it hurts
most today, and stop there:

1. **A rule copied into every class that needs it.** The four chat-path god
   classes (`MessageProcessor`, `MediaGenerationHandler`, `MessageClassifier`,
   `FileAnalysisHandler`) carry private copies of helpers that already have
   an owner — and some copies have drifted.
2. **A pipeline written twice, once buffered and once streamed.** V2 found
   this in `ChatHandler`; it is still alive one layer down, in
   `MessageProcessor` and `FileAnalysisHandler`.
3. **A list hand-wired into a constructor.** `SeedAllCommand` and
   `UserDeletionService` take 36 dependencies each, and both lists have
   drifted from their own documentation or their own copy.

**Why now:** V2 met its exit for the three named classes. Its broader goal —
"no class in the chat hot path over 800 lines" — is not met: the hot path
continues below `ChatStreamOrchestrator` into `MessageProcessor` (2,111
lines) and the handlers. Row 8 lands on exactly these files.
**Class:** every step `backend-only` (K0 `no-app-impact`). No OpenAPI change:
`make -C frontend generate-schemas` must produce a zero diff.
**Prerequisite:** V1 and V2 on `main` (done: #2478–#2486, #2492–#2500).

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Decisions, baseline, safety net, step table, gates, what is deliberately left for V4 |
| `01_seams.md` | Per-fix member → class tables, measured |
| `STATUS.md` | Step log with the three ratchet numbers |

---

## 0. Decisions

| # | Decision | Agreed |
| - | -------- | ------ |
| 1 | **Three fixes, not more.** Each removes one bad idea where it is measured worst. Everything else that the 2026-10-15 audit found stays frozen in the V2 ratchet and is listed in §7 as the V4 order. | 2026-10-15 |
| 2 | **Consolidate only where the copies agree.** A helper whose copies are identical (or differ only in transport or log wording) moves to its one owner. Copies that *disagree* are a behaviour decision: they are recorded in §3 with both sides and left in place. The topic / intent rules in `MessageProcessor` and `MessageClassifier` are such a case. | 2026-10-15 |
| 3 | **Safety net before a split** (V2 rule): no twin collapses until a characterization snapshot pins both entry points. A `refactor:` commit with a snapshot diff is wrong unless the diff is a called-out fix. | Locked |
| 4 | `FileAnalysisHandler`'s twins **collapse** into one path with a reply sink: they differ only in how the answer leaves (returned vs. pushed to the stream callback) and in log wording (§1). | 2026-10-15 |
| 5 | `MessageProcessor` follows the **G7 recipe**: shared stages that both `process()` and `processStream()` call; where the two paths differ, the stage has a buffered and a streamed entry and the difference is named there. Aligning the differences is behaviour work and goes to V4 (§3 lists them). | 2026-10-15 |
| 6 | Lists become **tagged collections** (the V2 runtime-config pattern): `app:seed` iterates tagged seeders in today's order, account deletion iterates tagged erasers in today's order. A test pins each order; a coverage test makes a forgotten user-owned table fail CI. | 2026-10-15 |
| 7 | V2's extraction rule applies: one reason to change, ≤ 10 constructor params, domain folder, `final readonly`, no "Helper" / "Util" / "Manager" names. | Locked |
| 8 | V1 and V2 were archived by #2510 on 2026-10-15. V3 moves to `2026-archive/` when its pull requests merge; the plan paths in `backend/deptrac.yaml`, `SizeRatchetTest` and `ServiceRootTest` point at the archived V1 / V2 folders. | 2026-10-15 |

---

## 1. Measured baseline (2026-10-15)

V2 ratchet ledger: **59** files > 800 lines, **73** constructors > 10
params, **151** methods > 100 lines (V2 start: 62 / 76 / 166).

### Fix 1 — copied helpers in the four chat-path god classes

| Copy | Where | Owner that already exists | Copies agree? |
| ---- | ----- | ------------------------- | ------------- |
| `notify()` status event | `MessageProcessor`, `MediaGenerationHandler`, `FileAnalysisHandler`, plus `MessagePreProcessor`, `InferenceRouter` (5) | `Message\ProgressCallback` (V2) | Same payload; the four handler copies send a whole-second `timestamp` and no `metadata` key. The SSE relay adds `metadata` anyway and passes `timestamp` through; every client reads it as seconds (the widget multiplies by 1000). → consolidate |
| Memory job for a media turn | `MediaGenerationHandler::maybeDispatchMemoryExtraction()` + `normalizeThreadForQueue()` (98 lines) | `Memory\PendingMemoryExtraction::build()` + `dispatch()` | Normaliser byte-identical; the guard order is the same (incognito, disabled by request, disabled by user, perf flag); media adds an empty-text guard. → consolidate, guard stays in the handler |
| Model tag lookup | `MessageClassifier::getModelTag()` via `EntityManager` | `ModelConfigService::getModelTag()` (already injected) | Same entity, same `'chat'` fallback. → consolidate |
| Same check written twice inside one class | `MessageClassifier`: `lastAssistantGeneratedFile` / `…Media` (90 % identical), `threadHasGeneratedFile` / `…Media` (82 %), `checkPromptOverride` / `checkModelOverride` (77 %); `MessageProcessor`: `readConfluenceLinks` / `readJiraLinks` (80 %) | — | → one parameterised method each |
| Supported UI locales | `FileAnalysisHandler`, `ChatErrorPresenter`, `GeneratedMediaTextRenderer`, `TelegramInboundService` (4 lists) | `App\Model\AccountLanguage::SUPPORTED` | Same five codes. → one list; each normalisation algorithm stays as it is |
| Topic for a model tag, intent for a topic | `MessageClassifier::mapModelTagToTopic` / `mapTopicToIntent`; `MessageProcessor::mapModelTagToTopic` / `mapTopicToIntentForAgain` | — | **No.** See §3. → recorded, not merged |

### Fix 2 — the twins

| Class | Lines | Ctor | Worst methods | Twins | Safety net today |
| ----- | ----- | ---- | ------------- | ----- | ---------------- |
| `Service/Message/Handler/FileAnalysisHandler` | 2,046 | 11 | `routeFiles()` 205 | document 94 %, audio 90 %, `handle`/`handleStream` 77 % identical; the image twin already delegates | 5 unit test files, no snapshot |
| `Service/Message/MessageProcessor` | 2,111 | 21 | `processStream()` 578, `process()` 468 | 246 identical code lines of ~380; nine behavioural differences (§3) | 1,538-line unit test with a positional constructor; **no characterization** — the SSE transcript scripts the processor away |

### Fix 3 — the hand-wired lists

| Class | Lines | Ctor | Drift found |
| ----- | ----- | ---- | ----------- |
| `Command/SeedAllCommand` | 287 | 36 | The `--help` text lists 26 of the 35 steps the command runs (`progress-narration`, `smart-search`, `compute` missing). |
| `Service/Iam/UserDeletionService` | 643 | 36 | The 27-call erase list exists twice. The copy in `cleanupUserData()` has **no caller** and has already lost `deleteSearchIndex()` — dead code, no user affected, but proof that two lists drift. |

---

## 2. Safety net

| Target | Characterization (before the split) | Notes |
| ------ | ----------------------------------- | ----- |
| `FileAnalysisHandler` (K4) | **Analysis transcript**, buffered and streamed, per case: the request handed to `AiFacade` (method, messages, options keys), the chunks the stream callback receives, the status events, the result shape. Cases: one document; documents over the context window; voice message; image batch with one failed image; extraction error; transcription error; unsupported types only; no file. `tests/Characterization/__snapshots__/file_analysis.json` | `AiFacade` test double; fixture files in a temp upload dir |
| `MessageProcessor` (K6) | **Turn transcript**, through `process()` and `processStream()`: ordered status events (name + payload keys), what reaches `InferenceRouter` / `TaskPlanExecutor` (routing-relevant classification and option values), the result shape. Cases: plain chat; fixed task prompt (api, widget, saved task); Again with a model id; forced image description; widget classification; web search voted; URL in the message; provider exception; generic exception; user cancel. `message_turn.json` | Scripted classifier, router, search gateway, URL reader — the style of `StreamTranscriptCharacterizationTest` |
| Seeders, erasers (K8, K9) | Order tests: the tagged order equals today's list. Coverage test for erasers (§3). | — |

Snapshots are re-recorded only with `UPDATE_*_SNAPSHOTS=1` and reviewed line by
line, as in V2.

---

## 3. Target shapes

### Fix 1 — one owner per rule

No new class. The copies above are deleted and their callers use the owner.
`MessageProcessor` keeps its per-status `info` log line next to the
`ProgressCallback` call (the log is the only part that is not a copy).

**Recorded, not merged — two topic / intent rules that disagree.** Both
answer "the user picked model X: which topic and intent?", one for a model
override (classifier), one for Again (processor):

| Input | `MessageClassifier` | `MessageProcessor` (Again) |
| ----- | ------------------- | -------------------------- |
| tag `chat` | topic `general` | keeps the message's previous topic (a custom prompt survives) |
| tag `document` / `officemaker` / `text2doc` | message topic, else `general` | `officemaker` |
| tag `vision` | message topic, else `general` | `general` |
| unknown tag | message topic, else `general` | previous topic, else `chat` |
| topic → intent | capability registry, then aliases (`tools:pic` → image generation) | own `match` (`tools:pic` → chat) |

Picking one is a routing change (the routing snapshot moves) and a product
decision — V4, §7.

### Fix 2 — one turn, written once

```
FileAnalysisHandler (2,046 → < 800 lines, 11 → ≤ 10 deps)   handle() / handleStream() → one analyse(…, AnalysisReply)
  FileAnalysis\AnalysisFileResolver     the files of the message, the conversation or the thread; file info
  FileAnalysis\DocumentAnalysis         context fit, overflow retry, document prompts
  FileAnalysis\VoiceMessageReply        conversational reply over the transcripts
  FileAnalysis\ImageAnalysis            batch, combine, all-failed check
  FileAnalysis\FileAnalysisFailureCopy  extraction / transcription / video / image failure copy, locale
  FileAnalysis\AnalysisReply            buffered (content in the result) | streamed (chunks to the callback)
```

```
MessageProcessor (2,111 → < 800 lines, 21 → ≤ 10 deps)      process() buffered, processStream() streamed
  Processing\TurnClassificationResolver fixed prompt, Again, forced image description, widget, classifier; agent pin
  Processing\TurnHistoryLoader          incognito, chat or tracking-id history; rolling summary
  Processing\LinkedContentReader        URL fetch, Confluence and Jira links, link-only detection
  Processing\TurnWebSearch              search vote, trigger reason, language, query, attachment context, search, deepen, persist
  Processing\TurnDispatcher             runtime tool flags, MCP allow-list, shadow plan, multitask executor or router, effective classification
```

The member → class tables are in [`01_seams.md`](./01_seams.md). The nine
differences between the two processor entries stay, each named in the stage
that owns it:

| # | Difference | Buffered `process()` | Streamed `processStream()` |
| - | ---------- | -------------------- | -------------------------- |
| 1 | `rag_group_key`, `rag_limit`, `rag_min_score` | ignored | applied |
| 2 | `options.language` | honoured | ignored |
| 3 | fixed-prompt language | override, else `en` | message language, else `auto` |
| 4 | widget mode | generic classifier branch | own branch, `is_widget_mode` set |
| 5 | prompt metadata | reuses `classification.prompt_metadata`, loads without language | always loads with language → `resolved_prompt_data` |
| 6 | search results | in `classification.search_results`, removed afterwards | in `options.search_results` |
| 7 | `complete` / `error` status | emitted by the processor | emitted by the caller |
| 8 | user cancel | generic failure | `cancelled: true` |
| 9 | perf timer | none | per phase |

### Fix 3 — lists in the container

```
SeedAllCommand (36 → 2 deps)          iterates #[AutoconfigureTag('app.seeder')] in priority order; --help built from the seeders
  Seed\SeederInterface                 label(), description(), seed(): SeedResult

UserDeletionService (36 → ≤ 4 deps)   transaction + order; cleanupUserData() deleted
  Iam\Erasure\UserDataEraserInterface  erase(UserErasure) inside the transaction, afterCommit(UserErasure) for external services
  Iam\Erasure\*Eraser                  one per domain (01_seams.md §3)
```

**Coverage test:** every Doctrine entity that maps a user-owner column is
named by one eraser or listed in the test as "removed by cascade" / "kept,
with reason". A new user-owned table without an eraser fails CI — the account
deletion path cannot silently leave data behind.

### As delivered (K1–K10)

- **Fix 1** as planned. The divergent topic / intent rules stay recorded, not
  merged (§0 #2).
- **Fix 2, file analysis:** two more small types than drawn —
  `AnalysisContext` (message, classification, status, reply for one turn) and
  `AnalysisModel` (the model choice and result metadata that were repeated in
  documents, voice and images) — and the routing decision is the pure
  `FileAnalysisRoute`, its three identical ready / pending / failed blocks
  folded into one. Handler 161 lines, 6 deps.
- **Fix 2, processor:** `TurnStatus` replaces the private `notify()`; the
  prompt lookup lives in `TurnClassificationResolver` beside the topic it
  resolves. Processor 303 lines, 6 deps. The moved code keeps weak typing
  (no `strict_types`), exactly like the file it came from: with strict types
  the first snapshot run failed on an int tracking id the old code coerced,
  and prompt-metadata flags read from the database may do the same (V4 #11).
- **Fix 3:** 15 erasers instead of 13, because the erase order is pinned and
  configs, prompts and the remaining account records sit at three different
  places in it (`ConfigEraser`, `PromptEraser`, `AccountRecordsEraser`). The
  after-commit cleanups run in eraser order; each already contains its own
  failures. The coverage test found **12 user-owned tables that are not
  erased** — the first item of V4 (§7 #0).
- Built on one branch in commit order K0–K5, K8, K9, K6, K7, K10
  (`STATUS.md`), then split by fix into #2512 → #2513 → #2514. `main`
  gained #2510 (V1 / V2 archived, cleanup window closed) meanwhile; V3 is
  row 17 of the roadmap and lands before the prompting flow.

---

## 4. Step table

| Step | Commit title | Fix | Depends |
| ---- | ------------ | --- | ------- |
| K0 | `docs(planning): code cleanup V3 — three bad ideas, three fixes` | — | — |
| K1 | `refactor(message): report pipeline status through ProgressCallback only` | 1 | K0 |
| K2 | `refactor(message): one owner for the media memory job, the model tag and the locale list` | 1 | K0 |
| K3 | `refactor(message): fold the same-check twins in classifier and processor` | 1 | K0 |
| K4 | `test(backend): characterization snapshots for file analysis` | 2 | K0 |
| K5 | `refactor(file-analysis): one analysis path with a reply sink` | 2 | K1, K4 |
| K6 | `test(backend): characterization snapshots for the message processor turn` | 2 | K0 |
| K7 | `refactor(message): shared turn stages for process() and processStream()` | 2 | K1, K3, K6 |
| K8 | `refactor(seed): run app:seed over tagged seeders` | 3 | K0 |
| K9 | `refactor(iam): erase user data through tagged erasers` | 3 | K0 |
| K10 | `docs(planning): close V3 and the cleanup window` | — | K1–K9 |

Delivery: one commit per step, each commit green on the gate below, built on
one branch and split by fix for review: [#2512](https://github.com/metadist/synaplan/pull/2512) (K0–K3),
[#2513](https://github.com/metadist/synaplan/pull/2513) (K4–K7), [#2514](https://github.com/metadist/synaplan/pull/2514) (K8–K10). Fix 2 builds on Fix 1's
consolidated helpers, so the three merge in order; the size ledger is
regenerated on each branch.

Commit types: every step is `refactor:`, `test:` or `docs:` — none raises
the minor version. A step that turns out to change behaviour is split and
the behaviour commit becomes `fix:`, called out in the description.

---

## 5. Gates

Per commit:

```bash
make -C backend lint
make -C backend phpstan
make -C backend deptrac
make -C backend test            # includes the size ratchet and every characterization snapshot
```

Per pull request, in addition:

```bash
docker compose exec -T backend php bin/console lint:container
make -C frontend generate-schemas && git diff --exit-code frontend/src/generated/
node scripts/mobile-impact.mjs --base main --head HEAD   # → backend-only
make test-e2e                                             # chat path (K5, K7) and account deletion (K9)
```

- Ledger: after each split `UPDATE_SIZE_RATCHET=1` drops the dead entries;
  the diff contains removals and smaller numbers only.
- `STATUS.md` row per step: files > 800, ctors > 10, methods > 100, and the
  lines / params of the class worked on.
- K9 touches the account deletion path: the pull request states the
  security impact (what is erased, in which order, what happens after commit).

### Exit

- `MessageProcessor` and `FileAnalysisHandler` leave the file and constructor
  ledgers; `SeedAllCommand` and `UserDeletionService` leave the constructor
  ledger. Expected ledger ≤ 57 / ≤ 69 / ≤ 151.
- Zero private `notify()` copies under `src/Service/Message/`; one thread
  normaliser; one locale list.
- Both new snapshots on `main`; the routing, prompt, SSE and runtime-config
  snapshots unchanged.

---

## 6. Non-goals

- No behaviour change, no new SSE event, no OpenAPI change, no UI change.
- No routing change: `MessageClassifier` / `MessageSorter` decisions and
  their snapshot stay as they are (K3 only folds code inside the class).
- No alignment of the nine processor differences (§3) and no merge of the
  two topic / intent rules — both are V4 behaviour work.
- No frontend change.

---

## 7. Deliberately left for V4 (in this order)

The audit of 2026-10-15 found these too. Each is real; none is in V3, so
that V3 can finish.

| # | What | Measured | Why it waits |
| - | ---- | -------- | ------------ |
| 0 | **Erase the 12 user-owned tables account deletion misses** (found by K9's coverage test) | Approval, ChatSummary, ComputeRun, ComputeWorkspace, Connection, Credential, CustomTool, DesktopDevice, DesktopJob, DocumentRevision, UrlWatch, VoiceSession: no eraser, no `ON DELETE CASCADE`, no listener — the rows (stored credentials, chat summaries, document revisions among them) survive the account | Security / privacy, so first. Behaviour: a `fix(iam):` per domain, each with files on disk, external sessions and connections revoked where they exist; every closed table leaves `UserErasureCoverageTest::NOT_ERASED_YET` |
| 1 | **Frontend size ratchet**, then `ChatView.vue`'s stream handling | `ChatView.vue` 6,481 lines; `streamAIResponse()` 1,873 lines; three `onUpdate` handlers, 337 of the guest handler's 396 code lines repeated in the signed-in one; no frontend size gate (29 files > 800 lines, 39 functions > 100) | Needs its own characterization, a browser walk and the app's SSE device check (`ota-candidate`). First item after V3, before row 8 grows the file further. |
| 2 | Align the processor's buffered and streamed turn | the nine differences in §3, plus a speed bug K6 pinned: a streamed widget turn without a fixed prompt runs the AI sorter twice (once without history, once with) and the second run drops `is_widget_mode` | Behaviour: one `fix:` per difference with its `message_turn.json` diff; the double classification first (one paid sorter call per such turn) |
| 3 | One topic / intent rule | the table in §3 | Routing change; owner decides which semantics win |
| 4 | Channel turns | `WidgetPublicController::message()` 735 lines (27 deps, own copy of `SseWriter`), `WhatsAppService::handleIncomingMessage()` 601, `WebhookController::email()` 606 | Each needs its own reply snapshot first |
| 5 | `MediaGenerationHandler::handleStream()` | 1,010 lines, the longest method on the chat path | Split after K2 removed its copied helpers |
| 6 | `MessageClassifier` split | 2,082 lines, `classify()` 575 | Routing snapshot exists; seams in the audit (attachment intent, thread artifacts, overrides, fast path, language) |
| 7 | `SystemConfigService` | 2,961 lines; `buildSchema()` 1,534 lines of data — splits into 13 per-tab sections of 22–236 lines | Admin-only, low risk, not on the chat path |
| 8 | Two MIME-to-extension maps that disagree | `WhatsAppService` vs `FileHelper` | Merging changes file extensions: a `fix:` |
| 9 | Controller rules | 115 of 809 controller methods > 50 lines; 25 controllers inject `EntityManagerInterface`, 9 inject `AiFacade` | A deptrac rule with a shrinking baseline |
| 10 | Provider adapters | `GoogleProvider` 2,764, `OpenAIProvider` 2,079, `XaiProvider` 1,791, `AnthropicProvider` 1,744 | Split per capability when touched; the registry resolves by capability |
| 11 | Strict types in `Message\Processing\` and `MessageProcessor` | the moved code has no `strict_types`, like the file it came from: prompt-metadata flags and tracking ids still rely on PHP's coercion | Type the boundaries (classification and prompt metadata as value objects), then turn strict types on; a runtime `TypeError` there is a failed turn |

Carried from V2 §7: domain-first layout, the `AI` ↔ `Service` split,
domain-to-domain deptrac rules, the `Plugin\Sdk` namespace, PHPStan 5 → 8,
`tests/` mirroring `src/`, aligning `ChatHandler`'s two turns.

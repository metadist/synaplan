# Research quality — steps

Roadmap: [`00_master_plan.md`](./00_master_plan.md) §5. Engine:
[`03_research_engine.md`](./03_research_engine.md). Prompts:
[`04_prompting.md`](./04_prompting.md). Journeys and copy:
[`05_journeys_and_ui.md`](./05_journeys_and_ui.md). Evaluation:
[`06_evaluation.md`](./06_evaluation.md).

Each step has a **brief**: paste it into the coding agent as the task, after
pointing it at `00_master_plan.md`. The exit criteria are the definition of
done; the agent reports each one with the command it ran. A step starts only
when the §0 rows it names are ticked. RD steps are pull requests in
`synaplan-desktop`; all others in `synaplan`.

UI steps (RD3–RD9, RW1, RW2) carry a **User-flow** line and the five exit
bullets of `05` §10 (UX contract §6).

---

## Before RQ1 — local setup

```bash
make up                                       # synaplan dev stack
cd _devextras/testing/desktop && ./pair.sh    # desktop harness key (needs curl and jq)
```

Provider keys for the arms you run (Anthropic, OpenAI, Google, Groq; Mistral
and TrustedTokens for the EU arms) in `backend/.env`, and a web search provider
(BCONFIG `PLUGS.WEB_SEARCH.PROVIDER`; SearXNG for a key-free setup). Local
models: the `local-ai` profile with `bge-m3` and `gpt-oss:20b` pulled.

---

## RQ1 — Re-baseline the desktop use cases (synaplan-desktop)

### Goal

- Do: run the full use-case plan against `main`; move every CHANGES row whose
  check is green to **Done** with the run id; keep failing rows with their new
  evidence.
- Do: copy the summary (pass / fail per case, the command, both SHAs) into this
  folder's STATUS.
- Do not: change product code.

### Files

Touches: `synaplan-desktop/_extras/planning/20260917-desktop-use-case-test-plan/STATUS.md`,
`CHANGES.md`, `runs/<timestamp>.json`; `STATUS.md` here.

### Brief

> Run `cargo run -p synaplan-core --example usecases -- --full` from
> `synaplan-desktop/src-tauri` against the local stack with a fresh harness key.
> For each row in `CHANGES.md`, move it to Done only if its STATUS check is green
> in this run; otherwise update its evidence line. Do not edit code. Record the
> run in both STATUS files.

### Exit criteria

1. A dated full run in the desktop STATUS history and in this STATUS.
2. CHANGES Done list reflects the run; no row ticked without a green check.

---

## RQ2 — Research eval corpus, judges and baseline report (no-app-impact)

### Goal

- Do: the 40-item corpus and the 10-item smoke set of `06` §2, a deterministic
  generator for the synthetic documents, judge prompts, two people's
  calibration labels (40 + 40).
- Do: `app:research:eval` with the scorer (deterministic metrics + judges), arm
  `b0` (today's web chat services), import of arm `b1` JSON from the desktop
  runner, `--frozen`, `--repeat`, `--report`.
- Do: record judge agreement (κ) and B0 on the full set in STATUS.
- Do not: add product behaviour; put customer text in the corpus.

### Files

Touches: `backend/src/Command/ResearchEvalCommand.php` (new),
`backend/src/Service/Research/Eval/*` (new: corpus loader, scorer, judge
client, B0 adapter, frozen store), `backend/tests/Eval/research/*` (new),
unit tests for the scorer.

### Brief

> Read `06_evaluation.md`. Build the corpus files and the generator script for
> the synthetic documents (same output on every run — test it with a
> checksum). Implement `app:research:eval` so arm `b0` runs today's web chat
> path through the same services the stream uses (classifier vote forced to web
> search, `TurnWebSearch`, `WebResearchService::deepen`, the CHAT answer;
> knowledge items through the chat's knowledge loader). Score deterministic
> metrics without models; judge the rest with two judges from other families,
> blind. Write a Markdown report and a JSON summary. Never write outside the
> evaluation account.

### Exit criteria

1. Corpus schema test and generator checksum test green (`make -C backend test`).
2. κ per judge in STATUS; judges below 0.7 not used.
3. B0 on the full set in STATUS with the command.

---

## RS1 — The web search wrap-up answers fully and keeps every source (backend-only)

### Goal

- Do: `GatewayToolLoop::WRAP_UP_AFTER_SEARCH` text as in `04` §7;
  `WEB_SEARCH_WRAP_AFTER` from `MessagesGatewayConfig` (BCONFIG
  `MESSAGES_GATEWAY.WEB_SEARCH_MAX_ROUNDS`, default 2).
- Do not: grow `GatewayToolLoop` (frozen at 1,432 lines and four methods —
  `03` §9); change the default round count; touch `EMPTY_SEARCH_RECOVERY`.

### Brief

> In `backend/src/AI/Messages/Tools/GatewayToolLoop.php`, replace the wrap-up
> sentence with the one in `04_prompting.md` §7 and read the round limit from
> `MessagesGatewayConfig` instead of the constant. The file and its frozen
> methods must not get longer. Add unit tests for the new text and the
> configured limit.

### Exit criteria

1. Gateway unit tests green; `SizeRatchetTest` green with no ledger change.
2. Desktop quick set: UC-03 green, UC-04 answers keep their content (run id in
   STATUS).

---

## RS2 — Read web pages on every model, not only on Anthropic (backend-only)

### Goal

- Do: an executable `web_fetch` (`Tools/WebFetchTool.php`) that Synaplan runs
  for non-Anthropic upstreams when `WEB_FETCH_MODE=auto` — fetched with
  `UrlContentService::fetchForReading()`, condensed with the reader model
  (READ → SORT) when longer than the tool result cap; Anthropic keeps its server
  tool; `off` still removes it.
- Do: a tagged native-tool registry the loop already reaches through
  `GatewayToolCatalog`, so no constructor parameter or branch is added to
  `GatewayToolLoop`.
- Do not: fetch internal or non-http(s) addresses (`SsrfGuard` decides); change
  the Anthropic route.

### Brief

> Mirror what `WebSearchTool` did for `web_search`: replace the `web_fetch`
> declaration on OpenAI-shaped and Gemini routes with an executable tool of the
> same name and run it inside the gateway loop. Respect `WebFetchPolicy` modes.
> Keep Desktop's contract (`03` §12): Desktop never receives an unpaired
> `web_fetch` call, including requests that mix `write_file` with server tools.
> Add a policy matrix test (provider × mode) and a fixture test for the mixed
> case.

### Exit criteria

1. Policy matrix, SSRF refusal, size cap and condensing tests green.
2. Desktop quick set: UC-04 and a new UC-04b (a fact found only in a page body)
   green on Groq, OpenAI and Gemini routes.

---

## RS3 — Search the pinned knowledge folder as a tool (backend-only)

### Goal

- Do: `Tools/KnowledgeSearchTool.php` (`query`, `limit` ≤ 10) and
  `Tools/ReadDocumentTool.php` (`file_id`, `around` chunk or page), executed by
  Synaplan inside the caller's scopes, offered when a knowledge folder is pinned
  (`x-synaplan-rag-group-key`) or an assistant has knowledge scopes; BCONFIG
  `MESSAGES_GATEWAY.KNOWLEDGE_TOOLS` (on for paired desktop keys).
- Do not: change `MessagesContextInjector::sessionBlock` (frozen); widen scopes.

### Brief

> Add two gateway-owned tools that search and read the pinned knowledge folder
> with `VectorSearchService` and `RagScope` exactly as the injector does. Results
> carry file name, file id, chunk id and score. Never return another person's
> passages; a folder shared at "Can use" is allowed. Tests for scopes, limits
> and flag off.

### Exit criteria

1. Scope tests (own, shared Can use, shared Can view refused, foreign refused)
   green.
2. UC-05e green; UC-05 and UC-06 unchanged.

---

## RS4 — Read PDFs and office files that web search finds (backend-only, §0 #12)

### Goal

- Do: `Service/Research/Run/Source/ResearchSourceReader.php` — HTML through
  `UrlContentService`, documents downloaded through `SsrfGuard` (≤ 20 MB, 15 s)
  and extracted with the extraction chain; temporary files deleted.
- Do: behind `WEB_SEARCH.READ_DOCUMENTS` (default off), `WebResearchService`
  uses the reader for document URLs (`SKIP_EXTENSIONS` from config; `deepen`
  swaps one call and does not grow).

### Exit criteria

1. Tests with fixture PDF and DOCX served by a stub; `deepen` behaviour
   unchanged with the flag off.
2. RQ2 smoke, regulation and science items, flag on vs off, in STATUS; the flag
   stays off unless it is better.

---

## RD1 — A stronger agent prompt and full-length answers (synaplan-desktop)

### Goal

- Do: `build_system_prompt` as in `04` §6 (date, time zone, language, method,
  web and project-file blocks); language-neutral file and source checks;
  remove `REWRITE_SOURCES_INSTRUCTION` and the English verb lists; plain-chat
  `max_tokens` from the Chat model's `max_output` (≤ 16,384; 8,192 unknown);
  `knowledge_search` and `read_document` added to the server-tool names.
- Do: the runner's `--research-corpus <file>` mode that writes B1 JSON; record B1
  before and after this change.
- Do: UC-03-de and UC-04-de.
- Do not: touch `protocol: 1` fixtures; add a dependency.

### Exit criteria

1. `make ci-local` green; quick set incl. the German variants green.
2. B1 before and after on the smoke set in this STATUS.

---

## RS5 — Model slots for reading sources and fact-checking (backend-only, §0 #4)

### Goal

- Do: `ModelCatalog::CAPABILITY_TAGS` += `READ`, `VERIFY` (`chat`);
  `ResearchRoleResolver` with the fallback chains, the world rule and the
  cross-family rule (`02` §7); `meta.family` and missing `meta.jurisdiction` on
  the chat rows presets use.
- Do: no seeded rows yet — the fallbacks apply until RQ3 seeds the winners
  (new rows then reach existing installs too).
- Do not: change existing slots or `ModelConfigService` (frozen); change
  `/v1/models/catalog` (Desktop picks roles from its Chat group); add the slots
  to `PickerCapabilities::ALL` (RW2 adds them with UI labels).

### Exit criteria

1. Resolver tests: chains, retirements, allow-lists, world rule (fail closed),
   cross-family rule with and without a candidate.
2. Routing snapshots unchanged; catalog data test green.

---

## RS6 — Research runs that plan, read and write a cited report (backend-only)

§0 #1–#3, #7–#9, #19 ticked.

### Goal

- Do: the engine of `03` without reflect and checker: brief, plan, one gather
  pass, read with the deterministic quote check, write; ledger, state store,
  event log, outcomes, budgets, usage per role, cost check per phase.
- Do: `RunResearchCommand` + handler on `async_ai_high`; `ResearchApiController`
  (`/v1/research*`) and `ResearchController` (`/api/v1/research*`) with complete
  OpenAPI; `GET …/capabilities`; `ResearchModule` with `RESEARCH.ENABLED`
  (code default off); `ResearchConfigSeeder` (budgets).
- Do: prompts `tools:research_brief|plan|triage|read|write` and their schemas;
  contract fixtures in `_devextras/testing/research/fixtures/` asserted against
  the live controllers (like `DesktopContractFixturesTest`); `UserDeletionService`
  removes the user's `research:*` keys; `docs/RESEARCH.md`; a row in
  `docs/FEATURE_FLAGS.md`; `make -C frontend generate-schemas`.
- Do not: reflect (RS7), check (RS8), hybrid search (RS9), any UI, any write.

### Brief

> Read `03_research_engine.md` and `04_prompting.md`. Build the run as phase
> classes sharing one `StageContext` (no constructor over ten parameters, no
> method over 100 lines, controllers thin). Readers get the untrusted-data
> wrapper and no tools; the writer gets evidence cards only. Every quote must be
> found in its source text or it is dropped. Emit sequenced, resumable events.
> With the flag off, the routes answer 404 and `RoutingCharacterizationTest` is
> unchanged. Test every stage and every outcome with `TestProvider` replays,
> including a source that tries to inject instructions.

### Exit criteria

1. Replayed-run tests for each stage and outcome; injection fixture changes no
   action and no sentence.
2. Flag off: 404 and unchanged snapshots. Flag on (dev): smoke set runs end to
   end; RQ2 smoke row "engine v1" in STATUS.
3. Contract fixtures match; deptrac, size ratchet, `make ci-local` green.

---

## RS7 — Search again until every point of the plan is covered (backend-only, §0 #10)

### Goal

- Do: `ReflectStage`, `tools:research_reflect`, action execution and validation,
  the five stop rules, clarifying questions, plan review, the `input` types of
  `03` §7.4, waiting without a worker, checkpoints and resume, one Thorough run
  per person.

### Exit criteria

1. Tests for each stop rule (including no progress), dropped invalid actions,
   waiting timeouts → outcomes, resume after a killed worker, event replay
   without gaps.
2. RQ2 smoke: fact recall above the RS6 row (beyond the noise band).

---

## RS8 — Fact-check every cited statement with a second model (backend-only, §0 #11)

### Goal

- Do: `CheckStage` (deterministic checks, checker batches, Thorough revision,
  Standard removal), `tools:research_check`, `tools:research_revise`, the
  check summary in state and report, the cross-family rule applied.

### Exit criteria

1. A seeded-error set (true statements plus planted wrong numbers, dates and
   names): detection and false-alarm rates in STATUS.
2. RQ2 smoke: citation precision above the RS7 row.

---

## RS9 — Keyword plus meaning search for research and knowledge_search (backend-only, §0 #14)

### Goal

- Do: a Qdrant full-text payload index on the chunk text of `user_documents`
  (idempotent), the keyword leg with the same scopes, RRF fusion shared with
  `SmartSearchService` (extracted, not copied), rerank inside the world; used
  by `GatherStage` and `KnowledgeSearchTool`.
- Do: document cards for research folders behind `RESEARCH.DOCUMENT_CARDS`
  (default off).
- Do not: add a MariaDB index without the ask-first row.

### Exit criteria

1. `app:rag:eval-rerank --hybrid` on the fixture corpus: recall@5 for named
   items up; p95 within the rerank budget; numbers in STATUS.
2. MariaDB-vector installs unaffected (keyword leg absent, said in Details).

---

## RS10 — Follow-ups and changes reuse the sources (backend-only)

### Goal

- Do: `POST …/followups` — question (quick depth on the parent ledger), revise
  (writer + check of changed sentences), deeper (Thorough budget on top), refresh
  (re-gather stale points); ledger inheritance and numbering.

### Exit criteria

1. Tests for each follow-up type and for citations surviving a revision.
2. Five follow-up items added to the corpus, scored in STATUS.

---

## RQ3 — Model team bake-off and seeded presets (backend-only)

### Goal

- Do: the five stages of `02` §8 with frozen inputs; tables in STATUS.
- Do: seed `DEFAULTMODEL.READ`, `DEFAULTMODEL.VERIFY`, `RESEARCH.PRESET.*` and
  `RESEARCH.VERIFY_CANDIDATES` with the winners (insert-if-missing).
- Do: decide the flag per `06` §5; if met, `RESEARCH.ENABLED` seeded on for new
  installs; existing installs are a separate owner decision (a migration).

### Exit criteria

1. Stage tables, the commands and the corpus version in STATUS.
2. The seeder diff; a ROADMAP decision-log row for the flag.

---

## RD2 — Split ChatView into thread, composer and turn runners (synaplan-desktop)

### Goal

- Do: split `src/views/ChatView.vue` (2,050 lines) into a thread component, a
  composer component and composables for the stream turn and the agent turn —
  behaviour identical; a Vitest size check (components ≤ 600 lines, with a
  ledger that may only shrink) so it stays split.
- Do not: change copy, behaviour or the Tauri commands.

### Exit criteria

1. `make ci-local` green; quick set green.
2. A walk of chat, agent with a skill, Web, dictation and file drop entered in
   STATUS.

---

## RD3 — Research mode in chat with live progress (synaplan-desktop)

**User-flow:** J-RQ-2 steps 1–6, J-RQ-6 (`05` §4); wireframes `05` §5.1, 5.3,
5.4, 5.5, 5.7.

### Goal

- Do: `synaplan-core/src/research.rs` (start, state, input, cancel, follow-ups,
  resumable SSE with `Last-Event-ID`; strict request types, tolerant event
  parsing); vendored `tests/fixtures/research-contract/` with a checksum test;
  Tauri commands; `tauri.ts` wrappers.
- Do: capability probe; the Research chip, depth and sources line; the plan
  card (Standard view), progress card with **Stop**, **Write now**, **Details**;
  the result card with **Open report** (rendered with the existing Markdown
  renderer until RD5); outcome copy by code; events and result stored in the
  thread's local JSON; the empty-chat tile *Research a topic*.
- Do: five locales, native-speaker review, forbidden words.
- Do: UC-11, UC-12, UC-14.

### Exit criteria

1. `make ci-local`; quick set incl. UC-11, UC-12, UC-14 green.
2. The five bullets of `05` §10.

---

## RD4 — Plan review and questions before a thorough research (synaplan-desktop)

**User-flow:** J-RQ-4 steps 1–3; wireframes `05` §5.2, 5.3.

### Goal

- Do: Thorough depth with the estimate line; question card (options, own
  answer, Skip); editable plan card (Remove, Add a point, Cancel, Start
  research); `input` calls; UC-13 (question, approval part).

### Exit criteria

1. UC-13 approval part green; the five bullets of `05` §10.

---

## RD5 — The report with sources you can check (synaplan-desktop)

**User-flow:** J-RQ-2 steps 7–8, J-RQ-9; wireframe `05` §5.6.

### Goal

- Do: report view (side panel and full width), citation chips, source drawer
  (quote, **Open page**, **Open file** through the project's file origins),
  sources list, fact-check sentence with **Show what was removed**.
- Do: `out/<slug>.md` + `out/<slug>.sources.json`, Out board entry, local
  versions with restore, **Ask about this report**, **Go deeper**, **Delete
  report** with consequence copy.

### Exit criteria

1. Quick set green; the five bullets of `05` §10.

---

## RD6 — Turn a report into a document, slides, a table or an email (synaplan-desktop)

**User-flow:** J-RQ-5.

### Goal

- Do: **Make a Word document** and **More** (slides, table, email draft, web
  page, translate) run the bundled skills through the agent loop with a fixed
  instruction and the report file; artifact chip; "made from version N"
  honesty; skills blocked by the doctor show their reason and a link to **Check
  this computer**.
- Do: UC-15.

### Exit criteria

1. UC-15 green; the five bullets of `05` §10.

---

## RD7 — Add a whole folder to the knowledge folder in one step (synaplan-desktop)

**User-flow:** J-RQ-3 steps 1–3 and 7; wireframe `05` §5.9.

### Goal

- Do: **Add a folder** (native picker), summary dialog (types, size, skipped
  kinds, sub-folders), consent sentence, per-file status including "Could not
  read any text from this file.", **Remove from this project** with consequence
  copy, the local origin of each file remembered.

### Exit criteria

1. UC-06 green from a folder add; the five bullets of `05` §10.

---

## RD8 — The research team in this project's models (synaplan-desktop)

**User-flow:** J-RQ-7; wireframe `05` §5.8.

### Goal

- Do: Models → **Research team** (lead = Chat, reader = Documents or
  recommended, checker = recommended from another provider), the world sentence,
  **Customize** pickers from the Chat group, **Reset to recommended**; every run
  sends `models` and `world.providers`.
- Do: UC-16.

### Exit criteria

1. UC-16 green; the five bullets of `05` §10.

---

## RD9 — Keep researching with the window closed and say when it is done (synaplan-desktop)

**User-flow:** J-RQ-4 steps 4–6.

### Goal

- Do: reattach running research on start and reconnect; tray tooltip with
  progress; an OS notification on completion through the existing
  `tauri-plugin-notification` (no new dependency); click opens the thread.
- Do: UC-13 (resume part).

### Exit criteria

1. UC-13 green; a walk with the window closed entered in STATUS; the five
   bullets of `05` §10.

---

## RW1 — Research mode in the web chat (ota-candidate)

**User-flow:** J-RQ-8; wireframe `05` §5.10.

### Goal

- Do: **Research** in the composer tools menu (icon-map entry); a research
  composer panel and a research card as new components — `ChatView.vue` and
  `ChatInput.vue` do not grow (frontend size ratchet); events through a new
  handler next to `handlers/progress.ts`; the report stored as a file attached to
  the assistant message (backend part in this step), `DocumentRevision` for its
  versions; Zod schemas from the OpenAPI.
- Do: five locales; E2E with the test provider stub.

### Exit criteria

1. `make ci-local && make test-e2e`, layout E2E green.
2. The five bullets of `05` §10.

---

## RW2 — Research team and budgets under AI infrastructure (ota-candidate)

**User-flow:** J-RQ-10 (`05` §4).

### Goal

- Do: `PickerCapabilities::ALL` += `READ`, `VERIFY` with labels; presets per
  world and depth; budgets per depth; the module status; **Run a sample
  research** with a human result ("Read 6 sources · 9 of 9 statements backed ·
  2 min 41 s · cost 0.21").

### Exit criteria

1. `make ci-local && make test-e2e`; the five bullets of `05` §10.

---

## RW3 — Research as a Saved Task step (backend-only)

### Goal

- Do: `ResearchRunner` with capability `research` (`quick` or `standard`, web
  and folder sources, no questions, no plan review), described in the planner's
  skill catalog; report as the step's text and file.
- Do not: change approvals; add UI (if the Saved Task editor does not list
  planner capabilities generically, the editor entry is a follow-up step).

### Exit criteria

1. Runner tests; a Saved Task "weekly brief → email me" run in dev with its
   result in STATUS.

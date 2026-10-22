# Research quality — agentic research with a model team, Desktop first

**Status:** Draft 2026-10-17, written from the owner's question of the same
day: *"Cursor's results are much better than ours. Can Synaplan Desktop reach
a similar quality for research with agentic looping and better prompting —
combining models for summarizing, sorting and indexing with models for
creation, modification and enhancement?"* The measured baseline is in
[`01_baseline_and_gaps.md`](./01_baseline_and_gaps.md). Tick §0 before the
first product PR. **Revised 2026-10-22** after the architecture review in
[`08_review.md`](./08_review.md) §2b: the lead drives tools itself where the
model allows it (§0 #2), a spike and go / no-go gate before Phase C (§0 #40),
and §0 #37–#42.
**Priority:** Row 21 of [`../ROADMAP.md`](../ROADMAP.md), not started. Its
place among the queued rows is the owner's call. Phase B (quick wins on
today's paths) is small and can run beside any track.
**Goal:** A person who is not technical asks a research question in Synaplan
Desktop and gets, within minutes, a report whose every factual sentence points
to a source they can open — researched by a team of models: fast, cheap models
search, sort, read and index; one frontier model plans, reflects and writes; a
second provider checks every cited statement. Then one click turns the report
into a Word document, slides, a table or an email.

1. **Phase A — measure (RQ0–RQ2).** Re-run the desktop use cases, build a
   research eval corpus, record today's numbers.
2. **Phase B — quick wins on today's paths (RS1–RS4, RD1).** Remove the
   ceilings that make today's answers short and unsourced.
3. **Gate — spike and go / no-go (RQ2b, §0 #40).** A console-only spike of the
   engine with both lead modes, measured against today's paths after Phase B
   and an external deep-research reference. Phase C starts only if the spike
   wins by the margin in §0 #40.
4. **Phase C — the research engine (RS5–RS10, RQ3).** A server-side research
   run: brief → plan → gather → read → reflect (loop) → write → check.
5. **Phase D — the Desktop research surface (RD2–RD9).** Research chip in
   Chat, plan card, live progress, report with sources, "Turn into…".
6. **Phase E — web chat and Saved Tasks (RW1–RW3).** Same engine, same card.

**Why now:** The answer to the owner's question is *yes, for research* — and
the gap is not the models. Synaplan's catalog already holds the frontier
models Cursor uses (Claude Opus 5.5, GPT-6.1 Sol, Gemini 3.x, Grok 4.7, Muse
Wonder) and the cheap fast ones (gpt-oss-120b on Groq / Cerebras, Gemini 3.8
Flash, GPT-6 Luna). What Cursor has and we lack is the *harness*: a model that
drives tools in a loop, a division of labour between models, evidence it can
point to, a verification step, and a surface that shows the work. Today our
gateway forces an answer after two web searches "in one or two sentences",
reads pages only on Anthropic routes, and gives Desktop five knowledge passages
per turn with no way to ask for more (§1).
**Binding UX:** [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) (U1–U12)
and, for Desktop, the copy rules of
`synaplan-desktop/_extras/planning/20260910-desktop-project-companion/05_ux_and_i18n.md`
(forbidden words, five questions, no sixth rail item). Surfaces in RD1,
RD3–RD9, RW1–RW2.
**Class:** RQ0, RQ2 `no-app-impact`; RQ3, RS*, RW3 `backend-only`; RW1–RW2
`ota-candidate`; RQ1 and RD* are pull requests in `synaplan-desktop` (its own
`make ci-local` gate, no mobile class).
**Prerequisite:** none for Phases A and B. Phase C needs §0 #1–#9 ticked and
the go decision of §0 #40 recorded in STATUS.
Phase D needs RD2 (the 2,050-line `ChatView.vue` split first).
**Serves:** use cases 10 (sovereign expert research: citation fidelity,
abstention, sovereignty as a job-wide setting), 5 (tender matrix with a
citation per row) and 3 (regulation watch) of
[`../20260913-use-case-research/README.md`](../20260913-use-case-research/README.md),
and its open Q8 for research runs.

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Start here: decisions, target shape, journeys, step table, gates, risks |
| [`01_baseline_and_gaps.md`](./01_baseline_and_gaps.md) | Why Cursor's results are better, factor by factor; what Synaplan and Desktop do today, with file references and measured limits |
| [`02_model_roles.md`](./02_model_roles.md) | The model combination: two job families, six roles, requirements, candidates from the live catalog, presets per world, cost estimates, the bake-off |
| [`03_research_engine.md`](./03_research_engine.md) | The research run: phases, loop, budgets, stop rules, evidence ledger, citations, API and events, durability, security, sovereignty |
| [`04_prompting.md`](./04_prompting.md) | Prompting principles, the `tools:research_*` prompt set (drafts), the Desktop agent prompt rewrite, prompt tests |
| [`05_journeys_and_ui.md`](./05_journeys_and_ui.md) | Journeys J-RQ-1…10, wireframes, copy in five locales, the five questions, empty / error / flag-off states |
| [`06_evaluation.md`](./06_evaluation.md) | The quality harness: corpus, metrics, judges, baselines, targets, the use cases UC-11…UC-16 |
| [`07_sprints.md`](./07_sprints.md) | One brief per step: goal, reads, touches, tests first, brief to paste, docs, exit criteria |
| [`08_review.md`](./08_review.md) | The 2026-10-17 plan review: gaps, forgotten work, regression risks with their guards, installation findings, what changed; §2b the 2026-10-22 architecture review against current deep-research systems |
| [`09_installations.md`](./09_installations.md) | Every way Synaplan is installed — dev, CI, single-node cloud, air-gapped, EU, minimal, Kubernetes, three-node production — what research does there, the degradation copy, the tests per profile, upgrade / rollback, the documentation matrix |
| [`10_contracts.md`](./10_contracts.md) | The shapes to code against: six schemas, endpoints, events, execution units, every BCONFIG key, PHP / Rust / Vue contracts, fixtures, log and usage fields |
| [`STATUS.md`](./STATUS.md) | Step log and measured numbers |

---

## Start here (60 seconds)

**What we build:** a **research run** on the Synaplan server. It turns a
question into a short brief, a plan of three to seven points, searches the web
and the person's knowledge folders, lets cheap models read each source with the
question as the lens and return **evidence cards** that cite numbered passages
of the source, lets one frontier model **drive the search itself** — it calls
`search`, `read_sources` and `finish` as tools, as an IDE agent does, while the
engine enforces budgets and returns only evidence cards — writes the report
from the evidence only, and has a model from a *different provider* check every
cited statement. Models without reliable tool calling (local ones among them)
get the same run in fixed rounds of JSON decisions (§0 #2). Synaplan Desktop gets it first as a **Research**
chip in Chat; the web chat gets the same engine and the same card later.

Before the engine, Phase B removes today's ceilings, because most questions
will keep going through normal chat: the two-sentence web wrap-up, page reading
only on Anthropic, a single five-passage knowledge injection, the 1,024-token
Desktop chat budget, file tools that appear only when a skill is enabled, and
language-bound intent checks.

**Where it lives:**

| You want to change… | Go to… |
| ------------------- | ------ |
| Web search (six providers, one-shot fallback) | `backend/src/Plug/WebSearch/WebSearchGateway.php`, `Adapter/*` (Brave, SearXNG, Tavily, Exa, Firecrawl, Perplexity) |
| Reading pages (SSRF, robots, login walls, redirects) | `backend/src/Service/Research/UrlContentService.php` (`fetchForReading`, `fetchManyForReading`) |
| Today's "deep search" (read top pages, condense) | `backend/src/Service/Research/WebResearchService.php` (`deepen`, `readMentionedUrls`; skips PDFs) |
| Condensing with a cheap model (map-reduce) | `backend/src/Service/Context/ContextCondenser.php` (`fit`, model order SORT → ANALYZE → CHAT) |
| Search query rewrite (one query) | `backend/src/Service/Message/SearchQueryGenerator.php`, prompt `tools:search` |
| Knowledge search, rerank | `backend/src/Service/RAG/VectorSearchService.php`, `backend/src/Plug/Rerank/RerankStage.php`, `ChatKnowledgeContextLoader` |
| Keyword + meaning fusion (RRF) precedent | `backend/src/Service/SmartSearch/SmartSearchService.php` |
| Model slots and resolution | `backend/src/Service/ModelConfigService.php`, `backend/src/Model/ModelCatalog.php` (`CAPABILITY_TAGS`, `findBidByKey`), `backend/src/Seed/DefaultModelConfigSeeder.php`, `backend/src/Service/Model/PickerCapabilities.php` |
| Reasoning level per call | `backend/src/AI/Provider/ReasoningLevelCatalog.php` |
| Provider calls, structured output | `backend/src/AI/Service/AiFacade.php`, `backend/src/AI/StructuredOutput/` |
| One-shot planner precedent | `backend/src/Service/Multitask/TaskPlanner.php`, `TaskPlanSchema.php`, `Execution/DagExecutor.php`, `Execution/Runner/*` |
| Durable streamed run precedent | `backend/src/Service/Chat/Run/ChatRun.php`, `ChatRunBuffer.php` (Redis replay) |
| Background work | `backend/config/packages/messenger.yaml` (`async_ai_high`), `backend/src/MessageHandler/` |
| Gateway tool loop and its limits | `backend/src/AI/Messages/Tools/GatewayToolLoop.php` (`WEB_SEARCH_WRAP_AFTER`, `WRAP_UP_AFTER_SEARCH`), `GatewayToolCatalog.php`, `WebSearchTool.php`, `WebFetchPolicy.php` |
| Desktop knowledge injection | `backend/src/AI/Messages/MessagesContextInjector.php` (`RAG_LIMIT = 5`, `MAX_CHARS = 8000`) |
| Budgets and usage | `backend/src/Service/RateLimitService.php` (`checkCostBudget`, `recordUsage`) |
| Feature modules | `backend/src/Module/` (precedent `Module/Chat/ChatArtifactsModule.php`) |
| Prompts | `backend/src/Prompt/PromptCatalog.php` (system prompts re-seeded on every deploy) |
| Key scopes | `backend/src/Security/ApiKeyScope.php` (`/v1/*` = `desktop:messages`) |
| Desktop agent loop and prompt | `synaplan-desktop/src-tauri/synaplan-core/src/agent.rs`, `agent_tools.rs` (`build_system_prompt`, `WEB_SEARCH_PROMPT`) |
| Desktop chat transport | `synaplan-desktop/src-tauri/synaplan-core/src/messages.rs`, `sse.rs`; `src-tauri/src/commands/mod.rs` (`send_chat`, `send_agent_chat`) |
| Desktop UI | `synaplan-desktop/src/views/ChatView.vue` (2,050 lines), `ChatActivity.vue`, `ProjectPanel.vue`, `InOutBoard.vue`, `ModelsView.vue`, `src/services/tauri.ts`, `src/i18n/*.json` |
| Desktop use-case harness | `synaplan-desktop/src-tauri/synaplan-core/examples/usecases/`, `_extras/planning/20260917-desktop-use-case-test-plan/` |
| Web UI to reuse (Phase E) | `frontend/src/components/multitask/TaskCard.vue`, `frontend/src/components/chat/KnowledgeSources.vue`, `frontend/src/services/chat/stream/handlers/progress.ts`, `frontend/src/components/ToolsDropdown.vue` |

**The loop that keeps you safe:** measure (use cases + eval baseline) → each
quick win turns a named use case green → engine behind a default-off flag →
eval report beats the baseline → Desktop surface → web surface → seeded on.
Never skip a rung. A research feature that cites a source which does not say
what the sentence claims is worse than no research feature.

**Rules for the AI assistant driving this:**

- One step = one PR. The commit title comes verbatim from §5; the type decides
  the release version (`feat` raises the minor).
- Every quality, cost or latency number in STATUS comes from a command you ran
  (`app:research:eval`, the use-case runner), with the command next to it. No
  "should be better".
- Never hardcode a model name in code. Roles resolve through
  `ModelConfigService` and catalog keys in seeders, exactly like
  `DefaultModelConfigSeeder`.
- The research run is read-only. Saving, sending or changing anything is a
  separate action through the existing approval rules.
- Web pages and files are untrusted data. Readers get no tools; the lead and
  the writer never see raw page or file text, only evidence cards (§0 #3).
- `protocol: 1` stays frozen; no new pairing scope. Desktop vendors the
  research contract fixtures byte for byte, like the job fixtures.
- Desktop copy: none of the forbidden words (`Claude`, `Anthropic`, `MCP`,
  `tool_use`, `shell`, …) in product sentences; model names only as catalog
  data; five locales in the same PR; no sixth rail item.
- Web: `ChatView.vue` (4,470 lines) and `ChatInput.vue` (2,605) must not grow —
  the frontend size ratchet fails the build. New surfaces are new components.
- Schema, Docker / CI and dependency changes are ask-first rows in §0. Stop if
  a step needs one that is not ticked.
- Flag off ⇒ `RoutingCharacterizationTest` snapshots unchanged; `capabilities`,
  start, `input` and `followups` answer 404 and no UI shows (U11); a person's
  existing runs stay readable and deletable (§0 #35).
- Do not touch `frontend/src/stores/config.ts` or any `*RuntimeConfig*` file
  under `frontend/src` (store-required in the mobile policy): capabilities come
  from the research endpoint (§0 #30).
- CI never reaches the live web: the `test` search adapter, the fixture source
  reader and the `TestProvider` mocks (§0 #27) are the only sources in PHPUnit
  and Playwright. Real providers are for the eval command and the desktop quick
  set on the dev stack.
- A long piece of work is a chain of short Messenger units (§0 #8), never one
  handler that runs for minutes.
- Every step's brief in `07` starts with **Reads** and **Tests first**; code
  comes after the red test, and the exit criteria are reported one by one with
  the command that proved them.
- Build against [`10_contracts.md`](./10_contracts.md); a contract change is
  made in that file in the same PR, and a wire change bumps the fixtures.

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | **The research engine runs on the Synaplan server.** Desktop and the web app are clients of one engine. Desktop's own agent loop stays for local file and skill work and gets the Phase B improvements. | Proposed | |
| 2 | **The lead runs in two modes with one set of guardrails.** **Tool mode** (default for lead models on `RESEARCH.TOOL_MODE_MODELS`, the catalog keys that passed the conformance suite `app:research:conformance`): the lead calls `search`, `read_sources`, `reread`, `ask_user` and `finish` as native tools and sees each result before its next step, the way an IDE agent works. **Round mode** (fallback for every other model, including `OllamaProvider` without native tool calling): one JSON "next actions" decision per round, executed by the engine. In both modes the engine executes every tool, enforces every budget inside the tool (a call beyond the budget returns "budget used" instead of running), validates ids, and returns only search result lists (sanitised titles and snippets) and evidence cards — never raw page or file text — so the quarantine of #3 holds unchanged. `RESEARCH.LEAD_MODE` (`auto` · `tools` · `rounds`) lets an admin force one. The spike (RQ2b) and RQ3 measure both modes; if tool mode does not beat round mode on `06` metrics, `auto` resolves to `rounds`. | Proposed (changed 2026-10-22) | |
| 3 | **Readers quarantine untrusted text.** Only readers see raw page and file text; they have no tools and return evidence cards. The lead, writer and checker see evidence cards only. This is both the cost lever and the prompt-injection defence. | Proposed | |
| 4 | **Two new model slots: `DEFAULTMODEL.READ` (reading sources) and `DEFAULTMODEL.VERIFY` (fact-checking).** Both `chat`-tagged. Fallbacks READ → SORT → ANALYZE → CHAT and VERIFY → ANALYZE → CHAT. VERIFY prefers a provider family different from the writer's. Lead and writer use the chat's model (web) or the project's Chat model (Desktop); a Thorough preset may name a stronger lead inside the world, and the run says which model it used. Seeded insert-if-missing with catalog keys by RQ3 (until then the fallbacks apply). Alternative without new slots: reader = ANALYZE (today Claude Sonnet 5 — about 3 to 20 times the reading cost, depending on the reader it replaces, `02` §6). | Proposed | |
| 5 | **People choose how thorough, not which models.** Two depths in the UI: **Standard** (default, about 3 minutes) and **Thorough** (about 15 minutes); after a Standard report, **Go deeper** continues with the Thorough budget on the same evidence. An internal `quick` depth serves follow-up questions and Saved Task steps. Normal chat ("Ask") stays the fast path. Budgets in BCONFIG `RESEARCH.BUDGET.<depth>.*` (`03` §4). | Proposed | |
| 6 | **World rule (sovereignty for the whole run).** Desktop sends the role models it allows; roles it leaves to "auto" are picked only among providers the project already uses. The web resolves slots inside the person's group allow-lists. A role that cannot be filled inside the world fails closed with one sentence — never a silent fallback to another provider. Every run lists every model it used. | Proposed | |
| 7 | **Run state in Redis, no table in v1.** `ChatRunBuffer` pattern, 48-hour TTL; Desktop persists the result in the project, the web as the chat message and its file. Alternative: table `BRESEARCHRUNS` (runs listed across devices, survive a Redis flush). **Schema change — ask first**; it would also join account erasure (code cleanup V4 S0). | Proposed | |
| 8 | **Runs execute as short units on `async_ai_high`** — one Messenger message per unit of work (brief + plan, one reflect round, one section, the check), each ≤ `RESEARCH.UNIT_SECONDS` (120), re-dispatching the next; so the single worker process every install runs today (`messenger:consume async_ai_high async_extract async_index --time-limit=3600 --memory-limit=512M`) keeps serving saved tasks, media and indexing between units, checkpoints come for free, and a memory-limit restart loses at most one unit. `RESEARCH.MAX_ACTIVE_RUNS` per install (5), at most one active Thorough run per person. A dedicated transport and worker stays a **Docker / Helm change — ask first**, only if RQ3 measures a queue wait people notice. | Proposed | |
| 9 | **API:** `/v1/research*` for paired keys (covered by `desktop:messages` — no new scope, no re-pair) and `/api/v1/research*` for the web session, both thin controllers over one service; complete OpenAPI; Desktop vendors `research-contract/` fixtures with a checksum test. `protocol: 1` untouched. | Proposed | |
| 10 | **Plan review and questions.** Standard starts at once with the plan visible and stoppable. Thorough shows the plan and waits for **Start research**. At most three clarifying questions, multiple choice with **Skip** — in Thorough, and in Standard only when the brief marks the question as ambiguous. Reuse the ask-the-user card pattern. **One timeout rule:** an unanswered question is treated as skipped after 10 minutes and the run continues; only an unconfirmed plan ends a run (`stopped_waiting` after 24 hours). | Proposed | |
| 11 | **Citations and fact-check.** Every factual sentence cites evidence; every finding cites numbered passages of its source that exist, and any quote it carries must be found inside those passages (deterministic check, no model — #39); a second model checks each cited statement; unsupported statements are removed or moved to **Not confirmed**; the report states the result in one sentence ("35 of 37 statements are backed by a source. 2 were removed."). | Proposed | |
| 12 | **Research runs read PDFs and office files found on the web** through the extraction chain (Tika), capped (20 MB, 15 s, character budget). Plain-chat deep search gets it only behind `WEB_SEARCH.READ_DOCUMENTS`, default off until RQ2 shows a gain. | Proposed | |
| 13 | **Untrusted data, read-only engine.** Source text is delimited and labelled as data in every reader prompt; the engine performs no write, send or purchase; any later write goes through `ToolExecutionGate` / Approvals. | Locked | |
| 14 | **Hybrid knowledge search for research:** keyword + meaning, fused with RRF, then rerank. Keyword leg first through a Qdrant full-text payload index (no MariaDB schema change). A MariaDB FULLTEXT index only if measured better — **schema change, ask first**. | Proposed | |
| 15 | **Desktop: Research is a chip in the Chat composer**, next to Web — not a rail item. The result is a card in the thread, a Markdown file in the project's `out/` folder and an entry on the Out board. | Proposed | |
| 16 | **Creation reuses what exists:** Desktop turns a report into Word, PowerPoint, Excel, an email draft or a web page with the bundled skills through its agent loop; the web uses officemaker and the document tools. No new document generator. | Proposed | |
| 17 | **Gateway quick wins keep today's behaviour as the fallback:** a wrap-up that no longer limits the answer to two sentences; an executable `web_fetch` that Synaplan runs on non-Anthropic routes (Anthropic keeps its own server tool); `knowledge_search` and `read_document` when a knowledge folder is pinned; Desktop's plain-chat output budget from the model's `max_output`. Each lands with its use case turning green. | Proposed | |
| 18 | **Prompts are `tools:research_*` topics in `PromptCatalog`** (internal, excluded from routing per AGENTS.md), with rendered-prompt snapshot tests and JSON-schema tests. A prompt change merges only with an eval run that is not worse than the last recorded one. | Proposed | |
| 19 | **Flag:** FeatureModule `ResearchModule` (`backend/src/Module/Chat/`) with BCONFIG `RESEARCH.ENABLED` and env pin `FEATURE_RESEARCH_ENABLED`, code default off. The module convention requires its decisive config in `backend/.env.minimal` **and** two lines in `docker-compose.minimal.yml` (`RESEARCH_ENABLED: ""`, `FEATURE_MODULES_GATE_RESEARCH: "true"`) — that compose edit is the one Docker change this plan needs and is listed here so it is ticked, not discovered. Seeded on for new installs only after RQ3 meets the targets in `06` §5. | Locked (AGENTS.md); compose lines: Ask | |
| 20 | **Costs are visible and capped.** Thorough shows an estimate before it starts; every run shows what it cost. A per-run cap per depth; `RateLimitService::checkCostBudget()` before each phase; usage recorded per role with source `RESEARCH`. | Proposed | |
| 21 | **No new composer, npm or cargo dependency in Phases A–D.** Desktop already renders Markdown (`useChatMarkdown.ts`, `MessageText.vue`). | Proposed | |
| 22 | **Copy:** one term per concept in five locales (`05` §4) — Research, Standard, Thorough, Sources, Fact-check, Not confirmed, Research team. Desktop forbidden words stay forbidden; native-speaker review before RD3 merges. | Locked (UX contract) | |
| 23 | **Eval first.** No default flips, no preset change and no prompt change without numbers from `06`. The targets table in `06` §5 is the gate for seeding the flag on. | Proposed | |
| 24 | **App only.** No research mode in the chat widget and no research runs over WhatsApp or mail in v1. | Proposed | |
| 25 | **Plan limits.** A rate-limit action `RESEARCH` counts runs per day by subscription level (`RateLimitConfigSeeder`), seeded conservatively (free level: 3 Standard and 0 Thorough runs per day). Limits for paid levels and for `web.synaplan.com` are the owner's call. | Ask (business) | |
| 26 | **Desktop: file tools without a skill.** A turn uses the agent loop even when no skill is enabled if the latest message names a file by a known extension (one that exists in a readable folder or the project `out/` folder is an input; one that does not exist after a request is an output) or refers to the project folder or out-box. Attached and dropped files are **not** a signal — both upload to the knowledge folder (`knowledge.add`) and stay on the streaming path with server-side search. That agent turn is answered without token streaming (the activity feed shows each step), as agent turns are today; `run_program` still needs an enabled skill and execution consent. | Proposed | |
| 27 | **Test doubles instead of the live web in CI.** In the `test` environment a `test` web-search adapter answers from fixtures, a `FixtureSourceReader` serves fixture pages for the host `fixture.synaplan.test` without a network call, and `TestProvider` mocks the `tools:research_*` prompts schema-aware. The SSRF guard is never relaxed. The desktop quick set keeps running against real providers on the dev stack. | Proposed | |
| 28 | **A sweeper, so nothing stays "running".** `app:research:reap` in the scheduler's `tick` lane, three transitions: a unit whose claim lease expired without a checkpoint is re-dispatched once, then the run ends as `failed_interrupted` with what was found; open questions past 10 minutes are marked skipped and the run continues (#10); an unconfirmed plan past 24 hours ends as `stopped_waiting`. | Proposed | |
| 29 | **The world rule has five legs, not one.** Models (as #6); the **web search provider** — only SearXNG is self-hosted, the others are US-hosted — is disclosed on the plan card ("Web search goes through {provider}, hosted in {country}") or refused with `RESEARCH.STRICT_WORLD=1`; **document extraction** from the web uses a cloud-filtered document chain in local and EU worlds — today's `extractionChain($family, $hasCloudStt)` flag selects the audio chain only, so RS4 adds a cloud marker to the extractor contract and the filter, with a test that a local-world PDF never reaches `pdf_vision`; the **condenser** uses the run's reader model, not its own order; **embeddings** use the operator's index model, named in Details, skipped under strict mode when outside the world; rerank skipped when outside. | Proposed (disclose by default) | |
| 30 | **No change to `frontend/src/stores/config.ts` or any `*RuntimeConfig*` frontend file** (store-required in `.github/mobile-impact-policy.json`). The web reads capabilities from `GET /api/v1/research/capabilities`. | Locked (mobile policy) | |
| 31 | **Input budgets come from the model's context window.** Per call: min(role cap, 60 % of `meta.context_window` − the role's output cap); the lead's ledger summary is compacted to fit; local worlds read fewer sources per round and run readers with `RESEARCH.LOCAL_PARALLEL` (1). | Proposed | |
| 32 | **Prompt caching is built, not assumed.** `AnthropicProvider` gets a `cache_system` option (one `cache_control` breakpoint after the stable prefix) for the lead and writer; OpenAI and Gemini cache long prefixes by themselves; Mistral and local models do not. RQ3 records the measured hit rate; `02` §6 shows the cost with and without. | Ask (small provider change) | |
| 33 | **Idempotent start, listing, deletion.** `Idempotency-Key` on `POST /v1/research` (24 h), `GET /v1/research?active=1` for reattach, `DELETE /v1/research/{id}`; Desktop's "Delete report" deletes the server state too. | Proposed | |
| 34 | **Documentation is part of every step.** Each step names its in-repo docs; user docs ship as three `synaplan-docs` pull requests (RX1–RX3) per the matrix in `09` §6; the Helm README note rides with RX2. | Proposed | |
| 35 | **Module off hides new work, not the person's data.** `capabilities`, start, `input` and `followups` answer 404 and no UI shows (U11); `GET`, events, `cancel` and `DELETE` keep working for the caller's own runs until their TTL, so a `stopped_by_admin` outcome and its partial findings stay readable and deletable. Active runs stop after their current unit with the report of what was found. | Proposed | |
| 36 | **One execution per unit, with any number of workers; the web report is delivered by the server.** Each unit takes an atomic claim (`SET NX PX` lease with a token) before any paid call and writes its checkpoint fenced by that token; the next unit is dispatched with a short `DelayStamp` so the single consumer also serves extraction and indexing between units. A web run carries its `chat_id`; the `deliver` unit writes the assistant message and the report file into that chat itself, so a closed tab never loses a report. Cancel is checked before every paid call; a request already in flight may still be billed and the copy says so. | Proposed | |
| 37 | **Reader calls run concurrently in v1**, not later. `AiFacade` has no concurrent chat call today, so a Standard run's 15 reader calls would run one after another (about 1.5–2.5 minutes of reading alone, against a 3-minute target). A `ConcurrentStructuredCaller` sends up to `BUDGET.<DEPTH>.READ_PARALLEL` (4 · 6 · 8) reader requests at once through Symfony HttpClient's multiplexing, inside one unit, with the same usage recording and cancel check per request. Local worlds keep `LOCAL_PARALLEL` (1). | Ask (provider-layer change) | |
| 38 | **Readers return links worth following.** An evidence card may carry up to five `leads` (URL, anchor text, point) — only URLs the engine finds in the page's own extracted link list, so a page cannot invent a target. Leads enter the ledger as `found` sources with origin `link` and pass the SSRF guard when read. This covers the common case "landing page → PDF / price list". | Proposed (2026-10-22) | |
| 39 | **Citations point at numbered passages, not free-text quotes.** The engine splits the raw extracted text into numbered passages (paragraph, table row, list item; long paragraphs into sentences) before the reader sees it; the reader cites passage ids (`§12`) and may add a short quote. Verification: the passage exists, and a quote, when present, is found inside it after normalisation. This removes the main failure of verbatim quotes (near-miss copies dropped as invented) and gives the source drawer an exact location for free. The share of dropped findings is an eval metric (`06` §3). | Proposed (2026-10-22) | |
| 40 | **Go / no-go before Phase C.** After Phase B, the spike RQ2b (a console command, no API, no units, no UI) runs both lead modes on the frozen smoke set against B0, **B1+** (today's gateway loop after RS1–RS3 with a frontier model and raised round limits — the "Cursor-like" path we already have) and **B4** (an external deep-research agent as a reference point, eval only). RS6 starts only if the best spike arm beats B1+ by ≥ 10 points on fact recall **or** reaches the citation-precision target of `06` §5 where B1+ does not, at no more than twice B1+'s cost. Otherwise Phase C shrinks to citations and fact-check around the gateway loop, and the owner decides the rest. | Ask (owner) | |
| 41 | **Budgets are hypotheses, measured as a curve.** The counts in `03` §4 are starting values. RQ2b and RQ3 sweep searches and sources read (Standard 10 / 20, Thorough 30 / 60) and record fact recall against cost; the seeded budget is the knee of that curve. Reference: external deep-research agents use about 80 searches for a typical task, and token spend explains most of the quality difference between research agents (`08` §2b). | Proposed (2026-10-22) | |
| 42 | **The report is written in one call when it fits.** The writer writes the whole report in one call whenever its `meta.max_output` covers the depth's word budget; section-by-section writing is the fallback for smaller models, and then sequential with the previous sections in context — never parallel sections, which read as disjoint. | Proposed (2026-10-22) | |

---

## 1. Why — facts this plan rests on

Full write-up with references in [`01_baseline_and_gaps.md`](./01_baseline_and_gaps.md).

1. **The gateway caps research at two searches and two sentences.**
   `GatewayToolLoop::WEB_SEARCH_WRAP_AFTER = 2`, then
   `WRAP_UP_AFTER_SEARCH`: *"Answer the user now in one or two sentences and
   include the http URL you used. Do not search again."* It fixed the empty-bubble
   bug (desktop CHANGES C2) and is right for "what is the current Node LTS?" —
   and a hard ceiling for anything larger.
2. **Pages are read only on Anthropic routes.** `WebFetchPolicy`: Synaplan never
   executes `web_fetch`; OpenAI, Gemini, Groq, Mistral and the others see search
   snippets only (5 results by default, `WebSearchTool`).
3. **Desktop sees five knowledge passages per turn and cannot ask for more.**
   `MessagesContextInjector`: one embedding of first + last user text,
   `RAG_LIMIT = 5`, `MAX_CHARS = 8000`, pure vector search, no search tool.
4. **Nothing reflects.** `TaskPlanner` plans once (max 24 nodes, `PLAN` =
   gpt-oss-120b) and the DAG runs once; the web chat answers once from
   pre-fetched snippets and up to three read pages (`READ_PAGES_MAX = 3`,
   PDFs and office files skipped).
5. **Desktop works with files only when a skill is on, and its checks are
   language-bound.** The agent loop — the only path with `list_files`,
   `read_file` and `write_file` — runs only when the project has at least one
   enabled skill (`ChatView.vue`: `agentMode = enabledSkillCount > 0`);
   otherwise every turn is plain streaming chat with `max_tokens: 1024`. Inside
   the loop, `user_asked_to_write_a_file` needs the English verbs *write /
   create / save*; plain chat's document detector is a five-language phrase
   list. A German "Schreib die Quellen in quellen.md" gets no file on either
   path. `build_system_prompt` has no date, no method and no language rule.
6. **One model does everything on Desktop.** The project's Chat model searches,
   reads, decides and writes. The server already splits roles by slot (SORT /
   PLAN / MEM on gpt-oss-120b, CHAT / ANALYZE on Claude Sonnet 5) and
   `ContextCondenser` already condenses pages with the fast model — but no
   research flow uses that split.
7. **The catalog cannot rank by job.** `BQUALITY` is 10 for 37 of the 66
   active chat models in the dev catalog and 9 for another 20 (gpt-oss-120b and
   Claude Opus 5.5 alike); there is no "good at reading" or "good at writing".
   Context windows, output limits, prices, `features` and some jurisdictions are
   there and are enough to build roles on.
8. **The base for measuring exists.** The desktop use-case runner (UC-00…10)
   last ran on 2026-09-18: 1 pass, 3 fail (UC-02, UC-03, UC-04). Several of its
   CHANGES rows (C1 `max_completion_tokens`, C3a live desktop RAG, C4
   `thought_signature`) are in the code now but were never re-run.

---

## 2. Target shape

```text
Synaplan Desktop (Tauri)                        Web app (Vue) — Phase E
  Chat · [Research] chip · depth · sources        Chat · Tools → Research
  research.rs: start, resumable SSE, input        /api/v1/research (session)
          │                                               │
          └──── /v1/research (paired key, desktop:messages)
                              │
Synaplan backend              ▼
  ResearchRunService ── RoleResolver: lead/writer = CHAT · reader = READ · sorter = SORT
         │                            checker = VERIFY (other provider) · world rule
         │  Messenger async_ai_high → ResearchRunHandler
         ▼
  Brief ─► Plan ─► ┌ Gather ─► Read ─► Reflect ┐ ─► Write ─► Check ─► Deliver
  sorter   lead    │ sorter    readers  lead   │    writer    checker
                   └──────── loop until covered or budget ──┘
     Loop:   tool mode — the lead calls search / read_sources / reread / finish itself;
             round mode — one JSON decision per round (fallback, §0 #2)
     Gather: WebSearchGateway (query fan-out) · knowledge: VectorSearch + keyword (RRF) + RerankStage
     Read:   UrlContentService (HTML) · extraction chain (PDF, office) · knowledge chunks
             → numbered passages → concurrent readers → evidence cards with passage ids and leads
  EvidenceLedger + ResearchEventLog (Redis, TTL 48 h, sequence numbers, resumable)
  RateLimitService budget check per phase · usage per role (source RESEARCH)
```

A run produces three things: the **report** (Markdown, citations `[n]`), the
**sources** (each with title, host or file, date, the quotes used and why) and
the **run summary** (time, searches, sources read and blocked, fact-check
result, models used, cost).

---

## 3. Journeys

Details, wireframes and copy in [`05_journeys_and_ui.md`](./05_journeys_and_ui.md).

| Journey | Who | Walked in |
| ------- | --- | --------- |
| **J-RQ-1** — Ask with Web on gets a full, sourced answer, and a named file without any skill enabled (normal chat, after the quick wins) | Ada | RD1 |
| **J-RQ-2** — First research from an empty project: question, plan, progress, report, find it again | Ada | RD3, RD5 |
| **J-RQ-3** — Research over my own documents (a folder of PDFs, Web off) | Ben | RD7 |
| **J-RQ-4** — Thorough research: a clarifying question, plan review, close the window, notification, fact-check | Chris | RD4, RD9 |
| **J-RQ-5** — Turn the report into a Word document; change a section; restore the earlier version | Chris | RD6 |
| **J-RQ-6** — Something goes wrong: no web search, budget used up, pages blocked, offline | Ada | RD3–RD5 |
| **J-RQ-7** — A project that stays in its world (EU or local models only) | Ben | RD8 |
| **J-RQ-8** — Research mode in the web chat | Dana | RW1 |
| **J-RQ-9** — Follow-up and "Go deeper" on a finished report | Chris | RD5 |
| **J-RQ-10** — An administrator turns research on, checks the team, runs a sample | Admin | RW2 |

---

## 4. What we do not rebuild

| Already here | Use it |
| ------------ | ------ |
| Web search with six adapters and a fallback | `WebSearchGateway`, `WebSearchRegistry` |
| Page reading with SSRF guard, robots.txt, redirects, login-wall detection | `UrlContentService::fetchForReading()` / `startReading()` |
| Question-aware condensing, map-reduce for long text | `ContextCondenser::fit()` |
| Thread-aware search query rewrite | `SearchQueryGenerator` (`tools:search`) |
| Knowledge search with owner scopes and sharing | `VectorSearchService`, `RagScope` |
| Knowledge search for MCP clients | MCP `rag_search` (`McpServerFactory::ragSearchHandler`; the caller's own documents only). RS3's `knowledge_search` is its gateway-owned twin and shares one search helper with `MessagesContextInjector` — no third retrieval path |
| Rerank with latency budget and fallback | `RerankStage`, `RerankRegistry`, `tools:rerank_listwise` |
| RRF fusion of keyword and meaning tiers | `SmartSearchService` |
| Text extraction for PDF and office files | `FileProcessor`, extraction chain `PLUGS.EXTRACTION.CHAIN.document` |
| Model slots with fallbacks, retirements, health | `ModelConfigService`, `ModelCatalog`, `ModelRetirementSeeder` |
| Reasoning level per call | `ReasoningLevelCatalog::apply()` |
| Structured JSON output with healing retry | `backend/src/AI/StructuredOutput/` |
| Durable, replayable streamed run | `ChatRun`, `ChatRunBuffer` |
| Budget and usage ledger | `RateLimitService`, `BUSELOG` |
| Ask-the-user card and pause / resume | `AskUserRunner`, ask-the-user card ([#2385](https://github.com/metadist/synaplan/pull/2385)) |
| Task cards with step input / output, knowledge sources panel | `TaskCard.vue`, `KnowledgeSources.vue` (web) |
| Desktop: agent loop, confined file tools, skills for docx / xlsx / pptx / eml / html | `agent.rs`, `agent_tools.rs`, `skills/bundled/` |
| Desktop: knowledge folder upload and status, Out board, side panel, activity feed | `files.rs`, `FilesView.vue`, `InOutBoard.vue`, `ProjectPanel.vue`, `ChatActivity.vue` |
| Desktop: use-case runner on the production core | `examples/usecases/` |

**Related tracks**

| Track | Relation |
| ----- | -------- |
| Row 8 — prompting flow (`20261014-prompting-flow/`, not written) | Shares the docked result pane, per-folder instructions and the verify idea; RW1 reuses its pane if row 8 ships first, otherwise builds it so row 8 can adopt it (U12) |
| Row 9 — cloud file sharing | Research over Nextcloud / OpenCloud folders is §9 #7, after its contract v1 |
| Row 11 — decision routing | Independent; research does not change `SORT` routing or the sorter prompt |
| Row 18 — out-of-process plugins | Install-wide MCP tools can become research sources later; not in this plan |
| Row 19 — code cleanup V4 | Touches the message path, not the gateway or research files; no ordering constraint |
| Use-case research Q8 | The world rule (§0 #6) is sovereignty as a job-wide setting for research runs |

---

## 5. Step table

| Step | Commit title | Class | Depends |
| ---- | ------------ | ----- | ------- |
| RQ0 | `docs(planning): research quality — agentic research with a model team` | no-app-impact | — |
| **Phase A — measure** | | | |
| RQ1 | `test(usecases): re-baseline the desktop use cases against main` (in `synaplan-desktop`) | desktop | — |
| RQ2 | `test(research): research eval corpus, judges, baseline and test doubles` | no-app-impact | RQ0, §0 #27 |
| **Phase B — quick wins on today's paths** | | | |
| RS1 | `fix(messages): the web search wrap-up answers fully and keeps every source` | backend-only | RQ1 |
| RS2 | `feat(messages): read web pages on every model, not only on Anthropic` | backend-only | RQ1 |
| RS3 | `feat(messages): search the pinned knowledge folder as a tool` | backend-only | RQ1 |
| RS4 | `feat(search): read PDFs and office files that web search finds` | backend-only | RQ2, §0 #12 |
| RD1 | `feat(chat): file tools in every chat, a stronger agent prompt and full-length answers` (in `synaplan-desktop`) | desktop | RQ1, §0 #26 |
| **Gate — spike and go / no-go** | | | |
| RQ2b | `test(research): research spike with both lead modes against today's paths` | no-app-impact | RQ2, RS1–RS3, §0 #40 |
| **Phase C — the research engine** (only after the go of §0 #40) | | | |
| RS5 | `feat(models): model slots for reading sources and fact-checking` | backend-only | §0 #4 |
| RS6 | `feat(research): research runs that plan, read and write a cited report` | backend-only | RS5, RQ2b go, §0 #1–#3, #7–#9, #19, #27–#28, #30–#32, #35–#39 |
| RS7 | `feat(research): search again until every point of the plan is covered` | backend-only | RS6, §0 #10 |
| RS8 | `feat(research): fact-check every cited statement with a second model` | backend-only | RS6, §0 #11 |
| RS9 | `feat(rag): keyword plus meaning search for research and knowledge_search` | backend-only | RS6, §0 #14 |
| RS10 | `feat(research): follow-ups and changes reuse the sources` | backend-only | RS8 |
| RQ3 | `test(research): model team bake-off and seeded presets` | backend-only | RS7, RS8 |
| **Phase D — Desktop research surface** (in `synaplan-desktop`) | | | |
| RD2 | `refactor(chat): split ChatView into thread, composer and turn runners` | desktop | — |
| RD3 | `feat(research): research mode in chat with live progress` | desktop | RS6, RD2 |
| RD4 | `feat(research): plan review and questions before a thorough research` | desktop | RS7, RD3 |
| RD5 | `feat(research): the report with sources you can check` | desktop | RS8, RD3 |
| RD6 | `feat(research): turn a report into a document, slides, a table or an email` | desktop | RD5 |
| RD7 | `feat(files): add a whole folder to the knowledge folder in one step` | desktop | — (RS9 for J-RQ-3 quality) |
| RD8 | `feat(models): the research team in this project's models` | desktop | RS5, RQ3 |
| RD9 | `feat(research): keep researching with the window closed and say when it is done` | desktop | RD3 |
| **Phase E — web chat and Saved Tasks** | | | |
| RW1 | `feat(chat): research mode in the web chat` | ota-candidate | RS8, RS10 |
| RW2 | `feat(admin): research team and budgets under AI infrastructure` | ota-candidate | RS5, RQ3 |
| RW3 | `feat(tasks): research as a waiting Saved Task step` | backend-only | RS7, RS10 |
| **User docs** (in `synaplan-docs`, §0 #34) | | | |
| RX1 | `docs: full web answers, page reading on every model, file tools without a skill` | docs | RS1–RS3, RD1 |
| RX2 | `docs: research in Synaplan Desktop, the research team, research without the internet` | docs | RD5, RQ3 |
| RX3 | `docs: research in the web chat and its administration` | docs | RW1, RW2 |

Phase B is useful on its own: normal chat answers fully, reads pages on every
model and can search a knowledge folder more than once. It also produces the
strongest cheap competitor of the engine (arm B1+), which is why the gate
RQ2b sits between Phase B and Phase C: the spike's prompts and stage classes
may be reused by RS6, but none of its code is a product path. Phase D starts when
RS6 is merged behind the flag; RD2 and RD7 can start at once. The
installation profiles each step must hold on are in
[`09_installations.md`](./09_installations.md) §2 and §4.

**Desktop plan of record.** When RD1 or RD2 starts, vendor this folder into
`synaplan-desktop/_extras/planning/20261017-research-quality/`; the source of
truth stays here, as for the 2026-08-29 plan. One step ID per PR; never one PR
across both repositories.

---

## 6. Gates

Synaplan, per commit:

```bash
make ci-local            # lint, phpstan (src + tests), deptrac, PHPUnit, Vitest, vue-tsc
```

Synaplan, per PR, in addition:

```bash
make test-e2e                                              # RW1, RW2, and any step whose OpenAPI the UI consumes
make -C frontend test-e2e-layout                           # RW1, RW2
make -C frontend generate-schemas && docker compose exec -T frontend npm run check:types   # after OpenAPI changes (RS6+)
docker compose exec -T backend php bin/console app:research:eval --corpus=smoke            # RS4, RS6–RS10, RQ3 (06 §6); RS1–RS3 are gated by the desktop use cases
node scripts/mobile-impact.mjs --base main --head HEAD     # class as in §5
```

Synaplan Desktop, per PR:

```bash
make ci-local                                              # lint, vue-tsc, Vitest (i18n parity), no-shell guard, cargo fmt/clippy/test, builds
cd src-tauri && cargo run -p synaplan-core --example usecases -- --quick   # plus the step's UC-11…16 case
```

- Flags off ⇒ `RoutingCharacterizationTest` and every gateway test unchanged;
  `/v1/research*` and `/api/v1/research*` answer 404.
- New classes respect deptrac (`App\Service\Research\Run\*` is Application
  layer) and the size ratchet (no file over 800 lines, no method over 100, no
  constructor over 10 parameters). The engine is split by phase (`03` §9).
- Prompt snapshot tests and schema tests green; locale parity green on both
  apps; no ledger additions.
- The research contract fixtures in Synaplan and their vendored copy in
  Desktop match byte for byte (checksum test).
- PHPUnit and Playwright use only the test doubles (§0 #27); a test that needs
  a provider key or the live web is not a gate test.
- Each step's exit names the installation profiles of `09` §4 it was checked
  on; RS6 and RQ3 include the minimal profile walk; RQ3 includes the local and
  EU presets.

### Track exit

- J-RQ-1…J-RQ-7 and J-RQ-9 walked on Windows and one other OS and entered in
  STATUS; J-RQ-8 and J-RQ-10 walked in the web app.
- RQ3 report meets `06` §5 for Standard and Thorough; `RESEARCH.ENABLED`
  seeded on for new installs with a migration note for existing ones.
- Desktop use cases UC-02…UC-05 and UC-11…UC-16 green on the quick set.
- The profile table of `09` §4 has a dated entry for every row, including the
  first three-node deploy.
- RX1–RX3 merged in `synaplan-docs`; the documentation matrix of `09` §6 has
  no open row.
- Plan archived with a dated note when the last step ships.

---

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| A citation points to a source that does not say it | Passage check (deterministic, §0 #39), second-provider checker, unsupported statements removed or listed under Not confirmed, citation precision is a gate metric (`06`) |
| Prompt injection from a web page or file | Readers quarantine text and have no tools; delimiters; the lead never sees raw text; the run is read-only (§0 #3, #13) |
| Cost runs away on Thorough | Estimate before start, cap per depth, budget check per phase, one active Thorough run per person, Stop always works |
| Runs feel slow | Fast tier for sorting and reading, parallel page fetches (existing first-hop overlap), concurrent reader calls (§0 #37), live progress, the window can close, Standard is the default |
| Tool-calling bugs across providers (desktop CHANGES C2, C4, C10) | Tool mode only for models on `TOOL_MODE_MODELS` after the conformance suite; round mode as the fallback (§0 #2); healing retry; a role that keeps failing is reported, not looped |
| The lead in tool mode over-searches or never stops (spawning work for trivial questions) | Every tool enforces the budget and answers "budget used"; `MAX_MODEL_CALLS`; effort-scaling rules in the lead prompt (`04` §4.5a); the wall clock ends the loop and the writer writes with what exists |
| The engine is built and does not beat Phase B | Go / no-go after the spike (§0 #40); B1+ and B4 arms in every comparison |
| Verbatim quotes are dropped although the finding is right | Passage-id citations (§0 #39); dropped-finding share is a metric |
| Sovereignty leak through an "auto" role | World rule (§0 #6), explicit models from Desktop, fail closed, models listed on every run |
| Condensing drops the decisive detail | Quotes with locations, `reread` action with a sharper question, writer can request a source again |
| Redis flush loses a running run | Honest failure with the sources already streamed kept by the client; "Start again" seeds them (`03` §6) |
| A long run blocks the install's only worker process | Units of ≤ 120 s on `async_ai_high` (§0 #8); `MAX_ACTIVE_RUNS`; a `queued` event and honest copy when a unit waits; queue wait measured in RQ3; dedicated transport is an ask-first row |
| A run stays "running" after a worker dies | Checkpoint per unit; atomic unit claims with leases and fenced writes (§0 #36); `app:research:reap` in the tick lane (§0 #28) |
| Two workers run the same unit and pay twice | The claim is taken before any paid call; a concurrent-delivery test asserts one set of provider calls |
| The research queue starves extraction and indexing on the single consumer | `DelayStamp` between units empties `async_ai_high` for a moment; `MAX_ACTIVE_RUNS`; a dev-stack check that an upload is indexed during a Thorough run (RS6 exit) |
| A web report is lost because the tab was closed | `deliver` writes the message and file into the run's `chat_id` server-side (§0 #36) |
| CI depends on the live web or provider keys | Test doubles (§0 #27); the SSRF guard untouched |
| A local or EU project leaks through a side channel | Five-leg world rule (§0 #29): search provider disclosed or refused, cloud extraction steps off, condenser on the reader model, index model named, rerank skipped |
| A local model's context is too small | Budgets from `meta.context_window` (§0 #31); fewer sources per round; "read the first part of {host}" in Details |
| The gateway quick wins change answers for Claude Code and Synamail | Documented in `docs/ANTHROPIC_COMPATIBLE_API.md` and `claude-code.md` (RX1); `WEB_FETCH_MAX_PER_TURN`; UC timings before / after in STATUS |
| Adding slots breaks seeding or health checks | Test defaults first; the three `CAPABILITY_TAGS` iterators run with unbound slots; production keys only in RQ3 |
| A minimal install shows a dead research feature | Composer lines with reasons; the chip exists only when the module is on; P-MIN walk in RS6 and RQ3 |
| Search provider quotas | Per-run search caps, query dedupe, the plug's fallback provider, SearXNG for self-hosters |
| Large components grow further | RD2 splits Desktop `ChatView.vue` first; web steps add components, the size ratchet guards `ChatView.vue` / `ChatInput.vue` |
| Promotional prices change the best preset | Presets are seeded catalog keys, admin-editable; RQ3 is a command that can be re-run; the daily price sync keeps costs honest |
| Evaluation drifts or flatters us | Fixed corpus with planted facts and traps, cross-provider judges, a human-labelled calibration set, numbers only from commands |

---

## 8. Non-goals

- A coding agent, a browser agent that fills forms, logs in or buys.
- Reading behind logins or paywalls, or scraping social networks (the existing
  skip list stays).
- A new pairing scope, `protocol: 2`, network or shell for Desktop skills.
- A local model runtime bundled with Desktop.
- Replacing normal chat or the Web toggle; Ask stays the default.
- A research mode in the widget or the messaging channels (§0 #24).
- Training or fine-tuning models.

---

## 9. Later (in this order)

| # | What | Why it waits |
| - | ---- | ------------ |
| 1 | **Read files on this computer during a run** without uploading them: the run asks, Desktop reads inside its allowlist and returns the text (client-executed tools over the run's input endpoint) | Needs the engine and its input channel (RS7); a privacy choice people should see first in J-RQ-3 |
| 2 | **Point researchers** — one sub-loop per plan point on a mid-tier model (search → read → search again, two or three iterations) that returns condensed findings to the lead, the orchestrator-worker shape of current deep-research systems | Only if RQ3 shows the lead's rounds are the bottleneck; it widens what sees untrusted snippets (queries could carry knowledge-folder text to a search provider), so it needs its own injection and exfiltration tests |
| 3 | **Units fanned out over several worker processes** | Concurrent reader calls inside a unit come first (§0 #37); measure RQ3 latency; `DagExecutor` parallel mode is the precedent |
| 4 | **A local index on Desktop** (search local folders without sending them) | Needs a local embedding runtime — native dependency, ask first |
| 5 | **Research packs**: "Market scan", "Regulation watch", "Supplier comparison", "Literature review" as assistants with research rules | After RQ3 shows which prompts and budgets work |
| 6 | **Share a report** (Share dialog, new kind) | UX contract §4.1 consequence copy per kind |
| 7 | **Research over cloud folders** (Nextcloud, OpenCloud) | Cloud file sharing (row 9) |
| 8 | **Academic and news sources** (OpenAlex, Crossref, arXiv, news APIs) as search adapters | Web search plug port, after the corpus shows the gap |
| 9 | **Figures and tables in PDFs as evidence** (vision on rendered pages) | `PIC2TEXT` cost per page; measure on the tender corpus |
| 10 | **An external deep-research agent as an optional engine** for cloud worlds (for example the Gemini Deep Research agent on the Interactions API) behind the same run contract | B4 shows whether it is worth it; sovereignty (US-hosted, its own search) and its citation format need the world rule and the passage check first |

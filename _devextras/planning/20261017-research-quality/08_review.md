# Plan review — 2026-10-17

**Scope:** the RQ0 plan as committed in
[#2540](https://github.com/metadist/synaplan/pull/2540) at `26b80d9b9`,
reviewed for gaps, forgotten work, regression risks, and how every kind of
installation — a laptop with Ollama only, a company behind a proxy with EU
providers, the three-node cloud — is handled, documented and tested. Every
finding names the code it rests on. The resolutions are applied in this
commit; the "Where" column says in which file.
**Method:** read the plan against `main` at `4675c572f`, the dev compose, the
minimal override, the Helm chart (`synaplan-charts`), the user docs
(`synaplan-docs`) and the Desktop code; checked each step's blast radius
against the tests and frozen files it touches.

Severity: **A** = would ship a stuck run, a wrong result or a broken install;
**B** = a visible regression or a gap a person hits in the first week; **C** =
quality of the plan for an AI-assisted implementation.

---

## 1. Findings

### 1.1 Gaps — things the plan did not cover

| # | Sev | Finding | Evidence | Resolution | Where |
| - | --- | ------- | -------- | ---------- | ----- |
| G1 | **A** | **One research run would block every other background job of the install.** The plan runs a whole research run inside one Messenger message. The dev worker and the Helm worker are one `messenger:consume` process over `async_ai_high async_extract async_index` with `--time-limit=3600 --memory-limit=512M`; a 20-minute handler stalls saved tasks, media jobs and indexing for all users, and a memory-limit restart kills the run. | `docker-compose.yml` lines 665–667; `synaplan-charts/charts/synaplan/values.yaml` `worker.replicaCount: 1`, `worker.transports` | **One message per unit of work** (brief+plan, one reflect round, one section, check); each unit ≤ `RESEARCH.UNIT_SECONDS` (120) and re-dispatches the next; checkpoints fall out of it; `RESEARCH.MAX_ACTIVE_RUNS` per install; a `queued` event when the next unit waits for a worker. A dedicated transport stays the ask-first scaling knob. | `00` §0 #8, `03` §6, `10` §3 |
| G2 | **A** | **No deterministic way to test research in CI.** `SsrfGuard` has no test mode (every private or loopback address is refused), there is no search stub (`frontend/tests/e2e/stub-servers/` has ollama, telegram, whatsapp only), and the one web-search E2E is tagged `@noci`. The plan's PHPUnit replays and the Playwright journey would need the live web. | `SsrfGuard.php`, `multitask.spec.ts` line 118 | Test doubles in the `test` environment: a `test` web-search adapter that answers from `backend/tests/Fixtures/research/search/*.json`, a `FixtureSourceReader` that serves `backend/tests/Fixtures/research/pages/*` for the host `fixture.synaplan.test` without any network call, and `TestProvider` mocks for the `tools:research_*` prompts (schema-aware, like `mockTaskPlan`). No SSRF relaxation. The desktop quick set keeps running against real providers. | `00` §0 #27, `06` §8, `07` RQ2 |
| G3 | **A** | **A run can stay "running" forever.** A worker killed between checkpoints leaves a run in `gathering` with no message queued; the plan had retries and resume but no sweeper. The UX bar forbids a stuck "running". | Precedents `app:chat:reap-stuck`, `app:desktop:reap-jobs` in `ScheduledJobs::LANE_TICK` | `app:research:reap` in `LANE_TICK`: a run whose last checkpoint is older than 3 × `UNIT_SECONDS` with no queued unit → `failed_interrupted` with what was found; waiting states past their limit → `stopped_waiting`. | `00` §0 #28, `03` §6, `07` RS6 |
| G4 | **A** | **The world rule covered models only.** In a local or EU project the run would still: send queries to a US-hosted search provider (Brave, Tavily, Exa, Firecrawl, Perplexity — only SearXNG is self-hosted; `WebSearchCapabilities` has no hosting flag), run the document extraction chain's `pdf_vision` step on the cloud `PIC2TEXT` model, condense pages with `ContextCondenser`'s own model order (SORT → ANALYZE → CHAT), and embed queries with the workspace `VECTORIZE` model. | `WebSearchCapabilities.php` constructor; `PLUGS.EXTRACTION.CHAIN.document = structured_office,office_convert,tika,pdf_vision`; `ContextCondenser::resolveCondenserModel`; `PlugConfigService::extractionChain(string $family, bool $hasCloudStt)` — the flag selects the **audio** chain only (lines 123–146), there is no document equivalent | The world rule names all five legs. Search provider: `selfHosted` flag on the capabilities; outside the world the plan card says "Web search goes through {provider}, hosted in {country}" (disclose) or refuses with `RESEARCH.STRICT_WORLD=1`. Extraction: RS4 adds `runsInCloud()` to the extractor contract and a filtered `chainFor($family, $allowCloud)`, with a provider-spy test that a local-world PDF never reaches `pdf_vision` (corrected after the Copilot round — the first pass wrongly assumed the audio flag covered documents). Condenser: the engine passes the reader model. Embeddings: the operator's index model, named in Details; strict mode skips knowledge search when it is outside the world. | `00` §0 #29, `02` §7.2, `03` §2.4, `07` RS4 |
| G5 | **B** | **Per-role input budgets ignored the model's context window.** Local models in the catalog have 33k–128k contexts (`qwen3.5:35b` 33k); a 60k-token reader input or an 80k-token lead context fails there. | `BMODELS` `meta.context_window`; `Model::getContextWindowTokens()` | Input budget per call = min(role cap, 60 % of the model's context − its output cap); the ledger summary for the lead is compacted to fit; local worlds read fewer sources per round and run readers with `RESEARCH.LOCAL_PARALLEL` (1). | `00` §0 #31, `02` §3, `03` §4 |
| G6 | **B** | **The cost estimate assumed prompt caching the code does not do.** `cache_control` exists only in the gateway's Anthropic passthrough translator, not in `AnthropicProvider` for `AiFacade` calls; the lead's "70 % cached" in `02` §6 would not happen. | `grep cache_control backend/src` → `AnthropicPassthroughTranslator`, `MessagesUsage`, `CachePriceResolver` only | RS6 adds a `cache_system` option to `AnthropicProvider` (one `cache_control` breakpoint after the stable prefix); OpenAI and Gemini cache automatically for long prefixes. `02` §6 now shows both numbers; RQ3 measures the real cache hit rate from usage records. | `00` §0 #32, `02` §6, `07` RS6 |
| G7 | **B** | **No idempotency, no listing, no delete.** A Desktop retry after a network error would start a second run; a reinstalled Desktop could not find its running research; "Delete report" left the server copy until the TTL. | `03` §7.1 as written | `Idempotency-Key` on `POST /v1/research` (24 h), `GET /v1/research?active=1`, `DELETE /v1/research/{id}`. Desktop's "Delete report" also deletes the server state. | `00` §0 #33, `03` §7, `10` §2 |
| G8 | **B** | **Documentation was one file.** The plan named `docs/RESEARCH.md` and `docs/FEATURE_FLAGS.md`. The user docs live in the separate `synaplan-docs` repository (`desktop.md`, `claude-code.md`, `air-gapped.md`, `ai-providers-and-models.md`, `kubernetes.md`, …); the gateway quick wins change what Claude Code users see (`claude-code.md`, `docs/ANTHROPIC_COMPATIBLE_API.md`); Helm operators need the transport note; `docs/CONFIGURATION.md` lists BCONFIG keys. | `ls /wwwroot/synaplan-docs/docs`; `docs/*.md` | A docs row in every step and a docs matrix; one `synaplan-docs` pull request per phase (RX1–RX3). | `00` §0 #34, `07` every step, `09` §6 |
| G9 | **B** | **Untrusted text reaches the lead through titles.** The lead sees source titles and hosts in the ledger summary; a page title can carry an instruction. The plan quarantined bodies only. | `03` §5.1 ledger shape | Titles and snippets are truncated (120 / 300 characters), control characters stripped, and shown inside the same data wrapper; the lead's prompt says titles are data. | `03` §8, `04` §3 |
| G10 | **B** | **Quote and number checks were underspecified.** Which text is the quote verified against (raw or condensed)? How are `12,40` and `12.40` compared? Where does a reread get the text from if it was "discarded"? | `03` §2.4, §2.7 | Quotes are verified against the raw extracted text; numbers are normalised for decimal and thousands separators and units before comparison; extracted source text lives in the run's Redis state for the run's TTL and is deleted with it (never stored as the person's file). | `03` §2.4, §2.7, §8 |
| G11 | **B** | **Politeness and caching of fetches were missing.** Forty parallel runs can hit one host repeatedly; the same page read by two runs is fetched twice. | `UrlContentService` has no cache | At most one in-flight fetch per host per run, `Retry-After` honoured, a per-URL text cache (`RESEARCH.FETCH_CACHE_MINUTES`, 60) keyed by URL only — never by question. | `03` §2.4, `10` §4 |
| G12 | **C** | **No contracts for the implementer.** Only the decision JSON and the API were spelled out; six schemas, the event payloads, the BCONFIG keys, the PHP class signatures, the Rust module API and the Vue component split were missing — the first thing an AI assistant asks for. | `03`, `07` | [`10_contracts.md`](./10_contracts.md). | `10` |
| G13 | **C** | **Step briefs were uneven.** RS4–RS10, RQ3, RD2–RD9 and RW1–RW3 had goals and exits but no files to read first, no tests to write first, no brief to paste. | `07` | Every step now has Reads, Touches, Tests first, Brief, Docs, Exit. | `07` |

### 1.2 Forgotten — things other plans or the code already demand

| # | Sev | Finding | Resolution | Where |
| - | --- | ------- | ---------- | ----- |
| F1 | **A** | **Mobile policy:** the capability probe for the web must not go through `frontend/src/stores/config.ts` / `*RuntimeConfig*` (store-required paths in `.github/mobile-impact-policy.json`); the OP plan locked this as a rule and this plan did not. | Rule and decision: capabilities come from `GET /api/v1/research/capabilities`; those files are not touched. | `00` rules, §0 #30 |
| F2 | **A** | **Module convention needs one compose line.** A `FeatureModule` lists its decisive env in `.env.minimal` **and** `docker-compose.minimal.yml` (the override that blanks every optional service — it has no services of its own). That is a Docker file edit and therefore an ask-first row the plan did not have. | §0 #19 names the exact two lines (`RESEARCH_ENABLED: ""`, `FEATURE_MODULES_GATE_RESEARCH: "true"`) as the one compose change the convention requires. | `00` §0 #19 |
| F3 | **B** | **`DefaultModelConfigSeeder::getRecommendedDefaults()` throws** when a key does not resolve, and `TEST_DEFAULTS` must carry every slot the code reads. Adding `READ` / `VERIFY` to `CAPABILITY_TAGS` without test defaults breaks the test environment; `ModelHealthRecorder` and `ModelSetDefaultCommand` iterate `CAPABILITY_TAGS` too. | RS5 adds the test defaults (`-1`) and only registers the tags; production keys are seeded in RQ3 after the bake-off; RS5's brief lists the three iterating classes. | `07` RS5 |
| F4 | **B** | **Account erasure.** V4 S0 erases every user-owned table; research state is Redis, which no table scan sees. | `UserDeletionService` deletes `research:*` keys of the user; a unit test asserts it (the S0 "nothing left behind" test does not cover Redis). | `03` §8, `07` RS6 |
| F5 | **B** | **Assistant tool flags.** A run started from a chat pinned to an assistant must honour the assistant's `tools.internet` and knowledge scopes (`AgentDefinitionValidator`). | Sources default from the assistant; web off when its `tools.internet` is off. | `03` §7.2 |
| F6 | **B** | **Audit log.** Admin changes to research settings (RW2) go through `AuditLogWriter` like the gateway settings ([#2249](https://github.com/metadist/synaplan/pull/2249)). | RW2 brief. | `07` RW2 |
| F7 | **B** | **Module status and health.** The plan had no row on the system status page and no structured log channel; stuck or failed runs would be invisible to an operator. | `ResearchModule::status()` reports active, queued and failed-in-24h counts; log channel `research` with ids and timings only. | `03` §8, `07` RS6 |
| F8 | **B** | **Desktop mock server.** `npm run mock-server` is the offline dev loop for Desktop; without `/v1/research` on it, RD3–RD9 cannot be developed offline. | RD3 adds the research routes to the mock server (fixture-driven events). | `07` RD3 |
| F9 | **B** | **Full API keys reach `/v1/research`.** Claude Code and other full keys hit `/v1/*` too, so research runs start from day one for them, not only from Desktop. | Rate-limit action `RESEARCH` applies to every key; documented in `claude-code.md` with the Desktop surface. | `00` §0 #25, `09` §6 |
| F10 | **C** | **Event schema versioning.** The job contract carries `protocol: 1`; research events had no version field for a tolerant client. | `run` event carries `research_protocol: 1`; Desktop parses unknown events as `Unknown` and ignores them. | `10` §2 |

### 1.3 Regression risks — and the test that guards each

| # | Sev | Step | Risk | Guard |
| - | --- | ---- | ---- | ----- |
| R1 | B | RS1 | A length-neutral wrap-up changes answers for every `/v1/messages` client (Claude Code, Synamail), not only Desktop; `OpenAiGatewayToolLoop` has its own loop | Gateway unit tests on the new text; UC-03 and UC-04 before and after; the OpenAI loop is left as it is in RS1 and listed in RS2's scope decision |
| R2 | B | RS2 | More tool rounds per turn on non-Anthropic routes (fetch after fetch) → latency and cost | `MESSAGES_GATEWAY.WEB_FETCH_MAX_PER_TURN` (3); the 16-tools and 240 s loop caps stay; UC-04 timing recorded |
| R3 | B | RS3 | A model that now has `knowledge_search` may call it instead of answering from the injected passages → one extra round on simple questions | Injection stays for the first turn; tool description says "when the passages above are not enough"; UC-05 time-to-first-token before / after in STATUS |
| R4 | B | RS2/RS3 | Desktop's agent loop must never receive an unpaired server-tool call (desktop CHANGES C2, C10) | Mixed-tools fixture test on both sides (`03` §12) |
| R5 | B | RS5 | `CAPABILITY_TAGS` is iterated by `ModelConfigService`, `ModelHealthRecorder`, `ModelSetDefaultCommand`, `DefaultModelConfigSeeder`; `/v1/models/catalog` has a fixed group list | Test defaults first (F3); the catalog response is unchanged; Desktop's `CatalogResponse` is a `BTreeMap` without `deny_unknown_fields` (checked), so later additive groups are safe |
| R6 | B | RS6 | New `tools:research_*` prompts are seeded rows; a snapshot that lists prompt topics would drift | Flags off ⇒ `RoutingCharacterizationTest` unchanged is a gate; if a snapshot lists topics, the re-record diff may contain only the new `tools:` rows |
| R7 | B | RS9 | A Qdrant payload index on an existing `user_documents` collection; the RRF extraction from `SmartSearchService` could change palette ranking | Idempotent `create_payload_index` on first use; `QdrantClientMock` gains the call; a ranking snapshot of the palette is recorded **before** the extraction and must not change |
| R8 | B | RD1 | A message naming an existing file now runs the non-streaming agent loop → feels slower for a plain question about that file | Signals limited to a named file that exists in a readable folder or is a clear output, and project-folder references; attachments and drops are **not** signals (they upload to the knowledge folder — `knowledge.add`); Vitest routing table; UC-01 unchanged |
| R9 | B | G1 | Chunked units interleave with media and saved-task messages on `async_ai_high` → a Thorough run takes longer on a busy install | `queued` event and "waiting for a free worker" copy after 20 s; `RESEARCH.MAX_ACTIVE_RUNS`; queue wait measured in RQ3 |
| R10 | B | RS6 | Redis memory: 1–3 MB per run × active runs × 48 h on an install whose Redis has no `maxmemory` | TTL from the last change; source texts dropped at `complete` after the check (quotes stay); the status row shows the key count; documented sizing in `docs/HEALTH_MONITORING.md` |
| R11 | C | RW1 | Mobile WebView: SSE closes when the app goes to the background | The run continues server-side; the app reattaches with `Last-Event-ID` on foreground — the same path as chat replay |
| R12 | C | RS6 | Caddy / FrankenPHP idle timeout on a long SSE | 15 s `ping`, as chat SSE does today |

### 1.4 Installations — what breaks where (summary; the matrix is [`09_installations.md`](./09_installations.md))

| Profile | What the plan assumed | What is true | Resolution |
| ------- | --------------------- | ------------ | ---------- |
| Minimal override (`docker-compose.minimal.yml`) | "Some installs have no web search" | **Every** optional service is blank: no Tika, no Docling, no SearXNG, no Ollama, no compute | Research is "files only" with native text extraction, or absent when no embeddings exist; every absence is one sentence on the composer; the module's decisive env is in the override (F2) |
| Local / air-gapped | Local models for every role | Also no web, cloud extraction steps must not run, 33k contexts, one GPU serialises readers | G4, G5; `RESEARCH.LOCAL_PARALLEL`; `air-gapped.md` chapter |
| EU | EU models | Search providers are US-hosted except SearXNG | G4 disclosure / strict mode |
| Helm | A worker exists | `worker.transports` can exclude `async_ai_high`; `replicaCount: 1`; RWO PVC co-scheduling | `kubernetes.md` note; module status says "no worker consumes research units" when a unit waits > 5 min |
| Production (three nodes, Galera, shared Redis) | Redis replay works across nodes | True for `ChatRunBuffer` today; no schema change in v1, so no Galera migration risk | Stated in `09`; the first deploy writes a STATUS row (ops in `synaplan-platform`) |

---

## 2. What changed in the plan (this commit)

| File | Change |
| ---- | ------ |
| `00_master_plan.md` | §0 #8 (units), #19 (the two compose lines), #26 (signals without attachments), new #27–#34; rules for config.ts and doubles; step table: RQ2 carries the doubles, new docs steps RX1–RX3; gates per profile; risks R1–R12 |
| `02_model_roles.md` | Context-window budgets; caching with and without; search provider in the world rule |
| `03_research_engine.md` | Units and re-dispatch (§6), reaper, idempotency / list / delete, event version, titles as data, raw-text quote check, number normalisation, source text retention, politeness and fetch cache, assistant flags, world rule legs, status and logging |
| `04_prompting.md` | Routing signals without attachments; condenser model hand-over; titles as data in the lead prompt |
| `05_journeys_and_ui.md` | World disclosure line on the plan card; "waiting for a free worker"; minimal-profile states; delete removes the server copy |
| `06_evaluation.md` | §8 test doubles and the CI journey; profile column in the use-case table |
| `07_sprints.md` | Rewritten: every step with Reads, Touches, Tests first, Brief, Docs, Exit; RX1–RX3 |
| `09_installations.md` | New: profiles, degradations, copy, tests, docs, upgrade and rollback |
| `10_contracts.md` | New: schemas, events, BCONFIG keys, PHP / Rust / Vue contracts, fixtures, i18n keys |
| `STATUS.md` | Review entry; step table rows |

---

## 2a. Copilot review round (2026-10-17, [#2541](https://github.com/metadist/synaplan/pull/2541))

Sixteen comments; every one was checked against the code and applied. Two
were factual corrections to this review's own claims.

| # | Comment (short) | Verified against | Resolution | Where |
| - | --------------- | ---------------- | ---------- | ----- |
| C1 | `extractionChain(…, false)` does not filter cloud steps for documents | `PlugConfigService.php` 123–146: the flag only picks `audio_no_cloud` | RS4 adds `runsInCloud()` and `chainFor($family, $allowCloud)` plus a provider-spy test; §0 #29, G4 and STATUS corrected | `00` #29, `03` §2.4, `07` RS4, `08` G4 |
| C2 | Web report delivery depended on a connected client | `03` §2.8 as written | The start request carries `chat_id` (session route); the `deliver` unit writes the message and file server-side (`ResearchReportDelivery`); Desktop reattaches via `list` | `00` #36, `03` §2.8, §7.2, `10` §2.3, §5 |
| C3 | Question timeout: §2.1 continued, the sweeper stopped | — | One policy: questions → skipped after 10 min and continue; only an unconfirmed plan ends a run (`stopped_waiting`, 24 h); outcome text names the plan | `00` #10, #28, `03` §2.1, §6, §7.5, `07` RS7 |
| C4 | Prompt listed `finish` as an action; schema has it top-level | `10` §1.5 | Prompt rewritten: `finish` is a top-level field, `actions` empty when set; engine ignores actions next to a finish | `04` §4.5, `03` §2.5, `10` §1.5 |
| C5 | Check prompt asked for a bare array; schema wants `{verdicts}` | `10` §1.6 | Prompt asks for the object envelope | `04` §4.7, `10` §1.6 |
| C6 | The test stack runs no Messenger worker; queued units would never run | `messenger.yaml` `when@test`: all transports `in-memory://`, selected commands routed to `sync://` | `RunResearchUnit` routed to `sync` in `test` (the `ExtractMemoriesCommand` precedent); pause-dependent tests pull from the in-memory transport; P-TEST row corrected | `06` §8, `09` §1, `10` §3, `07` RS6 |
| C7 | State lacked URL, verified quotes and sentence→quote mapping | `10` §2.5 as written | `sources[].url` / `file_id`, `evidence[]` with verified quotes, `report.citations[]` per occurrence, `sources_order`; `source` event carries `url` | `10` §2.5, §2.6, `03` §7.3, `07` RD5 |
| C8 | Expected-sequence check does not stop two workers racing | — | Atomic claim (`SET NX PX` + token, lease 2 × unit), fenced checkpoint (Lua CAS), sweeper recovers expired leases; a concurrent-delivery test | `00` #36, `03` §6, `10` §3, §5, `07` RS6 |
| C9 | 404 on every route hid existing runs when the module is off | `09` §5 vs `03` §7 | Flag-off split: new work 404; `GET`, events, `cancel`, `DELETE` work for own runs until TTL; `routeNames()` lists the four gated routes | `00` #35, `03` §7.1, `10` §2.1, `05` §8, `09` §5 |
| C10 | Standard removed only `unsupported` | `03` §2.7 | Every non-`supported` verdict leaves the factual text in Standard; Thorough keeps the supported part of `partial` only; tests for all four verdicts | `03` §2.7, `07` RS8 |
| C11 | Re-dispatching to `async_ai_high` still starves the other receivers | `messenger:consume` priority order | `DelayStamp(UNIT_GAP_MS)` on the next unit so the queue empties between units; `MAX_ACTIVE_RUNS`; a dev-stack fairness check as an RS6 exit item | `00` #8, #36, `03` §6, `10` §3, §4 |
| C12 | "Nothing was charged after Stop" overstated | — | Cancel checked before every paid call; copy: "No new work was started after you pressed Stop; a step already underway may still count"; UC-14 asserts no call *starts* after Stop | `03` §6, §7.5, `06` §7 |
| C13 | Revise prompt kept `contradicted` as a second version | — | `contradicted` = the draft contradicts its own quote → removed; two versions only for conflicts between sources | `04` §4.8, `03` §2.7 |
| C14 | Admin turn-off copy promised running research would finish | `09` §5 | Copy says it stops after its current step and keeps what it found | `05` J-RQ-10 |
| C15 | "Nothing is sent or changed anywhere" contradicted the data flow | the Brave disclosure line above it | "Nothing is emailed, saved or changed anywhere; the models of this project read your question and the sources it finds" (plan card and §7 row) | `05` §5.3, §7 |
| C16 | RW3 ran units inline in the Saved Task worker | `RunSavedTaskCommandHandler` → `SavedTaskRunner::run()` synchronous | The research node is a waiting node (the `AskUserRunner` pattern); `deliver` resumes the task | `03` §13, `07` RW3 |

---

## 2b. Architecture review against current deep-research systems (2026-10-22)

**Scope:** the plan as merged in
[#2541](https://github.com/metadist/synaplan/pull/2541) at `39631f29c`,
checked against the code on `main` and against how current deep-research
systems are built. Code facts re-checked: `GatewayToolLoop::WEB_SEARCH_WRAP_AFTER = 2`,
the two-sentence `WRAP_UP_AFTER_SEARCH`, `WALL_CLOCK_SECONDS = 240`;
`AiFacade::chat()` returns native `tool_calls` (normalised by
`ToolCallParser`; `Service/Document/ChatToolLoop.php` already runs a native
tool loop on it); `AiFacade` has no concurrent chat call (only `embedBatch`).

**External references:**
[Anthropic — How we built our multi-agent research system](https://www.anthropic.com/engineering/multi-agent-research-system)
(orchestrator-worker; the model drives tools; token use explains about 80 % of
the performance variance on BrowseComp; multi-agent runs use about 15× the
tokens of a chat; start evaluating with about 20 queries; a final citation
step; human testers found agents preferring SEO content farms),
[LangChain — Open Deep Research](https://www.langchain.com/blog/open-deep-research)
and [Learning the Bitter Lesson](https://rlancemartin.github.io/2025/07/30/bitter_lesson/)
(a plan-and-execute workflow was replaced by tool-calling researchers because
it was too rigid; sections written in parallel read as disjoint — write once
at the end),
[Gemini Deep Research agent](https://ai.google.dev/gemini-api/docs/deep-research)
(a callable deep-research agent, about 80 searches and 1–3 USD for a typical
task), [Anthropic — Effective context engineering](https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents)
(tool-result clearing, compaction),
[DeepResearch Bench](https://deepresearch-bench.github.io/) (RACE, FACT) and
[BrowseComp-Plus](https://arxiv.org/abs/2508.06600) (fixed corpus for
reproducible comparison).

### Findings

| # | Sev | Finding | Evidence | Resolution | Where |
| - | --- | ------- | -------- | ---------- | ----- |
| X1 | **A** | **The design contradicted its own thesis.** `01` §1 says an IDE agent is better because the model drives the tools and sees every result; §0 #2 then let the engine drive in fixed rounds and parked the model-driven loop as a "spike, never merged". Every current deep-research system lets the model call the tools; LangChain removed exactly this kind of workflow for being rigid. The safety the plan wants (quarantine, budgets, read-only) does not depend on who decides — it depends on what the tools return and who enforces the budget. | `00` §0 #2 and §9 #2 as merged; `AiFacade` already supports native tool calls | Two lead modes with one set of guardrails: **tool mode** for lead models that pass a conformance suite, **round mode** as fallback (local models, weak tool calling). Tools execute in the engine, enforce the budget, return only cards and sanitised result lists. | `00` §0 #2, `03` §2.5, §3, `04` §4.5a, `10` §1.5a |
| X2 | **A** | **No proof the engine beats what Phase B already gives.** After RS1–RS3 the gateway is a model-driven loop with `web_search`, `web_fetch` and `knowledge_search` — the strongest cheap competitor of the engine — but no arm measured it, and Phase C (≈ 20 PRs across three repositories) would start without that comparison. | `06` §5 arms B0–B3 as merged | Spike RQ2b (console only) with both lead modes against **B1+** (Phase B gateway loop, frontier model, raised rounds) and **B4** (external deep-research agent as reference); a written go / no-go before RS6. | `00` §0 #40, §5, `06` §5, `07` RQ2b |
| X3 | **B** | **Readers would run one after another.** "Parallel readers" sat under Later; `AiFacade` has no concurrent chat call, so a Standard run's 15 reader calls would take about 1.5–2.5 minutes alone against a 3-minute p50 target, and a Thorough run's 40 much more. | `AiFacade.php` (only `embedBatch`); `00` §9 #3 as merged | Concurrent reader calls inside a unit in v1 (`ConcurrentStructuredCaller`, `READ_PARALLEL`); fan-out over workers stays Later. Ask-first (provider layer). | `00` §0 #37, `03` §2.4, `10` §4, §5 |
| X4 | **B** | **Verbatim quotes would drop correct findings.** Models routinely copy quotes with small deviations; the raw-text check would drop those findings as invented, and "location" was free text. | `03` §2.4 as merged | Numbered passages: the engine splits the source, the reader cites passage ids and may add a short quote checked inside the passage; dropped-finding share is a metric. | `00` §0 #39, `03` §2.4, §2.7, `04` §4.4, §4.7, `10` §1.4 |
| X5 | **B** | **Readers could not follow a link.** The common case "landing page → PDF / price list / terms" needed a new search instead of one read. | `03` §2.4 as merged | Up to five leads per card, only from the page's own extracted link list, read like any other source. | `00` §0 #38, `03` §2.4, `04` §4.4 |
| X6 | **B** | **Budgets were fixed before measurement and look low.** Standard ≤ 10 searches, Thorough ≤ 30, against about 80 for a typical external deep-research task, while token spend is the largest quality factor. | `03` §4; Anthropic and Gemini references above | Budgets as hypotheses; RQ2b and RQ3 sweep them and seed the knee of the recall-against-cost curve. | `00` §0 #41, `03` §4, `07` RQ3 |
| X7 | **C** | **Thorough wrote section by section.** Independent section calls read as disjoint; current writers have 128k output. | `03` §2.6 as merged | One call when the writer's output cap fits; sequential sections only as fallback. | `00` §0 #42, `03` §2.6, `10` §3 |
| X8 | **C** | **The eval would start late.** 40 items plus two-person κ calibration before the first number; small sets already show the large early effects. | `06` §2, `07` RQ2 as merged | A 20-item starter set with B0 and B4 first; the full set and calibration before RQ3. | `06` §2, `07` RQ2 |
| X9 | **C** | **The lead prompt lacked effort scaling and source-quality heuristics** — the two most cited early failure modes (over-investing in simple questions, preferring content farms). | `04` §4.5 as merged | `tools:research_lead` with "start wide, then narrow", effort per question type, primary over SEO sources, parallel calls. | `04` §4.5a |

### What changed

| File | Change |
| ---- | ------ |
| `00_master_plan.md` | Status note; phases with the gate; "Start here"; §0 #2 rewritten, #11 passages, new #37–#42; prerequisite; target shape; step table with RQ2b; risks; Later #2, #3, #10 |
| `02_model_roles.md` | Lead requirement (tool calling → tool mode); bake-off stage 2b (lead mode); B1+ and B4 in stage 4; reader metrics |
| `03_research_engine.md` | Passages, concurrent readers, leads (§2.4); tool mode and round mode (§2.5); one-call writing (§2.6); passage-based check (§2.7); §3 rewritten with the conformance suite; budgets as hypotheses (§4); ledger example; unit description; code shape |
| `04_prompting.md` | Reader prompt with passages and leads; `tools:research_lead`; checker on passages; tests for tool-mode replays and conformance |
| `06_evaluation.md` | Public yardsticks; 20-item starter set; passage integrity, dropped findings, search efficiency; arms B1+, B4, S-R, S-T; the go / no-go rule; target vs B1+ |
| `07_sprints.md` | RQ2 starter set and arms; new RQ2b; RS6 passages, leads, concurrency, one-call writing; RS7 both modes and conformance; RQ3 `TOOL_MODE_MODELS`, budget curve |
| `10_contracts.md` | Evidence schema with passages and leads; lead tools and `ResearchFinishSchema`; state evidence shape; units; BCONFIG keys; PHP contracts; commands |
| `STATUS.md` | RQ2b row; review entry |

---

## 3. Open for the owner (new or changed ask-first rows)

| Row | Question |
| --- | -------- |
| §0 #8 | Units of ≤ 120 s on `async_ai_high` (no infra change) as the default; a dedicated transport only if RQ3 measures a queue wait people notice |
| §0 #19 | The two lines in `docker-compose.minimal.yml` the module convention requires |
| §0 #29 | Disclose (default) or refuse when the search provider or the index model is outside the project's world |
| §0 #32 | A `cache_control` option in `AnthropicProvider` (small provider change) — or accept the higher lead cost |
| §0 #35 | Module off: new work 404, the person's existing runs stay readable and deletable until their TTL |
| §0 #36 | Atomic unit claims, server-side delivery of web reports into `chat_id`, cancel before every paid call |
| RX1–RX3 | Three pull requests in `synaplan-docs`, one per phase |
| §0 #2 | Tool mode as the default for qualified lead models, round mode as fallback (2026-10-22) |
| §0 #37 | Concurrent reader calls through HTTP multiplexing in the provider layer (small provider change) |
| §0 #40 | The go / no-go rule after the spike, and the eval budget for arm B4 (an external deep-research agent, about 1–3 USD per item) |

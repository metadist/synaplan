# Research quality — status

Every result here names the command, the versions and the stack it came from.
Quality, cost and latency numbers come only from `app:research:eval` or the
desktop use-case runner (`06_evaluation.md` §6). A walk is a dated entry, not a
chat message.

| Step | State | Note |
| ---- | ----- | ---- |
| RQ0 | done | Plan, baseline research, model roles, engine, prompts, journeys, evaluation, step briefs; registered as roadmap row 21 |
| RQ1 | planned | Desktop use cases re-run against `main`; CHANGES Done list (synaplan-desktop) |
| RQ2 | planned | Eval corpus, judges, calibration, B0 baseline |
| RS1 | planned | Gateway wrap-up answers fully; round limit from config |
| RS2 | planned | `web_fetch` run by Synaplan on non-Anthropic routes |
| RS3 | planned | `knowledge_search` and `read_document` in the gateway |
| RS4 | planned | PDFs and office files from web search — §0 #12 |
| RD1 | planned | File tools without a skill, Desktop agent prompt, language-neutral checks, output budget, B1; J-RQ-1 (synaplan-desktop) |
| RQ2b | planned | Spike with both lead modes vs B1+ and B4, budget sweep, go / no-go — §0 #40 |
| RS5 | planned | `READ` and `VERIFY` slots, role resolver, `meta.family` — §0 #4 |
| RS6 | planned | Research runs v1 behind `RESEARCH.ENABLED` — §0 #1–#3, #7–#9, #19 |
| RS7 | planned | Tool mode and round mode, conformance suite, questions, plan review, resume — §0 #2, #10 |
| RS8 | planned | Fact-check — §0 #11 |
| RS9 | planned | Keyword + meaning search, document cards — §0 #14 |
| RS10 | planned | Follow-ups and revisions |
| RQ3 | planned | Bake-off, seeded presets, flag decision |
| RD2 | planned | `ChatView.vue` split (synaplan-desktop) |
| RD3 | planned | Research mode, progress, result card; J-RQ-2, J-RQ-6 |
| RD4 | planned | Questions and plan review; J-RQ-4 |
| RD5 | planned | Report, sources, versions, follow-ups; J-RQ-2, J-RQ-9 |
| RD6 | planned | Turn into Word, slides, table, email; J-RQ-5 |
| RD7 | planned | Add a folder; J-RQ-3 |
| RD8 | planned | Research team in Models; J-RQ-7 |
| RD9 | planned | Window closed, notification; J-RQ-4 |
| RW1 | planned | Research in the web chat; J-RQ-8 |
| RW2 | planned | Admin research settings; J-RQ-10 |
| RW3 | planned | Research as a Saved Task step |
| RX1 | planned | User docs: quick wins (synaplan-docs) |
| RX2 | planned | User docs: Desktop research, team, air-gapped (synaplan-docs) |
| RX3 | planned | User docs: web research, administration (synaplan-docs) |

---

## 2026-10-22 — architecture review (RQ0, third pass)

Full review in [`08_review.md`](./08_review.md) §2b: the plan checked against
current deep-research systems (Anthropic's research system, LangChain Open
Deep Research, the Gemini Deep Research agent) and re-checked against `main`.
Not committed yet; local working-tree changes for the owner's review.

| Finding | Evidence | Change |
| ------- | -------- | ------ |
| The engine-driven loop contradicted the plan's own thesis (the model should drive the tools) | `01` §1 vs `00` §0 #2; `AiFacade::chat()` already returns native `tool_calls`, `ChatToolLoop` uses them | Two lead modes: tool mode for qualified leads, round mode as fallback; guardrails stay in the engine (§0 #2) |
| No proof the engine beats Phase B's gateway loop | no B1+ arm in `06` §5 | Spike RQ2b with arms B1+ and B4 and a go / no-go before RS6 (§0 #40) |
| Readers would run sequentially | `AiFacade` has no concurrent chat call | Concurrent reader calls in v1 (§0 #37) |
| Verbatim quotes drop correct findings | `03` §2.4 | Numbered passages and passage-id citations (§0 #39) |
| Readers could not follow links; budgets fixed before measurement; parallel-section writing | `03` §2.4, §4, §2.6 | Leads (§0 #38), budget curve (§0 #41), one-call writing (§0 #42) |

---

## 2026-10-17 — plan review (RQ0, second pass)

Full review in [`08_review.md`](./08_review.md): 13 gaps, 10 forgotten
items, 12 regression risks with their guards, 5 installation findings. Four
findings would have shipped a broken run or install and changed the design:

| Finding | Evidence | Change |
| ------- | -------- | ------ |
| One research run would have blocked the install's only worker process for up to 20 minutes | `docker-compose.yml` 665–667: one `messenger:consume` over three transports, `--memory-limit=512M`; Helm `worker.replicaCount: 1` | Runs execute as ≤ 120 s Messenger units that re-dispatch (§0 #8) |
| Research could not be tested in CI | `SsrfGuard` has no test mode; no search or page stub; the only web-search E2E is `@noci` | Test doubles in the `test` environment (§0 #27); the SSRF guard untouched |
| The world rule left four side channels open | US-hosted search providers (only SearXNG self-hosted), `pdf_vision` in the document chain, the condenser's own model order, the workspace index model | Five-leg world rule with disclosure or strict refusal (§0 #29) |
| Decision #26 used attachments as a routing signal | `ChatView.vue`: attach and drop both call `knowledge.add` (upload to the knowledge folder) | Signals are named files and folder references only |

Also added: a sweeper (§0 #28), context-window budgets (#31), prompt caching
as a built feature with both cost numbers (#32), idempotency / list / delete
(#33), documentation as part of every step with three `synaplan-docs` pull
requests (#34), the mobile-policy rule for `config.ts` (#30), the two
`docker-compose.minimal.yml` lines the module convention requires (#19), and
three new files: [`08_review.md`](./08_review.md),
[`09_installations.md`](./09_installations.md) (eight profiles, degradation
copy, tests per profile, upgrade / rollback, docs matrix) and
[`10_contracts.md`](./10_contracts.md) (schemas, endpoints, events, units,
keys, PHP / Rust / Vue contracts, fixtures). Every step brief in
[`07_sprints.md`](./07_sprints.md) now has Reads, Touches, Tests first, Brief,
Docs and Exit.

Checked on this pass and found safe: Desktop's catalog parser is a `BTreeMap`
without `deny_unknown_fields` (additive server groups cannot break old
clients); `tauri-plugin-notification` is already shipped. **Corrected in the
Copilot round:** `PlugConfigService::extractionChain($family, $hasCloudStt)`
filters cloud steps for the *audio* chain only — the document chain has no
such switch, so RS4 adds the marker and the filter (`08` §2a C1).

---

## 2026-10-17 — Copilot review round on #2541

Sixteen review comments, each verified against the code and applied
(`08_review.md` §2a). Design changes: atomic unit claims with fenced
checkpoints and a `DelayStamp` between units (§0 #36); server-side delivery of
web reports into the run's `chat_id`; one question-timeout policy (skip and
continue; only an unconfirmed plan stops a run); module-off split (new work
404, own runs readable and deletable, §0 #35); `RunResearchUnit` routed to
`sync://` in the test environment; the citation map and source URLs in the
state; every non-supported verdict leaves the factual text; RW3 as a waiting
Saved Task node. Copy fixes: the plan card's read-only promise, the admin
turn-off confirmation, the Stop outcome.

---

## 2026-10-17 — RQ0 baseline research

Stack: local dev stack (`docker compose up -d`); synaplan `main` at
`4675c572f` (research started on the V4 S2 branch at `206cff346`; `git diff
origin/main` shows no change to any file cited, and the size-ratchet entries
cited match `main`); synaplan-desktop `main` at `12e2510`. Facts read from code
and from the live dev database; **no quality measurement yet** — that is RQ1
and RQ2.

| Question | Answer | Evidence |
| -------- | ------ | -------- |
| How many active chat models, and does `BQUALITY` separate them? | 66 active chat models; `BQUALITY` 10 for 37, 9 for 20, lower for 9 — not a job signal | `SELECT … FROM BMODELS WHERE BACTIVE=1 AND BTAG='chat' AND BID>0` |
| Which models run which slot on this stack? | CHAT / TOOLS / ANALYZE `anthropic:claude-sonnet-5`; SORT / PLAN / SUMMARIZE `groq:openai/gpt-oss-120b`; MEM gpt-oss-120b `mem`; VECTORIZE `ollama:bge-m3`; RERANK `cohere:rerank-v3.5` | `BCONFIG` `DEFAULTMODEL`, owner 0 |
| Which EU-hosted models can fill research roles? | TrustedTokens rows carry `meta.jurisdiction = DE` (gpt-oss-120b, GLM-5.3, GLM-5.3-Flash, Qwen3.6-35B); Mistral has no jurisdiction field | `BJSON` of the candidates (`02` §5.1) |
| How many web searches does the gateway allow before it forces an answer, and how long may the answer be? | 2; "one or two sentences" | `GatewayToolLoop::WEB_SEARCH_WRAP_AFTER`, `WRAP_UP_AFTER_SEARCH` |
| Does the gateway read pages for non-Anthropic models? | No — `web_fetch` is passed to Anthropic or dropped | `WebFetchPolicy` |
| How much knowledge does a Desktop turn see? | 5 passages, 8,000 characters including memories, one query per turn | `MessagesContextInjector::RAG_LIMIT`, `MAX_CHARS` |
| Does web chat deep search read PDFs? | No — `pdf`, office and media extensions skipped; 3 pages, 28,000 characters, 25 s | `WebResearchService::SKIP_EXTENSIONS`, `PlugConfigService` defaults |
| Does anything re-plan after seeing results? | No — `TaskPlanner` plans once, the DAG runs once | `TaskPlanner`, `DagExecutor` |
| Desktop output budget and intent checks | Plain chat `max_tokens 1024`; agent loop 40 rounds × 8,192; file intent needs English verbs in the loop, a five-language phrase list in plain chat | `commands/mod.rs`, `agent.rs`, `src/lib/classifyGeneration.ts` |
| When does Desktop chat offer file tools? | Only when the project has at least one enabled skill; otherwise every turn is plain streaming chat without `list_files`, `read_file` or `write_file` | `src/views/ChatView.vue` (`agentMode = enabledSkillCount > 0`) |
| Is there already a knowledge search a model could call? | MCP `rag_search` over `/mcp` — the caller's own documents only, outside the gateway loop | `McpServerFactory::ragSearchHandler` (`VectorSearchService::semanticSearch`) |
| Gateway / messages settings on this stack | `MCP_MAX_ITERATIONS 8`, `MCP_TOOLS_ENABLED 0`, `WEB_SEARCH_MODE auto`, `WEB_FETCH_MODE auto`, `CONTEXT_INJECTION_ENABLED 0`, web search provider `brave`, rerank on (seeded off) | `BCONFIG` groups `MESSAGES_GATEWAY`, `PLUGS` |
| Which frozen files do the quick wins touch? | `GatewayToolLoop.php` (1,432 lines, constructor 14), `MessagesContextInjector::sessionBlock` (109), `UrlContentService.php` (1,258), `WebResearchService::deepen` (122), `ModelConfigService.php` (1,327); `PromptCatalog.php` and `ModelCatalog.php` are exempt as catalog data | `backend/tests/Architecture/sizeRatchetBaseline.json`, `Support/SizeOffenders.php` |
| Does Desktop already have OS notifications? | Yes — `tauri-plugin-notification` with `notification:default` | `src-tauri/Cargo.toml`, `capabilities/default.json` |
| Last desktop use-case run | 2026-09-18, `e79b589`, quick subset: 1 pass, 3 fail (UC-02, UC-03, UC-04); C1, C2(a)(b), C3a, C4 and C5 are in the code since, not re-run | desktop `_extras/planning/20260917-desktop-use-case-test-plan/STATUS.md`, `CHANGES.md` |

Not measured yet: answer quality of any path (RQ1, RQ2), reader faithfulness
per model, cost per run, queue wait on `async_ai_high`.

Registered in the same commit: `ROADMAP.md` row 21 (§1, not started), its §3
line and the §6 decision of 2026-10-17, and the planning `README.md` live-tracks
table. The plan was committed from a separate worktree on
`planning/research-quality`, because another session had the V4 S3 branch
checked out in the shared working tree.

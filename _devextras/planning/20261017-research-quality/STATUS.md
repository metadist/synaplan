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
| RD1 | planned | Desktop agent prompt, language-neutral checks, output budget, B1 (synaplan-desktop) |
| RS5 | planned | `READ` and `VERIFY` slots, role resolver, `meta.family` — §0 #4 |
| RS6 | planned | Research runs v1 behind `RESEARCH.ENABLED` — §0 #1–#3, #7–#9, #19 |
| RS7 | planned | Reflect loop, questions, plan review, resume — §0 #10 |
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
| Desktop output budget and intent checks | Plain chat `max_tokens 1024`; agent loop 40 rounds × 8,192; file intent needs English verbs | `commands/mod.rs`, `agent.rs` |
| Gateway / messages settings on this stack | `MCP_MAX_ITERATIONS 8`, `MCP_TOOLS_ENABLED 0`, `WEB_SEARCH_MODE auto`, `WEB_FETCH_MODE auto`, `CONTEXT_INJECTION_ENABLED 0`, web search provider `brave`, rerank on (seeded off) | `BCONFIG` groups `MESSAGES_GATEWAY`, `PLUGS` |
| Which frozen files do the quick wins touch? | `GatewayToolLoop.php` (1,432 lines, constructor 14), `MessagesContextInjector::sessionBlock` (109), `UrlContentService.php` (1,258), `WebResearchService::deepen` (122), `ModelConfigService.php` (1,327); `PromptCatalog.php` and `ModelCatalog.php` are exempt as catalog data | `backend/tests/Architecture/sizeRatchetBaseline.json`, `Support/SizeOffenders.php` |
| Does Desktop already have OS notifications? | Yes — `tauri-plugin-notification` with `notification:default` | `src-tauri/Cargo.toml`, `capabilities/default.json` |
| Last desktop use-case run | 2026-09-18, `e79b589`, quick subset: 1 pass, 3 fail (UC-02, UC-03, UC-04); C1, C2(a)(b), C3a, C4 and C5 are in the code since, not re-run | desktop `_extras/planning/20260917-desktop-use-case-test-plan/STATUS.md`, `CHANGES.md` |

Not measured yet: answer quality of any path (RQ1, RQ2), reader faithfulness
per model, cost per run, queue wait on `async_ai_high`.

Registered in the same commit: `ROADMAP.md` row 21 (§1, not started), its §3
line and the §6 decision of 2026-10-17, and the planning `README.md` live-tracks
table. The plan was committed from a separate worktree on
`docs/research-quality-plan`, because another session had the V4 S3 branch
checked out in the shared working tree.

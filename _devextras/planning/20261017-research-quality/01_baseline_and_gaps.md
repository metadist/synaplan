# Baseline and gaps — why Cursor's results are better, and what we have

**Status:** Research 2026-10-17 against synaplan `main` at `4675c572f`,
synaplan-desktop `main` at `12e2510`, and the local dev stack (live `BMODELS` /
`BCONFIG`). Every limit below names the file and constant it comes
from. Quality numbers are not estimated here; RQ1 and RQ2 measure them.

---

## 1. The thesis

Cursor and Synaplan call the same frontier models. Cursor's results are better
because of the **harness** around the model, not the model:

- the model **drives** the work — it decides which tool to call next, many
  times, and sees every result;
- its tools are **precise and cheap to call again** (search by meaning, search
  by exact text, read a file range, list a folder, fetch a page);
- it **plans and tracks** (a visible to-do list) and **looks again** when a
  result is thin;
- it **delegates** exploration to sub-agents with their own context and fast
  models, and keeps its own context for reasoning;
- its context is **engineered** — rules files, environment, date, attached
  files, summaries of long history;
- it **verifies** (tests, lints) before it says "done";
- the user **sees the work** and can stop or steer it.

Synaplan already owns most of the parts — search, page reading, condensing,
knowledge search with rerank, a planner, a desktop agent loop, a rich model
catalog — but wires them as **retrieve-then-answer once**. This plan rewires
them into a loop with a division of labour, in which the lead model drives the
tools itself wherever its tool calling is reliable and the engine keeps the
budget, the quarantine and the citation checks (`00` §0 #2, revised
2026-10-22).

---

## 2. Factor by factor

| # | Factor | What produces quality in Cursor | Synaplan web chat today | Synaplan Desktop today | Closed by |
| - | ------ | ------------------------------- | ----------------------- | ---------------------- | --------- |
| 1 | A strong model drives | Frontier model with thinking, long output, many rounds | The pipeline decides (sorter vote → search → answer); the answering model calls no research tools | Project Chat model in an agent loop (40 rounds, 8,192 tokens per round, non-streaming); plain chat 1,024 tokens | RS6–RS7, RD1 |
| 2 | Tools called again and again | Semantic search, exact search, read range, list, web search, fetch | None for research; `ChatToolLoop` exists for document edits only | Only when a skill is enabled: `list_files`, `read_file`, `write_file`, `run_program`; `web_search` capped at 2 rounds then a two-sentence wrap-up; `web_fetch` only on Anthropic; knowledge injected once | RS1–RS3, RS6, RD1 |
| 3 | Plan and track | To-do list the user sees | `TaskPlanner` plans once, never re-plans | No plan | RS6, RD3–RD4 |
| 4 | Look again | Re-searches, re-reads, retries | No reflection anywhere | The loop continues while the model calls tools; nudges are English-only heuristics | RS7 |
| 5 | Context engineering | Rules files, date, environment, attached files, history summaries | Strong honesty rules in `general`; search block + knowledge block; capabilities block | 15-line system prompt: no date, no method, no language rule | RS6 prompts, RD1 |
| 6 | Division of labour | Sub-agents with own context, fast models explore | Slots split by job (SORT / PLAN / MEM cheap, CHAT / ANALYZE strong); `ContextCondenser` condenses with the fast model | One model for everything | RS5–RS6, RD8 |
| 7 | Evidence it can point to | Cites the code it read; shows tool output | Numbered `[n]` search results, knowledge sources panel | "At least three https URLs" heuristics (`REWRITE_SOURCES_INSTRUCTION`) | RS6, RD5 |
| 8 | Verification | Tests, lints, reads errors, fixes | None for facts | None for facts | RS8 |
| 9 | Two kinds of search | Exact + meaning | Pure vector in chat; RRF fusion only in the Ctrl+K palette | Server RAG only; no local index | RS9 |
| 10 | Show the work, steer | Live tool calls, stop, plan review, diffs, checkpoints | Task cards with step input / output, progress events, knowledge sources | Activity feed, Stop | RD3–RD5, RW1 |
| 11 | Measure | Internal evals | Routing characterization, sort eval (52 cases), rerank eval | Use-case runner UC-00…10 on the production core | RQ1–RQ3 |

---

## 3. What Synaplan has today

### 3.1 Web chat research path

1. The classifier votes `BWEBSEARCH` / `BREADPAGES`
   (`backend/src/Service/Message/MessageClassifier.php`, `ReadPagesPolicy`).
2. `SearchQueryGenerator` rewrites the question into **one** query of
   "typically 3–8 words" with the SORT model (`PromptCatalog::searchQueryPrompt()`);
   thread and attachment context are used when the question depends on them
   ([#2491](https://github.com/metadist/synaplan/pull/2491)).
3. `WebSearchGateway` runs it on the active provider (BCONFIG
   `PLUGS.WEB_SEARCH.PROVIDER = brave` on this stack; `BRAVE_SEARCH_COUNT`
   default 10).
4. `WebResearchService::deepen()` reads the top pages: `READ_PAGES_MAX = 3`
   (cap 8), budget 28,000 characters, 25-second deadline, host-diverse;
   **skips** `pdf`, `doc(x)`, `xls(x)`, `ppt(x)` and social hosts
   (`SKIP_EXTENSIONS`, `SKIP_HOSTS`).
5. `ContextCondenser::fit()` condenses each page with the question as the lens,
   extractive first, fast model (SORT → ANALYZE → CHAT).
6. The CHAT model answers once from a numbered `[1]…[n]` block
   (`SearchResultSet::formatForAi()`); the UI shows search chips and
   `reading_pages` / `pages_read` progress (`handlers/progress.ts`).

### 3.2 Multitask planner

`TaskPlanner` (prompt `tools:plan`, model `PLAN` → `SORT`, temperature 0.1,
3,000 → 8,000 tokens) emits a DAG of up to 24 nodes
(`TaskPlanValidator::MAX_NODES`). `DagExecutor` runs it once; failures skip
dependents. Runners include `WebSearchRunner`, `UrlFetchRunner`,
`ChatRunner` (`chat`, `summarize` on ANALYZE, `translate`, `rag_query`),
`FileAnalysisRunner`, `DocumentGenerationRunner`, `AskUserRunner`,
`CodeRunRunner`, `SaveToFolderRunner`. **No step looks at the results and plans
again.**

### 3.3 Knowledge search (RAG)

- Chunking `TextChunker`: 1,500 characters, overlap 150, heading-aware for
  Docling markdown.
- Embeddings `DEFAULTMODEL.VECTORIZE` = `ollama:bge-m3:vectorize` (1,024
  dimensions, 8k context, multilingual).
- Store: Qdrant `user_documents` or MariaDB VECTOR; owner scopes and sharing
  (`RagScope`).
- Chat loader: top 20, min score 0.2; **pure vector**, no keyword leg.
- Rerank: `RerankStage`, BCONFIG `PLUGS.RERANK.*` (seeded off; on in this dev
  stack with `cohere:rerank-v3.5`), candidates × 4, 800 ms budget, listwise LLM
  fallback on ANALYZE.
- Extraction chain for documents: `structured_office, office_convert, tika,
  pdf_vision` (BCONFIG `PLUGS.EXTRACTION.CHAIN.document`).

### 3.4 Messages gateway (Desktop and coding clients)

- `POST /v1/messages`, translators for Anthropic passthrough, OpenAI-shaped
  upstreams and Gemini (`backend/src/AI/Messages/`).
- Client tools are relayed; the gateway runs its own loop for server tools
  (`GatewayToolLoop`, `MESSAGES_GATEWAY.MCP_MAX_ITERATIONS = 8`, 240-second
  wall clock, 16 tools per turn, 12,000 characters per tool result).
- `web_search` is executed by Synaplan (`WebSearchTool`: 5 results by default,
  cap 10, language and freshness options) when `WEB_SEARCH_MODE = auto`.
- After two executed searches the loop forces `WRAP_UP_AFTER_SEARCH`:
  *"Answer the user now in one or two sentences and include the http URL you
  used. Do not search again."*
- `web_fetch` is never executed by Synaplan — injected for Anthropic, dropped
  for every other upstream (`WebFetchPolicy`).
- Knowledge for Desktop: `MessagesContextInjector` embeds first + last user
  text once per turn, `RAG_LIMIT = 5`, min score 0.3, `MAX_CHARS = 8000`
  including memories. No knowledge tool. (The two-hour empty-result cache of
  desktop CHANGES C3a is fixed: `liveDesktopRag` skips the cache.)
- MCP tools of connected servers are injected only with
  `MESSAGES_GATEWAY.MCP_TOOLS_ENABLED` (default off).
- A paired desktop key reaches `/v1/*`, `/mcp`, `/api/v1/files*`,
  `/api/v1/desktop/*` — not `/api/v1/rag*`, `/api/v1/search*`, `/api/v1/usage*`
  (`ApiKeyScope`). Usage comes back only as `x-synaplan-budget-*` headers.

### 3.5 Synaplan Desktop

- Two chat modes (`src-tauri/src/commands/mod.rs`): **plain stream**
  (`send_chat`, `max_tokens 1024`) and **agent loop** (`send_agent_chat` →
  `agent::run_agent_turn`, `MAX_ITERATIONS = 40`, `MAX_TOKENS = 8192`,
  non-streaming, wrap-up at the cap). The UI uses the agent loop only when the
  project has at least one enabled skill (`src/views/ChatView.vue`:
  `agentMode = enabledSkillCount > 0`); without a skill, chat has no file tools
  at all, although `list_files`, `read_file` and `write_file` need no skill.
- Agent tools: `list_files`, `read_file` (UTF-8; office files via a skill's
  `read` command), `write_file` (out-box only), `run_program` (allowlisted
  interpreters, no shell); with Web on, the server tools `web_search` and
  `web_fetch`.
- `build_system_prompt` (`agent_tools.rs`): identity, out-box, folders, enabled
  skills, a five-step "how to work". No date, language rule, research method or
  citation format; `WEB_SEARCH_PROMPT` asks for "at least three https:// links".
- File intent heuristics (`agent.rs`): `user_asked_to_write_a_file` needs one of
  the English verbs `write / create / save` plus an extension;
  `user_asked_for_source_urls` looks for English words. Other languages get no
  nudge. Plain chat has its own five-language phrase list for document
  requests (`generation::classify`, mirrored in `src/lib/classifyGeneration.ts`)
  and saves the reply as a text file named after the request; a request that
  names its file ("… in quellen.md") matches neither list.
- Projects: local chats, notes, a knowledge folder `DESKTOP:{projectId}`
  (upload, process, status), eight model slots (Chat, Dictation, Read aloud,
  Images understand / create, Video, Embed locked to the workspace index model,
  Documents), Assistants pin, skills overlay, In / Out board.
- `ChatView.vue` is 2,050 lines; `tauri.ts` 827; `ProjectPanel.vue` 661.
- Fourteen bundled skills (`skills/bundled/`), among them `docx`, `xlsx`,
  `pptx`, `email-draft`, `web-report`, `slides`, `data-table`, `chart` (the
  README table still lists twelve).

### 3.6 Models

Defaults on this stack (`BCONFIG` group `DEFAULTMODEL`, owner 0):

| Slot | Model | Used for |
| ---- | ----- | -------- |
| CHAT, TOOLS, ANALYZE | `anthropic:claude-sonnet-5:chat` ($2 / $10 per 1M tokens) | Answers; summaries and document analysis (ANALYZE) |
| SORT, PLAN, SUMMARIZE | `groq:openai/gpt-oss-120b:chat` ($0.15 / $0.60) | Routing, planning, query rewrite, condensing (SUMMARIZE is unread at runtime) |
| MEM | `groq:openai/gpt-oss-120b:mem` | Memory extraction |
| VECTORIZE | `ollama:bge-m3:vectorize` | Knowledge and memory embeddings |
| RERANK | `cohere:rerank-v3.5:rerank` (dev stack) | Optional rerank |
| PIC2TEXT | `groq:qwen/qwen3.6-27b:pic2text` | Images |

- 66 active chat models; `BQUALITY` 10 for 37, 9 for 20 — not a job signal.
- `BJSON` carries what roles need: `features` (`reasoning`, `tool_use`,
  `vision`, `web_search`, `code`, `multilingual`), `meta.context_window`,
  `meta.max_output`, `cache_read_price_per_1M`, `reasoning_effort_default`,
  sometimes `meta.jurisdiction` / `meta.host` (Muse: `US`); jurisdiction is
  missing on most rows.
- `OllamaProvider` has no native tool calling; `OpenAICompatibleProvider` does.
- Perplexity Sonar / Sonar Pro are seeded unselectable (`features:
  ["web_search"]`, no tool calling).

### 3.7 Measurement we can build on

| Harness | What it proves | Where |
| ------- | -------------- | ----- |
| Desktop use-case runner | Journeys on the production core: chat, agent write, web, files, dictation, notes, long answers, error copy | `synaplan-desktop/src-tauri/synaplan-core/examples/usecases/` |
| Routing characterization | Classifier and sorter contract | `backend/tests/Characterization/` |
| Sort eval | Live sorter accuracy (52 cases) | `app:sort-eval`, `backend/tests/Eval/sort_eval_corpus.json` |
| Rerank eval | recall@5, MRR, latency off vs on | `app:rag:eval-rerank` |

---

## 4. Measured limits

| Limit | Value | Where | Effect on research | Step |
| ----- | ----- | ----- | ------------------ | ---- |
| Web searches per gateway turn before forced wrap-up | 2 | `GatewayToolLoop::WEB_SEARCH_WRAP_AFTER` | No second angle, no follow-up query | RS1, RS6 |
| Wrap-up answer length | "one or two sentences" | `GatewayToolLoop::WRAP_UP_AFTER_SEARCH` | Long questions get short answers | RS1 |
| Page reading in the gateway | Anthropic only | `WebFetchPolicy` | Other models answer from snippets | RS2 |
| Results per gateway search | 5 (cap 10) | `WebSearchTool::DEFAULT_MAX_RESULTS` | Narrow view per query | RS6 |
| Pages read per web chat search | 3 (cap 8), 28,000 chars, 25 s | `PlugConfigService`, `WebResearchService` | Thin evidence | RS6 |
| PDFs and office files on the web | skipped | `WebResearchService::SKIP_EXTENSIONS` | Reports, laws, papers, price lists unread | RS4 |
| Desktop knowledge per turn | 5 passages, 8,000 chars incl. memories, one query | `MessagesContextInjector` | Multi-document questions fail | RS3, RS9 |
| Keyword search in chat RAG | none | `ChatKnowledgeContextLoader` | Names, numbers, article codes missed | RS9 |
| Planner | once, ≤ 24 nodes, no reflection | `TaskPlanner`, `DagExecutor` | No gap filling | RS7 |
| Desktop plain chat output | 1,024 tokens | `send_chat` | Truncated long answers (now shown as cut off) | RD1 |
| Desktop file tools | only when ≥ 1 skill is enabled | `ChatView.vue` `agentMode` | Without a skill, the assistant can neither read nor write a file | RD1 |
| Desktop file intent | English verbs (agent loop); a five-language phrase list (plain chat) | `agent.rs` `WRITE_VERBS`, `classifyGeneration.ts` | Requests in other wording or languages lose the file | RD1 |
| Desktop model use | one model for every step | `TurnContext.model` | Expensive reading or weak thinking | RS5, RD8 |

---

## 5. Desktop use-case ledger

Last recorded run (2026-09-18, desktop `e79b589`, `--quick --only
UC-00,UC-02,UC-03,UC-04`): **1 passed, 3 failed**.

| Case | Result | Finding |
| ---- | ------ | ------- |
| UC-00 | pass | 44 of 54 chat models available |
| UC-02 Write a file | fail | 5 of 6 models; Mistral Large rejected the request |
| UC-03 Web question | fail | 4 of 6 answered with a source; gpt-oss-120b "could not turn the results into an answer" |
| UC-04 Research into a file | fail | 2 of 3 wrote `sources.md` with ≥ 3 URLs; GPT-6 Astra wrote 0 URLs |

Since then, in the code (not re-run): C1 `max_completion_tokens` for GPT
reasoning models (`OpenAiMessagesTranslator::usesCompletionTokens`), C2(a)(b)
wrap-up and empty-search recovery (`GatewayToolLoop`), C3a live desktop RAG
(`MessagesContextInjector`), C4 Gemini `thought_signature` replay
(`GeminiMessagesTranslator`), C5 truncation shown on Desktop
(`chat://truncated`). RQ1 re-runs the full plan and moves fixed rows to Done.

Note that UC-04's check ("≥ 3 https lines in `sources.md`") is a format check,
not a quality check: a file with three irrelevant links passes. `06` adds
quality checks.

---

## 6. Where Synaplan can be better than Cursor for research

| Strength | Why it matters |
| -------- | -------------- |
| Many providers in one account | A second provider can check the first; each role gets the right price and speed |
| Worlds: EU, local, cloud | Research that never leaves a chosen jurisdiction, stated on every run |
| Knowledge folders with sharing | Research over an organisation's own documents, inside owner scopes |
| Office output on the person's computer | Word, Excel, PowerPoint and email drafts from the report, offline |
| Scheduled runs and approvals | A weekly research brief (use case 3) with nothing written without consent |
| Usage budgets | Thorough research with a visible, capped cost |

---

## 7. To measure, not guess (RQ1, RQ2)

1. How often today's web chat and Desktop answers contain the gold facts, and
   how often their citations support the sentence.
2. Whether gpt-oss-120b on Groq reads pages as faithfully as Gemini 3.8 Flash
   (quote exactness, fact recall) at a fraction of the price.
3. How much of a Thorough run's cost is reading — the basis for §0 #4.
4. Whether a checker from another provider catches seeded errors more often
   than the writer's own model.
5. Queue wait for long runs on `async_ai_high` with normal background load.
6. Whether keyword + meaning search finds named items (article numbers,
   product codes) that vector search misses on the fixture corpus.

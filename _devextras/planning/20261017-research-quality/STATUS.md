# Research quality — status

Every result here names the command, the versions and the stack it came from.
Quality, cost and latency numbers come only from `app:research:eval` or the
desktop use-case runner (`06_evaluation.md` §6). A walk is a dated entry, not a
chat message.

| Step | State | Note |
| ---- | ----- | ---- |
| RQ0 | done | Plan, baseline research, model roles, engine, prompts, journeys, evaluation, step briefs; registered as roadmap row 21 |
| RQ1 | done | Full desktop use-case run `V42D2` on 2026-10-23 against local `main`. 5 passed, 6 failed. Recorded below. |
| RQ2 | in progress | Eval corpus, scorer, test doubles and `app:research:eval` are in the working tree. B0 starter set is recorded below. B4 was stopped on cost and is not scored; it does not decide this step |
| RS1 | in progress | Gateway wrap-up answers fully; round limit from config. Merged in #2637. Run `V42D2`: UC-04 passed. UC-03 failed (46/51). Sonnet 5.5 and gpt-oss-120b answered with a source in 3 rounds. Not done. |
| RS2 | in progress | `web_fetch` run by Synaplan on non-Anthropic `/v1/messages` routes. `OpenAiGatewayToolLoop` (`POST /v1/chat/completions`) is a follow-up, decided 2026-10-22: that endpoint never sees Anthropic’s server-tool declaration, and its constructor is frozen at 13 parameters. Run `V42D2`: UC-04 passed on Sonnet 5.5, gpt-oss-120b and mistral-large-4. UC-04b is not in the runner. Not done. |
| RS3 | in progress | `knowledge_search` and `read_document` on `POST /v1/messages` when a folder is pinned. `OpenAiGatewayToolLoop` (`POST /v1/chat/completions`) is a follow-up, same reason as RS2. Manual walk 2026-10-22 below: the simple question answered from the first-turn injection, and one explicit follow-up ran both tools. Run `V42D2`: UC-05 passed (ready in 132 ms, UC-05d hit after Ready). UC-06 facts 0/3. UC-05e is not in the runner. Not done. |
| RS4 | in progress | Document reader behind `WEB_SEARCH.READ_DOCUMENTS` (web search reads locally, no vision). Smoke reg-01, reg-02, sci-01 scored 1.00 with the flag off and on (2026-10-23), but no run opened a document: the only PDF was outside the 3-page cap. Done needs one smoke item whose top 3 results include a PDF, measured flag off and on. The flag stays off. |
| RD1 | in progress | File tools without a skill, Desktop agent prompt, language-neutral checks, output budget, B1. `make ci-local` passed. Quick set `9RDYA` is recorded below; UC-03-de failed once and passed on the rerun. J-RQ-1 is not walked yet. |
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

## 2026-10-23 — RQ1 full desktop use-case run

Measurement only. No product code changed. The local stack was checked out
on synaplan `main` before the run. A fresh harness was paired for
`admin@synaplan.com`. Target `http://localhost:8000`. Run id `V42D2`.
Report: `synaplan-desktop/_extras/planning/20260917-desktop-use-case-test-plan/runs/20261023081938.json`.

```bash
cd synaplan-desktop/src-tauri
cargo run -p synaplan-core --example usecases -- --full
```

| Repo | SHA |
| ---- | --- |
| synaplan | `bb70055b055e773b4ed6b205c63b77e7b479aa72` |
| synaplan-desktop | `12e2510f035fc6776c34642e74007023d4119bd6` |

5 passed, 6 failed, 0 skipped, 1533 s. 51 of 62 chat models were available.

| Case | Result |
| ---- | ------ |
| UC-00 | FAIL. VECTORIZE `ollama:bge-m3:vectorize` is `provider_unavailable`. No SOUND2TEXT or TEXT2SOUND default. |
| UC-01 | FAIL. 46/51 answered `PONG`. Empty text: `mistral:mistral-large-4:chat`. `model_unavailable`: four Hugging Face Kimi rows. |
| UC-02 | FAIL. 46/51 wrote `hello.md`. `google:gemini-2.5-flash-lite:chat` left the file missing. Same four Hugging Face rows unavailable. |
| UC-03 | FAIL. 46/51 answered with a source. Sonnet 5.5 and gpt-oss-120b did, in 3 rounds. `mistral-large-4` ended on the wrap-up sentence with no URL. `gemini-3.7-flash` used 5 rounds (ceiling 4). |
| UC-04 | PASS. 3/3 wrote `sources.md` (Sonnet 5.5, gpt-oss-120b, mistral-large-4). |
| UC-05 | PASS. 3/3 named the fact. Ready in 132 ms. UC-05d hit after Ready in the same thread. |
| UC-06 | FAIL. 7/7 ready in 7.9 s, 37.72 MB/min, 0/3 facts. |
| UC-07 | FAIL. No dictation model. |
| UC-08 | PASS. |
| UC-09 | PASS. 616 words, `truncated=true`. |
| UC-10 | PASS. 4 failures, plain copy. |

`CHANGES.md` moved C1, C2, C3a and C5 to Done because those gates were green. C3b, C4, C6, C7, C8, C9 and C10 stay open. RS1, RS2 and RS3 stay in progress.

---

## 2026-10-23 — RD1 B1 smoke, before and after

Both exports used the workspace default chat model `anthropic:claude-sonnet-5-5:chat`, web search on, no skill. The catalog route does not send `maxOutput`, so plain chat uses 8,192. Agent turns are not streamed. Ten items finished in about a minute either way, so the missing stream did not slow this set.

The before binary was compiled before the prompt change. The after binary includes it. Both HEADs are the commits below; the desktop working tree holds the uncommitted RD1 change.

```bash
cd synaplan-desktop/src-tauri
cargo run -p synaplan-core --example usecases -- --research-corpus /path/to/backend/tests/Eval/research/corpus.json
```

| | Before | After |
| --- | --- | --- |
| Run | `7A1D7` | `VSZSV` |
| File | `runs/b1-7A1D7.json` | `runs/b1-VSZSV.json` |
| Time | 54 s | 62 s |
| synaplan | `bb70055b055e773b4ed6b205c63b77e7b479aa72` | same |
| synaplan-desktop | `12e2510f035fc6776c34642e74007023d4119bd6` | same |

Scored with `app:research:eval --corpus=smoke --arm=b1 --import=<file>` on the local stack. Fact recall is **0.78** on both (9 items with match strings). `doc-01` and `mix-01` are 0.00: the harness has no contract to read. `trap-01` abstained both times. The other seven items are 1.00. The `ms` column is 0 because an import is not a live answer.

Quick set `9RDYA` (2026-10-23T09:40:11Z, `--quick`, 458 s, report `runs/20261023094011.json`): 9 passed, 4 failed. UC-01, UC-02, UC-03, UC-04, UC-04-de, UC-05, UC-08, UC-09 and UC-10 passed. UC-00, UC-06 and UC-07 failed for the same local reasons as `V42D2`. UC-03-de failed 6/7: `groq:openai/gpt-oss-120b:chat` returned the gateway wrap-up sentence and no URL. The same case passed 7/7 on a second run (`--only UC-03-de`, report `/tmp/synaplan-uc03-de-rerun/runs/20261023094334.json`, copied to `runs/uc03-de-rerun-20261023094334.json`). That second run is not the ledger.

UC-01 first token was not slower than `V42D2`. Sonnet 5.5 ttft 1179 ms / total 1191 ms (was 3145 / 3146). gpt-oss-120b ttft 530 ms / total 530 ms (was 2072 / 2072). Plain chat now uses 8,192 tokens. UC-09 finished: 2795 words, `end_turn`.

`make ci-local` in synaplan-desktop passed on this working tree (lint, vue-tsc, Vitest 204, clippy, 227 Rust tests, frontend build, workspace clippy, cargo build). J-RQ-1 has not been walked in the desktop window. RD1 stays in progress.

---

## 2026-10-23 — RS4 flag on vs off

Live runs, not a fixture replay. Account is admin user id 1. The chat model is
that account's default (Claude Sonnet 5.5). Web search is Brave. The three
smoke items do not read the knowledge folder.

Stack: local `docker compose` on the m4 context. synaplan
`feat/web-search-read-documents` at `2b4dd17b6`. The flag row was missing
before the on-run, which reads as off. It was set to `1` for the second run
and set back to `0` afterwards. Reports:
`backend/var/research-eval/2026-10-23-rs4-off.md` and
`backend/var/research-eval/2026-10-23-rs4-on.md`.

```bash
docker compose exec -T backend php bin/console app:research:eval \
  --corpus=/tmp/rs4-corpus.json --arm=b0 --user=1 \
  --frozen=var/research-eval/frozen/2026-10-23-rs4-off \
  --report=var/research-eval/2026-10-23-rs4-off.md

docker compose exec -T backend php bin/console app:research:eval \
  --corpus=/tmp/rs4-corpus.json --arm=b0 --user=1 \
  --frozen=var/research-eval/frozen/2026-10-23-rs4-on \
  --report=var/research-eval/2026-10-23-rs4-on.md
```

`/tmp/rs4-corpus.json` is reg-01, reg-02 and sci-01 copied from the starter corpus.

| Flag | reg-01 | reg-02 | sci-01 | Mean recall |
| ---- | ------ | ------ | ------ | ----------- |
| off | 1.00 in 18651 ms | 1.00 in 7638 ms | 1.00 in 3882 ms | 1.00 |
| on | 1.00 in 17738 ms | 1.00 in 7660 ms | 1.00 in 3683 ms | 1.00 |

Both runs read the same HTML pages. The only document URL was
`https://www.datenschutz-bayern.de/datenschutzreform2018/OH_Loeschung.pdf`
on reg-02, at result 8. The page cap is 3, so it was not opened either time.
No chat cost row was written. This run shows the flag does not hurt HTML
reads; it does not measure document reading. The flag stays off.

---

## 2026-10-22 — RQ2 B0 on the starter set

Live run, not a fixture replay. Account is admin user id 1. The chat model is
that account's default (Claude Sonnet 5.5). Web search is Brave. Knowledge
folder `rq2-eval` holds the three generated files `preisliste.txt`,
`protokoll.txt` and `rahmenvertrag.txt`. The older `Wissensordner` files were
not used.

Stack: local `docker compose` on the m4 context. synaplan `test/research-eval-corpus`
at `0de94b240`, with the eval command still uncommitted. Report:
`backend/var/research-eval/2026-10-22-b0.md`.

```bash
docker compose exec -T backend php bin/console app:research:eval \
  --corpus=full --arm=b0 --user=1 --folder=rq2-eval \
  --frozen=var/research-eval/frozen/2026-10-22-b0 \
  --report=var/research-eval/2026-10-22-b0.md
```

| Date | Step | Arm | Corpus | Depth | Recall | Citation precision | Coverage | Abstention | Cost p50 | Time p50 / p95 | Command |
| ---- | ---- | --- | ------ | ----- | ------ | ------------------ | -------- | ---------- | -------- | -------------- | ------- |
| 2026-10-22 | RQ2 | B0 | starter 20 | standard | 0.95 | not judged | not judged | both traps avoided the forbidden sentence | not recorded | 4865 ms / 17819 ms | `app:research:eval --corpus=full --arm=b0` |

Recall is the mean over the 19 items that have a match string. `sci-02` ("What
is the chemical symbol of water?") is 0.00 because the answer did not contain
`H2O`. Every other scored item is 1.00. `trap-01` has no match string, so it
is not in the mean; it did not use the forbidden sentence. The chat calls did
not write a cost row, so no cost is entered. Times are the median and the 95th
percentile of the 20 row durations in the report.

---

## 2026-10-22 — RS3 manual walk (not the desktop runner)

A walk, not a use-case run. `06_evaluation.md` still wants UC-05, UC-05e and
UC-06 from the desktop runner; this entry only records what one paired key
did against the local dev stack.

Stack: local `docker compose` on the m4 context. synaplan
`feat/knowledge-folder-as-a-tool` at `c9534c70e`. `KNOWLEDGE_TOOLS=desktop`
(owner 0). Model `openai/gpt-oss-20b` on Groq, operator key. Command:
`POST /v1/messages` with `Authorization: Bearer` (a paired desktop key) and
`x-synaplan-rag-group-key: Wissensordner`.

Folder `Wissensordner`, three files, each vectorized as one chunk
(`BFILES` 19–21): `projektcode.txt` (“ZEBRA-41”), `budget.txt` (“73.900 €”),
`kickoff.txt` (“14. März”).

| Turn | Question | Result | Evidence |
| ---- | -------- | ------ | -------- |
| 1 | “Nenne Projektcode, Budget und Kickoff-Datum.” | Answer contains all three planted facts, cited as the injected passages. No tool call. | Response `chatcmpl-46ac7822-1276-4486-8aaf-54dae13a11ab`, 648 in / 182 out. `BUSELOG` 814 is `MESSAGES` only |
| 2 | “Die Textstellen oben reichen nicht. Suche im angehefteten Ordner nach dem Budget und lies die gefundene Datei vollständig.” | Answer quotes the whole budget file, including “Andere Beträge gelten nicht.” | Response `chatcmpl-5a974f2b-2e72-435a-adb6-b9a13bc703cd`, 2403 in / 351 out. `BUSELOG` 818 `tool:knowledge_search` success, 819 `tool:read_document` success |

Not measured: time to first token (UC-05 before / after), a question that
makes the model search twice on its own (UC-05e asks for `knowledge_search`
≥ 2), and UC-06. The desktop use-case runner was not used.

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

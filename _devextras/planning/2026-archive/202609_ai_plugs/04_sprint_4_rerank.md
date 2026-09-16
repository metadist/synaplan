# Sprint S4 — Rerank

**Track 3 (AI Plugs), sprint 4 of 6.** Steps `PL25`–`PL31`.

**Goal:** RAG retrieval can pass `k × N` candidates through a rerank model chosen from the catalog and keep the top `k`,
with a latency budget and a fallback to the embedding order. The default stays **off**; the PR that flips it must attach
an eval report showing recall@5 up and p95 latency within budget.
**Depends on:** S1 (`RerankRegistry`, `RerankProviderInterface`, `PlugConfigService`); S3 `PL16` (`PlugKeyStore` for
Jina/Cohere/Voyage keys); S2 `PL14` (tabbed page).
**Unlocks:** S5 model import can tag imported `/rerank`-capable models; the eval harness is reused for later retrieval changes.
**Repos:** `synaplan/` only.
**Flag:** `PLUGS.RERANK.ENABLED` (seeded `0`), `PLUGS.RERANK.LLM_FALLBACK` (seeded `0`).

---

## 0. Why this sprint exists

Retrieval quality is capped by embedding similarity: the right chunk is often in the top 20 but not the top 5. A
cross-encoder reranker fixes exactly that, at the price of one more network call per question. Because the price is
real and the gain depends on the corpus, this sprint ships the mechanism and the measurement, and lets the numbers
decide the default (decision 8).

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/RAG/VectorSearchService.php` | `semanticSearch()` / `semanticSearchByVector()` — the RAG search entry (there is no `RagRetriever` class; the stage is inserted here) |
| `backend/src/Service/RAG/VectorStorage/VectorStorageFacade.php`, `DTO/SearchQuery.php` | `search(SearchQuery)`: `limit`, `minScore`, group keys — candidates come from here |
| `backend/src/Model/ModelCatalog.php`, `backend/src/Seed/ModelSeeder.php` | Catalog rows (`service`, `name`, `tag`, `providerId`); today's tags `chat`, `vectorize`, `pic2text`, `text2pic`, `text2vid`, `sound2text`, `text2sound`, `mem` |
| `backend/src/Seed/DefaultModelConfigSeeder.php` | `DEFAULTMODEL.*` bindings by `service:providerId:tag`; how a missing binding is handled |
| `backend/src/Service/ModelConfigService.php` | Resolving a `DEFAULTMODEL` setting to a `BMODELS` row for a user |
| `backend/src/AI/Credential/OpenAiCompatibleEndpointRegistry.php` | `resolveForModel()` — a TEI `/rerank` endpoint is registered here like any OpenAI-compatible endpoint |
| `backend/src/Command/SelfAwareEvalCommand.php`, `backend/src/Service/SelfAware/Eval/SelfAwareEvalCorpus.php`, `backend/tests/Eval/*.json` | Eval discipline to reuse: JSON corpus, command, report, corpus unit test |
| `_devextras/planning/20260902-platform-self-awareness/05_eval_question_set.md` | Question-set rules (no provider key, no hostname, no class name in questions) |
| `backend/src/Plug/PlugKeyStore.php` (S3) | Keys for Jina, Cohere, Voyage |
| `frontend/src/services/api/adminModelsApi.ts`, `configApi.ts` | Model list endpoints the tab reuses for the `rerank` select |

---

## 2. Developer steps

### 2.1 `rerank` catalog tag and seeds (`PL25`)

`ModelCatalog::MODELS` gains rows with `tag = rerank`: a TEI-served open reranker under `service = openaicompatible`
(resolved per endpoint), and the current Jina (`service = jina`), Cohere (`cohere`) and Voyage (`voyage`) rerank models.
Names live in the catalog only. `ModelSeeder` inserts them with `BSELECTABLE = 0`, `BACTIVE = 1`, `BISDEFAULT = 0` — seeded
once, never overwritten. `DefaultModelConfigSeeder` gets **no** `DEFAULTMODEL.RERANK` row; `ModelConfigService::getDefaultModel('RERANK')`
returns `null`, and `RerankRegistry::active()` returns `null` while `RERANK.ENABLED = 0` or no model is bound. Key pattern: `service:providerId:rerank`.

### 2.2 `HttpRerankAdapter` (`PL26`)

`App\Plug\Rerank\Adapter\HttpRerankAdapter` (`key = http`) — one adapter, four wire shapes chosen by `BMODELS.BSERVICE` of the bound model:

| Service | Request | Response |
| ------- | ------- | -------- |
| `openaicompatible` (TEI) | `POST {endpoint}/rerank` `{query, texts[], raw_scores: false}` | `[{index, score}]` |
| `jina` | `POST https://api.jina.ai/v1/rerank` `{model, query, documents[], top_n}` | `results[{index, relevance_score}]` |
| `cohere` | `POST https://api.cohere.com/v2/rerank` `{model, query, documents[], top_n}` | `results[{index, relevance_score}]` |
| `voyage` | `POST https://api.voyageai.com/v1/rerank` `{model, query, documents[], top_k}` | `data[{index, relevance_score}]` |

Input `list<RerankCandidate{id, text, embeddingScore}>`, output
`RerankResult{ordered: list<{id, score}>, provider, ms}`. Candidate text is
truncated to `RERANK.MAX_CANDIDATE_CHARS` (seeded `2000`). HTTP timeout =
`RERANK.LATENCY_BUDGET_MS`. Keys: TEI via the endpoint's stored key, others via
`PlugKeyStore`. Recorded fixtures per shape in `tests/Fixtures/rerank/<service>/`.

### 2.3 `LlmReranker` (`PL27`)

`key = llm`, active only when `RERANK.LLM_FALLBACK = 1` and no `rerank` model
is bound. Listwise prompt `tools:rerank_listwise` (internal prompt, `tools:`
prefix, seeded by `PromptSeeder`) sent through `AiFacade::chat()` with the
model bound to `DEFAULTMODEL.SUMMARIZE` (the existing utility binding — no
new model name). Output parsed as a JSON array of candidate ids; any parse
error → fallback to embedding order. Documented as expensive; the tab shows a cost hint.

### 2.4 Insertion in the RAG path (`PL28`)

`App\Plug\Rerank\RerankStage::apply(string $query, array $results, int $k): array`
called from `VectorSearchService::semanticSearch()` and `semanticSearchByVector()`
only when `RerankRegistry::active()` is non-null:

```text
limit' = min(k × RERANK.CANDIDATES_MULTIPLIER, 100)        ← storage query uses limit'
minScore filter unchanged (applied before rerank, as today)
rerank(query, candidates, topK = k) within LATENCY_BUDGET_MS
  ok      → reorder, cut to k, results[i]['rerank_score'], meta.rerank = {provider, ms, applied: true}
  timeout / exception / empty → first k in embedding order, meta.rerank = {applied: false, reason}
```

Metrics: `synaplan_plugs_rerank_latency_ms` (histogram),
`synaplan_plugs_rerank_fallback_total{reason}`. Result array keys used by
callers (`content`, `score`, `file_id`, `chunk_id`, `group_key`, …) are
unchanged; `rerank_score` is additive. With `ENABLED = 0` the stage is not
constructed and the storage `limit` is `k` exactly as today (C5).

### 2.5 Eval harness and report (`PL29`)

`RagRerankEvalCommand` (`app:rag:eval-rerank --corpus=<id> --user=<id> --k=5 --report=<path>`),
mirroring `SelfAwareEvalCommand`: loads `backend/tests/Eval/rag_rerank_eval_corpus.json`
(`{corpusId, questions: [{id, question, expected: {file, mustContain}}]}`,
20–50 questions per corpus, first corpus = the S1 golden extraction files),
runs each question with rerank **off** and **on** against a fixed user's
vector store, and writes a markdown report with recall@5, MRR, p50/p95
latency, fallback count. `RagRerankEvalCorpusTest` validates the JSON
(unique ids, files exist in the corpus, no provider key / hostname / class name
in questions). Reports are committed under `_devextras/planning/202609_ai_plugs/eval/`.
**Rule:** the PR that changes the seeded `RERANK.ENABLED` to `1` (a seeder
change — bootstrap-only, existing installs keep their value) must link its
report and record the decision in `STATUS.md`. Without a winning report the
default stays `0` (decision 8, master plan §9.3).

### 2.6 Admin API and Reranking tab (`PL30`)

```text
GET  /api/v1/admin/plugs/rerank        → { enabled, modelKey|null, multiplier, budgetMs, llmFallback, adapters: [{key,label,health}], models: [{key,label,available}], lastEval: {date, recallOff, recallOn, p95Off, p95On}|null }
PUT  /api/v1/admin/plugs/rerank        { enabled, modelKey, multiplier (2..10), budgetMs (100..5000), llmFallback }   → 422 on unbound model when enabled
POST /api/v1/admin/plugs/rerank/test   { query, documents: [..≤20] }   → { ordered: [{index, score}], provider, ms }
```

`PUT` writes `DEFAULTMODEL.RERANK` through `ModelConfigService` (catalog key,
never a BID in the API) and the `PLUGS.RERANK.*` rows. `components/admin/plugs/RerankPlugTab.vue`:
enable switch, model select (only `tag = rerank` rows, unavailable greyed with
reason), multiplier and budget inputs, LLM fallback switch with cost hint,
"Test" textarea, last eval numbers. Namespace `aiInfra.rerank.*`, five locales.

### 2.7 Docs (`PL31`)

`docs/RAG.md`: rerank stage, budget, fallback, how to run the eval and read the report; `docs/CONFIGURATION.md`: `RERANK.*` settings and the `rerank` tag.

---

## 3. Tests and invariants

| Invariant | Proof in this sprint |
| --------- | -------------------- |
| C2 | `PlugsConfigSeederTest`: `ENABLED = 0`, `MULTIPLIER = 4`, `BUDGET_MS = 800`, `LLM_FALLBACK = 0`; `DefaultModelConfigSeederTest`: no `RERANK` row |
| C4 | `PlugBoundaryTest`: rerank HTTP clients only under `src/Plug/**` |
| C5 | `VectorSearchServiceRerankOffTest`: with `ENABLED = 0` the storage receives `limit = k` and results are identical to the pre-sprint fixture; existing `tests/Service/VectorSearch/*` untouched |
| C6 | `ModelSeederTest`: re-seed keeps an admin-set `BSELECTABLE = 1` on a rerank row |
| C7 | `HttpRerankAdapterHealthTest`: unreachable TEI endpoint → `unavailable`; `RerankStageFallbackTest`: timeout → embedding order, metric incremented, no exception to the caller |
| C8 | `/v1` gateway RAG tests unchanged; all steps `backend-only` except `PL30` (`ota-candidate`) |

Also: `HttpRerankAdapterContractTest` per wire shape (recorded fixtures),
`LlmRerankerTest` (parse ok / parse error → fallback), `RagRerankEvalCorpusTest`,
`AdminPlugsRerankControllerTest` (422 rules), `RerankPlugTab.spec.ts`.

---

## 4. Exit criteria / demo

1. Admin registers a TEI endpoint with a reranker, enables rerank, binds the model; a question whose answer chunk was rank 8 is now rank 1; `meta.rerank.applied = true` in the debug panel.
2. Set budget to 50 ms: answers still arrive, `meta.rerank.applied = false, reason = timeout`, fallback metric increments.
3. `app:rag:eval-rerank` produces the report; the numbers are pasted into `STATUS.md`; the default flips only if recall@5 improves and p95 on stays under budget.
4. Rerank off: `VectorSearchService` results byte-identical to before the sprint.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| PL25 | `feat(models): add rerank catalog tag and seeded rerank models without default binding` | backend-only | PL3 |
| PL26 | `feat(plugs): add HttpRerankAdapter for TEI, Jina, Cohere and Voyage rerank APIs` | backend-only | PL25, PL16 |
| PL27 | `feat(plugs): add LlmReranker fallback behind RERANK.LLM_FALLBACK` | backend-only | PL25 |
| PL28 | `feat(rag): insert optional rerank stage with candidate multiplier and latency budget` | backend-only | PL26 |
| PL29 | `feat(eval): add RAG rerank eval command, corpus and report format` | backend-only | PL28 |
| PL30 | `feat(admin): add Reranking tab and admin rerank API` | ota-candidate | PL14, PL28 |
| PL31 | `docs(rag): document rerank stage, eval procedure and the default-flip rule` | backend-only | PL29, PL30 |

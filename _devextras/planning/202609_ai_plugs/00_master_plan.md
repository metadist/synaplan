# AI Plugs — interchangeable providers behind capability ports — master plan

**Status:** Decisions ticked 2026-09-03 (log in [`STATUS.md`](./STATUS.md)).
Track 3 of [`../20260903_roadmap.md`](../20260903_roadmap.md).
Independent of tracks 1 and 2; S1 can run in parallel with IAM.
Sprint files: [`01_sprint_1_ports_and_refactor.md`](./01_sprint_1_ports_and_refactor.md) …
[`06_sprint_6_plugin_adapters.md`](./06_sprint_6_plugin_adapters.md).
**Owner surface:** Operate → **AI infrastructure** (today's *Provider setup*,
renamed and extended). No new nav item.
**Flags:** per plug, e.g. `PLUGS.EXTRACTION.CHAIN`, `PLUGS.WEB_SEARCH.PROVIDER`,
`PLUGS.RERANK.ENABLED` — defaults reproduce today's behavior exactly.
**Related:**

- [`../20260826-provider-aware-model-catalog/README.md`](../20260826-provider-aware-model-catalog/README.md)
  — availability-filtered catalog, soft-disable, `app:provider:list`
- [`../2026-archive/20260709-hosting-partner-core-requirements/README.md`](../2026-archive/20260709-hosting-partner-core-requirements/README.md)
  §CORE-1 — OpenAI-compatible endpoints (shipped) and model import (P3, open)
- [`../2026-archive/20260716-openai-compatible-models-auth-ux/README.md`](../2026-archive/20260716-openai-compatible-models-auth-ux/README.md)
- [`../20260822-open-plugin-platform/README.md`](../20260822-open-plugin-platform/README.md)
  — manifest v2; plugins contribute plug adapters
- [`../20260902-platform-self-awareness/05_eval_question_set.md`](../20260902-platform-self-awareness/05_eval_question_set.md)
  — the eval discipline reused for the rerank go/no-go

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **A "plug" is a capability port with interchangeable adapters.** v1 ports: **content extraction**, **web search**, **rerank**. Existing AI provider interfaces (`ChatProviderInterface`, `EmbeddingProviderInterface`, …) are already ports and are not renamed. | Three new ports | ✅ 2026-09-03 |
| 2 | **Same mechanics as `ProviderRegistry`:** one interface per port, adapters tagged (`app.plug.extractor`, `app.plug.web_search`, `app.plug.rerank`), a registry with `#[AutowireIterator]`, selection from `BCONFIG` via a `PlugConfigService`. | Tagged services | ✅ 2026-09-03 |
| 3 | **Refactor first.** S1 wraps `TikaClient` and `BraveSearchService` in adapters with **byte-identical** results before any new adapter exists. | Locked | ✅ 2026-09-03 |
| 4 | **Extraction is a chain, not a single choice:** ordered adapters per MIME family with the existing quality gate (`TIKA_MIN_LENGTH` / `TIKA_MIN_ENTROPY` generalized to `ExtractionQualityGate`). Default chain = today's `FileProcessor` order. | Chain | ✅ 2026-09-03 |
| 5 | **Docling runs as a sidecar** (official `docling-serve` image), compose profile `docling`, env `DOCLING_BASE_URL`. PHP holds a thin client. No Python in the PHP image. | Sidecar | ✅ 2026-09-03 |
| 6 | **Web search default stays Brave.** New adapters: **SearXNG** first (self-hosted, sovereign, compose profile `searxng`), then Tavily, Exa, Firecrawl, Perplexity. One `SearchResultSet` DTO; provider-specific extras go to `meta`. | Brave default, SearXNG first | ✅ 2026-09-03 |
| 7 | **Rerank models are catalog entries** (`BMODELS.BTAG = rerank`, binding `DEFAULTMODEL.RERANK`), never hard-coded names. Adapters: OpenAI-compatible-style `/rerank` (TEI, Jina, Cohere, Voyage), plus an `LlmReranker` fallback that uses the chat model (off by default; cost). | Catalog-managed | ✅ 2026-09-03 |
| 8 | **Rerank is default off until an eval proves it.** RAG eval set (20–50 questions per corpus) run with and without rerank; enable by default only if recall@k improves without a latency regression above the budget in §4.3. | Eval-gated | ✅ 2026-09-03 |
| 9 | **Model import from OpenAI-compatible endpoints and Ollama** is a UI on the existing "test connection" (`GET /models`, `/api/tags`): preview → select → create `BMODELS` rows with guessed tags (editable). Idempotent on `(service, endpoint, providerId)`. | Import UI | ✅ 2026-09-03 |
| 10 | **Multiple OpenAI-compatible endpoints already exist** (`OpenAiCompatibleEndpointRegistry`). This track adds import, per-endpoint capability probing and health, not a new registry. | Reuse | ✅ 2026-09-03 |
| 11 | **Plugins may contribute adapters** via manifest v2 `provides.plugs` (`{ port, class }`); boot-time declaration check like `provides.skills`. | Manifest v2 | ✅ 2026-09-03 |
| 12 | **Admin UI = one page, tabs per port.** `ProviderSetupView` becomes **AI infrastructure**: Models & keys · Extraction · Web search · Reranking. Each tab: active adapter/chain, health, test button. | One page | ✅ 2026-09-03 |
| 13 | **Secrets go where provider keys go:** `ProviderKeyStore`-style encrypted `BCONFIG` groups (`plug_keys`), env vars bootstrap-only, UI wins. | Locked | ✅ 2026-09-03 |
| 14 | **Contract tests with recorded fixtures** for every adapter (no live network in CI); a golden extraction corpus in `backend/tests/Fixtures/extraction/`. | Locked | ✅ 2026-09-03 |
| 15 | **Mobile:** backend `backend-only`; admin page `ota-candidate`. Docker profile additions are "ask first" and listed in §8. | Locked | ✅ 2026-09-03 |

---

## 1. The concept in three sentences

> Some jobs around the AI — reading a PDF, searching the web, ranking search
> hits — can be done by different services. A **plug** is the slot for one
> such job; the admin picks which service fills it (or a chain of them), and
> Synaplan uses whatever is plugged in. Everything else in Synaplan does not
> care which one it is.

---

## 2. Why this exists

- `FileProcessor` hard-codes its strategies; Tika is the only document
  extractor. Docling produces markdown with tables and reading order, which
  chunks and retrieves better — but there is no seam to add it.
- Web search is Brave only, via a concrete class called from three places.
  Sovereign installs want SearXNG; others want Tavily/Exa-style AI-ready
  results; a hoster wants to switch without a code change.
- There is no rerank at all; retrieval quality is capped by embedding
  similarity.
- Admins can register several OpenAI-compatible endpoints but must type every
  model by hand; "import what the endpoint offers" was asked for twice.

The partner review listed all four under "pluggable AI infrastructure".

---

## 3. What already exists (do not rebuild)

| Piece | State | Role here |
| ----- | ----- | --------- |
| `ProviderRegistry`, `ProviderMetadataInterface`, tagged `app.ai.*` | Shipped | The pattern to copy; `getStatus()` / `isAvailable()` shape reused for plug health |
| `TikaClient` (`TIKA_BASE_URL`, retries, quality thresholds) | Shipped | Becomes `TikaExtractor` adapter; thresholds move to `ExtractionQualityGate` |
| `FileProcessor` strategy order (plain text → office convert → Tika → PDF rasterize + vision → image vision → audio/video STT) | Shipped | Becomes the default chain configuration; the class becomes the chain runner |
| `OfficeConverterClient`, `PdfRasterizer`, `HeicConverter`, `WhisperService`, `VideoAnalysisService` | Shipped | Stay as steps inside the chain (adapters or helpers) |
| `BraveSearchService`, `WebSearchTopicPolicy`, `SearchQueryGenerator`, `WebSearchRunner`, `WebSearchTool` | Shipped | `BraveSearchAdapter`; callers switch to `WebSearchRegistry::active()` |
| `VectorizationService` → `TextChunker` → `AiFacade::embed` → `VectorStorageFacade` | Shipped | Rerank inserts after retrieval, before context assembly |
| `OpenAiCompatibleEndpointRegistry::testConnection()` (lists `/models`) | Shipped | Import UI builds on it |
| `OllamaModelInventory` (`/api/tags`) | Shipped | Import source |
| `ModelCatalog` / `ModelSeeder` / `DefaultModelConfigSeeder` | Shipped | `rerank` tag + `DEFAULTMODEL.RERANK` binding |
| `ProviderKeyStore` (`provider_keys`) | Shipped | Pattern for `plug_keys` |
| `ProviderSetupView.vue` (`/admin/setup`) | Shipped | Renamed, gains tabs |
| Compose sidecars `tika`, `tts`, `collabora` with profiles | Shipped | Pattern for `docling`, `searxng` |

---

## 4. Target architecture

```text
                    PlugConfigService (BCONFIG group PLUGS)
                                │
      ┌─────────────────────────┼──────────────────────────┐
      ▼                         ▼                          ▼
 ExtractionRegistry      WebSearchRegistry           RerankRegistry
 app.plug.extractor      app.plug.web_search         app.plug.rerank
      │                         │                          │
 ┌────┴────┬─────────┐    ┌─────┴─────┬────┬────┐    ┌─────┴──────┬──────────┐
 Tika   Docling  Vision   Brave  SearXNG Tavily …  HttpRerank  LlmReranker  (TEI/Jina/Cohere/Voyage)
 (http) (sidecar) (AiFacade)  (http)  (sidecar)         (http, catalog model)
```

### 4.1 Ports

```php
interface ContentExtractorInterface
{
    public function key(): string;                                        // 'tika', 'docling'
    public function supports(ExtractionRequest $r): bool;                 // mime, size, page count
    public function extract(ExtractionRequest $r): ExtractionResult;      // text, optional markdown, blocks/tables, pages, quality hints
    public function health(): PlugHealth;
}

interface WebSearchProviderInterface
{
    public function key(): string;                                        // 'brave', 'searxng', 'tavily', 'exa', 'firecrawl', 'perplexity'
    public function capabilities(): WebSearchCapabilities;                // freshness, country/lang, siteFilter, fullContent, answer
    public function search(SearchQuery $q): SearchResultSet;              // normalized results + meta
    public function health(): PlugHealth;
}

interface RerankProviderInterface
{
    public function key(): string;
    public function rerank(string $query, array $candidates, int $topK, RerankOptions $o): RerankResult;
    public function health(): PlugHealth;
}
```

`ExtractionResult` carries `text` (always) and `markdown` (optional): when
present, `TextChunker` prefers markdown (heading-aware chunks, table rows kept
together). That is the whole reason Docling helps RAG.

### 4.2 Configuration (`BCONFIG` group `PLUGS`, global with per-user override allowed for search provider only)

| Setting | Default | Meaning |
| ------- | ------- | ------- |
| `EXTRACTION.CHAIN.document` | `office_convert,tika,pdf_vision` | Ordered adapters for PDF/Office; first result passing the quality gate wins |
| `EXTRACTION.CHAIN.image` | `vision` | |
| `EXTRACTION.CHAIN.audio` | `stt_cloud,whisper_local` | |
| `EXTRACTION.QUALITY.min_length` / `min_entropy` | today's Tika values | Gate |
| `WEB_SEARCH.PROVIDER` | `brave` | Active provider |
| `WEB_SEARCH.FALLBACK` | `` | Optional second provider on error |
| `RERANK.ENABLED` | `0` | |
| `RERANK.CANDIDATES_MULTIPLIER` | `4` | Retrieve `k × 4`, rerank to `k` |
| `RERANK.LATENCY_BUDGET_MS` | `800` | Skip rerank (log) when exceeded |

Defaults reproduce today's behavior exactly — a fresh install after this
track behaves like a fresh install before it.

### 4.3 Rerank in the RAG path

`VectorSearchService::semanticSearch()` / `semanticSearchByVector()` (the
existing search entry; there is no separate retriever class) → candidates
`k × multiplier` →
`RerankRegistry::active()?->rerank()` → top `k` → context. On timeout or
error: fall back to the embedding order and record a metric. Eval gate
(decision 8): the platform-self-awareness eval harness runs the question set
against a fixed corpus with rerank on/off; the PR that flips the default must
attach the numbers.

### 4.4 Model import (S5)

`POST /api/v1/admin/models/import/endpoint/preview` `{ source: "openai_compatible:<endpoint>" | "ollama" }`
(the shorter `/import/preview|apply` pair already exists for the AI-generated
SQL import from pricing pages and is left untouched)
→ rows `{ providerId, guessedTags[], exists: bool }` (tags guessed from name
patterns and, for OpenAI-compatible, from a capability probe: one tiny chat
call, one embeddings call — opt-in, costs tokens) → `POST …/import/apply`
with the selected rows → `BMODELS` upserts. Operator-owned toggles
(`BSELECTABLE`, `BACTIVE`, `BISDEFAULT`) are never overwritten (seeder rule).

---

## 5. Sidecars and compose (ask first — recorded here)

| Service | Image | Profile | Env | Notes |
| ------- | ----- | ------- | --- | ----- |
| `docling` | `ghcr.io/docling-project/docling-serve` (pin digest) | `docling` | `DOCLING_BASE_URL=http://docling:5001` | CPU-only default; GPU variant documented for hosters. Memory-hungry: set limits in the platform compose, not in dev |
| `searxng` | `searxng/searxng` (pin) | `searxng` | `SEARXNG_BASE_URL=http://searxng:8080` | JSON format enabled in `settings.yml`; outbound network only from this container |

Both are optional; absence = adapter reports `unavailable` and the chain or
provider selection falls through. `synaplan-platform` gets a documented
service block per sidecar (private repo, no node details here).

---

## 6. Admin UI

`/admin/setup` → label **AI infrastructure** (en) / KI-Infrastruktur /
Infraestructura de IA / Infrastructure IA / AI altyapısı.

| Tab | Content |
| --- | ------- |
| Models & keys | Today's provider setup, plus per OpenAI-compatible endpoint: **Import models** (S5) |
| Extraction | Per MIME family: ordered chain (drag or up/down), adapter health pills, "Test with a file" (uploads, shows which adapter won and the text preview) |
| Web search | Active provider, fallback, key fields, "Test query"; per-user override toggle |
| Reranking | Enabled, model (from catalog `rerank` rows), multiplier, budget, last eval numbers |

All adapter descriptors (label, docs URL, required settings, sovereignty
badge *self-hosted* / *EU* / *US cloud*) come from the registry — the page
renders whatever is installed, including plugin-contributed adapters.

---

## 7. Compatibility invariants

| # | Invariant | Proof |
| - | --------- | ----- |
| C1 | **S1 refactor is byte-identical:** same extracted text for the golden corpus, same search results shape for recorded Brave fixtures | Golden corpus + fixture tests before/after |
| C2 | Default `PLUGS` values reproduce today's order and thresholds | Config characterization test |
| C3 | Routing snapshots untouched (web search decision logic `WebSearchTopicPolicy` not changed) | Snapshot suite |
| C4 | `AiFacade` public surface unchanged; no caller outside the plug registries talks to Tika/Brave directly | Static check (PHPStan rule or grep test) |
| C5 | Rerank off ⇒ RAG results identical to today | RAG tests |
| C6 | Model import never overwrites operator toggles; re-import is idempotent | Seeder-style tests |
| C7 | Missing sidecar ⇒ graceful `unavailable`, never a failed upload | Health tests with the URL unset |
| C8 | `/v1` gateways, widget, mobile unchanged | Existing suites; mobile-impact script |

---

## 8. Sprints

| Sprint | Content | Exit |
| ------ | ------- | ---- |
| **S1 — Ports & refactor** | Interfaces, DTOs, registries, `PlugConfigService`; `TikaExtractor`, `VisionExtractor`, `SttExtractor` wrapping existing code; `FileProcessor` becomes the chain runner; `BraveSearchAdapter`; callers use registries; golden corpus + recorded fixtures | C1 green; no UI change |
| **S2 — Docling** | `DoclingExtractor` (+ markdown-aware `TextChunker` path); compose profile; `ExtractionQualityGate`; Extraction tab with chain editor and test; docs (`docs/RAG.md`, `docs/CONFIGURATION.md`, docs site) | PDF with tables retrieves the table row; Tika still wins when Docling is down |
| **S3 — Web search providers** | `SearxngAdapter` (+ profile), `TavilyAdapter`, `ExaAdapter`, `FirecrawlAdapter`, `PerplexityAdapter` (answer capability surfaced as a distinct result type); Web search tab; per-user override; sovereignty badges | Switching provider in the UI changes results for the next search, no restart |
| **S4 — Rerank** | `rerank` catalog tag + seeds (no default binding), `HttpRerankAdapter`, `LlmReranker`, RAG insertion with budget/fallback, eval run and report, Reranking tab | Eval report attached; default stays off unless it wins |
| **S5 — Model import** | Preview/apply API, capability probe, Import UI for OpenAI-compatible endpoints and Ollama, scheduled re-check marking vanished models "unavailable" (soft) | Admin imports 12 models from a vLLM endpoint in one minute |
| **S6 — Plugin adapters** | Manifest v2 `provides.plugs`, boot check, docs for adapter authors, one reference plugin adapter | A third-party search adapter ships as a plugin with zero core edits |

Sprint files: [`01`](./01_sprint_1_ports_and_refactor.md) ·
[`02`](./02_sprint_2_docling.md) · [`03`](./03_sprint_3_web_search_providers.md) ·
[`04`](./04_sprint_4_rerank.md) · [`05`](./05_sprint_5_model_import.md) ·
[`06`](./06_sprint_6_plugin_adapters.md).

Cut line: S6, then S5 probe (keep name-based guessing). Never cut C1.

---

## 9. Rollout

1. S1 merges with no visible change; it is the safety net for everything
   after.
2. S2/S3 sidecars are opt-in profiles; Synaplan Cloud enables Docling once
   memory is sized; docs describe both.
3. Rerank default flips only with the eval report in the PR.
4. Rollback per plug: set the config back to the default adapter; sidecars
   can be stopped without affecting uploads.

---

## 10. Out of scope (v1)

- Replacing Tika or Brave; both stay bundled defaults.
- OCR-specific plugs (Tesseract etc.) — Docling covers OCR for PDFs; a
  dedicated port is a v2 candidate.
- A generic "HTTP plug gateway" sidecar in Go — not needed; every adapter is
  a thin PHP HTTP client against an existing OSS image or API.
- Per-user extraction chains (extraction is instance-level).
- Crawling / site indexing as a product (Firecrawl adapter covers fetch +
  search only).

---

## 11. Success criteria

1. Upload the same 20-document corpus before and after S1: identical text.
2. With Docling enabled, a question answered by a table cell in a PDF is
   retrieved in the top 3; with Docling stopped, the upload still succeeds
   via Tika.
3. Admin switches web search from Brave to SearXNG in the UI; the next chat
   search uses SearXNG; the audit of outbound hosts shows no Brave call.
4. Rerank eval: report with recall@5 and p95 latency for on/off; decision
   recorded in `STATUS.md`.
5. Import from an OpenAI-compatible endpoint creates only new rows, tags are
   editable before apply, re-import changes nothing.
6. Full gate green after every sprint; snapshots untouched.

---

## 12. Decisions from the 2026-09-03 review (formerly open questions)

| # | Question | Decision |
| - | -------- | -------- |
| 1 | Perplexity: search plug or chat provider? | **Both.** `PerplexityAdapter` (web search port, `capabilities.answer = true`, citations exposed as results, answer as a flagged extra) **and** a `PerplexityProvider` chat provider as an optional `BMODELS` entry. Independent toggles. |
| 2 | Extraction chain per user? | **Instance only** in v1. Web search provider keeps the per-user override (§4.2). |
| 3 | `TIKA_*` env vars | **Bootstrap-only**, UI wins — same rule as provider keys. No migration copies env into `BCONFIG`; the seeder reads env once on first boot as it does for keys. (Default stands; not contested.) |
| 4 | Docling | **Sidecar** (`docling-serve`, compose profile `docling`), S2. |
| 5 | First new search adapter | **SearXNG**, then Tavily, Exa, Firecrawl, Perplexity (S3). |
| 6 | Rerank default | **Off until the eval report wins**; `LlmReranker` fallback included, off by default. |
| 7 | Admin page | **Rename Provider setup → AI infrastructure**, tabs Models & keys / Extraction / Web search / Reranking. |
| 8 | Capability probe on model import | **Opt-in checkbox** on the preview step; name-based guessing always runs. |
| 9 | Bundle section (roadmap §8) | S5 registers a `model_preferences` section (`DEFAULTMODEL.*` bindings by catalog key, `PLUGS.WEB_SEARCH.PROVIDER` per-user override) with the track-2 bundle registry. Keys are never exported. |

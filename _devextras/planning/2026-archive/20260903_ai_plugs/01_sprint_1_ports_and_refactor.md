# Sprint S1 — Ports & refactor

**Track 3 (AI Plugs), sprint 1 of 6.** Steps `PL1`–`PL8`.

**Goal:** Every extraction and web-search call goes through a plug registry and nothing observable changes:
same extracted text for a golden corpus, same result arrays and AI text for recorded Brave responses, snapshots untouched.
**Depends on:** master plan decisions 1–4, 13, 14. No dependency on tracks 1/2 (roadmap §3.1: S1 may run in W1).
**Unlocks:** every later sprint — S2 (Docling) and S3 (search providers) add adapters to registries built here.
**Repos:** `synaplan/` only. **Class:** `backend-only` for every step.
**Flag:** none. `BCONFIG` group `PLUGS` is seeded with values that reproduce today's order and thresholds (§4.2); no UI.

---

## 0. Why this sprint exists

Roadmap principle 4: a refactor sprint always precedes a feature sprint on the same seam. `FileProcessor`
hard-codes seven strategies and `BraveSearchService` is injected into six classes; adding Docling or SearXNG on
top would touch every caller twice. This sprint moves the seam once, proves it with fixtures, stays byte-identical (C1).

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/AI/Service/ProviderRegistry.php` | The registry shape to copy: eight `#[AutowireIterator('app.ai.*')]` iterators, `getAvailableProviders()`, `getProvidersMetadata()` |
| `backend/src/AI/Interface/ProviderMetadataInterface.php` | `getStatus()` / `isAvailable()` — reuse the shape for `PlugHealth` |
| `backend/config/services.yaml` lines 321–484, 1218–1243 | How `app.ai.*` tags are attached; `TIKA_MIN_LENGTH` / `TIKA_MIN_ENTROPY` params (lines 142–143, 1102–1103); `BRAVE_SEARCH_API_KEY` (line 573) |
| `backend/src/Service/File/FileProcessor.php`, `Message/MessagePreProcessor.php` line 547 | `extractText()` → `extractStructured()` → `extractAfterOfficePrep()`: the strategy order that becomes the default chain (§2.3); the second direct Tika caller |
| `backend/src/Service/File/TikaClient.php`, `TextCleaner.php` | `extractText(path, mime): array`, `isEnabled()` — wrapped by `TikaExtractor`; `isLowQuality(text, minLength, minEntropy)` is today's quality gate |
| `Office/OfficeConverterClient.php`, `PdfRasterizer.php`, `HeicConverter.php`, `VideoAnalysisService.php`, `backend/src/Service/WhisperService.php` | Helpers that become steps inside the chain |
| `backend/src/Service/Search/BraveSearchService.php` | `isEnabled()`, `search(query, options): array`, `formatResultsForAI(array): string` — the contract the adapter reproduces |
| `MessageProcessor.php` (373, 446, 861, 922), `WebSearchRunner.php`, `AI/Messages/Tools/WebSearchTool.php`, `FeedbackExampleService.php` (1064–1075), `SelfAware/PlatformCapabilityInventory.php` (197) | All six `BraveSearchService` callers |
| `backend/src/Service/Message/WebSearchTopicPolicy.php`, `SearchQueryGenerator.php` | Decide *whether* and *what* to search — **not touched** (C3) |
| `backend/src/Seed/BConfigSeeder.php` | `insertIfMissing(Connection, label, rows)` — the seeder helper for `PLUGS` |
| `backend/tests/Characterization/__snapshots__/` | `routing_classification.json`, `utterance_plans.json`, `planner_system_prompt.txt` must not drift |
| `tests/Unit/Service/File/FileProcessor{AudioFallback,ImageOcr,Video}Test.php`, `tests/Unit/Service/Search/BraveSearchServiceTest.php` | Existing behavior tests that stay green unchanged |

---

## 2. Developer steps

### 2.1 Ports and DTOs (`PL1`)

New namespace `App\Plug\` (`backend/src/Plug/`): pure PHP, no Symfony imports, `final readonly` DTOs. `App\Service\RAG\VectorStorage\DTO\SearchQuery` already exists, so the web search query is `WebSearchQuery`.

```php
// App\Plug\Extraction — every port also has key(), descriptor(): PlugDescriptor, health(): PlugHealth
interface ContentExtractorInterface
{
    public function key(): string;                                   // 'tika', 'vision', 'stt_cloud', …
    public function descriptor(): PlugDescriptor;                    // label, docsUrl, requiredSettings, sovereignty
    public function supports(ExtractionRequest $r): bool;
    public function extract(ExtractionRequest $r): ExtractionResult; // text, ?markdown, pages, strategy, meta
    public function health(): PlugHealth;                            // available, reason, checkedAt
}
// App\Plug\WebSearch
interface WebSearchProviderInterface
{
    public function capabilities(): WebSearchCapabilities;          // freshness, country, language, siteFilter, fullContent, answer
    public function search(WebSearchQuery $q): SearchResultSet;     // results[] + meta[]
}
// App\Plug\Rerank
interface RerankProviderInterface
{
    /** @param list<RerankCandidate> $candidates */
    public function rerank(string $query, array $candidates, int $topK, RerankOptions $o): RerankResult;
}
```

`ExtractionRequest` = `{absolutePath, relativePath, mime, ext, userId, describe, family}`; `ExtractionResult` =
`{text, markdown?, strategy, meta[], pages?}` plus `ExtractionResult::rewrite(newPath, ext)` for preparer steps
(office convert). `SearchResultSet::toLegacyArray()` / `::formatForAi()` reproduce today's `BraveSearchService`
output exactly. The rerank port gets no adapter here; it exists so S4 adds only adapters.

### 2.2 Registries (`PL2`)

`ExtractionRegistry`, `WebSearchRegistry`, `RerankRegistry` in `App\Plug\`, each `#[AutowireIterator('app.plug.extractor' |
'app.plug.web_search' | 'app.plug.rerank')]`. Tagging via `_instanceof` in `services.yaml`, so adapters (core or plugin)
need no manual tag. API: `all()`, `byKey(string)`, `descriptors()`, plus `ExtractionRegistry::chain(string $family)` (ordered
adapters from config; unknown keys logged and skipped), `WebSearchRegistry::active(?int $userId)` / `fallback()`, and
`RerankRegistry::active()` (`null` while `RERANK.ENABLED=0`).

### 2.3 `PlugConfigService` and `PlugsConfigSeeder` (`PL3`)

`App\Plug\PlugConfigService` reads `BCONFIG` group `PLUGS` (owner `0`; owner `userId` only for `WEB_SEARCH.PROVIDER`, master plan §12.2). Seeder rows via `BConfigSeeder::insertIfMissing()` — bootstrap-only:

| Setting | Seeded value | Reproduces |
| ------- | ------------ | ---------- |
| `EXTRACTION.CHAIN.text` | `native` | Strategy 1 (`PLAIN_TEXT_MIMES`) |
| `EXTRACTION.CHAIN.document` | `structured_office,office_convert,tika,pdf_vision` | `extractStructured()` → legacy convert → Tika (incl. office→PDF→Tika retry) → rasterize + vision |
| `EXTRACTION.CHAIN.image` | `vision` | `extractFromImage()` (HEIC transcode inside the adapter) |
| `EXTRACTION.CHAIN.audio` | `stt_cloud,whisper_local` | `extractFromAudio()` when `AiFacade::hasConfiguredSttProvider()` |
| `EXTRACTION.CHAIN.audio_no_cloud` | `whisper_local,stt_cloud` | Same method without a configured STT provider — the one dynamic order today |
| `EXTRACTION.CHAIN.video` | `video_analysis` | `extractFromVideo()` |
| `EXTRACTION.QUALITY.min_length` / `min_entropy` | `TIKA_MIN_LENGTH` / `TIKA_MIN_ENTROPY` read once at first seed (`10` / `3.0`) | `TextCleaner::isLowQuality()` gate on PDFs |
| `WEB_SEARCH.PROVIDER` / `WEB_SEARCH.FALLBACK` | `brave` / `` | Only provider today, no fallback |
| `RERANK.ENABLED` / `CANDIDATES_MULTIPLIER` / `LATENCY_BUDGET_MS` | `0` / `4` / `800` | No rerank today |

### 2.4 Extraction adapters wrapping existing code (`PL4`)

`backend/src/Plug/Extraction/Adapter/`: `NativeTextExtractor` (`native`), `StructuredOfficeExtractor`
(`structured_office`, wraps `StructuredTextExtractor`), `OfficeConvertStep` (`office_convert`, wraps
`OfficeConverterClient`, returns `ExtractionResult::rewrite()`), `TikaExtractor` (`tika`; `health()` =
`isEnabled()` + `/version` probe), `PdfVisionExtractor` (`pdf_vision`, `PdfRasterizer` + `AiFacade` vision),
`VisionExtractor` (`vision`, `HeicConverter` + `AiFacade`), `SttExtractor` (`stt_cloud`, `AiFacade::transcribe`),
`WhisperLocalExtractor` (`whisper_local`, `WhisperService`), `VideoExtractor` (`video_analysis`,
`VideoAnalysisService`). Bodies are moved, not rewritten; log lines and `meta.strategy` strings (`native_text`,
`structured_office`, `tika`, `tika_office_pdf`, `rasterize_vision`, `office_pdf_vision`, …) stay identical.

### 2.5 `FileProcessor` becomes the chain runner (`PL5`)

New `App\Plug\Extraction\ExtractionChainRunner` resolves the family from mime/ext, walks `ExtractionRegistry::chain()`,
applies the quality gate (`TextCleaner::isLowQuality()` with `PlugConfigService` values; PDFs only, as today), returns
the first passing result and deletes converted temp files. `FileProcessor` keeps `extractText(string, string, ?int, bool): array`
and delegates. `MessagePreProcessor` line 547 switches to `ExtractionRegistry::byKey('tika')`; the `SystemConfigService`
Tika test (line 816) calls `TikaExtractor::health()`.

### 2.6 `BraveSearchAdapter` and callers (`PL6`)

`App\Plug\WebSearch\Adapter\BraveSearchAdapter` (`brave`, sovereignty `US cloud`) wraps `BraveSearchService`, which
becomes `@internal`. All six callers switch to `WebSearchRegistry::active($userId)`: `isEnabled()` → `active()?->health()->available`,
`search()` → `search()->toLegacyArray()`, `formatResultsForAI()` → `formatForAi()`. `WhatsAppService` only consumes arrays; untouched.

### 2.7 Golden corpus, recorded fixtures, boundary rule (`PL7`, `PL8`)

`backend/tests/Fixtures/extraction/` (new; siblings today: `Desktop/`, `ai/`, `openai-compatible/`, `selfaware/`):
20 files (text PDF, scanned PDF, docx, xlsx, pptx, doc, odt, rtf, csv, md, html, png, jpg, heic, mp3, m4a, mp4, …),
`expected/<name>.txt`, `manifest.json` `{file, mime, strategy, sha256}`, and `recorded/` Tika, vision and STT
responses. `ExtractionGoldenCorpusTest` runs in CI on recorded responses; a `@group live-tika` variant hits the
compose `tika` service in dev. `backend/tests/Fixtures/web_search/brave/*.json` + `BraveSearchAdapterContractTest`
(legacy array and AI text byte-equal). `PL8`: `tests/Unit/Plug/PlugBoundaryTest.php` greps `src/` for
`use App\Service\File\TikaClient` and `use App\Service\Search\BraveSearchService` outside `src/Plug/**` and the
classes themselves — the C4 check without a new PHPStan extension (adding one is "ask first").

---

## 3. Tests and invariants

| Invariant | Proof in this sprint |
| --------- | -------------------- |
| C1 | `ExtractionGoldenCorpusTest` (text and `meta.strategy` equal to `expected/`), `BraveSearchAdapterContractTest` |
| C2 | `PlugsConfigSeederTest`: fresh seed produces exactly the §2.3 table; `PlugConfigServiceTest`: env read once, UI value wins |
| C3 | Unfiltered characterization suite; `WebSearchTopicPolicy` and `SearchQueryGenerator` have no diff |
| C4 | `PlugBoundaryTest`; `AiFacade` public methods unchanged (reflection test) |
| C7 | `TIKA_BASE_URL` unset → `TikaExtractor::health()` unavailable → document chain falls through to `pdf_vision`, upload succeeds |
| C8 | Existing `/v1` gateway, widget and mobile suites; `node scripts/mobile-impact.mjs` → `backend-only`; `FileProcessor*Test`, `BraveSearchServiceTest` unchanged |

---

## 4. Exit criteria / demo

1. Upload the corpus before and after: identical text and strategy per file.
2. A chat that triggers web search returns the same sources; no code outside `src/Plug/` imports Tika or Brave.
3. Snapshots unchanged; `make lint && make -C backend phpstan && make test` green; no UI change, no new env var, no migration.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| PL1 | `refactor(plugs): add extraction, web search and rerank ports with DTOs` | backend-only | — |
| PL2 | `refactor(plugs): add tagged registries for the three plug ports` | backend-only | PL1 |
| PL3 | `feat(plugs): add PlugConfigService and idempotent PLUGS seeder with today's defaults` | backend-only | PL2 |
| PL4 | `refactor(files): wrap Tika, vision, STT and helpers as extraction adapters` | backend-only | PL2 |
| PL5 | `refactor(files): run FileProcessor through ExtractionChainRunner` | backend-only | PL3, PL4 |
| PL6 | `refactor(search): route web search callers through WebSearchRegistry and BraveSearchAdapter` | backend-only | PL3 |
| PL7 | `test(plugs): add golden extraction corpus and recorded Brave fixtures` | backend-only | PL5, PL6 |
| PL8 | `test(plugs): enforce plug boundary for Tika and Brave imports` | backend-only | PL6 |

# Sprint S2 — Docling

**Track 3 (AI Plugs), sprint 2 of 6.** Steps `PL9`–`PL15`.

**Goal:** A `DoclingExtractor` can be put in front of Tika in the document chain; PDFs with tables come out as markdown,
are chunked heading-aware, and the table row is retrievable. Sidecar down or not started: Tika wins as before, upload never fails.
**Depends on:** S1 (`PL1`–`PL8`): `ExtractionRegistry`, `ExtractionChainRunner`, `PlugConfigService`, golden corpus.
**Unlocks:** the Extraction tab is the first tab of the renamed AI infrastructure page; S3 and S4 add theirs beside it.
**Repos:** `synaplan/` (backend, frontend, `docker-compose.yml`), `synaplan-docs/` (one pointer), `synaplan-platform/` (service block, private).
**Flag:** `PLUGS.EXTRACTION.CHAIN.document` — Docling runs only when an admin adds `docling` to the chain; fresh installs keep the S1 default.

---

## 0. Why this sprint exists

Tika returns a flat text stream: tables become word soup, reading order is lost on two-column PDFs, scanned pages are
empty. Docling produces markdown with headings, tables and OCR. The RAG gain needs two things together: the extractor
and a chunker that understands markdown. Both land here, both opt-in, and the S1 corpus proves nothing changes when off.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Plug/Extraction/ExtractionChainRunner.php` (S1) | Where the gate and the chain walk live; Docling is one more adapter |
| `backend/src/Plug/Extraction/Adapter/TikaExtractor.php` (S1), `backend/src/Service/File/TikaClient.php` | HTTP adapter shape to copy (timeouts, retries, `TIKA_TIMEOUT_MS`, `health()`) |
| `backend/src/Service/File/TextChunker.php` | `chunkify(string): array` of `['content','start_line','end_line']` — the shape the markdown path must keep |
| `backend/src/Service/File/VectorizationService.php` line 110 | The single `chunkify()` call site |
| `backend/src/Service/File/TextCleaner.php` | `isLowQuality()` becomes one rule inside `ExtractionQualityGate` |
| `docker-compose.yml` lines 746–773 (`tika`), 902–919 (`collabora`, profile `office`, `mem_limit`), 929–943 (`tts`) | Sidecar block pattern: pinned digest, profile, healthcheck, no host port unless needed |
| `backend/src/Controller/AdminOpenAiEndpointsController.php` | Admin controller + OpenAPI style; `/test` endpoint pattern |
| `frontend/src/views/ProviderSetupView.vue` (175 lines), `frontend/tests/unit/views/ProviderSetupView.spec.ts` | Page to rename and split into tabs |
| `frontend/src/components/TabNav.vue`, `frontend/src/components/admin/ProviderKeyCard.vue` | Tab component and card style to reuse |
| `frontend/src/composables/useNavItems.ts` line 361, `router/index.ts` line 493 | Nav label `nav.adminProviderSetup`, route `/admin/setup` |
| `docs/RAG.md`, `docs/CONFIGURATION.md`, `docs/DEVELOPMENT.md` | Docs to extend |

---

## 2. Developer steps

### 2.1 `DoclingClient` and `DoclingExtractor` (`PL9`)

`App\Plug\Extraction\Docling\DoclingClient` — thin HTTP client against docling-serve; `App\Plug\Extraction\Adapter\DoclingExtractor`
(`key = docling`, sovereignty `self-hosted`). Contract (pin the image whose paths match; one recorded response per corpus file in `tests/Fixtures/extraction/recorded/docling/`):

```text
GET  {DOCLING_BASE_URL}/health                       → 200 {"status":"ok"}
POST {DOCLING_BASE_URL}/v1/convert/file              multipart: files=<binary>
     form fields: to_formats=md, to_formats=text, do_ocr=true, image_export_mode=placeholder, table_mode=accurate
     → 200 {"status":"success","document":{"md_content":"…","text_content":"…"},"timings":{…}}
```

`supports()`: family `document` (PDF, docx, pptx, xlsx, html), and images when the admin adds `docling` to the image
chain; size ≤ `DOCLING.MAX_BYTES` (default 50 MB). `extract()` returns `text = text_content`, `markdown = md_content`,
`strategy = docling`, `meta.pages`. Settings (`PLUGS` group, env bootstrap-only): `DOCLING.BASE_URL` ← `DOCLING_BASE_URL`,
`DOCLING.TIMEOUT_MS` (default `120000`). `health()` caches the `/health` probe 30 s like `ChatReadinessService`. Connection
refused, timeout, 5xx → `PlugHealth::unavailable(reason)`; the runner logs at `info` and continues with the next adapter (C7).

### 2.2 `ExtractionQualityGate` (`PL10`)

`App\Plug\Extraction\ExtractionQualityGate::verdict(ExtractionResult, ExtractionRequest): GateVerdict` replaces the inline
`TextCleaner::isLowQuality()` call in the runner. Rules: non-empty text; for families in `EXTRACTION.QUALITY.apply_to`
(seeded `pdf` — today the gate runs on PDFs only, C2) `min_length` / `min_entropy`; a markdown result additionally passes
when it contains a table or heading even if entropy is low (table-heavy pages are legitimately repetitive). Verdicts land
in `meta.gate` so the admin test button can show why an adapter lost.

### 2.3 Markdown-aware chunking (`PL11`)

`TextChunker::chunkifyMarkdown(string $markdown): array` — same return shape as `chunkify()`. Splits at headings (`#`–`###`),
prefixes each chunk with its heading breadcrumb (`Report › Q3 › Revenue by region`), never splits inside a table
(contiguous `|` rows with a separator line move as one block; a table larger than `maxChunkSize` is split by rows with
the header row repeated), keeps `maxChunkSize` / `overlapSize` / `minChunkSize`. `VectorizationService::vectorizeAndStore()`
gains `?string $markdown = null` and calls `chunkifyMarkdown()` when set; `FileProcessor` passes `ExtractionResult::markdown`
through `meta.markdown`. No markdown → old path, byte-identical chunks (`TextChunkerMarkdownTest` asserts both).

### 2.4 Compose profile `docling` (`PL12` — ask first, recorded here)

House rule: Docker changes are "ask first". The ask is recorded in this file and in `STATUS.md`; the PR links both.

```yaml
  # Optional Docling document extraction — https://github.com/docling-project/docling-serve
  # Not started by default. Enable with:  docker compose --profile docling up -d
  docling:
    image: ghcr.io/docling-project/docling-serve:<version>@sha256:<digest>
    container_name: synaplan-docling
    profiles: [docling]
    restart: unless-stopped
    environment:
      DOCLING_SERVE_ENABLE_UI: "0"
    healthcheck:
      test: ["CMD", "python", "-c", "import urllib.request;urllib.request.urlopen('http://127.0.0.1:5001/health')"]
      interval: 30s
      timeout: 10s
      retries: 5
      start_period: 120s
    networks:
      - synaplan-network
```

`backend` and `worker` get `DOCLING_BASE_URL: http://docling:5001`. Memory: the CPU image needs 3–4 GB during OCR; no
`mem_limit` in dev (the Cursor Cloud sandbox cannot start services with one — see `frontend-widgets` in `AGENTS.md`);
`synaplan-platform` sets the limit and documents the GPU variant. `backend/.env.example` gains `DOCLING_BASE_URL=` (empty = off).

### 2.5 Admin API for the Extraction tab (`PL13`)

`AdminPlugsExtractionController` (`#[Route('/api/v1/admin/plugs/extraction')]`, admin only), full OpenAPI, then `make -C frontend generate-schemas`:

```text
GET  /api/v1/admin/plugs/extraction            → { adapters: [{key,label,docsUrl,sovereignty,health:{available,reason}}], chains: {family: [key,…]}, quality: {…} }
PUT  /api/v1/admin/plugs/extraction/chains     { chains: {document: ["docling","tika","pdf_vision"], …} }   → 200 same shape; unknown key → 422
POST /api/v1/admin/plugs/extraction/test       multipart file → { winner, strategy, attempts: [{key, verdict, ms}], preview: "<first 2000 chars>", markdown: bool }
```

Writes go through `PlugConfigService::setChain()` (owner `0`); the runner reads config per request, so the next upload uses the new chain — no restart.

### 2.6 Rename and tabs: AI infrastructure, Extraction tab (`PL14`)

`ProviderSetupView.vue` keeps route `/admin/setup` and `data-testid="view-admin-setup"`, gains `TabNav` with tabs `models`
(today's content moved to `components/admin/plugs/ModelsAndKeysTab.vue`), `extraction` (this sprint), `web-search` and
`rerank` (hidden until S3/S4 land). New `components/admin/plugs/ExtractionPlugTab.vue` (< 300 lines: chain list per family
with up/down and remove, adapter health pills, "Test with a file" upload showing winner, attempts and preview) and
`services/api/adminPlugsApi.ts` on the generated Zod schemas. i18n: `nav.adminProviderSetup` and `adminSetup.title` →
*AI infrastructure* / *KI-Infrastruktur* / *Infraestructura de IA* / *Infrastructure IA* / *AI altyapısı*; new namespace
`aiInfra.extraction.*` in all five locales; `localeParityBaseline.json` untouched. Dark, V2 and 320 px checked.
`ProviderSetupView.spec.ts` extended, not replaced.

### 2.7 Docs (`PL15`)

`docs/RAG.md`: extraction chain, markdown chunking, when Docling helps. `docs/CONFIGURATION.md`: `PLUGS` group table, `DOCLING_BASE_URL`,
profile `docling`, memory sizing. `docs/DEVELOPMENT.md`: starting the profile. `synaplan-docs/docs/administration.md`: section "AI infrastructure" linking to the in-repo docs (separate PR).

---

## 3. Tests and invariants

| Invariant | Proof in this sprint |
| --------- | -------------------- |
| C1 | `ExtractionGoldenCorpusTest` unchanged and green with the S1 default chain (Docling not in chain) |
| C2 | `PlugsConfigSeederTest`: `EXTRACTION.QUALITY.apply_to = pdf`, no new chain entries by default |
| C4 | `PlugBoundaryTest` extended: `DoclingClient` imported only from `src/Plug/**` |
| C7 | `DoclingExtractorHealthTest`: `DOCLING.BASE_URL` empty / refused / timeout → unavailable; chain `docling,tika,pdf_vision` yields `strategy = tika`; no exception reaches `FileProcessor` |
| C8 | Mobile-impact: backend steps `backend-only`, `PL14` `ota-candidate`; `/v1` suites untouched |

Also: `DoclingExtractorContractTest` on recorded responses (markdown and text returned, tables detected); `TextChunkerMarkdownTest`
(breadcrumb, table kept whole, header row repeated, plain path unchanged); `ExtractionQualityGateTest` (today's PDF verdicts identical
to `isLowQuality()`); `AdminPlugsExtractionControllerTest` (admin-only, 422 on unknown adapter, attempts returned); `ExtractionPlugTab.spec.ts`; i18n parity.

---

## 4. Exit criteria / demo

1. `docker compose --profile docling up -d`; admin moves `docling` to the top of the document chain; uploads a PDF with a revenue table; asks for one cell's value; the answer cites the table row (top 3 chunk).
2. `docker compose stop docling`; the same upload succeeds with `strategy = tika`; the Extraction tab shows Docling unavailable with the reason.
3. Fresh install without the profile: corpus test identical to S1; page renamed, Models & keys tab behaves like today's page.
4. Full gate green; snapshots untouched; docs merged.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| PL9 | `feat(plugs): add DoclingExtractor with recorded docling-serve fixtures` | backend-only | PL5, PL7 |
| PL10 | `refactor(plugs): generalize Tika thresholds into ExtractionQualityGate` | backend-only | PL5 |
| PL11 | `feat(rag): add markdown-aware chunking for extractors that return markdown` | backend-only | PL9 |
| PL12 | `chore(compose): add opt-in docling sidecar profile` | backend-only | PL9 |
| PL13 | `feat(plugs): add admin API for extraction chains, health and test extraction` | backend-only | PL9, PL10 |
| PL14 | `feat(admin): rename Provider setup to AI infrastructure and add Extraction tab` | ota-candidate | PL13 |
| PL15 | `docs(rag): document extraction chains, Docling sidecar and markdown chunking` | backend-only | PL12, PL14 |

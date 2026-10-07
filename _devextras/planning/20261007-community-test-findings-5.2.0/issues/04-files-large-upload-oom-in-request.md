<!-- title: Files: a 78 MB CSV exhausts PHP memory while being indexed inside the upload request; every failed attempt leaves a duplicate -->
<!-- type: Bug -->
<!-- labels: prio:1, area:files, area:semantic-search -->
<!-- issue-type: Bug -->

## Problem
Uploading a 78.2 MB CSV (under the 128 MB limit) fails with a generic "failed to load" and HTTP 500. The file is stored and text extraction starts, then chunking runs inside the same upload request and `TextChunker.php` exhausts PHP's 512 MB memory limit. Each failed attempt still leaves the file in the Library, so the user sees duplicates of a file whose upload reported failure.

---

## Expected
A file under the size limit uploads, is listed once, and is made searchable in the background with bounded memory. If indexing cannot finish, the one stored copy is marked "Not searchable" with a one-sentence reason and a retry action; the upload itself never reports failure for a file that was stored.

## Actual
1. 78.2 MB CSV → "failed to load", HTTP 500, twice.
2. Backend log: `OutOfMemoryError` in `TextChunker.php:63`, called from `VectorizationService::vectorizeAndStore` via `FileUploadService::uploadBatch`.
3. Two Library rows for the same file after two attempts.
4. A 20,000-row, 5.2 MB slice of the same file uploads fine.

---

## Steps to reproduce
1. Take any CSV of about 70–80 MB (a public dataset slice works).
2. Library → Upload → pick the file.
3. Watch the backend log; refresh the Library.

---

## Notes
- Findings: F46 — community test round on 5.2.0. The unrelated Ollama log noise from the same report is a separate tiny issue (models: skip listing Ollama models without a base URL).
- Verified in code: `FileUploadService::uploadBatch()` → private `vectorize()` → `VectorizationService::vectorizeAndStore()` runs in the request for Library uploads (`backend/src/Service/File/FileUploadService.php`). `TextChunker::chunk()` (`backend/src/Service/File/TextChunker.php`) materialises every line into `$segments` and then every chunk into `$chunks`, so peak memory grows with file size. An async path exists: `FileUploadService::processFile()` is documented "used for async processing after fast upload" and already handles the `extracting` / `vectorizing` statuses.
- Limits: `FileStorageService::MAX_FILE_SIZE = 128 MB` (`backend/src/Service/File/FileStorageService.php`); PHP memory_limit 512 MB in the production image.

Fix direction (in this order, each independently useful):
1. Library uploads store + return immediately with status `queued`, and dispatch indexing to the worker (`processFile`), the way "fast upload" already does. The row shows a progress state; the Library legend explains it.
2. `TextChunker` becomes a generator over the extracted text (yield chunks, never hold the whole segment list); `vectorizeAndStore` embeds in batches.
3. A cap for the indexed portion (configurable, e.g. first N MB or N chunks) with the file marked "Partly indexed (first X MB)" rather than failing.
4. On any indexing exception: keep the single stored copy, set status `error` with a user-readable reason, offer "Retry indexing"; never create a second row for a retried upload of the same bytes (hash check).

Journey (U10): upload 78 MB CSV → row appears once, "Indexing…" → later "Searchable" (or "Not searchable: too large to index, first 20 MB indexed") → search finds a row from the file → delete → gone.

Verification:
1. 78 MB CSV: no 500, one Library row, worker log shows chunked batches, peak memory well under the limit (log the peak in debug).
2. Killing the worker mid-way leaves the row in a terminal state after the reaper, never "Indexing…" forever (U8).
3. `make -C backend test` covers the generator chunker with a synthetic 50 MB string.

---

## Screenshots/Logs
`PHP Fatal error: Allowed memory size of 536870912 bytes exhausted … TextChunker.php on line 63` (from the report; path and line as of 5.2.0).

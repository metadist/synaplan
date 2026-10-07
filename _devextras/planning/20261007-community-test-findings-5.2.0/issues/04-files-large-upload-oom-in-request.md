<!-- title: Files: a 78 MB CSV exhausts PHP memory while being indexed inside the upload request; every failed attempt leaves a duplicate -->
<!-- type: Bug -->
<!-- labels: prio:1, area:files, area:semantic-search -->
<!-- status: shipped -->
<!-- issue-type: Bug -->

> **Shipped** in [#2379](https://github.com/metadist/synaplan/pull/2379) (`c97e79144`). Do not re-implement. The sections below describe the 5.2.0 bug.

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
- Verified in code: the Library page uploads with `processLevel: 'vectorize'` (`frontend/src/views/FilesView.vue`), so `FileUploadService::uploadBatch()` runs `vectorize()` → `VectorizationService::vectorizeAndStore()` inside that PHP request. `TextChunker::chunk()` holds every line and every chunk. The file picker uses `processLevel: 'store'` (`FileSelectionModal.vue`) and does not hit this path.
- `FileUploadService::processFile()` is **not** a background job. `POST /api/v1/files/{id}/process` (`FileController::processFile`) calls it in the request. Moving the Library button from `vectorize` to `store` + `process` only moves the same OOM into the second request. There is no file-index message in `backend/src/Message/` today (`ReVectorizeMessage` is a different job). A worker dispatch has to be added, or the chunker has to stay under `memory_limit` inside the request. Do both: bounded memory first, then a real queue so the upload returns.
- Limits: `FileStorageService::MAX_FILE_SIZE = 128 MB`; PHP `memory_limit` 512 MB in the production image.
- `recordFileAnalysisOnce` already stops a retry of the same file id from writing a second usage row (#887). Do not add a content-hash dedupe: two different files may share bytes, and the upload API already has overwrite by `(group_key, original_name)`.

Fix direction:
1. Library upload (`FilesView`) stores and returns one row. Indexing runs where a 78 MB extract cannot exhaust the web request. Chat attachments and `process_level=store` callers stay as they are.
2. `TextChunker` must not hold the whole file as one string of segments plus the whole chunk list. Keep chunk size, overlap, and line numbers identical on a small fixture — a generator that drops overlap changes search results with no visible error.
3. Do not ship a silent "index the first 20 MB" default. If a cap is added, it is off unless an admin turns it on, and the row says "Partly indexed" in the status the citation issue will show.
4. On failure: one stored row, status `error`, one sentence, "Retry indexing". A second click must not insert a second row for that upload.

Journey (U10): upload 78 MB CSV on the Library page → one row, "Indexing…" → later "Searchable" → search finds a row from the file → delete → gone. If indexing fails, the same row says why and offers "Retry indexing".

Verification:
1. 78 MB CSV through the Library page: no 500, one row, peak PHP memory logged and under the limit. A 5 MB CSV still indexes with the same chunk size and overlap as before (fixture comparison).
2. Stopping the indexer mid-way leaves a terminal status, never "Indexing…" with no way out (U8). Chat attach and `process_level=store` responses are unchanged.
3. `make -C backend test` covers the chunker on a large string without allocating the whole chunk list, and a retry of the same file id does not insert a second row.

---

## Screenshots/Logs
`PHP Fatal error: Allowed memory size of 536870912 bytes exhausted … TextChunker.php on line 63` (from the report; path and line as of 5.2.0).

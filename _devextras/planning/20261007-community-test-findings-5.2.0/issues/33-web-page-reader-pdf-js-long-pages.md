<!-- title: Web page reader: PDF links are not read, JavaScript pages come back empty, long articles are cut without headings — run documents through the Library extractor, use the configured Firecrawl for rendering, and show how much was read -->
<!-- type: Feature -->
<!-- labels: prio:2, area:routing -->
<!-- issue-type: Feature -->

## Summary
The built-in "Reading web page" step handles three cases it currently refuses honestly: a link to a PDF or Office document (run it through the same extraction the Library uses — Tika or Docling), a page rendered by JavaScript (use the configured Firecrawl or another renderer when the plain fetch yields no body text), and a long article (raise or chunk the limit, keep section headings, and tell the person how much of the page was read).

---

## Problem / Motivation
Five tests, each with a known right answer: a plain page was read correctly; a public test PDF was not ("I could not read the PDF; it was not returned as readable text") although the Library extracts the same PDF; a JavaScript-rendered page returned no quotes (honestly); a 127,500-character Wikipedia article came back incomplete without section headings; a private address was refused with a clear reason. Each run used 20,000–23,000 tokens and 7–19 s. The answers never invented content — that honesty must stay (F49). Open WebUI's Attach Webpage read the PDF and the JavaScript page (with a source chip) but rejects long pages and localhost with the same generic error.

---

## Goal
"Read this URL" works for the documents and pages people actually paste, and the step says what it read ("Read 18,000 of 127,500 characters, 3 of 11 sections") so the answer can be judged.

---

## Acceptance criteria
- [ ] `Content-Type` of `application/pdf` or Office types → the body is handed to the Library's extraction plug (Tika / Docling) and the Markdown result is used; the step label says "Reading PDF".
- [ ] A fetched HTML page with (almost) no body text and `<script>` content → one retry through the configured Firecrawl (or the renderer plug) when the admin has one; otherwise the honest "JavaScript rendering is required" stays.
- [ ] Long pages: keep headings as Markdown, read up to a configurable limit (default well above 4,000 characters for a direct read), and report coverage in the step; the model is told the text is partial.
- [ ] Private / loopback addresses stay refused with the existing clear reason; the refusal is distinguishable from a fetch failure.
- [ ] Token use per read is shown in message details.

---

## Notes
- Findings: F49 — community test round on 5.2.0.
- Verified in code: `backend/src/Service/UrlContentService.php` — `MAX_TEXT_LENGTH = 4000` (direct read), `MAX_CRAWL_TEXT_LENGTH = 50000`, `MAX_RESPONSE_SIZE = 5 MB`; the runner is `backend/src/Service/Multitask/Execution/Runner/UrlFetchRunner.php`; Firecrawl client at `backend/src/Plug/WebSearch/Client/FirecrawlClient.php`; the extraction plug is the one the Library uses (`FileUploadService` extraction path, Docling / Tika plugs).
- Journey (U10): paste the w3.org dummy PDF link → "Reading PDF" → answer "Dummy PDF file"; paste quotes.toscrape.com/js with Firecrawl configured → quotes answered; paste the Formula One article → answer lists the section headings and the step shows coverage.

---

## Screenshots/Logs
Answers (5.2.0): "I could not read the PDF; it was not returned as readable text"; "The fetched content is incomplete and does not include the article's section headings".

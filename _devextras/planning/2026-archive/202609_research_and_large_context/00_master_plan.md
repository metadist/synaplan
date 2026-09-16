# Web research quality & large-attachment context fitting

Status: implemented on branch `fix/web-research-and-context-fitting` (Sept 2026).
Two production bug reports drove this work; both are fixed here with a shared
building block (question-aware condensation) and a handful of BCONFIG flags.

## 1. Bug reports

1. **Web fetch / link research is poor.**
   - A German research question ("VAE wollen 40 Mrd. € in Deutschland
     investieren — welche Sektoren/Unternehmen?") showed *Web Search · 10*
     and the model still answered "I cannot confirm the figure".
   - A pasted LinkedIn shortlink (`https://lnkd.in/p/…`) showed
     *Web Search · 10* and the model said it cannot reliably open the link.
2. **A 250k-character XLSX sent to GPT-6 Astra produced a short or weird
   answer.** The attachment blew the request past what the pipeline could
   carry sensibly; the routing model (gpt-oss-120b, 131k) got the full
   text as well.

## 2. Root causes (with code references)

### Research

| # | Cause | Where |
| - | ----- | ----- |
| R1 | Brave returns **snippets only**; no result page was ever fetched, so the model only saw teasers. | `SearchResultSet::formatForAi()`, `ChatHandler::formatSearchResultsForPrompt()` |
| R2 | Pasted URLs were fetched **only** for prompts with `tool_url_screenshot` or Saved-Task reruns. | `MessageProcessor::maybeFetchUrlContent()` (old gate) |
| R3 | Even when fetched, the **streaming** chat path never appended `classification['url_content']` (only `handle()` did). | `ChatHandler::handleStream()` |
| R4 | The fetcher used the HTTP client's redirect following (redirect targets not SSRF-checked), respected robots.txt/noindex for a one-off user read, and did not resolve `lnkd.in`-style interstitials (200 + "This link will take you to…"). LinkedIn's `999` bot block and auth walls came back as generic failures. | `UrlContentService::fetch()` / `fetchForCrawling()` |
| R5 | For a bare link the web-search query was generated from the URL string itself. | `MessageProcessor` step 2.5 → `SearchQueryGenerator::generate()` |
| R6 | In the DAG path the read page only reached the model through a planner-placed `url_fetch` node; the planner prompt told it *not* to emit one for a bare link. | `PromptCatalog::planPrompt()` rule 9b, `ChatRunner` |

### Large attachments

| # | Cause | Where |
| - | ----- | ----- |
| C1 | `BFILETEXT` (the full extracted text) went into the SORT/PLAN prompt for gpt-oss-120b. | `MessageClassifier::buildMessageData()`, `TaskPlanner::buildCurrentMessageJson()` |
| C2 | The XLSX extractor kept the first 500 rows of every sheet; no statistics for the rest, so "analyze this" could not be answered for a 20k-row sheet. | `StructuredTextExtractor::extractSpreadsheet()` |
| C3 | `FileAnalysisHandler` sent the whole document text with a fixed small `max_tokens`; no relation to the catalog's `context_window` / `max_output`. `ChatHandler` truncated attachments to 10k chars blindly. | `FileAnalysisHandler`, `ChatHandler::buildCurrentMessageContent()` |
| C4 | A provider `context_length_exceeded` was reported as a generic failure. | `ChatFailureClassifier` (existed) — nobody retried |

## 3. Design

### 3.1 Shared building block: `App\Service\Context`

```
TokenEstimator        chars↔tokens with prose/table/CJK density
ContextWindowSpec     {contextTokens, maxOutputTokens, source, modelId}
ModelContextWindow    forModel(id) from BMODELS.BJSON.meta.{context_window,max_output}
                      attachmentCharBudget(modelId, sample, userId, reservedOutput)
                      answerOutputTokens(modelId, floor, ceiling)
ContextChunker        split(text, maxChars) on headings → lines, repeats table headers
ContextCondenser      fit(text, question, budgetChars, userId, onProgress) : CondensedText
                      — the STACKED LOOP: chunk → condense each chunk WITH the user's
                        question as lens → join → repeat up to MAX_LEVELS while still
                        over budget → fall back to head/tail trim with a provenance note
AttachmentDigest      forRouting(text) : header + sections + first table columns +
                      beginning/end — what the SORT/PLAN model sees instead of BFILETEXT
```

`CondensedText::provenanceNote()` tells the answer model honestly that it saw a
condensed version (how many characters, how many rounds) — it never pretends
the full file was in context.

Condenser model = `DEFAULTMODEL ANALYZE → SORT → CHAT`. Every call is recorded as
usage action `CONDENSE`.

### 3.2 Large attachments (bug 2)

1. **Routing digest** (C1): `MessageClassifier` and `TaskPlanner` pass
   `AttachmentDigest::forRoutingWithConfig()` (`CONTEXT.ROUTING_FULL_TEXT_MAX_CHARS`
   = 12 000 pass-through, else a ~1 800-char digest).
2. **Spreadsheet profile** (C2): every sheet with more rows than the sampled
   table gets a `### Profile` block computed over **all** rows: row/column
   counts, per-column type, min/max/sum/mean for numeric columns, distinct
   counts + top values for text columns. The sampled table follows.
3. **Fit to the model window** (C3): `FileAnalysisHandler` and `ChatHandler`
   resolve the answer model first, compute
   `budget = (context − reservedOutput) × CONTEXT.INPUT_SHARE_PERCENT` and run
   `ContextCondenser::fit(text, question, budget)`. Output tokens scale with
   the catalog (`answerOutputTokens`, 4 000–16 000) so a 1M-context model
   answers with a real analysis, not a paragraph.
4. **Overflow retry** (C4): a `ContextLengthExceeded` / `RequestTooLarge`
   failure retries once with the text halved (`trimmed()`).

### 3.3 Web research (bug 1)

1. **Reader mode** (R4): `UrlContentService::fetchForReading()` — hop-by-hop
   redirects with an SSRF check on **every** hop, shortlink interstitials
   (`lnkd.in`, `l.facebook.com`, …) and `<meta http-equiv="refresh">` resolved,
   login walls / `999` / `403` / `429` reported as `blockedReason`
   (`login_wall`, `bot_blocked`), no robots.txt for a one-off user read,
   `finalUrl` preserved for citation. `fetchManyForReading()` batches.
2. **`WebResearchService`**
   - `deepen(searchResults, question, userId)`: picks the top *K* host-diverse
     result URLs (skips LinkedIn/Facebook/X/YouTube and file URLs), reads
     them (25 s wall-clock budget), condenses each with the question as lens
     into its share of `WEB_SEARCH.READ_PAGES_BUDGET_CHARS`, and attaches
     `page_content`, `fetched`, `final_url`, `blocked_reason` to the result
     rows; adds `pages_read` / `pages_attempted`. Citation numbers stay stable.
   - `readMentionedUrls(urls, question, userId)`: reads pasted links and
     builds a **"Linked Pages"** prompt block that says *NOT READ + why*
     for walled pages instead of letting the model guess.
3. **Pipeline order** (`MessageProcessor`, both `processStream()` and `process()`):
   - **2.4 read pasted links** (before search). A message that is *only* a
     link whose page was read drops the classifier's search vote — the page
     is the source. Explicit `/search` or prompt `tool_internet=true` still
     search. The read page becomes the `attachmentContext` for
     `SearchQueryGenerator`, so a thin message with a link searches for the
     article's topic, not the URL (R5).
   - **2.5 search** unchanged → `search_complete` (sources render immediately).
   - **2.6 deepen** only when the sorter's `BREADPAGES` vote is 2 or 3
     (or the field was omitted on a running search — fallback 2). A vote
     of 0 leaves snippets only. Pasted URLs already read skip this step.
4. **Formatters** show page content: `SearchResultSet::formatForAi()`,
   `ChatHandler::formatSearchResultsForPrompt()` (with an instruction to use
   page content as authoritative evidence and to say what is missing rather
   than hedge), `MessageProcessor::formatSearchResultsForClient()` and
   `StreamController::formatSearchResultsForSse()` add `fetched` / `final_url`.
5. **Streaming fix** (R3): `ChatHandler::handleStream()` appends
   `classification['url_content']` like `handle()` does.
6. **DAG path** (R6): `WebSearchRunner` deepens fresh searches too
   (`params.read_pages: false` opts a node out); `UrlFetchRunner` uses reader
   mode for plain reads (crawl mode stays for `compare`/URL-watch snapshots);
   `ChatRunner` appends the pre-read linked pages when the plan has no
   `url_fetch` node; planner rule 9b now treats a bare link as "read this".
7. **UI**: statuses `fetching_urls`, `urls_fetched`, `reading_pages`,
   `pages_read` in the thinking indicator; the Web Search badge shows
   `· 10 · 4 read`; `webSearch.pagesRead` persisted as meta
   `web_search_pages_read` and returned by `MessageApiFormatter`.

## 4. Configuration (BCONFIG, code defaults — no migration needed)

| Group | Key | Default | Meaning |
| ----- | --- | ------- | ------- |
| `CONTEXT` | `CONDENSE_ENABLED` | `1` | Stacked condensation on/off (off ⇒ head/tail trim) |
| `CONTEXT` | `INPUT_SHARE_PERCENT` | `55` | Share of the model window an attachment may use |
| `CONTEXT` | `MAX_LEVELS` | `3` | Max condensation rounds |
| `CONTEXT` | `CONDENSER_CHUNK_TOKENS` | `24000` | Chunk size for the condenser model |
| `CONTEXT` | `ROUTING_FULL_TEXT_MAX_CHARS` | `12000` | Attachment text passed verbatim to SORT/PLAN |
| `CONTEXT` | `ROUTING_DIGEST_CHARS` | `1800` | Digest size above that |
| `PLUGS` | `WEB_SEARCH.READ_PAGES_ENABLED` | `1` | Read result pages after a search |
| `PLUGS` | `WEB_SEARCH.READ_PAGES_MAX` | `3` (max 8) | Ceiling; the sorter votes 0 / 2 / 3 per turn |
| `PLUGS` | `WEB_SEARCH.READ_PAGES_BUDGET_CHARS` | `28000` | Evidence budget per search |
| `PLUGS` | `URL_READ.ENABLED` | `1` | Read links pasted into the chat |
| `PLUGS` | `URL_READ.MAX` | `3` (max 6) | Links per message |

Per-user rows override global rows (owner `0`) for the `CONTEXT` group.

## 5. Acceptance

- Pasting `https://lnkd.in/p/…` alone: the interstitial is resolved, the
  article is read, no web search is fired, the answer summarizes the article
  and cites the resolved URL. A LinkedIn post behind the auth wall yields
  "I could not open this link — it requires a LinkedIn login" plus the
  public preview if any.
- A research question: 10 sources appear within seconds; the badge then
  shows `· 4 read`; the answer quotes figures from the page bodies with
  `[n]` citations instead of "I cannot confirm".
- 250k-char XLSX → GPT-6 Astra: SORT/PLAN receive a ~2k digest; the answer
  model receives the profile + a question-condensed table within its budget
  and answers with up to 16k output tokens; the response starts with no
  "[Note…]" when the text fit verbatim, or an honest provenance note when it
  was condensed.
- Nothing outbound bypasses `SsrfGuard`; a redirect to a private address is
  refused before the request is made (unit-tested).

## 6. Tests

- `tests/Unit/Service/Context/*` — estimator, chunker, condenser loop
  (multi-round, stalled round, disabled), digest, window budget.
- `tests/Unit/Service/UrlContentServiceReadingTest.php` — redirects hop by
  hop, SSRF on redirect, loop, `lnkd.in` interstitial, meta refresh, 999,
  auth wall + preview, binary, JSON/plain, SPA no-text, batch dedupe/limit.
- `tests/Unit/Service/Research/WebResearchServiceTest.php` — host diversity,
  skip lists, budget split, blocked pages, prompt block wording, limits.
- `tests/Unit/MessageProcessorTest.php` — link-only message answered from the
  page (no search), research question deepened with `pages_read` after
  `search_complete`.
- `tests/Unit/Service/Message/Handler/FileAnalysisHandlerContextFitTest.php`,
  `StructuredTextExtractorTest` (profile), `TaskPlannerTest` (digest).
- Characterization snapshot `planner_system_prompt.txt` re-recorded for the
  `web_search` descriptor and rule 9b wording only.

## 7. Follow-ups (not in this change)

- Concurrent page reads (Symfony HttpClient streams) to cut the 4-page
  latency; today reads are sequential inside a 25 s wall-clock budget.
- PDF result pages (`unsupported_content` today) via the existing document
  extractors.
- Short-lived cache of read pages per URL to serve "Again" and follow-up
  questions without re-fetching.
- Expose `page_content` in the Sources dropdown (hover/expand) — the SSE
  already carries `fetched` / `final_url`.
- Admin UI for the `CONTEXT.*` and `URL_READ.*` flags (today BCONFIG only).

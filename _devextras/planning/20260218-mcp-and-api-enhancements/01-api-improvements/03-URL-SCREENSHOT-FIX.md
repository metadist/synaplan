# 03 — URL Screenshot: Audit & Fix

## Goal

The URL Screenshot tool should do what it was designed for: *"If the user asks about a specific URL, fetch the URL, extract the text from the page, and include the extracted text in the prompt context."*

For v1, this is **text extraction from URLs** — not visual screenshots. Visual screenshots require a headless browser and can be added in v2.

## Current State: Broken / Not Implemented

| Layer | Status | Details |
|-------|--------|---------|
| **Frontend UI toggle** | Commented out | Was togglable in `TaskPromptsConfiguration.vue`, now commented out (no backend) |
| **Frontend metadata** | Exists | Saves `tool_url_screenshot` in prompt metadata |
| **Backend defaults** | Naming mismatch | `PromptService.php` uses `tool_screenshot`, frontend uses `tool_url_screenshot` |
| **Backend processing** | TODO | `MessageProcessor.php` line 243: `// TODO: Add similar logic for url_screenshot` |
| **Screenshot service** | Missing | No URL fetching or text extraction service |
| **Frontend display** | Incomplete | `MessageScreenshot.vue` exists but only renders an `<img>` — no text-only mode |

### Naming Mismatch Detail

```
Frontend: tool_url_screenshot  ← correct (descriptive)
Backend:  tool_screenshot      ← wrong (vague), also in WordPressIntegrationService
```

## What Changes

### Step 3.1 — Fix Tool Naming

Standardize on `tool_url_screenshot` everywhere.

**Files to change:**
- `backend/src/Service/PromptService.php` — rename `tool_screenshot` → `tool_url_screenshot` in defaults (line 69)
- `backend/src/Service/WordPressIntegrationService.php` — rename `tool_screenshot` → `tool_url_screenshot` (line 247)
- Verify `MessageProcessor.php` TODO comment references match

**Test:** Default metadata includes `tool_url_screenshot`. No references to `tool_screenshot` remain in backend/src/.

### Step 3.2 — URL Fetch & Text Extraction Service

For v1, use `symfony/http-client` to fetch URL content and extract text from HTML. No headless browser needed.

Create: `backend/src/Service/UrlContentService.php`

```php
final readonly class UrlContentService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    public function fetch(string $url): UrlContentResult;
    public function fetchMultiple(array $urls, int $maxUrls = 3): array;
}

final readonly class UrlContentResult
{
    public function __construct(
        public string $url,
        public string $extractedText,
        public string $title,
        public string $hostname,
        public bool $success,
        public ?string $error = null,
    ) {}
}
```

**Implementation details:**
- Use `symfony/http-client` with 5s timeout, 5MB max response size
- Strip HTML tags, extract `<title>`, main content (heuristic: `<main>`, `<article>`, or `<body>`)
- Truncate extracted text to ~4000 chars (avoid token bloat)
- SSRF protection: reject private IPs (127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)

**Test:** Service fetches URL, extracts text. Invalid URLs handled gracefully. Timeout on slow sites. SSRF blocked.

### Step 3.3 — URL Detection in Messages

**Where:** `UrlContentService.php` (static helper or in the service)

Detect URLs in user messages:

```php
public function extractUrls(string $message): array
{
    preg_match_all('/https?:\/\/[^\s<>"{}|\\^`\[\]]+/i', $message, $matches);
    return array_unique($matches[0]);
}
```

**Test:** Extracts URLs from various message formats. Ignores non-URLs. Handles edge cases (trailing punctuation, parentheses).

### Step 3.4 — Integration into MessageProcessor

**Where:** `MessageProcessor.php`

Add URL content extraction step after Web Search, before inference:

```php
// Step 4: URL Content Extraction
if ($promptMetadata['tool_url_screenshot'] ?? false) {
    $urls = $this->urlContentService->extractUrls($userMessage);
    if (!empty($urls)) {
        $this->notify($statusCallback, 'fetching_urls', 'Fetching URL content...');
        $urlResults = $this->urlContentService->fetchMultiple($urls);
        // Format and inject into prompt context (same pattern as search results)
        $this->notify($statusCallback, 'urls_fetched', sprintf('Extracted content from %d URLs', count($urlResults)));
    }
}
```

**Files to change:**
- `backend/src/Service/Message/MessageProcessor.php` — add step, wire `UrlContentService` via DI
- `backend/src/Service/Message/Handler/ChatHandler.php` — include URL content in prompt context

**Test:** URL in message + tool enabled = URL content in prompt. Tool disabled = no fetch. Bad URL = graceful skip.

### Step 3.5 — Re-enable Frontend Toggle

Uncomment the URL Screenshot option in `TaskPromptsConfiguration.vue` now that the backend works.

**Files to change:**
- `frontend/src/components/config/TaskPromptsConfiguration.vue` — uncomment the tool option

**Test:** Toggle appears in UI, saves metadata correctly.

### Step 3.6 — Frontend Display (Text-Only Mode)

Rewrite `MessageScreenshot.vue` to handle text-only URL content (v1 has no image):

**Expected data structure from backend:**
```json
{
  "type": "url_content",
  "url": "https://example.com",
  "title": "Example Domain",
  "hostname": "example.com",
  "extractedText": "This domain is for use in illustrative examples..."
}
```

The component should show:
- URL hostname badge with link
- Page title
- Collapsible extracted text preview

**Files to change:**
- `frontend/src/components/MessageScreenshot.vue` — rewrite for text-only mode with optional image
- `frontend/src/components/MessagePart.vue` — verify routing
- `frontend/src/stores/history.ts` — verify type definition

**Test:** URL content part renders with text. Handles missing image gracefully. Collapses long text.

## Implementation Order

```
3.1 (naming) → 3.2 (service) → 3.3 (detection) → 3.4 (pipeline) → 3.5 (toggle) → 3.6 (display)
```

## Security

- URL fetching: 5s timeout, 5MB max response, no private IPs (SSRF protection)
- Sanitize extracted text before prompt injection
- Rate limit: max 3 URLs per message
- Set a reasonable User-Agent header (identify as Synaplan bot)

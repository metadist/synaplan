# 03 — URL Screenshot: Audit & Fix

## Goal

The URL Screenshot tool should do what it was designed for: *"If the user asks about a specific URL, take a screenshot, extract the text from the image, and include both the image and the text in the prompt result."*

## Current State: Broken / Not Implemented

### What Exists

| Layer | Status | Details |
|-------|--------|---------|
| **Frontend UI** | Exists | `MessageScreenshot.vue` displays screenshots; `TaskPromptsConfiguration.vue` has toggle |
| **Frontend metadata** | Exists | Saves `tool_url_screenshot` in prompt metadata |
| **Backend defaults** | Naming mismatch | `PromptService.php` uses `tool_screenshot`, frontend uses `tool_url_screenshot` |
| **Backend processing** | TODO | `MessageProcessor.php` line 243: `// TODO: Add similar logic for url_screenshot` |
| **Screenshot service** | Missing | No Puppeteer, Browsershot, or headless browser service |
| **Text extraction** | Missing | No OCR or vision-based text reading from screenshots |
| **Docker service** | Missing | No headless browser container |

### Naming Mismatch Detail

```
Frontend: tool_url_screenshot  ← correct (descriptive)
Backend:  tool_screenshot      ← wrong (vague)
```

## What Changes

### Step 3.1 — Fix Tool Naming

Standardize on `tool_url_screenshot` everywhere.

**Files to change:**
- `backend/src/Service/PromptService.php` — rename `tool_screenshot` → `tool_url_screenshot`
- Verify `MessageProcessor.php` references match

**Test:** Default metadata includes `tool_url_screenshot`. No references to `tool_screenshot` remain.

### Step 3.2 — Add Headless Browser Service (Docker)

Add a lightweight screenshot service to Docker Compose.

Option A: **Browsershot** (PHP, uses Puppeteer/Chrome under the hood)
Option B: **Dedicated screenshot microservice** (e.g., `screenshotone/local` or custom Node.js)
Option C: **Use existing vision AI** — just fetch the URL HTML and send to AI for analysis

**Recommendation: Option C for v1** — simplest, no new Docker service needed:
1. Fetch URL content via `symfony/http-client`
2. Extract text from HTML (strip tags, get meaningful content)
3. If the user specifically wants a visual screenshot, use a lightweight tool like `chrome-php/chrome` or an external screenshot API

For v1, focus on **text extraction from URLs** (the primary use case). Visual screenshots can be v2.

**Files to create:**
- `backend/src/Service/UrlScreenshotService.php`

```php
final readonly class UrlScreenshotService
{
    public function capture(string $url): UrlCaptureResult;
}

final readonly class UrlCaptureResult
{
    public function __construct(
        public string $url,
        public string $extractedText,
        public ?string $screenshotPath,  // null for v1 text-only
        public string $title,
        public string $hostname,
    ) {}
}
```

**Test:** Service fetches URL, extracts text. Invalid URLs handled gracefully. Timeout on slow sites.

### Step 3.3 — URL Detection in Messages

**Where:** `MessageProcessor.php` or `MessagePreProcessor.php`

Detect URLs in user messages when `tool_url_screenshot` is enabled:

```php
private function extractUrls(string $message): array
{
    preg_match_all(
        '/https?:\/\/[^\s<>"{}|\\^`\[\]]+/i',
        $message,
        $matches
    );
    return array_unique($matches[0]);
}
```

**Test:** Extracts URLs from various message formats. Ignores non-URLs.

### Step 3.4 — Integration into MessageProcessor

**Where:** `MessageProcessor.php`

Add URL Screenshot step between File Search and MCP Enrichment:

```php
// Step 4.5: URL Screenshot
if ($promptMetadata['tool_url_screenshot'] ?? false) {
    $urls = $this->extractUrls($userMessage);
    if (!empty($urls)) {
        $urlResults = $this->urlScreenshotService->captureMultiple($urls);
        // Format and inject into prompt context
    }
}
```

**SSE Status Events:**
- `capturing_url` → shows which URL is being captured
- `url_captured` → shows success/failure

**Files to change:**
- `backend/src/Service/Message/MessageProcessor.php`
- Wire `UrlScreenshotService` via DI

**Test:** URL in message + tool enabled = URL content in prompt. Tool disabled = no capture. Bad URL = graceful skip.

### Step 3.5 — Frontend Display

The `MessageScreenshot.vue` component already exists. Ensure it receives data in the correct format from the SSE stream.

**Expected data structure from backend:**
```json
{
  "type": "screenshot",
  "url": "https://example.com",
  "title": "Example Domain",
  "imageUrl": null,
  "extractedText": "This domain is for use in..."
}
```

**Files to verify/change:**
- `frontend/src/components/MessageScreenshot.vue` — handle `imageUrl: null` (text-only mode)
- `frontend/src/components/MessagePart.vue` — verify routing
- `frontend/src/stores/history.ts` — verify type definition

**Test:** Screenshot part renders with text content. Handles missing image gracefully.

## Implementation Order

```
3.1 (naming) → 3.2 (service) → 3.3 (detection) → 3.4 (pipeline) → 3.5 (display)
```

## Security

- URL fetching: timeout (5s), max response size (5MB), no private IPs (SSRF protection)
- Sanitize extracted text before prompt injection
- Rate limit URL captures per message (max 3 URLs)

# 05 — Enrichment UI & Logging in Chat GUI

## Goal

All enrichment data (Internet Search, File Search, URL Screenshot, MCP results) and the **enriched prompt** are visible in the Chat GUI and backend logs. Users can see exactly what context was added before the model received the prompt.

## Current State

### What Works

- **Internet Search:** Results shown as a collapsible carousel below messages. Status events: `searching` → `search_complete`.
- **File Search:** File badges shown in message header.
- **Processing status:** Real-time SSE status events displayed during streaming (classifying, searching, generating, etc.).
- **Model badges:** Chat model + topic shown in message footer.

### What's Missing

- **MCP results:** No display (doesn't exist yet).
- **URL Screenshot results:** No display.
- **Enriched prompt view:** Users can't see the final prompt that was sent to the model.
- **Backend logging:** Enrichment details are partially logged but not structured for easy debugging.

## What Changes

### Step 5.1 — Unified Enrichment Data Structure

**Where:** Backend DTOs (Core or Plugin?)

If MCP is a plugin, it should return a standard structure that the core understands, or the core needs a generic "EnrichmentResult" DTO.

```php
final readonly class EnrichmentResult
{
    public function __construct(
        public string $source,       // 'web_search', 'file_search', 'url_screenshot', 'mcp'
        public string $label,        // Human-readable: 'Internet Search', 'CRM Lookup', etc.
        public array $items,         // Array of result items
        public float $durationMs,    // How long the enrichment took
        public ?string $serverName,  // For MCP: which server
        public ?string $toolName,    // For MCP: which tool was called
    ) {}
}

final readonly class EnrichmentItem
{
    public function __construct(
        public string $title,
        public string $content,      // The actual enrichment text
        public ?string $url,
        public ?string $thumbnail,
        public array $metadata = [],
    ) {}
}
```

**Files to create:**
- `backend/src/DTO/EnrichmentResult.php`
- `backend/src/DTO/EnrichmentItem.php`

**Test:** DTOs serialize to JSON correctly.

### Step 5.2 — SSE Events for All Enrichment Sources

**Where:** `MessageProcessor.php`, `StreamController.php`

Standardize SSE status events for all enrichment:

| Source | Status Event | Metadata |
|--------|-------------|----------|
| Internet Search | `enriching` | `{ source: 'web_search', label: 'Internet Search' }` |
| File Search | `enriching` | `{ source: 'file_search', label: 'File Search' }` |
| URL Screenshot | `enriching` | `{ source: 'url_screenshot', label: 'URL: example.com' }` |
| MCP | `enriching` | `{ source: 'mcp', label: 'CRM Lookup', server: 'My CRM' }` |
| All complete | `enrichment_complete` | `{ sources: [...], totalItems: 5, totalDurationMs: 1200 }` |

Keep backward compatibility: existing `searching` / `search_complete` events still work.

**Files to change:**
- `backend/src/Service/Message/MessageProcessor.php` — emit unified events
- `frontend/src/views/ChatView.vue` — handle new event types

**Test:** Each enrichment source emits correct SSE events. Frontend processes them.

### Step 5.3 — MCP Results Display in Chat GUI

**Where:** `ChatMessage.vue`

Display MCP results alongside web search results. Reuse the collapsible carousel pattern:

```
┌─────────────────────────────────────────────┐
│  🔍 Internet Search (3 results)        [▼]  │
│  ┌─────┐ ┌─────┐ ┌─────┐                   │
│  │ R1  │ │ R2  │ │ R3  │                   │
│  └─────┘ └─────┘ └─────┘                   │
│                                             │
│  🔌 MCP: My CRM (2 results)           [▼]  │
│  ┌─────────────┐ ┌─────────────┐           │
│  │ Contact: ... │ │ Deal: ...   │           │
│  └─────────────┘ └─────────────┘           │
│                                             │
│  🔗 URL: example.com                  [▼]  │
│  ┌─────────────────────────────┐           │
│  │ Extracted text preview...    │           │
│  └─────────────────────────────┘           │
└─────────────────────────────────────────────┘
```

**Files to change:**
- `frontend/src/components/ChatMessage.vue` — add MCP + URL sections
- New: `frontend/src/components/MessageMcpResults.vue` (or inside plugin `plugins/mcp/frontend/components/`)
- Update: `frontend/src/components/MessageScreenshot.vue` — text-only mode

**Test:** MCP results render with server name and tool info. Collapsible. Handles 0 results gracefully.

### Step 5.4 — Enriched Prompt Viewer (Debug Mode)

**Where:** Chat GUI, new component

Add a "View enriched prompt" toggle/button on assistant messages (visible in a debug/developer mode):

- Collapsible section showing the final prompt sent to the model
- Includes: system prompt, enrichment context, conversation history, user message
- Syntax-highlighted, read-only
- Only visible when user has enabled "Developer Mode" in settings

**Implementation:**

1. Backend: Include enriched prompt in message metadata when debug mode is on
   - Store in `BMESSAGEMETA` with key `enriched_prompt`
   - Only when `BCONFIG` setting `debug_mode` = `true` for the user

2. Frontend: New component `MessageEnrichedPrompt.vue`
   - Collapsible code block
   - Shows each section (system, enrichment, history, user) with labels

**Files to create/change:**
- New: `frontend/src/components/MessageEnrichedPrompt.vue`
- `frontend/src/components/ChatMessage.vue` — add debug section
- `backend/src/Service/Message/Handler/ChatHandler.php` — save enriched prompt to metadata

**Test:** Debug mode on = enriched prompt saved and visible. Debug mode off = not saved (no storage waste).

### Step 5.5 — Backend Structured Logging

**Where:** `MessageProcessor.php`, enrichment services

Log enrichment details in a structured, queryable format:

```php
$this->logger->info('Prompt enrichment complete', [
    'message_id' => $messageId,
    'user_id' => $userId,
    'topic' => $classification['topic'],
    'enrichments' => [
        ['source' => 'web_search', 'items' => 3, 'duration_ms' => 450],
        ['source' => 'mcp', 'server' => 'My CRM', 'tool' => 'search_contacts', 'items' => 2, 'duration_ms' => 800],
    ],
    'total_enrichment_tokens_estimate' => 1200,
]);
```

**Files to change:**
- `backend/src/Service/Message/MessageProcessor.php`
- Each enrichment service should return timing info

**Test:** Log output contains structured enrichment data. Verify with monolog test handler.

## Implementation Order

```
5.1 (DTOs) → 5.2 (SSE events) → 5.3 (MCP display) → 5.4 (debug viewer) → 5.5 (logging)
```

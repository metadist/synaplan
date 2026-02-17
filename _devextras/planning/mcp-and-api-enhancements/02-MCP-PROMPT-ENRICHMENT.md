# 02 — MCP as Prompt Enrichment

## Goal

Users can configure external MCP servers. Before a prompt goes to the AI model, Synaplan calls the configured MCP tools, collects the results, and injects them into the prompt — exactly like Internet Search and File Search work today.

## Current Enrichment Pipeline

```
MessageProcessor.processStream()
    │
    ├── Step 1: Preprocess (files, downloads)
    ├── Step 2: Classify (intent, topic, language)
    ├── Step 3: Web Search (if tool_internet enabled)
    ├── Step 4: File Search / RAG (if tool_files enabled)
    ├── [NEW] Step 5: MCP Enrichment (if tool_mcp enabled)
    │
    └── Step 6: Build enriched prompt → send to model
```

MCP enrichment follows the **same pattern** as Internet Search: it's a pre-inference enrichment step, not a tool call during inference.

## What Changes

### Step 2.1 — MCP Server Configuration (Backend)

**Where:** `BCONFIG` table

Store MCP server configs per user:

| BOWNERID | BGROUP | BSETTING | BVALUE |
|----------|--------|----------|--------|
| 42 | `mcp_servers` | `server_1_name` | `My CRM` |
| 42 | `mcp_servers` | `server_1_url` | `https://crm.example.com/mcp` |
| 42 | `mcp_servers` | `server_1_transport` | `sse` |
| 42 | `mcp_servers` | `server_1_auth_type` | `api_key` |
| 42 | `mcp_servers` | `server_1_auth_ref` | (encrypted ref) |
| 42 | `mcp_servers` | `server_1_enabled` | `1` |
| 42 | `mcp_servers` | `server_1_tools` | `["search_contacts","get_deal"]` |

New service: `backend/src/Service/MCP/McpConfigService.php`
- `getServers(int $userId): array`
- `getServer(int $userId, string $serverId): ?array`
- `saveServer(int $userId, array $config): void`
- `deleteServer(int $userId, string $serverId): void`

**Test:** CRUD operations on MCP server config. Validation rejects invalid URLs.

### Step 2.2 — MCP Client Service

**Where:** New service

Create: `backend/src/Service/MCP/McpClient.php`

Responsibilities:
- Connect to an MCP server via SSE transport
- Discover available tools (`tools/list`)
- Call a tool (`tools/call`) with arguments
- Handle timeouts (10s default, configurable)
- Circuit breaker integration

```php
final readonly class McpClient
{
    public function listTools(array $serverConfig): array;
    public function callTool(array $serverConfig, string $toolName, array $arguments): McpToolResult;
}
```

Use `symfony/http-client` for HTTP/SSE communication. Follow the [MCP spec](https://modelcontextprotocol.io/) JSON-RPC format.

**Security:**
- Block private IP ranges (SSRF protection)
- Strict timeout enforcement
- Response size limit (1MB default)

**Test:** Unit test with mocked HTTP responses. Test timeout handling. Test SSRF blocking.

### Step 2.3 — MCP Tool Registry (Per-User)

**Where:** New service

Create: `backend/src/Service/MCP/McpToolRegistry.php`

On configuration save or periodic refresh:
1. Call `listTools()` on each enabled MCP server
2. Cache the tool list per user (in-memory or short TTL cache)
3. Expose for prompt metadata selection

```php
final readonly class McpToolRegistry
{
    public function getAvailableTools(int $userId): array;
    public function getToolsForServer(int $userId, string $serverId): array;
    public function refreshTools(int $userId): void;
}
```

**Test:** Tools are cached. Refresh updates cache. Disabled servers excluded.

### Step 2.4 — Prompt Metadata: MCP Tool Selection

**Where:** `PromptService.php`, `TaskPromptsConfiguration.vue`

Add to prompt metadata defaults:
```php
'tool_mcp' => false,
'tool_mcp_servers' => [],  // specific server IDs, empty = all enabled
```

Frontend: In the Task Prompts Configuration, add an "MCP Tools" toggle + multi-select for which servers/tools to call.

**Files to change:**
- `backend/src/Service/PromptService.php` — add defaults
- `frontend/src/components/config/TaskPromptsConfiguration.vue` — add MCP section
- i18n: `en.json` + `de.json`

**Test:** Metadata saves and loads with MCP config. Default is off.

### Step 2.5 — MCP Enrichment in MessageProcessor

**Where:** `MessageProcessor.php`

Add MCP enrichment step after File Search, before inference:

```php
// Step 5: MCP Enrichment
if ($promptMetadata['tool_mcp'] ?? false) {
    $mcpResults = $this->mcpEnrichmentService->enrich(
        $userId,
        $userMessage,
        $classification,
        $promptMetadata['tool_mcp_servers'] ?? []
    );
    // Inject results into prompt context
}
```

New service: `backend/src/Service/MCP/McpEnrichmentService.php`
- Determines which MCP tools to call based on prompt metadata + classification
- Calls tools via `McpClient`
- Formats results for prompt injection (similar to `BraveSearchService::formatResultsForAI()`)
- Returns structured results for UI display

**SSE Status Events:**
- `mcp_calling` → shows which server/tool is being called
- `mcp_complete` → shows result count

**Files to change:**
- `backend/src/Service/Message/MessageProcessor.php` — add Step 5
- New: `backend/src/Service/MCP/McpEnrichmentService.php`

**Test:** MCP results injected into prompt. Disabled MCP = no calls. Failed MCP = graceful degradation (log, continue without).

### Step 2.6 — MCP Results Storage

**Where:** New or reuse `BSEARCHRESULTS`

Store MCP results alongside search results for history/replay:

Option A: Add `source` column to `BSEARCHRESULTS` (`web_search` | `mcp`)
Option B: New `BMCP_RESULTS` table

Prefer **Option A** — less schema change, same display pattern.

**Test:** Results persisted and retrievable by message ID.

## Implementation Order

```
2.1 (config) → 2.2 (client) → 2.3 (registry) → 2.4 (metadata) → 2.5 (pipeline) → 2.6 (storage)
```

## MCP Transport Notes

For v1, support **SSE transport only** (remote servers). Stdio transport (local processes) is a future enhancement for self-hosted setups.

The MCP JSON-RPC protocol over SSE:
1. Client opens SSE connection to server's `/sse` endpoint
2. Server sends an `endpoint` event with the message URL
3. Client POSTs JSON-RPC messages to that URL
4. Server sends responses via SSE

Use `symfony/http-client` with SSE support for this.

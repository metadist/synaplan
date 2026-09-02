# 02 — MCP Client: Enrichment (Pull)

## Goal

Enable users to configure external MCP servers, discover available tools, and map specific tools to task prompts. When a user chats with a mapped prompt, Synaplan calls the MCP tools, collects the results, and injects them into the AI context.

## Current State

Currently, Synaplan has:
- **Internet Search:** `tool_internet` (Brave Search)
- **File Search:** `tool_files` (Vector Search)
- **URL Screenshot:** `tool_url_screenshot` (Planned)

We are adding:
- **MCP Enrichment:** `tool_mcp` (Generic MCP Client)

## What Changes

### Step 2.1 — MCP Server Configuration (Backend)

**Where:** `plugins/mcp/backend/Controller/McpConfigController.php`

Store MCP server configs per user in `plugin_data` (type: `server`).

- **Fields:** Name, URL, Transport (SSE only for v1), Auth Type (API Key, Bearer Token, None), Auth Token (Encrypted), Enabled.
- **Validation:** Check URL format, prevent localhost unless allowed.

**Test:** CRUD operations on MCP server config. Validation rejects invalid URLs.

### Step 2.2 — MCP Client Service

**Where:** `plugins/mcp/backend/Service/McpClient.php`

Responsibilities:
- Connect to an MCP server via SSE transport.
- Discover available tools (`tools/list`).
- Call a tool (`tools/call`) with arguments.
- Handle timeouts (10s default, configurable).
- Circuit breaker integration.

```php
final readonly class McpClient
{
    public function listTools(array $serverConfig): array;
    public function callTool(array $serverConfig, string $toolName, array $arguments): McpToolResult;
}
```

Use `symfony/http-client` for HTTP/SSE communication. Follow the [MCP spec](https://modelcontextprotocol.io/) JSON-RPC format.

**Test:** Unit test with mocked HTTP responses. Test timeout handling. Test SSRF blocking.

### Step 2.3 — MCP Tool Registry (Per-User)

**Where:** `plugins/mcp/backend/Service/McpToolRegistry.php`

On configuration save or periodic refresh:
1. Call `listTools()` on each enabled MCP server.
2. Cache the tool list per user (in-memory or short TTL cache).
3. Expose for prompt metadata selection.

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

**Where:** `PromptService.php`, `plugins/mcp/frontend/components/McpPromptMapper.vue`

Add to prompt metadata defaults:
```php
'tool_mcp' => false,
'tool_mcp_servers' => [],  // specific server IDs, empty = all enabled
```

Frontend: In the Task Prompts Configuration (or a dedicated MCP Prompt Mapper UI), allow selecting which servers/tools to enable for a prompt.

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

New service: `plugins/mcp/backend/Service/McpEnrichmentService.php`
- Determines which MCP tools to call based on prompt metadata + classification.
- Calls tools via `McpClient`.
- Formats results for prompt injection (similar to `BraveSearchService::formatResultsForAI()`).
- Returns structured results for UI display.

**SSE Status Events:**
- `mcp_calling` → shows which server/tool is being called
- `mcp_complete` → shows result count

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

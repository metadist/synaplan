# 06 — BONUS: Expose Synaplan API as MCP Services

## Goal

External MCP clients (Cursor, Claude Desktop, custom agents) can discover and call Synaplan tools via the MCP protocol. Synaplan becomes a **first-class MCP server**.

This builds on the existing plan in `_devextras/planning/mcp-integration-plan.md` (Step 1) and provides the concrete implementation breakdown.

## Current State

- OpenAPI spec at `/api/doc.json` documents all endpoints
- API key auth via `X-API-Key` header works
- SSE streaming exists for chat (`/api/v1/messages/stream`)
- No MCP server endpoint exists yet

## What Changes

### Step 6.1 — MCP Server Endpoint (SSE Transport)

**Where:** New controller

Create: `backend/src/Controller/McpServerController.php`

Endpoints:
- `GET /api/v1/mcp/sse` — SSE connection endpoint (long-lived)
- `POST /api/v1/mcp/message` — JSON-RPC message endpoint

MCP protocol flow:
1. Client connects to `/api/v1/mcp/sse` with `X-API-Key` header
2. Server sends `endpoint` event with message URL
3. Client POSTs JSON-RPC requests to `/api/v1/mcp/message`
4. Server responds via SSE stream

**Auth:** Reuse `ApiKeyAuthenticator`. Add `mcp:*` scope.

**Files to create:**
- `backend/src/Controller/McpServerController.php`
- `backend/src/Service/MCP/McpServerService.php`

**Test:** SSE connection established. Auth required. Invalid key → 401.

### Step 6.2 — Tool Catalog from OpenAPI

**Where:** New service

Create: `backend/src/Service/MCP/McpToolCatalog.php`

Reads the OpenAPI spec and generates MCP tool definitions:

```php
final readonly class McpToolCatalog
{
    public function getTools(int $userId): array;
    public function getTool(string $name): ?McpToolDefinition;
}
```

Tool allowlist config: `config/mcp_tools.yaml`

```yaml
mcp_tools:
  # Chat
  - operation_id: sendMessage
    name: synaplan_send_message
    description: "Send a message and get AI response"
    
  # Prompts
  - operation_id: listPrompts
    name: synaplan_list_prompts
    description: "List available task prompts"
    
  # RAG
  - operation_id: searchDocuments
    name: synaplan_search_documents
    description: "Search vectorized documents"
    
  # Files
  - operation_id: uploadFile
    name: synaplan_upload_file
    description: "Upload a file for processing"
```

Each tool maps to an OpenAPI operation. Input schema derived from OpenAPI request schema. Output schema derived from OpenAPI response schema.

**Files to create:**
- `backend/src/Service/MCP/McpToolCatalog.php`
- `config/mcp_tools.yaml`
- `backend/src/DTO/McpToolDefinition.php`

**Test:** Catalog returns tools matching allowlist. Tools have valid JSON Schema. Disabled tools excluded.

### Step 6.3 — Tool Invocation Router

**Where:** New service

Create: `backend/src/Service/MCP/McpToolInvoker.php`

Maps MCP `callTool` requests to internal controller actions:

```php
final readonly class McpToolInvoker
{
    public function invoke(string $toolName, array $arguments, int $userId): McpToolResponse;
}
```

The invoker:
1. Looks up the tool in the catalog
2. Validates arguments against the tool's input schema
3. Creates an internal Symfony Request
4. Dispatches to the appropriate controller
5. Captures the response
6. Formats as MCP result

**Files to create:**
- `backend/src/Service/MCP/McpToolInvoker.php`
- `backend/src/DTO/McpToolResponse.php`

**Test:** Valid tool call → correct controller invoked. Invalid arguments → MCP error. Unknown tool → MCP error.

### Step 6.4 — JSON-RPC Message Handler

**Where:** `McpServerService.php`

Handle standard MCP JSON-RPC messages:

| Method | Handler |
|--------|---------|
| `initialize` | Return server info + capabilities |
| `tools/list` | Return `McpToolCatalog.getTools()` |
| `tools/call` | Dispatch to `McpToolInvoker.invoke()` |
| `ping` | Return pong |

```php
public function handleMessage(array $jsonRpc, int $userId): array
{
    return match ($jsonRpc['method']) {
        'initialize' => $this->handleInitialize($jsonRpc),
        'tools/list' => $this->handleToolsList($userId),
        'tools/call' => $this->handleToolCall($jsonRpc, $userId),
        'ping' => ['jsonrpc' => '2.0', 'id' => $jsonRpc['id'], 'result' => []],
        default => $this->errorResponse($jsonRpc['id'], -32601, 'Method not found'),
    };
}
```

**Test:** Each JSON-RPC method returns correct response format. Error handling for malformed requests.

### Step 6.5 — Plugin Tools via Manifest

**Where:** Plugin system

Plugins can expose their tools via `manifest.json`:

```json
{
  "name": "sortx",
  "mcp_tools": [
    {
      "name": "sortx_classify",
      "description": "Classify a document",
      "operation_id": "sortxClassify"
    }
  ]
}
```

The `McpToolCatalog` reads plugin manifests and includes their tools (if the user has the plugin installed).

**Files to change:**
- `backend/src/Service/MCP/McpToolCatalog.php` — scan plugin manifests
- Plugin `manifest.json` — add optional `mcp_tools` section

**Test:** Plugin tool appears in catalog when plugin installed. Hidden when not installed.

### Step 6.6 — Audit Logging

Log every MCP tool call:

```php
$this->logger->info('MCP tool call', [
    'user_id' => $userId,
    'tool' => $toolName,
    'duration_ms' => $duration,
    'status' => 'success',
    'api_key_id' => $apiKeyId,
]);
```

Store in `BUSELOG` for the admin dashboard.

**Test:** Tool calls logged with correct metadata. Failed calls logged with error details.

## Implementation Order

```
6.1 (endpoint) → 6.2 (catalog) → 6.3 (invoker) → 6.4 (handler) → 6.5 (plugins) → 6.6 (audit)
```

## v1 Tool Allowlist (Recommended Starting Set)

| Tool | Type | Risk |
|------|------|------|
| `synaplan_send_message` | Chat | Write |
| `synaplan_list_chats` | Chat | Read |
| `synaplan_list_prompts` | Prompts | Read |
| `synaplan_search_documents` | RAG | Read |
| `synaplan_upload_file` | Files | Write |
| `synaplan_list_files` | Files | Read |
| `synaplan_get_config` | Config | Read |

Start with **read-only tools**, add write tools after audit logging is solid.

## Security

- All calls require valid API key with `mcp:*` scope
- Tools filtered by user permissions (same RBAC as API)
- Rate limiting applies (same as API)
- Audit log for every call
- No tool auto-discovery of admin endpoints

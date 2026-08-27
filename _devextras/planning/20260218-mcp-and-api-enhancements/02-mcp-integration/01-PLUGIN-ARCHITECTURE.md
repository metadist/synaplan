# 01 — MCP Integration: Plugin Architecture

## Goal

Integrate the Model Context Protocol (MCP) as a modular Synaplan plugin. This ensures the core platform remains lean while providing powerful integration capabilities for users who need them.

## Plugin Structure

The plugin will reside in `plugins/mcp/` and follow the standard Synaplan plugin architecture.

```
plugins/mcp/
├── manifest.json              # Plugin metadata & capabilities
├── backend/
│   ├── Controller/
│   │   ├── McpConfigController.php  # Manage servers/tools
│   │   └── McpProxyController.php   # Proxy calls to MCP servers
│   ├── Service/
│   │   ├── McpClient.php            # Core MCP client (SSE transport)
│   │   ├── McpToolRegistry.php      # Discover & cache tools
│   │   └── McpEnrichmentService.php # Inject data into prompts
│   └── Entity/                      # (Optional, if needed beyond plugin_data)
├── frontend/
│   ├── components/
│   │   ├── McpServerList.vue        # Manage servers
│   │   ├── McpToolBrowser.vue       # Browse available tools
│   │   └── McpPromptMapper.vue      # Map tools to prompts
│   └── views/
│       └── McpSettingsView.vue      # Main settings page
└── migrations/                      # DB migrations (if any)
```

## Data Storage

We will use the generic `plugin_data` table via `PluginDataService` to store configuration, avoiding custom tables where possible.

| Data Type | Key Pattern | Description |
|-----------|-------------|-------------|
| `server` | `server_{uuid}` | MCP Server configuration (URL, auth, enabled status) |
| `tool_cache` | `tools_{serverId}` | Cached list of tools for a server (to avoid fetching on every request) |
| `prompt_map` | `map_{promptId}` | Mapping of which tools are enabled for a specific task prompt |

**Example `server` data:**
```json
{
  "name": "My CRM",
  "url": "https://crm.example.com/mcp",
  "transport": "sse",
  "auth_type": "api_key",
  "auth_token": "enc:..."
}
```

## Integration Points

### 1. Prompt Enrichment (Pull)
The plugin needs to hook into the `MessageProcessor`. Since Synaplan doesn't have a full plugin event system yet, we will add a specific hook in `MessageProcessor` that checks for the MCP plugin and calls it if enabled.

**In `MessageProcessor.php`:**
```php
// Step 5: MCP Enrichment
if ($this->pluginService->isPluginEnabled('mcp')) {
    $mcpService = $this->pluginService->getService('mcp', 'McpEnrichmentService');
    $enrichmentData = $mcpService->enrich($message, $promptMetadata);
    // Add to context
}
```

### 2. Action Execution (Push)
This can be triggered:
- **Manually:** User clicks a button in the UI (e.g., "Send to Jira").
- **Automatically:** Post-processing step after AI response (e.g., "If intent=create_ticket, call MCP").

## Security

- **SSRF Protection:** The `McpClient` must block calls to private IP ranges (localhost, 127.0.0.1, 192.168.x.x, etc.) unless explicitly allowed via env var.
- **Auth Storage:** API keys for MCP servers must be encrypted using `EncryptedConfigService`.
- **Timeouts:** Strict timeouts (e.g., 5s connection, 30s read) to prevent hanging the chat.

## Deliverables

1.  **Plugin Skeleton:** Basic file structure and manifest.
2.  **Backend Services:** `McpClient`, `McpToolRegistry`, `McpEnrichmentService`.
3.  **Frontend UI:** Settings page to add servers and view tools.
4.  **Core Hooks:** Integration into `MessageProcessor`.

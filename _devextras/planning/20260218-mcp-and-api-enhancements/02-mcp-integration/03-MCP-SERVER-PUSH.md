# 03 — MCP Server: Push (Action)

## Goal

Enable Synaplan to push data to external MCP servers or trigger actions on them. This turns Synaplan into an active participant in workflows, not just a passive information consumer.

## Use Cases

1.  **Create Jira Ticket:** User asks "Create a ticket for this bug," and Synaplan calls the `jira-mcp` server to create it.
2.  **Update CRM:** User says "Update contact John Doe with new email," and Synaplan calls `crm-mcp`.
3.  **Trigger Workflow:** User says "Deploy to production," and Synaplan calls `deployment-mcp`.

## Architecture

This is distinct from "Enrichment" (Pull). Enrichment happens *before* the AI generates a response. Push/Action happens *during* or *after* the AI response, often requiring confirmation.

### Step 3.1 — Action Definition (Tool Use)

The AI model needs to know *how* to call these actions. We expose MCP tools as function definitions to the AI model (OpenAI Function Calling / Tools).

**Where:** `plugins/mcp/backend/Service/McpToolRegistry.php`

- Convert MCP tool definitions (JSON Schema) into OpenAI-compatible tool definitions.
- Inject these tools into the chat completion request if `tool_mcp_actions` is enabled for the prompt.

```php
// In InferenceRouter or ChatHandler
$tools = [];
if ($promptMetadata['tool_mcp_actions']) {
    $tools = array_merge($tools, $this->mcpToolRegistry->getOpenAiTools($userId));
}
// Pass $tools to AI provider
```

### Step 3.2 — Action Execution (Tool Call)

When the AI model decides to call a tool (e.g., `jira_create_ticket`), the provider returns a `tool_calls` response.

**Where:** `plugins/mcp/backend/Service/McpActionService.php`

- Intercept `tool_calls` from the AI response.
- Map the tool name back to the correct MCP server and tool.
- Execute the tool call via `McpClient`.
- Return the result to the AI model (standard tool use loop).

```php
public function executeAction(string $toolName, array $args, int $userId): array
{
    // 1. Find server for tool
    $server = $this->mcpToolRegistry->findServerForTool($userId, $toolName);
    
    // 2. Call tool
    return $this->mcpClient->callTool($server, $toolName, $args);
}
```

### Step 3.3 — User Confirmation (Human-in-the-Loop)

For sensitive actions (e.g., "Delete Database"), we need user confirmation.

**Where:** Frontend `ChatMessage.vue` + Backend `McpActionService`

- If a tool is marked as `sensitive` (in MCP config or prompt metadata), the backend pauses execution.
- Returns a "Confirmation Required" block to the frontend.
- User clicks "Approve" or "Deny".
- Frontend sends the decision back to resume execution.

**UI:**
- A card showing the proposed action: "Call `jira_create_ticket` with args `{...}`?"
- Buttons: "Approve", "Edit", "Deny".

### Step 3.4 — Result Feedback

The result of the action (e.g., "Ticket JIRA-123 created") is fed back to the AI model, which then generates a final natural language response to the user.

## Implementation Order

```
3.1 (tool definition) → 3.2 (execution loop) → 3.3 (confirmation UI) → 3.4 (feedback loop)
```

## Security

- **Action Whitelisting:** Users must explicitly enable which tools can be called as actions.
- **Confirmation:** Sensitive actions always require confirmation.
- **Audit Log:** Log every action execution (who, what, when, result).

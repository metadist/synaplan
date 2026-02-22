# 04 — UI/UX Design: Managing MCP Complexity

## Goal

Provide a robust interface for managing MCP servers, tools, and their mappings to task prompts. This goes beyond simple toggles and requires a dedicated management area.

## Navigation

Add a new top-level menu item or a prominent section in Settings: **"MCP Integrations"**.

## Views

### 1. MCP Dashboard (Overview)
- **Status:** Overall health of connected servers.
- **Recent Activity:** Log of recent tool calls (Enrichment & Actions).
- **Quick Actions:** "Add Server", "View Tools".

### 2. Server Management (`/mcp/servers`)
- **List:** Card view of configured servers.
  - Name, URL, Status (Online/Offline), Last Sync.
  - Toggle: Enable/Disable.
  - Actions: Edit, Delete, Sync Tools.
- **Add/Edit Modal:**
  - Name, URL (SSE endpoint).
  - Auth Type (API Key, Bearer, None).
  - Auth Token (masked input).
  - Advanced: Headers, Timeout.

### 3. Tool Browser (`/mcp/tools`)
- **List:** Searchable table of all discovered tools across all servers.
- **Columns:** Tool Name, Description, Server, Type (Read/Write - inferred or manual tag).
- **Detail View:** Click a tool to see its schema (arguments, return type).
- **Test Tool:** A "Playground" to manually call a tool with JSON arguments and see the result.

### 4. Prompt Mapping (`/mcp/mapping` or inside Task Prompts)
- **Goal:** Associate specific tools with specific task prompts.
- **UI:**
  - Select a Task Prompt (e.g., "Jira Assistant").
  - **Enrichment (Pull):** Multi-select list of "Read" tools (e.g., `jira_get_issue`).
  - **Actions (Push):** Multi-select list of "Write" tools (e.g., `jira_create_issue`).
  - **Configuration:**
    - "Auto-call" vs "Ask first" (for actions).
    - Parameter presets (e.g., set `project_key` to `PROJ-A` for this prompt).

### 5. Chat Interface Updates
- **Status Indicator:** "Calling MCP..." spinner/toast during enrichment.
- **Result Block:** Collapsible section showing "Used 3 MCP tools" with details (inputs/outputs).
- **Action Card:**
  - "Proposed Action: Create Jira Ticket"
  - Fields: Summary, Description (editable).
  - Buttons: "Approve", "Deny".
- **Error Handling:** Clear error messages if MCP call fails, with "Retry" option.

## Implementation Plan

1.  **Server Management:** Build the CRUD interface first.
2.  **Tool Browser:** Visualize what's available.
3.  **Prompt Mapping:** Connect tools to prompts.
4.  **Chat UI:** Update `ChatMessage.vue` to handle MCP states.

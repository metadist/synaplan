# MCP and API Enhancements (Split Plan)

This plan has been split into two distinct sections to better manage the complexity of the MCP integration and the API improvements.

## Section 1: API Improvements
Focuses on OpenAI compatibility, custom base URLs, per-user API keys, and fixing broken tools.

- [01-api-improvements/00-OVERVIEW.md](./01-api-improvements/00-OVERVIEW.md)
- [01-api-improvements/01-OPENAI-COMPATIBLE.md](./01-api-improvements/01-OPENAI-COMPATIBLE.md)
- [01-api-improvements/02-API-CUSTOM-PROMPTS.md](./01-api-improvements/02-API-CUSTOM-PROMPTS.md)
- [01-api-improvements/03-URL-SCREENSHOT-FIX.md](./01-api-improvements/03-URL-SCREENSHOT-FIX.md)
- [01-api-improvements/04-TEST-STRATEGY.md](./01-api-improvements/04-TEST-STRATEGY.md)

## Section 2: MCP Integration (Plugin Architecture)
Focuses on integrating the Model Context Protocol (MCP) as a full-featured plugin. This includes:
- **Enrichment (Pull):** Fetching external data to enrich prompts.
- **Action (Push):** Pushing results or triggering actions on external MCP servers.
- **Complex UI:** A dedicated interface for managing MCP flows beyond simple task prompts.

- [02-mcp-integration/00-OVERVIEW.md](./02-mcp-integration/00-OVERVIEW.md)
- [02-mcp-integration/01-PLUGIN-ARCHITECTURE.md](./02-mcp-integration/01-PLUGIN-ARCHITECTURE.md)
- [02-mcp-integration/02-MCP-CLIENT-ENRICHMENT.md](./02-mcp-integration/02-MCP-CLIENT-ENRICHMENT.md)
- [02-mcp-integration/03-MCP-SERVER-PUSH.md](./02-mcp-integration/03-MCP-SERVER-PUSH.md)
- [02-mcp-integration/04-UI-UX-DESIGN.md](./02-mcp-integration/04-UI-UX-DESIGN.md)
- [02-mcp-integration/05-ENRICHMENT-UI-LOGGING.md](./02-mcp-integration/05-ENRICHMENT-UI-LOGGING.md)
- [02-mcp-integration/06-TEST-STRATEGY.md](./02-mcp-integration/06-TEST-STRATEGY.md)

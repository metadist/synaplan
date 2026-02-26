# MCP Integration Plugin: Overview

This section details the plan for integrating the Model Context Protocol (MCP) into Synaplan as a full-featured plugin. The integration is designed to be powerful and flexible, supporting both data enrichment (pull) and action execution (push).

## Core Concepts

1.  **Plugin Architecture:** The MCP integration will be built as a Synaplan plugin (`plugins/mcp/`), ensuring it's modular and doesn't clutter the core codebase.
2.  **Two Main Flows:**
    *   **Enrichment (Pull):** Before inference, the system calls MCP tools to fetch data (e.g., "Get latest Jira tickets") and injects it into the prompt context.
    *   **Action (Push):** After inference (or as a standalone action), the system pushes data or triggers actions on an MCP server (e.g., "Create a Jira ticket").
3.  **Complex UI:** A dedicated management interface is required to configure servers, browse tools, and map tools to specific task prompts or workflows.

## Plan Files

- **[01-PLUGIN-ARCHITECTURE.md](./01-PLUGIN-ARCHITECTURE.md):** Defines the plugin structure, database schema, and integration points.
- **[02-MCP-CLIENT-ENRICHMENT.md](./02-MCP-CLIENT-ENRICHMENT.md):** Details the "Pull" flow: configuring servers, discovering tools, and enriching prompts.
- **[03-MCP-SERVER-PUSH.md](./03-MCP-SERVER-PUSH.md):** Details the "Push" flow: exposing Synaplan data to MCP servers or triggering actions.
- **[04-UI-UX-DESIGN.md](./04-UI-UX-DESIGN.md):** Outlines the user interface for managing MCP servers, tools, and prompt mappings.
- **[05-ENRICHMENT-UI-LOGGING.md](./05-ENRICHMENT-UI-LOGGING.md):** Displaying results in the Chat GUI and structured logging.
- **[06-TEST-STRATEGY.md](./06-TEST-STRATEGY.md):** Test matrix and strategy for the MCP plugin.

## Reference

- [00-ORIGINAL-ROADMAP.md](./00-ORIGINAL-ROADMAP.md) — Original 3-step MCP roadmap (Expose → Consume → Orchestrate) that preceded this detailed plan.

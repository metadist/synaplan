# MCP Integration Roadmap (Planning)

## Executive Summary
Synaplan will integrate the **Model Context Protocol (MCP)** to transform from a static knowledge system into a dynamic AI agent platform. This roadmap follows a 3-step evolution:
1.  **Expose (Synaplan as Server)**: Standardize Synaplan's core services and plugins as tools for external AI agents (e.g., Cursor, Claude Desktop).
2.  **Consume (Synaplan as Client)**: Enable Synaplan to call third-party MCP servers (configured per user) to fetch live data or perform external actions.
3.  **Orchestrate (Synaplan as Process Engine)**: Chain internal and external tool calls into automated, multi-step "Synaplan Processes".

---

## 0. Terminology & Roles

To ensure alignment with the [MCP Specification](https://modelcontextprotocol.io/introduction), Synaplan will occupy multiple roles:

| Role | Description in Synaplan Context |
| :--- | :--- |
| **MCP Host** | The environment (e.g., Cursor, a Python script) that *hosts* Synaplan's tools. |
| **MCP Client** | Synaplan acting as the caller of external services (Step 2). |
| **MCP Server** | Synaplan exposing its own tools (Step 1), or an external service providing tools (Step 2). |
| **Transport** | The communication layer. We use **SSE** (Server-Sent Events) for remote connectivity. |

---

## Goals

- **Single source of truth for “commands”**: Synaplan already documents its API via Swagger/OpenAPI; MCP should **mirror** this and not become a second, diverging interface.
- **Services + Plugins as Tools**: MCP must expose both core services and plugin-provided capabilities (the platform already requires OpenAPI annotations for plugin routes).
- **Multi-tenant & secure**: External callers must only access tools allowed for their tenant/user and within rate limits and auditability expectations.
- **Bidirectional**: Synaplan is both:
  - an **MCP server** (others call us)
  - an **MCP client** (we call external MCP servers)
- **Configurable per user account**: external MCP services are configured per user via `BCONFIG` settings.
- **API parity**: Anything that is callable via MCP should also be callable via API (and vice versa), where that makes sense.

## Non-goals (for early phases)

- **General workflow engine** in Step 1/2 (kept for Step 3).
- **Arbitrary tool execution** without explicit allowlisting, permissions, and audit trails.
- **Storing large secrets in `BCONFIG`** (250 char limit in `BVALUE`)—we can store references/ids there and keep secrets elsewhere (exact storage to be decided).

---

## Concepts & mapping to Synaplan

### What MCP adds (in our context)

MCP is a standardized way for LLM apps/hosts to **discover tools** and **invoke them** over a defined transport (commonly **stdio** for local and **SSE** for remote) using JSON-RPC style messaging. It standardizes “tooling” and reduces custom integration per provider/host. See the high-level architecture in the Google Cloud MCP overview.
([Google Cloud: What is MCP?](https://cloud.google.com/discover/what-is-model-context-protocol))

### Our core mapping

- **Synaplan “commands”** = OpenAPI operations already exposed (core + plugins).
- **MCP tools** = a curated subset (or safe wrapper) of those OpenAPI operations.
- **Tool schemas** = derived from OpenAPI schemas (and/or the generated Zod schemas on the frontend).
- **Auth** = reuse existing cookie/bearer token patterns and the existing SSE token mechanism where needed.

### Why this is “complex”

We have **three dimensions** that can interact:

1. **Protocol dimension**: API calls vs MCP calls (both must exist, ideally parity).
2. **Direction dimension**: inbound MCP (Synaplan as server) vs outbound MCP (Synaplan as client).
3. **Extensibility dimension**: core services vs plugins vs external MCP servers.

The 3-step plan below deliberately tackles these dimensions one at a time.

---

## Step 1 — Synaplan as an MCP Server (Inbound)

**Objective**: Expose Synaplan's API "commands" as a standardized tool catalog for external AI clients.

### Outcome
External MCP clients (Hosts) can discover and call Synaplan tools (core services + active plugins) via a single authenticated SSE connection.

### Decisions (Locked)
- **Transport**: **SSE-only** for `web.synaplan.com` (optimized for remote cloud connectivity).
- **Exposure Policy**: **Curated allowlist** (derived from OpenAPI `operationId`).
- **Authentication**: Dual-mode support (**Bearer Header** + **Query Token** `?token=`).

### Technical Flow
1.  **Discovery**: Host requests `/api/v1/mcp/tools`. Synaplan scans the OpenAPI spec, filters by allowlist + user permissions, and returns JSON-RPC tool definitions.
2.  **Invocation**: Host sends a `callTool` request. Synaplan maps this to the internal Controller action, executes it, and returns a standardized MCP response.
3.  **Auditing**: Every call is logged for security and usage tracking.

### Tool Allowlist Model (v1)
- **Core Tools**: Maintained in a server-side allowlist (e.g., `config/mcp_tools.yaml`) referencing stable OpenAPI `operationId`s.
- **Plugin Tools**: Plugins opt-in via their `manifest.json`.
- **User Scoping**: Tools are dynamically filtered based on the user's active session, tenant rights, and enabled plugins.

### v1 Security Checks (Inbound)
Every inbound MCP tool call must pass:
- **Auth**: Valid session/token (reuse existing SSE token pattern).
- **ACL**: RBAC checks matching the underlying API route.
- **Allowlist**: Tool must be explicitly enabled in the global and user-specific allowlist.
- **Validation**: Strict request schema validation based on the OpenAPI specification.

---

## Step 2 — Synaplan as an MCP Client (Outbound)

**Objective**: Enable Synaplan to call third-party MCP servers, configured per user, to extend its capabilities with live external data.

### Outcome
Users connect their own MCP servers (e.g., local database, custom CRM, specialized AI tools) to Synaplan. Results are integrated into Synaplan's assistant, RAG system, or plugins.

### Decision (Locked)
- **External Bridging**: **Proxy Model** with centralized security enforcement. Synaplan acts as a secure gateway, mediating all outbound communication.

### Configuration via `BCONFIG`
External servers are persisted in the `BCONFIG` table:
- `BGROUP`: `MCP_EXT`
- `BSETTING`: `server_{id}.{key}` (e.g., `server_1.url`, `server_1.name`, `server_1.enabled`).
- `BVALUE`: Specific configuration values.
- **Secrets**: Stored as references (`secret_ref`) to avoid plain-text storage in `BCONFIG`.

### v1 Security & Resiliency
Proxying external MCP is powerful but risky. Minimum controls:
- **Egress Filtering**: Block outbound calls to private IP ranges (localhost, 10.*, 192.168.*).
- **Tool Scoping**: Only expose/call explicitly allowed external tools per server.
- **Timeouts**: Strict execution timeouts (e.g., 10s) to prevent resource exhaustion.
- **Circuit Breaker**: Automatically disable external servers that are persistently unreachable or slow.
- **Audit Logging**: Record every external call (server, tool, duration, status) for debugging and auditing.

---

## Step 3 — Composing Calls into Processes (Orchestration)

**Objective**: Chain internal and external tools into automated, multi-step "Synaplan Processes".

### Outcome
Users define repeatable workflows (e.g., "Monthly Lead Sync", "Security Audit") that combine Synaplan services, plugin actions, and external MCP tools.

### Process Lifecycle
1.  **Definition**: A JSON-based DSL defining steps, triggers, and data mapping.
2.  **Execution**: A stateful runner (utilizing Symfony Messenger for background jobs) handles retries and step progression.
3.  **Governance**: Human-in-the-loop (HITL) gates for sensitive "write" actions or high-cost operations.
4.  **Traceability**: A unified log provides a full trace of every process run, including inputs, outputs, and intermediate states.

### Data Flow Model
- **Mapping**: Outputs of previous steps are available as inputs for subsequent steps (e.g., `{{step_1.result.user_id}}`).
- **Persistence**: Final results can be stored in Synaplan (as files, chat history, or database records).

---

## Open Questions & Implementation Details

### Step 1 (Inbound)
- **Tool Naming**: Should we use namespaces like `synaplan.core.search` or just the `operationId`?
- **Version Parity**: Should the MCP tool catalog version exactly mirror the API version (v1, v2, etc.)?

### Step 2 (Outbound)
- **Auth Support**: Which external auth types should we prioritize (API Keys vs. OAuth2)?
- **Schema Normalization**: How strictly should we validate external tool outputs before passing them to the LLM?

### Step 3 (Orchestration)
- **Definition Storage**: Should processes be stored in the database or as versioned files (like plugins)?
- **HITL Mechanism**: How should "Human Approval" gates be presented in the UI/API?

---

## Next Actions (Immediate)

1.  **Define Tool Allowlist**: Identify the first 10–20 read-only OpenAPI operations to expose as tools.
2.  **Auth Implementation**: Implement the dual-mode SSE authenticator (Bearer + `?token=`).
3.  **BCONFIG Naming**: Formalize the `MCP_EXT` setting keys and secret reference logic.


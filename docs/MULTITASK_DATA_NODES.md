# Multitask Data Nodes (release 4.0)

The multitask engine plans a user request into a small DAG of capability
nodes (`backend/src/Service/Multitask/`). Besides the generator/answer nodes
(chat, media, documents, TTS) it has a **data layer**: planner-placed nodes
that pull external information into the turn and hand it downstream as
citable text via `$nX.text`.

Planning docs: `_devextras/planning/release4.0/08_mcp-data-nodes-and-skill-registry.md`
and `09_external-data-nodes.md` (locked decisions).

## The uniform data-node contract

Every data node obeys the same rules (plan 09 §2):

1. **Planner-placed, never speculative** — a node runs only because the plan
   says this request needs it.
2. **Isolated failure** — runners return `NodeResult::failed()` for expected
   failures; the turn still answers honestly. Never hang, never invent data.
3. **Timeout-bounded** — a slow source degrades one node, not the turn.
4. **Read-only in v1** — pull only. No mutating MCP tools, no IMAP flag
   changes (`OP_READONLY`/`FT_PEEK`), no POSTing to arbitrary URLs.
5. **One SSRF guard** — `App\Service\Security\SsrfGuard` for every outbound
   fetch (URL node, MCP client, URL enrichment).
6. **Tenant isolation** — credentials/connections are only ever resolved for
   the requesting user.
7. **Flag-gated** — one `BCONFIG` flag per node; a disabled block is omitted
   from the planner catalog entirely (the planner never learns it exists) and
   the runner re-checks the flag at run time.

## The nodes

| Capability | Runner | Source | Flag (`BCONFIG`) | Default |
| ---------- | ------ | ------ | ---------------- | ------- |
| `web_search` | `WebSearchRunner` | Brave web search | – (provider config) | on |
| `url_fetch` | `UrlFetchRunner` | A specific URL named in the message (`UrlContentService`: robots.txt/noindex compliant) | `MULTITASK.URL_FETCH_ENABLED` | **on** |
| `mcp_fetch` | `McpFetchRunner` | The user's connected external MCP servers (`McpClient`, Streamable HTTP) | `MCP.CLIENT_ENABLED` + `MULTITASK.MCP_FETCH_ENABLED` + per-topic `tool_mcp` prompt metadata (optional `mcp_servers` id allowlist) | **on** (seeded) |
| `email_search` | `EmailSearchRunner` | Live read-only IMAP search over the user's `InboundEmailHandler` accounts | `MULTITASK.EMAIL_SEARCH_ENABLED` | off |
| `rag_query` | `ChatRunner` | User knowledge base (Qdrant) | – | on |

Flags resolve per-user row → global row → built-in default (see
`MultitaskRoutingConfig::isFeatureEnabled`).

The outbound MCP client rolls out via seed rows, not a code default:
`McpConfigSeeder` (`MCP.CLIENT_ENABLED = 1`, `MCP.NODE_TIMEOUT = 15`) and
`MultitaskConfigSeeder` (`MULTITASK.MCP_FETCH_ENABLED = 1`) run inside
`app:seed` on every container start, insert-if-missing — so a deploy
activates the client, while an operator's explicit `0` override survives
every deploy (the kill switch). The built-in code default stays OFF as the
safety net when no row exists and the seeder has not run. Calls still
require a connected server (Channels → MCP Servers) and the per-topic
"MCP Data Sources" opt-in.

## The skill catalog (how the planner learns about blocks)

Each `TaskRunner` declares its capabilities via `describe()` returning
`SkillDescriptor`s; the `SkillCatalog` assembles the planner prompt's
`[CAPABILITYLIST]` from them (catalog-lite, plan 08 §2). Adding a block is
one runner file — no parallel edit of a descriptions array.

**Dynamic blocks** set `requiresDynamicNote`: the capability only appears
when its per-user note expands to something.

- `mcp_fetch` renders the per-user tool sub-catalog (server ids, read-safe
  tools, argument hints) — only when the matched routing topic has
  `tool_mcp = true` ("MCP Data Sources" toggle in AI Instructions).
- `email_search` renders the connected-mailbox note — only when the user has
  an active email account.

No note ⇒ no capability line ⇒ the planner cannot emit (or hallucinate) the
node. The runner re-checks every gate at run time (defense in depth).

## Connections UI

- **Channels → MCP Servers** (`/channels/mcp`): connect external MCP servers
  (Streamable HTTP URL + optional auth header, encrypted at rest), test the
  connection, browse discovered tools.
- **Channels → Email Automation**: the existing IMAP accounts double as the
  `email_search` corpus.
- **AI Instructions → prompt → Available tools → MCP Data Sources**: the
  per-topic opt-in for `mcp_fetch`.

## Testing instruments

- `PlannerPromptCharacterizationTest` — golden snapshot of the fully rendered
  planner prompt. Any prompt/catalog change is an explicit, reviewed diff
  (`UPDATE_ROUTING_SNAPSHOTS=1` to re-record).
- `RoutingCharacterizationTest` — locks the classifier contract.
- `make -C backend plan-eval` (`app:multitask:plan-eval`) — LIVE-model eval
  of the planner against `backend/tests/Eval/plan_eval_corpus.json`
  (compound prompts, multilingual variants, negative must-not-trigger cases;
  asserts required/forbidden capabilities + dependency edges). Run it before
  and after every planner-prompt change; not part of the CI gate.

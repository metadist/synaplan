# Feature 9 — External Data Nodes ("fetch before you answer")

**Release:** 4.0 · **Priority:** P1 (MCP part is customer-committed → treat as P0 for that sprint)
**Status:** Planned (2026-07-02)
**Related:** [`08_mcp-data-nodes-and-skill-registry.md`](./08_mcp-data-nodes-and-skill-registry.md)
(the MCP node + skill registry design — **decisions locked, absorbed into this
sprint plan**), the multitask engine in `backend/src/Service/Multitask/`,
`UrlContentService`, `InboundEmailHandler`/`InboundEmailHandlerService`.

> **The ask:** the DAG routing already enriches a prompt with file parsing, web
> search, RAG and memories. Extend that with three more external data sources —
> **"search for infos in my emails"**, **"load this URL and read the content"**,
> and **"call this MCP server and deal with the answer"** — as a *reliable*
> integration, not an overfeatured one. The MCP call is committed to a customer.

---

## 1. Current state (evaluated 2026-07-02 — what runs where)

Two distinct mechanisms exist today; the design below respects both:

| Source | Pre-planner enrichment | DAG node |
|---|---|---|
| File parsing | ✅ always (`MessagePreProcessor`, step 1) | `extract_text`, `file_analysis` consume the result |
| Web search | ✅ policy-driven (step 2.5, `BraveSearchService` + `WebSearchTopicPolicy`) | ✅ `web_search` (`WebSearchRunner`, reuses pre-fetched results) |
| URL content | ⚠️ only if topic prompt sets `tool_url_screenshot` (step 2.7, `UrlContentService`) | ❌ none |
| RAG | ❌ (injected at *answer* time in `ChatHandler`) | ✅ `rag_query` |
| Memories | ❌ (injected at *answer* time in `ChatHandler`, legacy path only) | ❌ none (nodes pass `disable_memories`) |
| Email as a corpus | ❌ nothing — IMAP handlers route/forward inbound mail, they never search a mailbox | ❌ none |
| External MCP tools | ❌ Synaplan is MCP **server** only (`backend/src/Mcp/`); no client exists | ❌ none |

**The established pattern** (proven by `web_search`/`rag_query`): *always-on,
cheap* enrichment stays pre-planner; *request-specific* data pulls are **DAG
nodes the planner places on demand**. All three new sources are request-specific
("search MY emails for X", "load THIS url", "ask THAT server") → **all three
become DAG nodes.** No new pre-planner steps.

---

## 2. Design principles (the "reliable, not overfeatured" contract)

Every data node — existing and new — obeys one uniform contract:

1. **Planner-placed, never speculative.** A node runs only because the plan says
   this request needs it. Routing rules tell the planner when *not* to emit it.
2. **Isolated failure, honest degradation.** Runners never throw for expected
   failures — they return `NodeResult::failed()`; `DagExecutor` isolates the
   node and the turn still answers ("I couldn't reach your mailbox, but…").
   Never hang, never invent data.
3. **Hard per-node timeout.** Every external call is timeout-bounded (config
   per node type). A slow source degrades one node, not the turn.
4. **Read-only in v1.** All three nodes *pull*. No mutating MCP tools, no IMAP
   flag/delete (use `BODY.PEEK`), no POSTing to arbitrary URLs. Write/action
   nodes are a separate future feature (locked — 08 §7 Q6).
5. **One SSRF guard.** Extract `UrlContentService::isBlockedUrl()` (+ DNS
   resolution check) into a shared `App\Service\Security\SsrfGuard` used by the
   URL node **and** the MCP client. Private ranges blocked everywhere, always.
6. **Per-user credentials, encrypted, tenant-isolated.** MCP server configs and
   the existing IMAP creds (`InboundEmailHandler`, AES via `EncryptionService`)
   are only ever resolved for `$context->userId`. Cross-tenant access is
   structurally impossible.
7. **Uniform downstream shape.** Each node emits formatted, citable text (the
   `BraveSearchService::formatResultsForAI()` pattern) so `chat`/`summarize`/
   `compose_reply` consume all sources identically via `$nX.text`.
8. **Flagged + snapshot-gated.** One `MULTITASK.*` flag per node (default off →
   on when validated); every planner-prompt change re-records the routing
   characterization snapshots with a reviewed diff (AGENTS.md trap).

**Deliberately NOT in scope (overfeature guards):** a generic "call any REST
API" node, browser automation/scraping behind logins, per-integration nodes
(Jira node, CRM node, …), mailbox indexing into Qdrant, MCP write actions.
The long tail of integrations is exactly what `mcp_fetch` is for.

---

## 3. The three nodes

### 3.1 `url_fetch` — "load this URL and read the content" (smallest, do first)

**Everything needed already exists.** `UrlContentService` has SSRF guarding,
robots.txt + noindex compliance, title/meta/JSON-LD/body extraction, and size
caps. Today it only fires behind a topic-prompt flag; the node makes it
planner-decidable for every user.

- **Capability:** `Capability::UrlFetch = 'url_fetch'` · uiKind: `search`-like card.
- **Runner:** `UrlFetchRunner` — thin adapter over `UrlContentService`.
  - Inputs: `urls` (explicit list from the planner) or fallback to
    `extractUrls($message.text)`. Max 3 URLs (service constant).
  - Uses the richer `fetchForCrawling()` extraction, capped ~12k chars total
    for token control.
  - Reuse-first: if step 2.7 already fetched the same URLs this turn
    (`$classification['url_content']`), reuse instead of re-fetching — same
    pattern as `WebSearchRunner` reusing step 2.5 results.
  - Output: `formatForPrompt()`-style sections (`--- URL … Title … Content`)
    as node text; metadata carries `urls`, `titles` for the Sources UI.
  - Failures (robots-blocked, 4xx/5xx, private IP, timeout) →
    `NodeResult::failed()` with a user-friendly reason.
- **Planner rule:** *emit `url_fetch` when the user asks to read/summarize/use
  the content of a specific URL in the message; do NOT emit it for bare link
  mentions where the question doesn't depend on page content; prefer
  `web_search` when no concrete URL is given.*
- **Flag:** `MULTITASK.URL_FETCH_ENABLED` (default off → on quickly; low risk).
- **Keep step 2.7 as-is** for topics that always want URL context; the node and
  the enrichment coexist exactly like web search does.

### 3.2 `mcp_fetch` — "call this MCP server and deal with the answer" (customer-committed)

Design is fully specified in [`08_mcp-data-nodes-and-skill-registry.md`](./08_mcp-data-nodes-and-skill-registry.md).
**We lock its recommended options now** so the customer sprint can start:

| 08 open question | **Locked decision** |
|---|---|
| Q1 node shape | **Option A** — one generic `mcp_fetch` capability; server/tool in `params`, tool arguments in `inputs.arguments` |
| Q2 surface | The **DAG node is the primary surface**; topic-level "always enrich" is not built in v1 |
| Q4 transport | **Streamable HTTP** (matches our own `McpController`; SSE transport is deprecated) |
| Q6 side effects | **Pull-only in v1** — the runner refuses tools not marked read-safe; write/action MCP is a later feature |
| Q7 naming | `mcp_fetch` |
| Q3 prompt builder scope | **Catalog-lite** in this release: generate `[CAPABILITYLIST]` + the per-user dynamic MCP tool sub-catalog from descriptors; per-block routing prose stays in the `tools:plan` DB prompt for now (smallest snapshot churn; full skill-registry refactor can follow later) |

**New plumbing (per 08 §3.4, adapted to what actually exists in the repo):**

- `McpServerConfig` entity (`BMCPSERVERS`): per-user rows — name, URL, auth
  header/token (encrypted via `EncryptionService`), enabled flag. Follows the
  proven `InboundEmailHandler` pattern (dedicated entity + encrypted secrets),
  **not** `plugin_data` — these are first-class connections with a settings UI.
- `McpClient`: Streamable HTTP `initialize` / `tools/list` / `tools/call`;
  `SsrfGuard`-checked target; hard timeout; response size cap.
- `McpToolRegistry`: cached `tools/list` per server (short TTL) → feeds the
  planner's dynamic sub-catalog ("Available connections for this user: …").
- `McpFetchRunner`: resolve server+tool from params → authorize ownership →
  `callTool()` → format result text (JSON pretty-printed/truncated) → `NodeResult`.
- Settings UI: **Settings → Connections → MCP servers** (add/test/list servers,
  browse discovered tools). i18n in all four locales.
- **Flags:** `MCP.CLIENT_ENABLED` (master), `MULTITASK.MCP_FETCH_ENABLED`,
  `MCP.NODE_TIMEOUT` (default 15s).

**Per-prompt enablement (locked 2026-07-02).** `mcp_fetch` is configured in the
**prompt config section** — the same place the web-search toggle lives — not
only globally. Two layers, mirroring how web search works today:

1. **Connection layer** (Settings → Connections → MCP servers): *which* servers
   exist, their URLs and credentials. Configured once per user.
2. **Prompt layer** (`TaskPromptsConfiguration.vue`, per task prompt): *whether
   this topic may use them.* A new **"MCP data sources"** entry next to the
   existing "Internet Search" / "Files Search" / "URL Content" tools:
   - Metadata key `tool_mcp` in `BPROMPTMETA` (canonical short form from the
     start — heed the `tool_internet` alias lesson in `PromptService`;
     register it in `METADATA_KEY_ALIASES` thinking). Plain on/off checkbox,
     **default off** — external calls are opt-in per topic.
   - Optional server scoping: `mcp_servers` metadata (comma-separated
     `McpServerConfig` ids, absent = all connected servers). Lets the customer
     bind e.g. only the CRM server to the "support" topic. The UI shows a
     multi-select of the user's connected servers when the checkbox is on.

**How the gate is enforced (both ends):**

- **Plan time:** classification (step 2) resolves the topic before the planner
  runs (step 3), so `TaskPlanner` sees the topic's metadata. The dynamic MCP
  tool sub-catalog is injected into the planner prompt **only if** the matched
  topic has `tool_mcp = true` — filtered to the topic's `mcp_servers` allowlist.
  No flag ⇒ the planner never even learns the tools exist ⇒ no `mcp_fetch`
  nodes (and no prompt-token cost for topics that don't use MCP).
- **Run time (defense in depth):** `McpFetchRunner` re-checks that the node's
  `server_id` is connected, owned by the user, **and allowed by the resolved
  topic's `tool_mcp`/`mcp_servers`** before calling out — a hallucinated or
  stale plan can never reach a server the topic isn't entitled to.

Precedence: `MCP.CLIENT_ENABLED` (global master) → `MULTITASK.MCP_FETCH_ENABLED`
(routing flag) → `tool_mcp` per prompt (user-facing switch) → runner re-check.

### 3.3 `email_search` — "search for infos in my emails"

**Key finding:** Synaplan has no email corpus. `InboundEmailHandler(Service)`
polls IMAP/POP3 accounts to *route/forward* inbound mail — it never indexes or
searches a mailbox. Two possible shapes:

- **(a) Live IMAP search at question time** — reuse the user's already-configured
  `InboundEmailHandler` account(s) (encrypted creds exist, connection code
  exists), run an `IMAP SEARCH`, fetch the top matches, feed them downstream.
- **(b) Index the mailbox into Qdrant/RAG** — continuous sync, storage,
  privacy/GDPR surface, re-vectorization… a whole product.

**v1 = (a), firmly.** Live search is stateless, privacy-friendly (mail content
never persisted; it exists only in the turn context), reuses existing plumbing,
and honestly covers "search for infos in my emails". (b) stays a backlog
candidate if live search proves too slow/limited.

- **Capability:** `Capability::EmailSearch = 'email_search'` · uiKind: `search`-like card.
- **Runner:** `EmailSearchRunner`:
  - Resolve the user's **active** `InboundEmailHandler` rows. None →
    `NodeResult::failed('no email account connected')` and the planner rule
    (below) plus the answer copy point the user to Settings → Email.
  - Params from planner: `query` (keywords), optional `from`, `since`/`before`
    (planner already has the `TimeContextBuilder` block to resolve "last week").
  - Build IMAP criteria (`TEXT "…" SINCE … FROM …`), search INBOX, fetch the
    **newest ≤10 matches**, headers + text part only via `BODY.PEEK` (strictly
    read-only, no attachments in v1), truncate each body ~2k chars.
  - Multi-account: search each active handler, merge by date, cap total.
  - Format like web-search results: `From / Subject / Date` + snippet, citable
    for downstream nodes. Metadata: subject/from/date lines only (no bodies)
    for the Sources dropdown.
  - Timeout ~15s total; a dead IMAP server fails the node, not the turn.
- **Planner rule:** *emit `email_search` only when the user explicitly asks
  about their own email/mailbox ("in my emails", "what did X mail me about…").
  Never emit it for generic questions. If no email account is connected the
  node fails gracefully — do not plan around it.*
  - The planner learns whether the user *has* a connected mailbox the same way
    `mcp_fetch` learns its tools: the catalog-lite renderer includes/omits the
    availability note per user. No account ⇒ the capability line says so ⇒ the
    planner doesn't emit it.
- **Flag:** `MULTITASK.EMAIL_SEARCH_ENABLED` (default off → on when validated).
- **Synamail note:** the Outlook add-in reads the *currently open* mail
  client-side and sends it as context — that stays as-is and is complementary.
  Server-side mailbox search requires IMAP creds; users who only use the add-in
  (no `InboundEmailHandler`) won't have `email_search` — correct and honest.

---

## 4. Architecture after this feature

```
user message ─▶ pre-planner enrichment (unchanged: file text, policy web search, step-2.7 URL)
                     │
                     ▼
              TaskPlanner  ◀── catalog-lite: [CAPABILITYLIST] from SkillDescriptors
                     │          + per-user dynamic notes (MCP tools, email availability)
                     ▼
        ┌─────────── JSON DAG ───────────┐
        ▼            ▼           ▼        ▼
   web_search    url_fetch  email_search  mcp_fetch     ← the DATA layer (all: timeout,
   (Brave)       (UrlContent (IMAP live   (McpClient      NodeResult::failed isolation,
                  Service)    search)      + SsrfGuard)    formatted citable text out)
        └────────────┴───────────┴────────┘
                     ▼  $nX.text
        chat / summarize / translate / compose_reply   ← the ANSWER layer
```

---

## 5. Sprints

Ordered for the customer commitment (MCP) while letting the cheap node prove
the pattern first. Each sprint ends gate-green with snapshots re-recorded.

- **S1 — Foundation (≈2–3 d).** `SsrfGuard` extraction (used by
  `UrlContentService`, ready for `McpClient`); catalog-lite: `describe()` on
  `TaskRunner` returning a minimal `SkillDescriptor` (capability, summary,
  uiKind, optional per-user dynamic note callback), `[CAPABILITYLIST]`
  assembled from it; prove the rendered planner prompt is equivalent to today's
  (snapshot diff reviewed). No behaviour change.
- **S2 — `url_fetch` node (≈2 d).** Capability + runner + planner rule/example
  + task card + i18n (en/de/es/tr) + tests (runner unit, reuse-of-step-2.7,
  SSRF, characterization). Flag on internally. *Proves the data-node pattern
  end-to-end at minimal risk.*
- **S3 — MCP client plumbing (≈4–5 d).** `McpServerConfig` entity + migration,
  `McpClient` (Streamable HTTP), `McpToolRegistry` (cached discovery),
  Settings → Connections UI, encrypted auth, `MCP.CLIENT_ENABLED`. Testable
  against our own `/mcp` endpoint as the fixture server.
- **S4 — `mcp_fetch` node (≈4–5 d, customer deliverable).** Capability + runner
  (pull-only guard, ownership + `tool_mcp` topic re-check, timeout, result
  formatting), dynamic per-user tool sub-catalog in the planner prompt gated on
  the topic's `tool_mcp`, the **"MCP data sources" toggle + server multi-select
  in the prompt config section** (`TaskPromptsConfiguration.vue` + `tool_mcp`/
  `mcp_servers` metadata), task card + SSE status, i18n, tests incl. a fixture
  MCP server + failure isolation.
  **Milestone: customer scenario works** ("look up X in our system and answer with it").
- **S5 — `email_search` node (≈3–4 d).** Capability + runner (IMAP live search,
  read-only, multi-account merge), availability note in the catalog, planner
  rule/example, task card + i18n, tests with a mocked IMAP layer + MailHog
  E2E happy path.
- **S6 — Polish & rollout (≈2 d).** Sources-dropdown entries for all three
  nodes, admin observability (node runs/failures), docs, flags default-on,
  final full-gate + snapshot review.

**Not blocking, later:** full skill-registry refactor (08 S1 complete form),
MCP write/action nodes, mailbox indexing (shape b), a `memories` DAG node.

---

## 6. Config / flags (additive)

| Flag | Group | Default | Purpose |
|---|---|---|---|
| `URL_FETCH_ENABLED` | `MULTITASK` | off → on | planner may emit `url_fetch` |
| `EMAIL_SEARCH_ENABLED` | `MULTITASK` | off → on | planner may emit `email_search` |
| `MCP_FETCH_ENABLED` | `MULTITASK` | off → on | planner may emit `mcp_fetch` |
| `CLIENT_ENABLED` | `MCP` (new group) | off | master switch, outbound MCP client |
| `NODE_TIMEOUT` | `MCP` | 15 | per-call hard timeout (s) |

Per-user override → global → built-in default, like existing `MULTITASK.*`.

---

## 7. Coverage check — "is that most potential external sources?"

| Source category | Covered by |
|---|---|
| Public web (fresh info) | `web_search` (existing) |
| A specific page/document on the web | **`url_fetch`** (new) |
| User's uploaded knowledge / files | RAG `rag_query` + file world (existing) |
| Personal context | memories (existing, answer-time) |
| User's mailbox | **`email_search`** (new) |
| CRM, tickets, wikis, DBs, calendars, internal APIs, n8n flows, … | **`mcp_fetch`** (new) — the deliberate *extensible* escape hatch; every further integration is "connect an MCP server", **never** a new bespoke node |

Deliberately uncovered (and why): authenticated browsing behind logins (needs
browser automation — different risk class), real-time streams, and anything
requiring **writes** (v1 is read-only by design). With MCP as the generic
adapter, this set addresses effectively all *pull* sources without growing the
capability vocabulary again.

---

## 8. Definition of done

- "Load https://… and summarize it" produces `url_fetch → summarize →
  compose_reply` and answers from the page content; robots-blocked/private URLs
  fail the node with an honest message.
- "Search my emails for the Acme offer and summarize it" produces
  `email_search → summarize`, grounded in real mailbox hits; a user with no
  connected account gets a clear pointer to Settings, never a hallucination.
- The customer's MCP scenario works end-to-end: connect server in Settings →
  enable "MCP data sources" on the topic in the prompt config → tools
  discovered → "look up X and answer" runs `mcp_fetch → chat` on pulled data.
  Unreachable/slow/misconfigured servers fail the node in isolation.
- A topic **without** `tool_mcp` never emits `mcp_fetch` (the planner isn't
  shown the tools) and the runner rejects any node targeting a server the
  topic isn't entitled to.
- All three nodes: timeout-bounded, SSRF-guarded, tenant-isolated, read-only,
  i18n-complete (en/de/es/tr), visible as task cards with Sources entries.
- Routing snapshots re-recorded + reviewed per sprint; full gate green
  (`make lint && make -C backend phpstan && make test && docker compose exec -T
  frontend npm run check:types`).

---

## 9. File index (touch points)

| Area | Paths |
|---|---|
| Shared guard (new) | `backend/src/Service/Security/SsrfGuard.php` (extracted from `UrlContentService`) |
| Catalog-lite (new) | `backend/src/Service/Multitask/Skill/SkillDescriptor.php`, `SkillCatalog.php`; `Execution/TaskRunner.php` (+`describe()`) |
| Capabilities | `backend/src/Service/Multitask/Plan/Capability.php` (`UrlFetch`, `EmailSearch`, `McpFetch`) |
| Runners (new) | `Execution/Runner/UrlFetchRunner.php`, `EmailSearchRunner.php`, `McpFetchRunner.php` |
| Planner | `TaskPlanner.php` (`[CAPABILITYLIST]` ← catalog; dynamic notes), `Prompt/PromptCatalog.php` (`planPrompt()` rules/examples for the three nodes) |
| MCP client (new) | `backend/src/Service/Mcp/McpClient.php`, `McpToolRegistry.php`; `Entity/McpServerConfig.php` + migration; `Controller/McpServerConfigController.php` |
| Per-prompt gate | `backend/src/Service/PromptService.php` (`tool_mcp`/`mcp_servers` metadata keys), `frontend/src/components/config/TaskPromptsConfiguration.vue` (MCP toggle + server multi-select) |
| Email | reuse `Entity/InboundEmailHandler.php`, `Service/InboundEmailHandlerService.php` (extract a read-only search method), `Service/Email/RawMimeEmailParser.php` |
| URL | reuse `Service/UrlContentService.php` |
| Frontend | task-card kinds for the three nodes, Settings → Connections (MCP), Sources dropdown entries, i18n ×4 |
| Tests | runner units, `SsrfGuard`, fixture MCP server, mocked IMAP, `tests/Characterization/RoutingCharacterizationTest.php` re-records |

# Feature 8 — MCP Data Nodes + a Skill Registry for the DAG

**Release:** 4.0 · **Priority:** P1 · **Status:** Decisions locked 2026-07-02 (Option A `mcp_fetch`, pull-only v1, Streamable HTTP, catalog-lite prompt builder) — sprint plan absorbed into [`09_external-data-nodes.md`](./09_external-data-nodes.md)
**Related:** [`00_master_plan.md`](./00_master_plan.md),
[`06_self-aware-routing.md`](./06_self-aware-routing.md) (also touches the planner
prompt), the multitask engine in `backend/src/Service/Multitask/`, the MCP
**server** in `backend/src/Mcp/`, and the prior pull-side research in
[`../mcp-and-api-enhancements/02-mcp-integration/02-MCP-CLIENT-ENRICHMENT.md`](../mcp-and-api-enhancements/02-mcp-integration/02-MCP-CLIENT-ENRICHMENT.md)
and [`../n8n-integration-research.md`](../n8n-integration-research.md).

> **Two intertwined asks** (from the request):
>
> 1. **Add a "pull data from MCP" point to the DAG routing** — a node that, mid-plan,
>    calls an external MCP server's tool and feeds the result into downstream nodes
>    (`chat`/`summarize`/`compose_reply`/…).
> 2. **How do we manage these DAG building blocks efficiently?** Routing today is
>    one big hand-written Markdown prompt. Should we build a small prompt builder
>    so each "point" is a self-describing **skill** that the planner assembles —
>    n8n-style — instead of editing a monolithic prompt every time we add a block?
>
> This doc argues the two are the **same problem**: the MCP node is the first
> *dynamic* building block, and it forces us to stop hand-maintaining the planner
> catalog. Solving #2 cleanly is what makes #1 (and every future block) cheap.

---

## 1. Where we are today (the building blocks already exist — but their metadata is scattered)

The multitask engine is already a DAG-of-skills system. The "points" the request
asks about are the `Capability` enum cases, each backed by a `TaskRunner`:

| Concept | Lives in | Role (n8n analogy) |
|---|---|---|
| **Capability** (`chat`, `web_search`, `image_generation`, `email_me`, …) | `Service/Multitask/Plan/Capability.php` | the **node type** in the palette |
| **Runner** (`WebSearchRunner`, `ChatRunner`, …) | `Service/Multitask/Execution/Runner/*` | the node's **executor** |
| **RunnerRegistry** | `Service/Multitask/Execution/RunnerRegistry.php` | dispatch capability → runner (tagged `app.multitask.runner`) |
| **TaskPlanner** + `tools:plan` prompt | `Service/Multitask/TaskPlanner.php` + `Prompt/PromptCatalog::planPrompt()` | the **planner** that wires nodes into a DAG (an LLM, not a GUI) |
| **TaskNode / TaskPlan** | `Service/Multitask/Plan/` | the **wired graph** (JSON: `id`, `capability`, `depends_on`, `inputs`, `params`) |
| **NodeContext** (`$nX.text`, `$message.files`) | `Service/Multitask/Execution/NodeContext.php` | the **connections** between nodes |
| **DagExecutor** | `Service/Multitask/Execution/DagExecutor.php` | the **runtime** (topological, node failures isolated) |

So we **already** have an n8n-like model. The pain the request points at is real
but specific: **a single skill's truth is smeared across four files**, and the
planner prompt is a 400-line hand-written rulebook.

### 1.1 The duplication that makes adding a block expensive

To add one capability today you touch, in lockstep:

1. `Capability` enum — add the case.
2. `Capability::uiKind()` — add the card kind (frontend coupling).
3. `TaskPlanner::CAPABILITY_DESCRIPTIONS` — add the planner-facing description.
4. `PromptCatalog::planPrompt()` — hand-write routing rules + a canonical example
   into the big Markdown prompt (and then **re-record routing characterization
   snapshots** and review the diff — AGENTS.md trap).
5. A new `TaskRunner` implementing `supportedCapabilities()` + `run()`.
6. `TaskPlanValidator` already validates "known capability" generically (good —
   one thing that *doesn't* need touching).

Steps 1–4 are the same fact ("there is a block called X that does Y, takes params
P, emits O") written four times in four shapes. That is exactly the
"manage the points efficiently" smell. **The MCP node can't even be expressed in
this model**, because its sub-tools are discovered at runtime — you can't
hand-write `CAPABILITY_DESCRIPTIONS` for tools you don't know at deploy time.

---

## 2. The idea: a **Skill descriptor** is the single source of truth (the "small prompt builder")

Introduce one object per building block — call it a **Skill** (a.k.a. node
descriptor / manifest). Each runner *declares* its skill; everything else is
**derived** from it. This is the n8n "node description" pattern adapted to an
LLM planner instead of a drag-and-drop canvas.

```php
// Service/Multitask/Skill/SkillDescriptor.php  (new)
final readonly class SkillDescriptor
{
    public function __construct(
        public Capability $capability,        // 'mcp_fetch'
        public string $summary,               // one-liner for [CAPABILITYLIST]
        public string $uiKind,                // moves uiKind() here (de-dupes enum)
        /** @var list<SkillParam> */
        public array $params = [],            // name, type, required, enum, description
        /** @var list<string> */
        public array $consumes = [],          // 'text','file','files' it reads from upstream
        public array $produces = [],          // 'text','file' it emits
        /** @var list<string> */
        public array $routingHints = [],      // the "apply in order" rules for THIS block
        /** @var list<PlanExample> */
        public array $examples = [],          // canonical DAG snippets for THIS block
        public bool $dynamic = false,         // tools discovered at runtime (MCP!)
    ) {}
}
```

A new **`SkillCatalog`** collects every runner's descriptor (same
`AutowireIterator('app.multitask.runner')` mechanism the registry already uses —
just add `describe(): SkillDescriptor` to the `TaskRunner` interface). Then:

- **`TaskPlanner::buildSystemPrompt()`** stops reading the hard-coded
  `CAPABILITY_DESCRIPTIONS` array and instead **assembles `[CAPABILITYLIST]`,
  the per-block routing rules, and the canonical examples from the catalog.**
  The big Markdown prompt shrinks to a stable *shell* (output schema + hard rules
  + assembly instruction); the variable, per-block content is generated.
- **`Capability::uiKind()`** is sourced from the descriptor (one place).
- Adding a block = **one runner file that also declares its skill.** No prompt
  edit, no four-file shuffle.

### 2.1 Why generate the prompt instead of keeping the hand-written one?

| | Today (hand-written MD) | Skill-derived prompt builder |
|---|---|---|
| Add a block | edit 4 files + monolithic prompt + re-record snapshots | 1 runner file (declares its skill) |
| Consistency | descriptions drift between enum / prompt / runner | impossible to drift (one source) |
| Dynamic blocks (MCP) | **can't express** | first-class (`dynamic: true`) |
| Admin preview | `renderSystemPrompt()` already previews the assembled prompt | unchanged — still works, now richer |
| Snapshot risk | every block change churns the whole prompt | catalog order is stable; diffs are local |

> ⚠️ **Honest caveat for discussion:** moving prompt text into PHP descriptors
> trades "one file a non-coder can edit" for "structured, drift-proof, but
> code-shaped". The `tools:plan` row in the DB stays the editable shell; only the
> **catalog** (capabilities/rules/examples) becomes generated. We should confirm
> we're OK with per-block routing prose living next to the runner rather than in
> the prompt row. (Alternative: keep examples in the DB prompt, generate only
> `[CAPABILITYLIST]`. Smaller change, less payoff — see §7 Q3.)

---

## 3. The MCP data node — design

Synaplan is currently an MCP **server** (`McpServerFactory` exposes our tools to
others). This feature makes Synaplan also an MCP **client** *inside the DAG*:
a node that calls **someone else's** MCP tool and returns the data.

### 3.1 Two shapes — pick one (discussion point, §7 Q1)

**Option A — One generic `mcp_fetch` capability (recommended).**
A single block; the *which server / which tool* lives in `params`:

```json
{
  "id": "n1",
  "capability": "mcp_fetch",
  "inputs": { "arguments": { "query": "$message.text" } },
  "params": { "server_id": "srv_crm", "tool": "search_customers" }
}
```

- ✅ One enum case, one runner, one validator rule. Static plan vocabulary stays
  tiny and stable (snapshots barely move).
- ✅ The planner picks the tool from a **dynamic catalog** we inject per-user
  (the `dynamic: true` path in §2): "Available MCP tools for this user: …".
- ⚠️ The planner must put a valid `server_id`+`tool` in params; the runner
  validates and fails the node gracefully if not (degrades to a `chat` that says
  "couldn't reach that data source").

**Option B — Each discovered MCP tool becomes its own synthetic capability.**
Closer to n8n's "every integration is a node", but it explodes the capability
vocabulary per user, breaks the `Capability` *enum* (cases aren't known at
compile time), and makes `TaskPlanValidator`'s "known capability" check
user-dependent. **Not recommended for v1** — it's the reason §2's
`dynamic` flag exists instead.

> **Recommendation:** Option A — a generic `mcp_fetch` block whose *parameter
> space* is dynamically described to the planner. This is the smallest blast
> radius and reuses the static-DAG machinery unchanged.

### 3.2 How the planner learns which MCP tools exist

This is the crux. The planner is an LLM reading `[CAPABILITYLIST]`. For a dynamic
block we inject a **per-user tool sub-catalog** into the system prompt at plan
time (cheap — it's a cached `tools/list` per configured server):

```
- "mcp_fetch": Pull data from an external connected system before answering.
  Available connections for this user:
    • server_id "srv_crm" — tools: search_customers(query), get_invoice(id)
    • server_id "srv_wiki" — tools: search_pages(q)
  Use params.server_id + params.tool, pass tool arguments in inputs.arguments.
  Only use a tool listed above. If none fits, do NOT emit mcp_fetch.
```

`SkillCatalog::render($userId)` asks the descriptor for its dynamic block to
expand itself (descriptor holds a callback / the runner exposes
`describeDynamic($userId)`), so the **prompt builder stays generic** and the MCP
specifics live in the MCP runner.

### 3.3 The runner (thin adapter, isolated failures)

```php
// Service/Multitask/Execution/Runner/McpFetchRunner.php  (new)
final readonly class McpFetchRunner implements TaskRunner
{
    public function supportedCapabilities(): array { return [Capability::McpFetch]; }

    public function describe(): SkillDescriptor { /* dynamic: true, §3.2 */ }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        // 1. resolve server_id + tool from params; arguments from inputs
        // 2. authorize: server must belong to $context->userId (no cross-tenant)
        // 3. McpClient->callTool(serverConfig, tool, args)  (timeout + circuit breaker)
        // 4. format result text for downstream (like BraveSearchService::formatResultsForAI)
        // 5. NodeResult::ok($formatted, files: [], metadata: ['mcp' => …])
        //    on any failure → NodeResult::failed(...) so the DAG isolates it
    }
}
```

It must obey the existing runner contract: **never throw for an expected
failure** — return `NodeResult::failed()` so `DagExecutor` isolates the node and
the turn still answers.

### 3.4 Reuse the MCP **client** building blocks (don't reinvent)

The prior plan
[`02-MCP-CLIENT-ENRICHMENT.md`](../mcp-and-api-enhancements/02-mcp-integration/02-MCP-CLIENT-ENRICHMENT.md)
already specs the *plumbing* we need — we should build the DAG node **on top of
it**, not parallel to it:

| Need | Reuse from the enrichment plan | Notes |
|---|---|---|
| Per-user server config (URL, auth, enabled) | `McpConfigController` + `plugin_data` (`server_{uuid}`) | Encrypted auth via `EncryptedConfigService`. **SSRF guard mandatory** (block private IPs). |
| `tools/list` + `tools/call` | `McpClient` | Transport: spec'd SSE; **prefer Streamable HTTP** per the n8n research note (SSE is deprecated 2026). Confirm in §7 Q4. |
| Cached tool catalog per user | `McpToolRegistry` | Feeds §3.2's prompt injection. Short TTL. |
| Result storage / replay | `BSEARCHRESULTS` + `source` column (`web_search`\|`mcp`) | Same display pattern as web search; cheap. |

**The difference vs. that older plan:** it injects MCP results into a *single*
prompt as a pre-inference enrichment step gated by `promptMetadata['tool_mcp']`.
**This feature makes MCP a first-class DAG node** the planner can place anywhere,
chain (`mcp_fetch → summarize → text2sound → compose_reply`), and run in parallel
with other nodes. Both can coexist (enrichment = "always pull for this topic";
node = "pull because *this* request needs it"), but we should pick the primary
surface — recommend the **DAG node** as the strategic one and treat enrichment as
an optional convenience layer (§7 Q2).

---

## 4. Architecture at a glance

```
                 ┌────────────────────────── SkillCatalog (NEW) ──────────────────────────┐
                 │  collects SkillDescriptor from every TaskRunner (tagged service)        │
                 │  render($userId) → [CAPABILITYLIST] + routing rules + examples          │
                 │  (mcp_fetch expands per-user tools via McpToolRegistry — dynamic block)  │
                 └───────────────────────────────┬─────────────────────────────────────────┘
                                                 │ assembled into
   user message ─▶ TaskPlanner ── tools:plan shell prompt + catalog ─▶ LLM ─▶ JSON DAG
                                                 │
                                                 ▼
                       DagExecutor ── RunnerRegistry ─▶ McpFetchRunner ─▶ McpClient ─▶ external MCP server
                                                 │                                   (SSRF-guarded, timeout,
                                                 │                                    circuit breaker, per-user auth)
                                                 ▼
                       NodeContext ($n1.text) ─▶ summarize ─▶ compose_reply ─▶ reply
```

Net new code is small: `SkillDescriptor` + `SkillCatalog` + `McpFetchRunner` +
the `Capability::McpFetch` case, **plus** wiring the planner prompt to the
catalog. The MCP *client/config/registry* comes from the enrichment plan.

---

## 5. Sprints

- **S1 — Skill registry refactor (no behaviour change, gate-green).**
  Add `SkillDescriptor` + `SkillCatalog`; add `describe()` to `TaskRunner`;
  move `CAPABILITY_DESCRIPTIONS` + `uiKind()` into descriptors; make
  `TaskPlanner::buildSystemPrompt()` assemble `[CAPABILITYLIST]` (and, if we
  decide so, rules/examples) from the catalog. **Re-record routing snapshots and
  prove the assembled prompt is byte-equivalent** (or diff-justified) to today's.
  This de-risks everything after it.
- **S2 — MCP client plumbing.** Land (or finish) `McpConfigController`,
  `McpClient`, `McpToolRegistry`, encrypted auth, SSRF guard, transport choice
  (§7 Q4). Settings UI to add/list servers + browse tools. Flag
  `MCP_CLIENT_ENABLED`.
- **S3 — `mcp_fetch` DAG node.** `Capability::McpFetch` + `McpFetchRunner`
  (Option A); dynamic `describe()` that injects the per-user tool sub-catalog;
  result formatting + `BSEARCHRESULTS` persistence. Planner routing rule/example
  for "pull data then answer". Flag `MULTITASK.MCP_FETCH_ENABLED`.
- **S4 — UX + observability.** Task card for the MCP node (`uiKind` =
  `search`-like); SSE `mcp_calling`/`mcp_complete` status; admin view of MCP node
  runs + failures; rate-limit/budget accounting (an `mcp_fetch` may be free or
  may proxy a paid call — decide accounting, §7 Q5).
- **S5 — Polish & rollout.** Tests (catalog unit, planner characterization with a
  fixture MCP server, runner failure isolation, SSRF), docs (`docs/` + the n8n
  guide cross-link), flags-on.

> S1 is independently valuable even if MCP slips: it pays down the "managing the
> points" debt the request is really about. S2–S3 deliver the MCP node.

---

## 6. Config / flags (additive, mirrors `MultitaskRoutingConfig`)

| Flag | Group | Default | Purpose |
|---|---|---|---|
| `MCP_CLIENT_ENABLED` | new `MCP` group | off | master switch for the outbound MCP client |
| `MCP_FETCH_ENABLED` | `MULTITASK` | off → on when validated | allow the planner to emit `mcp_fetch` nodes |
| `MCP_NODE_TIMEOUT` | `MULTITASK` | e.g. 15s | per-call hard timeout (node fails, never hangs the turn) |

Per-user override → global → built-in default, exactly like the existing
`MULTITASK.*` flags. Server configs + auth live in `plugin_data` (encrypted), not
in flags.

---

## 7. Open questions — for our discussion

1. **MCP node shape:** generic `mcp_fetch` with dynamic params (Option A, §3.1,
   recommended) **vs.** one synthetic capability per discovered tool (Option B)?
2. **Relationship to the existing enrichment plan:** is the **DAG node** the
   primary MCP-pull surface (recommended), with topic-level "always enrich"
   enrichment as a secondary convenience — or do we ship only one?
3. **How far does the prompt builder go in S1?** Generate **only**
   `[CAPABILITYLIST]` (small, safe) vs. also generate the per-block **routing
   rules + canonical examples** (bigger payoff, the real "skill builder", but
   moves prose into PHP)? See §2.1 caveat.
4. **Transport:** the old plan says SSE; the n8n research says **Streamable HTTP**
   is the 2026 standard (SSE deprecated). Standardise the client on Streamable
   HTTP from the start?
5. **Accounting/limits:** does an `mcp_fetch` count against a rate-limit bucket?
   External MCP calls can be free (user's own server) or expensive (proxied paid
   API). One bucket, per-server config, or none in v1?
6. **Write/side-effecting MCP tools:** v1 = **read/pull only** (the request says
   "pull data"). Do we explicitly forbid the planner from calling mutating MCP
   tools (e.g. "create Jira ticket") until a separate "MCP action/push" feature,
   to avoid an LLM-planned irreversible action? (Recommend: **yes, pull-only in
   v1.**)
7. **Naming:** `mcp_fetch` vs. `mcp_tool` vs. `pull_data`. The capability string
   is user-invisible (planner-facing) but shows in logs/snapshots — pick one.

---

## 8. Definition of done

- Adding a **new** static building block requires **one runner file** (declares
  its `SkillDescriptor`); no edits to the planner prompt body, the enum
  descriptions array, or `uiKind()`.
- A user can connect an external MCP server in Settings; its tools are
  discovered and shown.
- A request that needs external data ("look up customer X in our CRM and draft a
  reply") produces a DAG with an `mcp_fetch` node feeding a `chat`/`compose_reply`
  node, and answers grounded in the pulled data.
- An unreachable/slow/misconfigured MCP server **fails the node in isolation**
  (timeout-bounded) and the turn still answers honestly — never hangs, never
  invents data.
- SSRF guard blocks private-range targets; auth tokens are encrypted at rest;
  cross-tenant server access is impossible.
- Routing characterization snapshots re-recorded and reviewed; full gate green
  (`make lint && make -C backend phpstan && make test && docker compose exec -T
  frontend npm run check:types`).

---

## 9. File index (touch points)

| Area | Paths |
|---|---|
| Skill registry (new) | `backend/src/Service/Multitask/Skill/SkillDescriptor.php`, `SkillCatalog.php`, `SkillParam.php`, `PlanExample.php` |
| Runner contract | `backend/src/Service/Multitask/Execution/TaskRunner.php` (add `describe()`) |
| Planner | `backend/src/Service/Multitask/TaskPlanner.php` (`buildSystemPrompt` ← catalog), `Prompt/PromptCatalog.php` (`planPrompt()` shrinks to a shell) |
| Capability | `backend/src/Service/Multitask/Plan/Capability.php` (`McpFetch` case; `uiKind()` sourced from descriptor) |
| MCP node | `backend/src/Service/Multitask/Execution/Runner/McpFetchRunner.php` (new) |
| MCP client (reuse/finish) | `McpClient`, `McpToolRegistry`, `McpConfigController` per [`02-MCP-CLIENT-ENRICHMENT.md`](../mcp-and-api-enhancements/02-mcp-integration/02-MCP-CLIENT-ENRICHMENT.md) |
| Storage | `BSEARCHRESULTS` + `source` column (additive migration) |
| Config/flags | new `MCP` group + `MULTITASK.MCP_FETCH_ENABLED` (pattern: `MultitaskRoutingConfig`) |
| Tests | `backend/tests/Characterization/RoutingCharacterizationTest.php`, `backend/tests/Unit/Service/Multitask/Skill/`, MCP runner + SSRF tests |
| Docs | `docs/` (MCP client), cross-link `_devextras/planning/n8n-integration-research.md` |

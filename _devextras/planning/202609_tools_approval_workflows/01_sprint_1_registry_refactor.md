# Sprint 1 — Registry refactor

**Track 4 (`synaplan/`), sprint 1 of 5.** Steps `TL1`–`TL8`.

**Goal:** Every callable the AI can invoke is described once as a `ToolDescriptor` in one
`ToolRegistry`; the three tool loops and the planner read from it, and `GET /api/v1/tools` lists it.
Behaviour is byte-identical to today — this is the "wrap the existing thing in the new port" sprint
(roadmap §4, principle 4).
**Depends on:** nothing from tracks 1–3. Master plan §0 rows 1, 2, 13; §12 row 9 (the `mcp_servers`
bundle section lands here).
**Unlocks:** S2 (`ApprovalPolicy` needs a class on every tool), S4 (custom tools are one more source),
track 5 (`code_run` registers as a tool).
**Repos:** `synaplan/` only.
**Flag:** `TOOLS.REGISTRY_ENABLED` (`BCONFIG` group `TOOLS`). Off ⇒ the loops use their existing
catalogs unchanged; on ⇒ they read descriptors. Code default flips to `true` in `TL8` once C1 is
green; the flag stays one release as a kill switch and is removed after (master plan §9).

---

## 0. Why this sprint exists

Tools are described in four shapes today: `GatewayToolCatalog` builds an Anthropic tool snapshot from `WebSearchTool` / `AnalyzeImageTool` plus `McpToolRegistry::catalogForUser()`; `OpenAiGatewayToolLoop` repeats that for the OpenAI shape; `DocumentToolRegistry::declarationsFor($kind)` feeds `ChatToolLoop`; `SkillCatalog` renders `[CAPABILITYLIST]` for the planner from `SkillDescriptor`s. Nothing lists "all tools", so no policy can be applied uniformly. S2 cannot start until there is exactly one list.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/AI/Messages/Tools/GatewayToolCatalog.php` | `build()`, `nativeTools()`, `mcpTools()`, `replacedServerTools()` — the snapshot that must stay byte-identical |
| `backend/src/AI/Messages/Tools/GatewayToolLoop.php` | `executeOurs()` dispatches to `McpClient::callTool()` or `executeNative()` |
| `backend/src/AI/OpenAI/OpenAiGatewayToolLoop.php`, `backend/src/Service/Api/OpenAiToolCallingGate.php` | OpenAI-shape twin of the above |
| `backend/src/Service/Mcp/McpToolRegistry.php`, `McpClient.php` | `toolsFor()`, `catalogForUser()`, `listTools()`; annotations arrive here |
| `backend/src/Entity/McpServerConfig.php` (`BMCPSERVERS`) | `BALLOWWRITE`, `BAUTHMODE`, `BAUTHTOKEN`, `BOAUTH` — what the bundle section may and may not export |
| `backend/src/Mcp/McpServerFactory.php` | Server-side `ToolAnnotations` (`readOnlyHint`, `destructiveHint`) — the vocabulary the classes map from |
| `backend/src/Service/Multitask/Execution/Runner/McpActionRunner.php` | `isMutatingTool()`: unannotated tools currently stay in `mcp_fetch`; note for S2 |
| `backend/src/Service/Document/Tool/DocumentToolRegistry.php`, `ChatToolLoop.php` | Tagged `app.document_tool`; `declarationsFor($kind)`; third loop |
| `backend/src/Service/Multitask/Skill/SkillCatalog.php`, `SkillDescriptor.php`, `Plan/Capability.php` | `renderCapabilityList()` is locked by `tests/Characterization/PlannerPromptCharacterizationTest.php` |
| `backend/src/Service/Plugin/PluginManifest.php` | `chatCommands` — the opt-in seam for plugin tools |
| `backend/src/AI/Service/ProviderRegistry.php`, `backend/src/Service/SavedTask/SavedTaskConfig.php` | `#[AutowireIterator]` registry pattern; `BCONFIG` flag reader pattern |
| `backend/tests/Unit/AI/Messages/GatewayToolCatalogTest.php`, `GatewayToolLoopTest.php`, `OpenAiGatewayToolCatalogTest.php` | Existing contract tests; extended, never rewritten |

---

## 2. Developer steps

### 2.1 `TL1` — descriptor, registry, port

New namespace `backend/src/Service/Tool/`:

```php
enum SideEffect: string { case Read = 'read'; case Write = 'write'; case Destructive = 'destructive'; }
enum ToolSource: string { case Builtin = 'builtin'; case Mcp = 'mcp'; case Document = 'document'; case Skill = 'skill'; case Plugin = 'plugin'; case Custom = 'custom'; }
final readonly class ToolDescriptor {
    public function __construct(
        public string $name,            // unique across the registry, e.g. "mcp:{serverId}:{tool}", "skill:email_search"
        public string $title, public string $description,
        public array $inputSchema,      // JSON Schema as the loops already emit it
        public SideEffect $sideEffect, public ToolSource $source,
        public int $ownerId,            // 0 = system
        public ?string $policyException = null, // 'own_artefact' for document tools (§12 row 1)
        public array $meta = [],        // source-private: serverId, capability, kind, pluginId
    ) {}
}
interface ToolSourceInterface { public function source(): ToolSource; /** @return list<ToolDescriptor> */ public function describe(int $userId, array $context = []): array; }
final readonly class ToolRegistry { /* #[AutowireIterator('app.tool.source')] */ public function forUser(int $userId, array $context = []): array; public function get(int $userId, string $name): ?ToolDescriptor; }
```

Unit tests only (`tests/Unit/Service/Tool/ToolRegistryTest.php`): duplicate names throw
`DuplicateToolNameException`; unknown class defaults to `write`. Nothing is wired to a loop yet.

### 2.2 `TL2` — source adapters

One class per source, each tagged `app.tool.source`, each a thin read of the existing catalog:

| Adapter | Reads | Class derivation |
| ------- | ----- | ---------------- |
| `GatewayBuiltinToolSource` | `WebSearchTool::declaration()`, `AnalyzeImageTool::declaration()` | Static: both `read` |
| `McpClientToolSource` | `McpToolRegistry::catalogForUser()` | `readOnlyHint: true → read`; `destructiveHint: true → destructive`; anything else (including no annotations) `→ write`. `meta.serverId`, `meta.allowWrite` from `BMCPSERVERS` |
| `DocumentToolSource` | `DocumentToolRegistry::declarationsFor()` for every kind | `Read*` tools `read`; `Delete*` tools `destructive`; rest `write`; all carry `policyException: 'own_artefact'` |
| `SkillToolSource` | `SkillCatalog` via a `SkillDescriptor` adapter | Constant map `SkillToolSource::CLASS_BY_CAPABILITY`: `email_me`, `save_to_folder`, `calendar_event`, `compose_reply`, `mcp_action` `→ write`; every other `Capability` case `→ read`. Enum untouched |
| `PluginCommandToolSource` | `PluginManifest::$chatCommands` of installed plugins | **Opt-in:** only commands with a new optional manifest key `tool: { "sideEffect": "read\|write\|destructive" }` enter the registry; others stay slash-only. Manifest v2 `provides.tools` supersedes this later |

`meta` keeps whatever the loop needs to execute (`serverId`, `tool`, `capability`, `kind`); loops never parse names.

### 2.3 `TL3` — flag reader

`ToolsConfig` (`BCONFIG` group `TOOLS`, key `REGISTRY_ENABLED`, per-user row → global row → code default
`false`), same shape as `SavedTaskConfig`. Seeder row via `BConfigSeeder::insertIfMissing`. No behaviour yet.

### 2.4 `TL4` — Anthropic gateway reads descriptors

Behind the flag, `GatewayToolCatalog::build()` composes its snapshot from `ToolRegistry::forUser()`
filtered to `Builtin` + `Mcp`, emitting the same ordering, names, descriptions and `input_schema` as
today. `GatewayToolLoop::executeOurs()` resolves the tool via `ToolRegistry::get()` and throws
`ToolNotRegisteredException` for any name not in the registry (C5). Flag off: untouched code path.

### 2.5 `TL5` — OpenAI gateway and document loop read descriptors

Same for `OpenAiGatewayToolLoop` (OpenAI `function` shape) and for `ChatToolLoop`, which asks the
registry for `Document` descriptors of the session's `kind` instead of
`DocumentToolRegistry::declarationsFor()`. `DocumentToolRegistry::get()` keeps executing; only the list moves.

### 2.6 `TL6` — `GET /api/v1/tools`

`ToolsController::list()` returns descriptors for the current user (session or API key) with
`policy: null` as a placeholder until S2, plus `source`, `sideEffect`, `policyException`. The serializer
is an explicit allow-list (never `meta`). 404 while the flag is off. Full OpenAPI; `make -C frontend generate-schemas`; no UI.

### 2.7 `TL7` — characterization proof C1 and negative tests C5

- `tests/Unit/AI/Messages/GatewayToolCatalogRegistryParityTest.php`: `build()` with flag off and on for the same fixture user (two MCP servers, one with `BALLOWWRITE=0`, native tools on/off) — `assertSame(json_encode(...))`; `tests/Unit/AI/OpenAI/OpenAiGatewayToolCatalogRegistryParityTest.php` and `tests/Unit/Service/Document/ChatToolLoopRegistryParityTest.php` do the same per loop.
- `PlannerPromptCharacterizationTest`, `RoutingCharacterizationTest`, `UtterancePlanCharacterizationTest`: **no snapshot re-record in this sprint**; the PR diff of `tests/Characterization/__snapshots__/` is empty.
- C5 per loop: a tool removed from the registry (test source returning `[]`) cannot be executed by `GatewayToolLoop`, `OpenAiGatewayToolLoop`, `ChatToolLoop` — each asserts `ToolNotRegisteredException`.

### 2.8 `TL8` — `mcp_servers` bundle section, default flip

`McpServersBundleSection implements BundleSectionInterface` (tagged `app.bundle.section`, defined by track 2
S6 — [`../202609_agent_builder/06_sprint_6_portability_and_packs.md`](../202609_agent_builder/06_sprint_6_portability_and_packs.md);
do not redefine the interface here). Exports per server: `name`, `url`, `authMode` (`bearer` / `oauth` /
`none`), `authHeader` name, `allowWrite`, `enabled`. **Never** `BAUTHTOKEN`, `BOAUTH`, client secrets.
Import creates the row with `BENABLED=0` and adds the checklist item "needs a credential". Keyed by
owner-scoped `name`, unknown keys rejected. If track 2 S6 has not merged when S1 starts, this PR is the
last of the sprint and waits. The same PR flips the `ToolsConfig` code default to `true` after `TL7` is green.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C1 pure refactor | Parity tests in `TL7`; empty snapshot diff; existing `GatewayToolCatalogTest` / `GatewayToolLoopTest` / `OpenAiGatewayToolLoopTest` unchanged and green |
| C2 `BALLOWWRITE` | Not enforced here (S2); `McpClientToolSource` carries `meta.allowWrite` and a test asserts it is read from `BMCPSERVERS`, not defaulted |
| C5 unregistered ⇒ uncallable | Negative test per loop in `TL7` |
| C8 gateways unchanged | Parity tests cover `/v1` (OpenAI) and Messages gateway shapes; mobile-impact: all PHP ⇒ `backend-only` |

Known deviation to carry into S2: `McpActionRunner::isMutatingTool()` treats unannotated MCP tools as non-mutating (they stay in `mcp_fetch`), while decision 2 classes them `write`. S1 records the class only; S2 decides whether the `mcp_fetch` catalog follows the class and re-records the utterance snapshots deliberately if it does. Gate: `make lint && make -C backend phpstan && make test`; frontend only for the regenerated `api-schemas.ts` (`vue-tsc`).

---

## 4. Exit criteria / demo

1. Flag off: every existing test green, snapshots untouched, gateways emit the same tool lists as before.
2. Flag on: parity tests prove identical snapshots; `GET /api/v1/tools` lists builtins, MCP tools (with `read` / `write` / `destructive`), document tools (`policyException: own_artefact`), skills, opted-in plugin commands.
3. Removing a descriptor from a test source makes the tool uncallable in all three loops.
4. An exported `mcp_servers` section contains URLs and auth *types*, no token; importing it yields disabled rows and a "needs a credential" item.
5. `STATUS.md` rows `TL1`–`TL8` ticked; code default `true`.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| TL1 | `refactor(tools): add ToolDescriptor, SideEffect and ToolRegistry port` | backend-only | — |
| TL2 | `refactor(tools): add registry source adapters for builtins, MCP, documents, skills, plugins` | backend-only | TL1 |
| TL3 | `chore(tools): add TOOLS.REGISTRY_ENABLED flag reader and seed row` | backend-only | TL1 |
| TL4 | `refactor(tools): read gateway tool snapshot from ToolRegistry behind flag` | backend-only | TL2, TL3 |
| TL5 | `refactor(tools): read OpenAI gateway and document tool lists from ToolRegistry` | backend-only | TL4 |
| TL6 | `feat(tools): add GET /api/v1/tools listing registry descriptors` | backend-only | TL2, TL3 |
| TL7 | `test(tools): prove registry parity (C1) and unregistered-tool refusal (C5)` | backend-only | TL5 |
| TL8 | `feat(tools): register mcp_servers bundle section and enable registry by default` | backend-only | TL7, track 2 S6 |

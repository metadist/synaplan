# Sprint S4 — Knowledge, tools and skills

**Track 2 (Agent Builder), sprint 4 of 6.** Steps `AB26`–`AB32`.

**Goal:** An assistant carries the owner's *shared* knowledge folders, an explicit tool allow/deny list and a skill allow-list that the planner enforces. An assistant restricted to `chat` + `rag_search` never plans `email_me`, and it never lets a user read a folder the owner did not share. The router opt-in (`BROUTABLE`) is wired, but the sorter change ships in its own PR with a reviewed snapshot re-record.
**Depends on:** S3 (published versions, IAM `use`); **track 1 S2** (`RagScopeResolver`). Track 4 S1's `ToolRegistry` is **optional**: this sprint ships an adapter seam that maps onto the registry when present and onto today's `tool_*` flags otherwise.
**Unlocks:** S5 (widgets inherit the tool/knowledge policy), track 4 S2 (approval policies attach to an assistant's tool list).
**Repos:** `synaplan/` only. **Class:** `backend-only` + `ota-candidate`.
**Flag:** `AGENTS.ENABLED`. `BROUTABLE` is off per assistant; the sorter topic-list change (`AB30`) is additionally guarded by `AGENTS.ROUTABLE_ENABLED` (seeded `0`) so it merges dark.

---

## 0. Why this sprint exists

Knowledge, tools and skills are configured in four different places today (prompt meta, MCP servers page, saved tasks, widget config). This sprint puts them into the one definition and — more importantly — makes the runtime *enforce* them per request. The security value is C6: an assistant is a recipe, not a key; it cannot grant what the owner did not share.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| Track 1 S2 `RagScopeResolver` (`backend/src/Iam/Rag/`) | Returns the `(ownerId, groupKey)` pairs the current user may search; the assistant's folders are filtered *through* it |
| `backend/src/Service/Message/Handler/ChatHandler.php` | `$options['rag_group_key']`, RAG fallback search — becomes a scope list via the `RuntimeProfile` |
| `backend/src/Service/PromptService.php` | Canonical flags `tool_internet`, `tool_files` (+ legacy aliases), `tool_mcp`, `mcp_servers` — today's tool policy |
| `backend/src/Service/Multitask/Plan/TaskPlanValidator.php` | `validate(mixed $payload): array`, `Capability::values()` — the enforcement point for skill allow-lists |
| `backend/src/Service/Multitask/TaskPlanner.php` | `SkillCatalog::renderCapabilityList($userId, $context)` — the capability list the AI sees must already be filtered |
| `backend/src/Service/Multitask/Skill/SkillCatalog.php`, `ClassificationPlanMapper.php` | `descriptorFor(Capability)`; the fast-path mapper must respect the same list |
| `backend/src/Service/Message/MessageSorter.php` | `promptRepository->getAllTopics(0, $userId, excludeTools: true)` + `getTopicsWithDescriptions()` — where routable assistants enter |
| `backend/tests/Characterization/RoutingCharacterizationTest.php` + `__snapshots__/routing_classification.json` | Re-recorded **only** in `AB30` |
| `_devextras/planning/202609_tools_approval_workflows/00_master_plan.md` §0 row 1 | `ToolDescriptor` / `ToolRegistry` (tagged `app.tool.source`) — the adapter target |

---

## 2. Developer steps

### 2.1 Shared folders in the definition and the resolver (`AB26`)

- `knowledge.folders[]` entries are `"{ownerId}:{groupKey}"` (validated in `AB4`). `BuilderKnowledge.vue` gets an **Add shared folder** picker listing folders the *owner* has `use` on (own + shared) from track 1's `GET /api/v1/me/shared?kind=knowledge_folder` plus own folders.
- `AgentRuntimeResolver` builds `RuntimeProfile::$ragScopes` = own folder (`TASKPROMPT:agent:{slug}`, owner = assistant owner) + every `knowledge.folders[]` entry **intersected with** `RagScopeResolver::scopesFor($assistantOwner)`. A folder is searched only if the owner may use it *now* **and** the current user has `use` on the assistant (already enforced in `AB21`). Folders the owner lost access to are dropped silently and noted (`scope_dropped:{ownerId}:{groupKey}`), never escalated.
- `ChatHandler` passes the scope list to the vector storage (track 1 S2's multi-scope query); results keep `owner` / `shared: true` for badges.
- The user's *own* files are **not** searched inside an assistant chat unless `knowledge.includeUserFiles = true` — a new optional key (default `false`) added to the validator in this PR; the schema stays `agent.v1` (additive optional keys are allowed, unknown keys still rejected).

### 2.2 Tool policy adapter seam (`AB27`)

One interface, two adapters, no double implementation; the resolver depends on the interface only (service alias `app.agent.tool_policy`, chosen at container build depending on whether `App\Tools\ToolRegistry` exists):

```php
interface ToolPolicySourceInterface
{
    /** @return list<string>|null tool names the assistant may call; null = unrestricted */
    public function allowedTools(RuntimeProfile $profile): ?array;
    public function isAllowed(RuntimeProfile $profile, string $toolName): bool;
}
```

- `LegacyFlagToolPolicy` (default): maps `tools.internet` → `tool_internet`, `tools.files` → `tool_files`, `tools.mcpServers[]` → `mcp_servers` + `tool_mcp`, and `tools.allow` / `tools.deny` against the fixed name set the loops know today (`web_search`, `rag_search`, `mcp_fetch`, `mcp_action`, document tool names). Writes the flags into `RuntimeProfile::$toolFlags` so `ChatHandler` and the gateway loops behave as with prompt meta.
- `RegistryToolPolicy` (track 4 S1 present): `allow` / `deny` evaluated against `ToolRegistry::names()`; `deny` wins; unknown names in `allow` are a builder warning, not an error (an imported assistant may name tools this instance lacks).
- Builder **Tools & skills** section (`BuilderToolsSkills.vue`): toggles for Internet / Files / MCP servers (multi-select from `mcpServersApi`), advanced allow/deny chips, skill allow/deny chips from the capability list.

### 2.3 Skill allow-list in the planner (`AB28`)

- `skills.allow` / `skills.deny` are `Capability` values; `deny` wins; `allow = null` = everything the user may use today.
- `TaskPlanner`: `renderCapabilityList($userId, $context)` receives `context['allowedCapabilities']` from the profile so the AI never sees a forbidden skill in the first place. `ClassificationPlanMapper` and the fast path get the same list — a denied skill cannot enter through a heuristic either.
- `TaskPlanValidator::validate()` gains an optional second argument `?array $allowedCapabilities`; a step outside the list throws `InvalidTaskPlanException` with reason `capability_not_allowed:{name}`; `TaskPlanExecutor` turns that into "This assistant is not allowed to {skill label}" (five locales, key `assistants.skillNotAllowed`).

### 2.4 `BROUTABLE` wiring — everything except the sorter (`AB29`)

- Publish section: checkbox **Also let the router pick this assistant** (owner only) → `BAGENTS.BROUTABLE`, with helper text that it applies to published assistants and to users with `use` only.
- `AgentRepository::routableForUser(int $userId): list<Agent>` — published, `BROUTABLE = 1`, `use` for the user via `AccessGate`.
- `MessageClassifier`: when a *sorter-driven* classification returns a topic of the form `agent:{slug}`, resolve it to the agent id and attach the `RuntimeProfile` (pinned path). With `AGENTS.ROUTABLE_ENABLED = 0` no such topic can be returned, so behaviour is unchanged.

### 2.5 Sorter topic list — dedicated PR with re-recorded snapshot (`AB30`)

- `MessageSorter`: when `AGENTS.ROUTABLE_ENABLED = 1`, append `routableForUser()` entries (`topic = agent:{slug}`, description = `BDESCRIPTION`) to `$topics` / `$topicsWithDesc` before `buildDynamicList()` and `validateTopic()`.
- `RoutingCharacterizationTest` gains fixtures with one and with three routable assistants (flag on) and keeps every existing fixture. Re-record with `UPDATE_ROUTING_SNAPSHOTS=1`, then **every changed line** of `routing_classification.json` is listed in the PR description; existing fixtures must show zero diff, only new entries appear.
- Merged with `AGENTS.ROUTABLE_ENABLED` seeded `0` (master plan §12 row 2).

### 2.6 Parameters section (`AB31`)

`BuilderParameters.vue`: `temperature` (0–2), `maxTokens`, `language` (`auto` or a locale code), `responseSchema` (JSON textarea, validated as a JSON-schema object on save). The resolver copies them into `RuntimeProfile::$parameters`; `ChatHandler` forwards `temperature` / `maxTokens` through the existing options array to `AiFacade`, and `responseSchema` through the structured-output path (governed by the `StructuredOutputConfigSeeder` flag; unsupported provider ⇒ ignored with a note).

### 2.7 Negative tests for C6 (`AB32`)

Its own PR so reviewers read only tests: `AgentKnowledgeIsolationTest` and `AgentToolSkillEnforcementTest` (§3). Fixtures: owner A, users B (has `use`) and C (no share), folders `A:legal` (shared to B's group) and `A:private` (not shared), an assistant naming both folders.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C2 | Except `AB30`, no fixture in `routing_classification.json` changes; `AB30` adds fixtures only and documents every added line |
| C3 | Prompt meta is still read for prompts without an assistant (`LegacyFlagToolPolicy` reads through `PromptService` accessors) |
| C6 | `AgentKnowledgeIsolationTest`: B's chat searches `A:legal` and the own folder, never `A:private`; A unshares `A:legal` ⇒ B's next message searches the own folder only; C gets 404; a definition naming `Z:folder` (owner never had access) yields no scope and a `scope_dropped` note; the vector storage mock asserts the exact scope list |
| C6 | `AgentToolSkillEnforcementTest`: `skills.deny = ['email_me']` + "email me this" ⇒ plan rejected with `capability_not_allowed:email_me`, no task row; `tools.internet = false` ⇒ `web_search` never offered to the loop; `ToolPolicyContractTest` runs the same cases against both adapters and expects identical answers |
| C8 | New paths `backend/src/Service/Agent/Policy/**` listed `backend-only`; Vue `ota-candidate` |

- Unit: `TaskPlanValidatorAllowListTest` (allowed, denied, `null` = unrestricted), `AgentRuntimeResolverScopesTest`, `AgentDefinitionValidatorTest` extended (`includeUserFiles`, `responseSchema` must be an object), `MessageSorterRoutableTest` (flag off ⇒ topic list identical).
- Frontend: `BuilderKnowledge.spec.ts` (picker lists only `use` folders), `BuilderToolsSkills.spec.ts`, `BuilderParameters.spec.ts` (invalid JSON schema blocks save).
- Unfiltered backend + frontend gates; `AB30` additionally runs the characterization suite with the flag on and off.

---

## 4. Exit criteria / demo

1. "Contract review" names the shared folder `Legal contracts`; a Legal member's answer cites it with a *shared* badge; a Sales member gets 404. The owner removes the share; the next Legal message no longer cites it.
2. The assistant has `skills.allow = ['chat', 'rag_search']`; the member writes "email me the summary" — the reply explains the assistant may not send mail; no `email_me` task row exists.
3. With track 4's registry absent, tool toggles behave like prompt-meta flags; with it present (feature branch), the same definition yields the same allowed set.
4. `BROUTABLE` checked with `AGENTS.ROUTABLE_ENABLED = 0` ⇒ sorter output identical; flag on (dev instance) ⇒ the router can pick the assistant and the snapshot diff contains only the new fixtures.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| AB26 | `feat(agents): resolve shared knowledge folders through RagScopeResolver` | backend-only + ota-candidate | AB21, IAM S2 |
| AB27 | `feat(agents): add tool policy seam with legacy-flag and registry adapters` | backend-only + ota-candidate | AB21 |
| AB28 | `feat(multitask): enforce assistant skill allow-list in planner and validator` | backend-only | AB27 |
| AB29 | `feat(agents): wire BROUTABLE opt-in (builder, repository, classifier) without sorter change` | backend-only + ota-candidate | AB21 |
| AB30 | `feat(routing): offer routable assistants to the sorter behind AGENTS.ROUTABLE_ENABLED (snapshot re-record)` | backend-only | AB29 |
| AB31 | `feat(assistants): add Parameters section (temperature, tokens, language, response schema)` | ota-candidate + backend-only | AB13 |
| AB32 | `test(agents): add knowledge isolation and tool/skill enforcement negative tests` | backend-only | AB26, AB28 |

# Sprint S1 — Entity and pinned runtime

**Track 2 (Agent Builder), sprint 1 of 6.** Steps `AB1`–`AB8`.

**Goal:** An assistant exists as a row (`BAGENTS`) with a validated `agent.v1` draft, the owner can create it through the API, and a chat that carries `agentId` runs pinned to it — the same short-circuit the classifier already does for a pinned `PROMPTID`. No UI yet.
**Depends on:** nothing outside this repo. Track 1 (IAM) is **not** required: S1 assistants are owner-only.
**Unlocks:** S2 (builder + gallery need the CRUD API and the pinned chat), S3 (versions table already exists), every later sprint.
**Repos:** `synaplan/` only. **Class:** `backend-only` (no Vue in this sprint).
**Flag:** `AGENTS.ENABLED` (`BCONFIG` group `AGENTS`, setting `ENABLED`), seeded `0`. Every `/api/v1/agents*` route 404s when off; `agentId` on the stream endpoint is ignored when off.

---

## 0. Why this sprint exists

Everything later (publishing, gallery, widgets, export) hangs off one row and one runtime object. This sprint creates both and proves the runtime seam is additive: `ChatHandler` receives a `RuntimeProfile` that is *always* present, the default profile being "the user's defaults". There is no `if ($agent)` branch in the chat path — that is how C2 stays true.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/Message/MessageClassifier.php` | `checkPromptOverride()` reads `BMESSAGEMETA` key `PROMPTID` and skips the sorter — the model for the `agentId` early return |
| `backend/src/Service/Message/MessageSorter.php` | Must not be called for a pinned message; read only, do not touch |
| `backend/src/Controller/StreamController.php` | `$params->get('promptTopic')` / `$params->get('promptId')` — where `agentId` is added |
| `backend/src/Service/Message/Handler/ChatHandler.php` | `handleStream()` reads `$options['rag_group_key']`, model id, system prompt — the seam the `RuntimeProfile` maps onto |
| `backend/src/Service/PromptService.php` | Tool-flag keys `tool_internet` / `tool_files` and their legacy aliases; the assistant references a prompt row, never copies its meta |
| `backend/src/Service/ModelConfigService.php` | Per-user `DEFAULTMODEL` — the fallback for every model slot |
| `backend/src/Model/ModelCatalog.php` | `findBidByKey('service:providerId:tag')` — the only way a definition names a model |
| `backend/src/Service/Desktop/DesktopAgentConfig.php` + `backend/src/Seed/DesktopAgentConfigSeeder.php` | Flag class + seeder pattern to copy (`insertIfMissing`, value `'0'`) |
| `backend/src/Controller/ConfigController.php` | Runtime config booleans (`desktopAgentEnabled`) — add `agentsEnabled` the same way |
| `backend/tests/Characterization/RoutingCharacterizationTest.php` | Snapshot `__snapshots__/routing_classification.json` — must not change in this sprint |
| `docs/MIGRATIONS.md` | Galera-safe `addSql`, no `Schema` API |

---

## 2. Developer steps

### 2.1 Migration — `BAGENTS` (`AB1`)

Own PR, raw `addSql`, idempotent, no foreign keys (Galera rule 3):

```sql
CREATE TABLE IF NOT EXISTS BAGENTS (
  BID BIGINT NOT NULL AUTO_INCREMENT, BOWNERID BIGINT NOT NULL, BPROMPTID BIGINT NOT NULL,
  BSLUG VARCHAR(96) NOT NULL, BNAME VARCHAR(128) NOT NULL, BDESCRIPTION TEXT NULL, BICON VARCHAR(64) NOT NULL DEFAULT '',
  BSTATUS VARCHAR(16) NOT NULL DEFAULT 'draft', BDRAFT JSON NOT NULL,
  BPUBLISHEDVERSIONID BIGINT NULL, BPARENTID BIGINT NULL,
  BSOURCE VARCHAR(64) NOT NULL DEFAULT 'manual', BROUTABLE TINYINT(1) NOT NULL DEFAULT 0,
  BCREATED BIGINT NOT NULL, BUPDATED BIGINT NOT NULL,
  PRIMARY KEY (BID), UNIQUE KEY uq_agents_owner_slug (BOWNERID, BSLUG),
  KEY idx_agents_owner (BOWNERID), KEY idx_agents_prompt (BPROMPTID)
);
```

`BSTATUS` ∈ `draft` / `published` / `archived`; `BSOURCE` ∈ `manual` / `import` / `plugin:<id>` / `system`.

### 2.2 Migration — `BAGENTVERSIONS` (`AB2`)

Created now so S3 needs no schema PR. Rows are never updated or deleted by application code; a migration that deletes agents deletes versions first.

```sql
CREATE TABLE IF NOT EXISTS BAGENTVERSIONS (
  BID BIGINT NOT NULL AUTO_INCREMENT, BAGENTID BIGINT NOT NULL, BVERSION INT NOT NULL,
  BDEFINITION JSON NOT NULL, BPROMPTTEXT MEDIUMTEXT NOT NULL, BCHANGELOG TEXT NULL,
  BPUBLISHEDBY BIGINT NOT NULL, BCREATED BIGINT NOT NULL,
  PRIMARY KEY (BID), UNIQUE KEY uq_agentversions_agent_version (BAGENTID, BVERSION)
);
```

### 2.3 Flag, config class, runtime config (`AB3`)

- `App\Service\Agent\AgentConfig` with `CONFIG_GROUP = 'AGENTS'`, `KEY_ENABLED = 'ENABLED'`, `isEnabled(): bool`; `App\Seed\AgentConfigSeeder` → `BConfigSeeder::insertIfMissing(..., 'agent_config', [ownerId 0, AGENTS, ENABLED, '0'])`.
- `ConfigController` runtime config gains `agentsEnabled` (boolean, default `false`, OpenAPI property with description); Zod regenerated. A `guard()` helper in the controller (Saved Tasks style): flag off ⇒ 404, never 403.

### 2.4 `agent.v1` definition schema (`AB4`)

`App\Service\Agent\Definition\AgentDefinitionValidator::validate(array $json): AgentDefinition` — pure PHP, unit-tested against fixtures in `backend/tests/Fixtures/agents/`:

- `schema` must equal `agent.v1`; the only top-level keys are `models`, `knowledge`, `tools`, `skills`, `parameters`, `behaviour`, `tasks`, `channels`. **Unknown keys at any level are rejected** with the JSON path in the message (`Unknown key "tools.foo" in agent.v1`).
- `models.*` values are catalog keys `service:providerId:tag` or `null`; the validator checks the *shape*, resolution happens in the resolver.
- `behaviour.memory` accepts only `user` (master plan §12 row 1). `knowledge.folders[]` entries match `^\d+:[A-Za-z0-9:_-]+$`.
- `AgentDefinition::defaults()` returns the empty-but-valid definition used when a draft is created with only a name.

### 2.5 Entities, repository, `AgentService` (`AB5`)

- `App\Entity\Agent` (`BAGENTS`), `App\Entity\AgentVersion` (`BAGENTVERSIONS`), `AgentRepository`, `AgentVersionRepository`; slug via `AgentSlugger::from(name)` (`[a-z0-9-]{3,96}`), unique per owner.
- `App\Service\Agent\AgentService` (`final readonly`): `create(User $owner, string $name, ?int $promptId, array $draft): Agent`, `update(Agent, array $patch): Agent`, `delete(Agent): void`, `getOwned(int $id, int $ownerId): ?Agent`, `listOwned(int $ownerId)`.
- `create()` without `promptId` creates a `BPROMPTS` row through `PromptService` (topic `agent:{slug}`, owner = user) so an assistant always has an instruction row. The prompt API contract does not change (C3).

### 2.6 `RuntimeProfile` and `AgentRuntimeResolver` (`AB6`, refactor first)

Two PRs on the same seam. **PR one is the refactor-sprint rule (roadmap §7 step 4):**

- `App\Service\Runtime\RuntimeProfile` (readonly value object): `promptId`, `promptTopic`, `systemPrompt`, `modelIds` (per capability), `ragScopes` (list of `{ownerId, groupKey}`), `toolFlags`, `skillAllow` / `skillDeny` (null = unrestricted), `parameters`, `agentId`, `agentVersionId`, `notes`.
- `RuntimeProfileFactory::forUserDefaults(User, array $classification): RuntimeProfile` builds today's behaviour: `ModelConfigService` defaults, the classified prompt, `TASKPROMPT:{topic}` group key, prompt-meta tool flags.
- `ChatHandler::handleStream()` reads model, prompt, RAG scope and tool flags from `$options['runtime_profile']`, falling back to the existing `$classification` keys when absent. Behaviour byte-identical; the gate and the routing snapshot prove it.

PR two adds `App\Service\Agent\AgentRuntimeResolver::resolve(int $agentId, User $user, bool $draft = false): RuntimeProfile`:

- Loads the draft (owner only, `test` mode) — published versions arrive in S3; throws `AgentNotAccessibleException` (→ 404) for another user's assistant.
- Resolves each `models.*` key via `ModelCatalog::findBidByKey()`; a miss or an inactive model falls back to the user's default for that capability and is recorded in `notes` (`model_fallback:chat`).
- Knowledge in S1 = own folder only (`knowledge.ownFolder` ⇒ `TASKPROMPT:agent:{slug}`); shared folders are S4.

### 2.7 Classifier early return and `agentId` on the stream endpoint (`AB7`)

- `StreamController`: read `$params->get('agentId')`; when the flag is on and the value is an int, persist `BMESSAGEMETA` `AGENTID` on the user message (where `PROMPTID` is written for the "again" flow) and pass it in the classification options.
- `MessageClassifier::classify()`: **before** `checkPromptOverride()`, if the options carry `agentId`, call the resolver and return the pinned classification (`topic` = the prompt topic, `prompt_id`, `agent_id`, `agent_version_id` = null in S1, `rag_group_key`, no `sorting_usage`). `MessageSorter` is never invoked on this path.
- No `agentId` ⇒ the method body is the old one, line for line; the snapshot file shows no diff in the PR. `ChatHandler` receives `$options['runtime_profile']` from the resolver; the reply message gets `BMESSAGEMETA` `AGENTID` too, so the chat list can show the assistant later (S2).

### 2.8 CRUD API with OpenAPI (`AB8`)

`App\Controller\AgentController`, prefix `/api/v1/agents`, session or API key (new scope `agents:*` in `ApiKeyScope`; wildcard keys pass as today):

| Method | Path | Body / result |
| ------ | ---- | ------------- |
| `GET` | `/api/v1/agents` | Owner's assistants: `id`, `slug`, `name`, `description`, `icon`, `status`, `updatedAt` — never the draft JSON in the list |
| `POST` | `/api/v1/agents` | `{ name, description?, icon?, promptId?, draft? }` → 201 with the full agent incl. `draft` |
| `GET` | `/api/v1/agents/{id}` | Full agent incl. `draft`, `promptId`, `parentId`, `source` |
| `PATCH` | `/api/v1/agents/{id}` | Partial: `name`, `description`, `icon`, `draft` (validated whole), `routable` (stored, no effect until S4) |
| `DELETE` | `/api/v1/agents/{id}` | 204; drafts only in S1 (published / archived arrive in S3) |

Full `@OA` annotations (required fields, examples, 400 body with `path` of the offending key). Another user's id ⇒ 404. `make -C frontend generate-schemas` in the same PR so `frontend/src/generated/api-schemas.ts` carries `AgentSchema`, `AgentDefinitionSchema`, `CreateAgentRequestSchema` for S2.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C1 | `AgentControllerFlagOffTest`: every route 404 with `AGENTS.ENABLED = 0`; `PromptControllerTest` unchanged and green |
| C2 | `RoutingCharacterizationTest` green without re-record; `MessageClassifierAgentPinTest` asserts the `MessageSorter` mock is never called when `agentId` is set and *is* called otherwise |
| C3 | Existing `PromptController` API tests untouched; `AgentService::create()` uses the `PromptService` public API only |
| C8 | New paths `backend/src/Service/Agent/**`, `backend/src/Service/Runtime/**`, `backend/src/Controller/AgentController.php` added to the `backend-only` allow-list in `.github/mobile-impact-policy.json`; `node scripts/mobile-impact.mjs` run in the PR |

- Unit: `AgentDefinitionValidatorTest` (unknown key at root / nested, bad model key shape, `memory != user`, defaults round-trip), `AgentSluggerTest`, `AgentRuntimeResolverTest` (model fallback note, own-folder group key, foreign owner ⇒ exception), `RuntimeProfileFactoryTest` (defaults equal today's classification-derived values).
- Feature: `AgentControllerTest` (CRUD, owner scoping, 404 for foreign id, PATCH with invalid draft ⇒ 400 with path), `StreamAgentIdTest` (meta `AGENTID` written on both messages; flag off ⇒ ignored).
- Gate: unfiltered `make -C backend lint && make -C backend phpstan && make -C backend test`; `vue-tsc` after schema regeneration.

---

## 4. Exit criteria / demo

1. Flag off: gate green, `routing_classification.json` unchanged, no new route reachable.
2. Flag on: via Swagger UI the demo user creates "Contract review" with a draft naming `models.chat = anthropic:…:chat`, posts to `/api/v1/messages/stream` with `agentId`, and the reply uses that prompt and model; the message meta shows `AGENTID`. A draft naming a model the catalog lacks still answers (fallback note at debug level).
3. Runtime config exposes `agentsEnabled`; Zod schemas regenerated.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| AB1 | `feat(agents): add BAGENTS table (Galera-safe migration)` | backend-only | — |
| AB2 | `feat(agents): add BAGENTVERSIONS table` | backend-only | AB1 |
| AB3 | `feat(agents): add AGENTS.ENABLED flag, seeder and runtime config` | backend-only | — |
| AB4 | `feat(agents): add agent.v1 definition validator (deny unknown keys)` | backend-only | — |
| AB5 | `feat(agents): add Agent entities, repositories and AgentService` | backend-only | AB1, AB2, AB4 |
| AB6a | `refactor(chat): route ChatHandler model, prompt and RAG scope through RuntimeProfile` | backend-only | — |
| AB6b | `feat(agents): add AgentRuntimeResolver producing a RuntimeProfile` | backend-only | AB5, AB6a |
| AB7 | `feat(chat): pin a chat to an assistant via agentId (classifier early return)` | backend-only | AB6b, AB3 |
| AB8 | `feat(agents): add owner CRUD API with OpenAPI and generated schemas` | backend-only | AB5, AB3 |

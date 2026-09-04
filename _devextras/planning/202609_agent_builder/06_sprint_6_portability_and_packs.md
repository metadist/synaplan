# Sprint S6 — Portability and packs

**Track 2 (Agent Builder), sprint 6 of 6.** Steps `AB40`–`AB49`.

**Goal:** A user exports their assistants and instructions as a `synaplan-bundle.v1` file and imports it on another instance with a different model catalog; the import shows a checklist of what is missing ("needs a model", "needs a key") and never creates shares, credentials or file rows. The same section format ships assistants inside plugins. Assistants become addressable from outside: `assistant:<slug>` in the OpenAI gateway's `/v1/models` and a `list_assistants` MCP tool.
**Depends on:** S3 (published definitions are what is exported), S5 (tasks and channel defaults are part of the definition). Track 1 S1 for the admin variant's audit row. Manifest v2 `provides.*` (open-plugin-platform plan) — if it has not landed, `AB46` lands the `provides.agents` reader as the first `provides.*` key.
**Unlocks:** track 3 (`model_preferences` section), track 4 (`mcp_servers`, `custom_tools`, `saved_tasks` sections), the connections owner (`connections`). **This file is the reference those sprint files cite (§3.1).**
**Repos:** `synaplan/` only. **Class:** `backend-only` + `ota-candidate`.
**Flag:** `AGENTS.ENABLED` gates the `agents` section, the alias and the MCP tool; the bundle endpoints themselves are gated by `BUNDLE.ENABLED` (seeded `0`) so later tracks can ship sections while assistants stay off on an install.

---

## 0. Why this sprint exists

Roadmap §8.1: if organizations are instances, people must be able to move their work between instances. A good assistant must also be shippable in a plugin and reachable from a coding client or an office sidebar. This sprint defines the one archive every track exports into, so there is exactly one importer, one checklist and one "never export secrets" rule.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Controller/WidgetExportController.php` | Existing per-resource export style (`/api/v1/widgets/{id}/export`, `/formats`) |
| `backend/src/Service/Agent/Definition/AgentDefinitionValidator.php` (`AB4`) | Deny-unknown-keys validator the bundle validator wraps |
| `backend/src/Model/ModelCatalog.php` | `findBidByKey()` — how the import detects "needs a model" |
| `backend/src/Kernel.php` `getPlugins()`, `backend/src/Service/Plugin/PluginManager.php` | Manifest glob `plugins/*/manifest.json`, `id` / `namespace` keys — the `provides.agents` reader goes here |
| `backend/src/Controller/OpenAICompatibleController.php` | `listModels()` at `/v1/models` (`id` = provider id or name) — the alias is appended |
| `backend/src/Mcp/McpServerFactory.php` | `list_prompts` tool + `listPromptsHandler()`; `synaplan_chat` — siblings `list_assistants` and `agentId` |
| `backend/src/Service/RateLimitService.php` | `checkLimit(User, string $action)` — import size and frequency limits |
| `backend/src/Controller/AdminSystemConfigController.php` | `/api/v1/admin/config/schema` + `/values` — the admin-level settings the instance bundle carries |
| `frontend/src/views/SettingsView.vue`, `frontend/src/views/AdminConfigView.vue` | Where the **Export & import** sections are added |
| `_devextras/planning/20260903_roadmap.md` §8.1 | Binding decisions: sections, "never" list, who, format rule |

---

## 2. Developer steps

### 2.1 Format decision and the bundle envelope (`AB40`)

**A single JSON document** (`*.synaplan-bundle.json`, UTF-8, ≤ 5 MB), not a zip. Reasons: v1 carries no binaries (roadmap §8.1 — knowledge files are v2), a JSON file is reviewable in a diff and in a plugin-pack PR, one validator covers envelope and sections with deny-unknown-keys, and it removes the zip attack surface (path traversal, nested bombs, symlinks). When files join in v2 the envelope becomes `manifest.json` inside a zip declared `synaplan-bundle.v2` — the section contract does not change.

```json
{
  "schema": "synaplan-bundle.v1",
  "createdAt": "2026-10-01T09:12:00Z",
  "sourceInstance": "sha256:9f2c…",
  "sourceVersion": "3.14.0",
  "scope": "user",
  "sections": [
    { "kind": "prompts", "version": 1, "items": [ { "key": "contract-review", "topic": "agent:contract-review", "text": "…", "description": "…" } ] },
    { "kind": "agents", "version": 1, "items": [ { "key": "contract-review", "name": "Contract review", "prompt": "contract-review", "definition": { "schema": "agent.v1", "…": "…" } } ] }
  ]
}
```

- `sourceInstance` = `sha256` of an `APP_SECRET`-derived instance salt — stable, non-reversible, used only to badge "from another instance"; never the URL. `scope` ∈ `user` / `instance`.
- `App\Bundle\BundleEnvelopeValidator`: schema string, ISO date, known `scope`, every `sections[].kind` registered, unknown keys rejected with path, JSON depth ≤ 32.
- `BundleSectionInterface`, tagged `app.bundle.section`; `BundleSectionRegistry` orders sections by `dependsOn()`; `BundleExporter::export(userId, scope, kinds[])` and `BundleImporter::preview()` / `apply()` are the only callers:

```php
interface BundleSectionInterface
{
    public function kind(): string;                                                    // 'agents'
    public function version(): int;                                                   // section schema version
    public function export(int $userId, BundleScope $scope): array;                    // items, stable keys only
    public function preview(array $items, int $userId): SectionPreview;               // checklist, no writes
    public function apply(array $items, int $userId, ImportOptions $options): SectionResult; // writes
    public function dependsOn(): array;                                               // e.g. ['prompts']
}
```

**Stable-key rule:** items reference each other by keys, never BIDs — models as `service:providerId:tag`, prompts by `key` (slug), tools by owner-scoped name, MCP servers by name.

### 2.2 `agents` section (`AB41`)

`AgentBundleSection` exports published definitions (`BDEFINITION` of the published version; drafts opt-in per assistant) with `key = BSLUG`, `prompt = <prompt key>`, `parent` (slug, only if the parent is in the same bundle), `icon`, `description`. **Stripped on export:** `knowledge.folders` entries other than the own folder (they name another owner); `tools.mcpServers` ids are replaced by MCP server *names* so track 4's section can rebind. `preview()` per item: unresolvable `models.*` ⇒ **needs a model** (`chat`, …); unknown MCP server names ⇒ **needs an MCP server**; dropped folders ⇒ note. `apply()` creates **drafts** (`BSOURCE = import`, `BSTATUS = draft`, no version, no share), slug deduplicated with `-imported`.

### 2.3 `prompts` section (`AB42`)

`PromptBundleSection` exports the user's own non-`tools:` prompts (`key`, `topic`, `text`, `description`, `meta` reduced to model keys and tool flags — `mcp_servers` ids become names). `apply()` creates rows through `PromptService` (C3); an existing topic for the same user ⇒ **skip** or **overwrite** per `ImportOptions::$conflict` (default `skip`). Unresolvable model keys ⇒ **needs a model**, binding left empty.

### 2.4 Exporter, importer, API, rate limits (`AB43`)

| Method | Path | Purpose |
| ------ | ---- | ------- |
| `GET` | `/api/v1/bundle/sections` | Registered kinds with `version`, `dependsOn`, item counts for the caller |
| `POST` | `/api/v1/bundle/export` | `{ kinds: [...], include: { agents: [slugs] } }` → the JSON document (`Content-Disposition: attachment`) |
| `POST` | `/api/v1/bundle/preview` | Body = bundle → checklist per section (`needsModel`, `needsKey`, `needsCredential`, `needsMcpServer`, `conflicts`, `dropped`) |
| `POST` | `/api/v1/bundle/import` | Body = bundle + `options` → `SectionResult[]` (created / skipped / failed with reason) |
| `GET` | `/api/v1/agents/{id}/export` | Convenience: one assistant + its prompt as a bundle (master plan §6) |
| `POST` | `/api/v1/agents/import` | Convenience: bundle restricted to `agents` / `prompts` |

Limits via `RateLimitService` actions `bundle_export` (20 / h) and `bundle_import` (10 / h); body ≤ 5 MB (413), ≤ 200 items per section, ≤ 20 sections. Import runs one transaction per section; a failed section rolls back only itself and is reported. Admin variant: same routes with `scope = instance` (admin only) adding the `instance_settings` section — `AdminSystemConfigController` values **minus** every key the config schema flags `secret: true` (missing ⇒ **needs a key**); one `BAUDITLOG` row (track 1) per admin import. Full OpenAPI; Zod regenerated.

### 2.5 Settings → Export & import (user) and Operate variant (`AB44`, `AB45`)

- `SettingsView.vue` section `section-export-import` (`ExportImportPanel.vue`): section checkboxes from `/bundle/sections`, **Export** (download), **Import** (file picker → `/bundle/preview` → checklist dialog with per-item conflict choice → `/bundle/import` → result list). Checklist wording in five locales, namespace `bundle`: "Needs a model — pick one after import", "Needs a key — add it under Connections", "Skipped — already exists".
- `AdminConfigView.vue` gets the same panel with `scope = instance` under an **Export & import** heading; excluded secret keys are listed visibly as "not included". The gallery card **Export** action and the builder **Import** button reuse the panel.

### 2.6 Plugin packs — `provides.agents` (`AB46`)

Manifest v2 key `provides.agents: ["agents/*.json"]`; each file is a `synaplan-bundle.v1` document restricted to `agents` + `prompts`. `PluginAgentInstaller` runs at plugin enable (admin action): imports as the enabling admin with `BSOURCE = plugin:<id>`, publishes v1, shares with `everyone` (`use`) through `AccessGate` — the one place an import *does* create a share, because the admin explicitly enabled the plugin; the admin can unshare. Re-enable with a changed file ⇒ new version, not a new assistant. Example pack `plugins/hello_world/agents/hello.json`.

### 2.7 `assistant:<slug>` in `/v1/models` (`AB47`)

`agent.v1` gains optional `channels.modelAlias: true` (default `false`). `OpenAICompatibleController::listModels()` appends `{ id: "assistant:<slug>", object: "model", owned_by: "synaplan" }` for every published assistant with the alias on that the key's user may `use`. `/v1/chat/completions` with such a `model` resolves the assistant, sets `agentId` and runs the pinned path on the assistant's `models.chat`. Unknown alias ⇒ the existing "model not found" error; `ApiKeyScope` unchanged (aliases need `messages:*` or wildcard as today).

### 2.8 `list_assistants` MCP tool and `agentId` on `synaplan_chat` (`AB48`)

`McpServerFactory`: tool `list_assistants` (read-only, `openWorldHint: false`) returns `slug`, `name`, `description`, `version`, `origin` for the user's gallery; the `synaplan_chat` input schema gains optional `agentId` (slug or int), forwarded into the classification options. Desktop and Outlook clients pick the tool up with no change on their side.

### 2.9 C7 tests (`AB49`)

Dedicated test PR, see §3.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C1 | `BUNDLE.ENABLED = 0` ⇒ bundle routes 404; `AGENTS.ENABLED = 0` ⇒ `agents` section absent from `/bundle/sections`, alias and MCP tool absent |
| C3 | `PromptBundleSection` writes only through `PromptService`; prompt API tests green |
| C7 | `BundleImportNeverTest`: importing a bundle that names folders, MCP servers, credentials and other users' slugs leaves `BSHARES`, `BCREDENTIALS`, `BFILES`, `BMCPSERVERS` row counts unchanged; every created `BAGENTS` row is owned by the importer, is a `draft` and has no version |
| C7 | `BundleExportNeverTest`: the export JSON contains no `sk_`, no `BAPIKEYS` material, no numeric user ids outside the importer's own folder key, no `BDRAFT` of unpublished assistants unless opted in (regex + structural assertions) |
| C7 | `PluginAgentInstallerTest`: the only share created is `everyone/use` by the admin at enable; disable removes it |
| C8 | New paths `backend/src/Bundle/**`, `backend/src/Service/Plugin/PluginAgentInstaller.php` listed `backend-only`; Vue `ota-candidate` |

- Unit: `BundleEnvelopeValidatorTest` (unknown key with path, wrong schema, depth limit, order by `dependsOn`), `AgentBundleSectionTest` (strip rules, needs-a-model per capability, slug dedupe), `PromptBundleSectionTest` (skip vs overwrite, model key miss), `BundleSectionRegistryTest` (a fake section from a test plugin registers via the tag).
- Feature: `BundleRoundTripTest` — export on catalog A, import on a kernel booted with catalog B (different `BMODELS` fixture) ⇒ drafts created, checklist names exactly the missing keys; `BundleRateLimitTest` (11th import ⇒ 429, 6 MB body ⇒ 413); `OpenAiAliasTest` (`/v1/models` lists the alias only with `use`; completion pinned); `McpListAssistantsTest`.
- Frontend: `ExportImportPanel.spec.ts` (checklist rendering, conflict choice sent). Unfiltered gates.

### 3.1 Sections owned by later tracks (binding — cite this file)

| Kind | Track / sprint | Stable key | Never exported |
| ---- | -------------- | ---------- | -------------- |
| `model_preferences` | Track 3 (AI Plugs), the sprint that owns the AI infrastructure page | `service:providerId:tag` per capability | Provider keys, endpoint credentials |
| `mcp_servers` | Track 4 S1 (tool registry) | Server name (owner-scoped) | Auth headers, OAuth tokens (⇒ **needs a credential**) |
| `custom_tools` | Track 4 S3 (`BTOOLS`) | Tool name (owner-scoped) | `BCREDENTIALS` references (⇒ **needs a credential**) |
| `saved_tasks` | Track 4 S5 (workflow builder) | Task name + prompt key | `BCHATID`, run history |
| `connections` | Connections owner (track 4 or the channels plan) | Connection name + type + URL | Passwords, tokens |

Each of those sprints registers one class implementing `BundleSectionInterface`, adds a `*BundleSectionTest` with the C7 "never" assertions, and appears in `ExportImportPanel` automatically via `/bundle/sections` — no frontend change needed.

---

## 4. Exit criteria / demo

1. On instance A the admin exports "Contract review" + its instruction. On instance B (different catalog, no Anthropic key) the preview says "Needs a model: chat"; after import the draft opens in the builder with the model picker highlighted; nothing else was created (C7 suite).
2. Enabling `hello_world` installs its pack; the gallery shows it under **From plugins** for every user; the admin unshares it.
3. A coding client lists `assistant:contract-review` in `/v1/models` and a completion against it runs pinned; the Desktop client's MCP session shows `list_assistants`.
4. Settings → Export & import and Operate → System config → Export & import both work; the admin variant lists the excluded secret keys explicitly.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| AB40 | `feat(bundle): add synaplan-bundle.v1 envelope, section interface and registry` | backend-only | — |
| AB41 | `feat(bundle): add agents section (export, preview checklist, import as draft)` | backend-only | AB40, AB18 |
| AB42 | `feat(bundle): add prompts section` | backend-only | AB40 |
| AB43 | `feat(bundle): add export/preview/import endpoints with rate limits and admin scope` | backend-only | AB41, AB42 |
| AB44 | `feat(settings): add Export & import panel (user scope)` | ota-candidate | AB43 |
| AB45 | `feat(admin): add Export & import to System config (instance scope)` | ota-candidate + backend-only | AB43 |
| AB46 | `feat(plugins): install assistant packs from manifest provides.agents` | backend-only | AB41, AB20 |
| AB47 | `feat(gateway): expose opt-in assistant:<slug> aliases in /v1/models` | backend-only | AB21 |
| AB48 | `feat(mcp): add list_assistants tool and agentId on synaplan_chat` | backend-only | AB21 |
| AB49 | `test(bundle): add C7 never-export / never-create suites and catalog round-trip` | backend-only | AB43, AB46 |

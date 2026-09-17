# Sprint S6 — Plugin adapters

**Track 3 (AI Plugs), sprint 6 of 6.** Steps `PL39`–`PL43`.

**Goal:** A third party ships a web search (or extraction, or rerank) adapter
as a plugin: a manifest v2 `provides.plugs` entry plus one PHP class. The
registry picks it up, the boot-time check refuses undeclared adapters, the
AI infrastructure page renders its descriptor, and no line in `backend/src/`
changes. One reference plugin proves it.
**Depends on:** S1 (registries with `_instanceof` tagging), S3 `PL16`/`PL23`
(`PlugKeyStore`, Web search tab rendering from descriptors). Coordinates with
`20260822-open-plugin-platform` S1 item 4 (manifest v2 parser): if that
parser is merged first, `PL39` extends it; otherwise `PL39` introduces the
minimal `provides` reader in the shape that plan specifies.
**Unlocks:** the plugin catalog can list plugs; the cut line of this track
(master plan §8: S6 is cut first if capacity runs out).
**Repos:** `synaplan/` (`backend/`, `plugins/`, docs), `synaplan-docs/` (one section).
**Flag:** none — an adapter is inert until an admin selects it.

---

## 0. Why this sprint exists

`Kernel` already autoloads and autoconfigures every class under a plugin's
`backend/` directory, so a plugin class implementing `WebSearchProviderInterface`
is tagged `app.plug.web_search` today by accident. This sprint makes that
intentional and safe: declared in the manifest, checked at boot, documented
for authors, and demonstrated with a plugin that touches nothing in core.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Kernel.php` lines 74–146, 160–165 | Plugin discovery (`plugins/*/manifest.json`, `/plugins` or `backend/plugins`), namespace autoload, autoconfiguration |
| `backend/src/Service/Plugin/PluginManager.php` | Manifest reading (`listAvailablePlugins()`, `installPlugin()`), the place for the v2 `provides` reader |
| `plugins/hello_world/manifest.json`, `plugins/castingdata/manifest.json`, `plugins/synafastbill/` | Manifest v1 shape and the smallest plugin layout (`backend/`, `frontend/`, `migrations/`) |
| `_devextras/planning/20260822-open-plugin-platform/README.md` §3.1, §"provides.skills" (lines 184–189), table lines 259–265 | Manifest v2 shape and the skills boot check this sprint mirrors |
| `backend/src/Plug/WebSearchRegistry.php`, `ExtractionRegistry.php`, `RerankRegistry.php` (S1) | `descriptors()`, key uniqueness; where the duplicate-key rule goes |
| `backend/src/Plug/PlugDescriptor.php`, `PlugKeyStore.php` (S1/S3) | What a plugin adapter must return; how it stores a key |
| `backend/src/Plug/WebSearch/Adapter/TavilyAdapter.php` (S3) | The smallest core adapter — the template for the reference plugin |
| `backend/src/Service/Multitask/Skill/SkillCatalog.php` | `#[AutowireIterator('app.multitask.runner')]` — how plugin-tagged services already flow into a core registry |
| `frontend/src/components/admin/plugs/WebSearchPlugTab.vue` (S3) | Must render plugin descriptors without change — verify, do not edit |
| `backend/tests/Unit/Plug/PlugBoundaryTest.php` (S1) | Extended with the "no plugin name in core" rule |

---

## 2. Developer steps

### 2.1 Manifest v2 `provides.plugs` (`PL39`)

`App\Service\Plugin\Manifest\PluginManifest` value object (or the v2 parser
from the plugin-platform plan) reads:

```json
{
  "id": "serper_search",
  "namespace": "Plugin\\SerperSearch",
  "version": "1.0.0",
  "minSynaplanVersion": "4.2",
  "provides": {
    "plugs": [
      { "port": "web_search", "class": "Plugin\\SerperSearch\\Plug\\SerperSearchAdapter", "key": "serper" }
    ]
  },
  "permissions": ["network:google.serper.dev", "plug_keys"]
}
```

`port` ∈ `extraction | web_search | rerank` (maps to `app.plug.extractor` /
`app.plug.web_search` / `app.plug.rerank`); `class` must be under the plugin
namespace; `key` must match `class::key()`. A manifest without `provides` is
valid (back-compat). `PluginManifestTest` covers valid, unknown port (reject),
class outside namespace (reject).

### 2.2 Boot-time declaration check and key uniqueness (`PL40`)

`App\Plug\DependencyInjection\PlugDeclarationCheckPass` registered in
`Kernel::build()`: for every service tagged `app.plug.*` whose class lives in
a discovered plugin namespace, require a matching `provides.plugs` entry
(port, class, key); an undeclared or mis-declared adapter fails container
compilation with `Plugin "serper_search" registers Plugin\SerperSearch\Plug\SerperSearchAdapter for port web_search but does not declare it in manifest.json provides.plugs`.
Core adapters (`App\Plug\**`) are exempt. Each registry throws
`DuplicatePlugKeyException` when two adapters share a key (core wins nothing —
it is a boot error; plugin authors are told to prefix with the plugin id when
in doubt). `PlugDescriptor` gains `pluginId: ?string` so the admin tabs can
show a "from plugin X" hint — the Vue tabs already render label, docs URL,
sovereignty and capabilities from descriptors and need no change.

### 2.3 Adapter author documentation (`PL41`)

`plugins/README.md` (new; the directory has none) section **Contributing a
plug adapter** and the same content in `synaplan-docs/docs/plugins.md`:

1. Pick the port; implement the interface from `backend/src/Plug/<Port>/`.
2. Return a complete `PlugDescriptor` (label, `docsUrl`, `requiredSettings`, sovereignty `self-hosted | eu | us_cloud`, `pluginId`).
3. Secrets: `PlugKeyStore::getKey('<key>')`; declare `"plug_keys"` in `permissions`; never read env directly, never log the key.
4. `health()` must be cheap and never throw; network failures → `PlugHealth::unavailable(reason)` (C7 applies to plugins too).
5. Declare the adapter in `provides.plugs`; boot fails otherwise.
6. Ship a contract test on recorded fixtures under `plugins/<id>/backend/tests/`; no live network in tests.
7. Locales: descriptor labels are plain strings (plugins have no i18n hook in v1 — documented limitation).

### 2.4 Reference plugin: `plugins/serper_search/` (`PL42`)

Chosen because it is the smallest real search API (one `POST`, one header):

```text
POST https://google.serper.dev/search   header X-API-KEY: <key>
     { "q": "<query>", "num": 10, "gl": "<country>", "hl": "<lang>", "tbs": "qdr:w" }
→ 200 { "organic": [ { "title", "link", "snippet", "date", "position" } ], "knowledgeGraph": {…} }
```

Layout: `manifest.json` (above), `backend/Plug/SerperSearchAdapter.php`
(`key = serper`, capabilities `{freshness, country, language, siteFilter}`,
sovereignty `us_cloud`, `health()` = key present), `backend/tests/SerperSearchAdapterContractTest.php`
with `backend/tests/fixtures/serper-search.json`, `README.md` (key from
serper.dev, how to select it in Web search). No `frontend/`, no `migrations/`.
The PR diff touches only `plugins/serper_search/**` — reviewers verify this
with `git diff --stat main -- backend/src` being empty.

### 2.5 Zero-core-edit tests (`PL43`)

- `Kernel::resolvePluginsDir()` honours `PLUGINS_DIR` (test-only override, documented in `backend/.env.test`), so tests can boot with a fixture plugin directory.
- `backend/tests/Fixtures/plugins/fixture_search/` — minimal declared adapter (`key = fixture_search`, returns a fixed `SearchResultSet`).
- `PluginPlugAdapterIntegrationTest`: boot with the fixture dir → `WebSearchRegistry::byKey('fixture_search')` exists; `GET /api/v1/admin/plugs/web-search` lists it with `pluginId`; set it active → `WebSearchRegistry::search()` returns the fixture results; characterization snapshots are recorded without plugins (plugin-platform discipline) and stay untouched.
- `backend/tests/Fixtures/plugins/undeclared_search/` — same class, manifest without `provides.plugs`; `UndeclaredPlugAdapterTest` asserts container compilation fails with the message above.
- `PlugBoundaryTest` extended: `backend/src/**` contains no `serper` / `Serper` string.
- `plugins/serper_search` contract test runs in the backend PHPUnit suite via the existing plugin test discovery (or an added `<directory>` in `phpunit.xml.dist` if none exists — noted in the PR).

---

## 3. Tests and invariants

| Invariant | Proof in this sprint |
| --------- | -------------------- |
| C3 | Snapshots recorded with zero plugins; `PluginPlugAdapterIntegrationTest` uses its own kernel boot, never the characterization one |
| C4 | `PlugBoundaryTest` (no plugin identifiers in core); plugin adapters reach Tika/Brave-class services only through the ports |
| C7 | Fixture plugin with `health()` unavailable → fallback / empty set, upload and chat unaffected; author docs make it a rule |
| C8 | Steps are `backend-only`; `plugins/**` is already `backend-only` in `.github/mobile-impact-policy.json` (server-delivered plugins) |
| C2 | No `PLUGS` seed change; a plugin adapter is never the default |

Also: `PluginManifestTest`, `PlugDeclarationCheckPassTest` (declared ok,
undeclared fails, wrong port fails, duplicate key fails), `SerperSearchAdapterContractTest`
(result mapping, freshness → `tbs`, missing key → unavailable).

---

## 4. Exit criteria / demo

1. Drop `plugins/serper_search/` into a checkout, `docker compose restart backend`, enter the key in the Web search tab: Serper appears with its badge and "from plugin serper_search", "Test query" returns results, selecting it makes the next chat search use it.
2. Remove the `provides.plugs` entry: the backend refuses to boot with a message naming plugin, class and port.
3. `git diff main --stat -- backend/src frontend/src` for the reference plugin PR: empty.
4. Full gate green; snapshots untouched; docs section published.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| PL39 | `feat(plugins): parse manifest v2 provides.plugs declarations` | backend-only | PL2, plugin-platform S1.4 (coordinate) |
| PL40 | `feat(plugs): fail boot on undeclared plugin adapters and duplicate plug keys` | backend-only | PL39 |
| PL41 | `docs(plugins): document how a plugin contributes a plug adapter` | backend-only | PL40 |
| PL42 | `feat(plugins): add serper_search reference web search adapter plugin` | backend-only | PL40, PL16 |
| PL43 | `test(plugs): prove plugin adapters need zero core edits with fixture plugins` | backend-only | PL40, PL23 |

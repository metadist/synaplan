# Sprint S5 — Model import

**Track 3 (AI Plugs), sprint 5 of 6.** Steps `PL32`–`PL38`.

**Goal:** An admin imports the models an OpenAI-compatible endpoint or the local Ollama offers in one minute: preview
with guessed (editable) tags, optional capability probe, apply creates only new `BMODELS` rows, re-import changes
nothing, and a scheduled re-check soft-disables models the endpoint no longer lists. The user's model preferences
become a portable bundle section.
**Depends on:** S4 `PL25` (the `rerank` tag exists for guessing); track 2 S6 (`BundleSectionInterface`, tag
`app.bundle.section`, `202609_agent_builder/06_sprint_6_portability_and_packs.md`) for `PL37` only.
**Unlocks:** hosters with vLLM / LiteLLM / TGI endpoints stop typing models by hand (hosting-partner CORE-1 P3 closes).
**Repos:** `synaplan/` only.
**Flag:** none — import is an admin action; the probe is an opt-in checkbox per preview (decision §12.8).

---

## 0. Why this sprint exists

`OpenAiCompatibleEndpointRegistry::testConnection()` already lists `/models` and throws the list away. Admins then add
each model by hand in `AIModelsAdminPanel.vue`, guessing the tag. Twice requested, small to build, and the seeder rules
(`BSELECTABLE` / `BACTIVE` / `BISDEFAULT` are operator property) make idempotency a solved problem.

**Route collision recorded:** `POST /api/v1/admin/models/import/preview|apply` from master plan §4.4 already exists
(`AdminModelsController::importPreview()` / `importApply()`, `AdminModelsService::generateImportPreview()`): it is the
AI-generated **SQL import from pricing pages**. This sprint therefore uses `/api/v1/admin/models/import/endpoint/{preview,apply}`;
the master plan path is amended in `STATUS.md` with this sprint's first PR.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/AI/Credential/OpenAiCompatibleEndpointRegistry.php` | `testConnection()` (GET `{base}/models`), `listEndpoints()`, `getEndpoint()`, `CONFIG_GROUP = 'openai_compatible'`, `SERVICE = 'OpenAICompatible'` |
| `backend/src/AI/Service/OllamaModelInventory.php` | `isPulled()` via `OllamaProvider` — grows a `listPulled()` (`GET /api/tags`) |
| `backend/src/Controller/AdminModelsController.php` lines 237–380, `backend/src/DTO/AdminModelsImport*Request.php`, `backend/src/Service/Admin/AdminModelsService.php` | The existing SQL import (keep; different route), the OpenAPI style to copy, and the upsert helpers where the applier lives |
| `backend/src/Seed/ModelSeeder.php`, `backend/src/Entity/Model.php` | Operator-toggle preservation on re-seed — the rule the applier copies (C6); `BSELECTABLE`, `BACTIVE`, `BISDEFAULT`, `BJSON` |
| `backend/src/Command/ModelHealthCheckCommand.php`, `backend/src/AI/Health/ModelAutoDisabler.php`, `ModelHealthState.php`, `backend/src/Entity/ModelHealth.php` | Existing scheduled health check and soft-disable (`BAUTODISABLED`) — the re-check extends this, no second scheduler |
| `_devextras/planning/20260826-provider-aware-model-catalog/README.md` §3.1, PR A | Availability semantics; `app:model:disable` is soft; rows are never deleted |
| `frontend/src/components/config/AIModelsAdminPanel.vue` (lines 371–440), `frontend/src/services/api/adminModelsApi.ts`, `adminOpenAiEndpointsApi.ts` | Existing bulk SQL import UI, model API client, endpoint cards |
| `frontend/src/components/admin/plugs/ModelsAndKeysTab.vue` (S2), `backend/tests/Fixtures/openai-compatible/` | Where the Import buttons go; existing fixture directory for `/models` responses |

---

## 2. Developer steps

### 2.1 Discovery and tag guessing (`PL32`)

`App\AI\Import\ModelDiscoveryService::discover(string $source): list<DiscoveredModel>` — `source` is
`openai_compatible:<endpointName>` (uses `getEndpoint()` + the `/models` call factored out of `testConnection()`) or `ollama`
(`OllamaModelInventory::listPulled()`, new, `GET {OLLAMA_BASE_URL}/api/tags` → `models[]{name, size, details.family}`).
`App\AI\Import\ModelTagGuesser::guess(string $id): list<string>` by name patterns only — `embed|bge|e5-|minilm|nomic|arctic-embed`
→ `vectorize`; `rerank` → `rerank`; `whisper|parakeet` → `sound2text`; `tts|speech|kokoro|orpheus` → `text2sound`;
`flux|stable-diffusion|sdxl|dall-e` → `text2pic`; `vision|-vl|llava|pixtral|gemma-3|qwen.*vl` → `chat` + `pic2text`; otherwise `chat`.
Exists check on `(BSERVICE, BJSON.meta.import.endpoint, BPROVID)`. Fixtures: `tests/Fixtures/openai-compatible/models-vllm.json`,
`models-litellm.json`, `tests/Fixtures/ollama/tags.json`.

### 2.2 Opt-in capability probe (`PL33`)

`App\AI\Import\CapabilityProbe::probe(endpoint, providerId): ProbeResult{chat, embeddings, ms}` — one `POST /chat/completions`
with `max_tokens: 1` and one `POST /embeddings` with `input: "ping"`, each 8 s timeout, OpenAI-compatible sources only. Results
override the guess (`embeddings ok` adds `vectorize`, `chat fail` removes `chat`), reported per row as
`probe: {chat: "ok"|"fail"|"skipped", embeddings: …}`. Runs only when the preview request sets `probe: true`; at most 50 rows
per call; the response carries `probeCostNote`. Name guessing always runs (master plan §8 cut line keeps this step optional).

### 2.3 Preview / apply API (`PL34`)

```text
POST /api/v1/admin/models/import/endpoint/preview
  { "source": "openai_compatible:vllm-lab" | "ollama", "probe": false }
  → { "source": …, "rows": [ { "providerId": "Qwen/Qwen3-32B", "name": "Qwen3 32B", "guessedTags": ["chat"], "exists": false, "probe": null } ], "endpointOk": true, "error": null }

POST /api/v1/admin/models/import/endpoint/apply
  { "source": …, "rows": [ { "providerId": "Qwen/Qwen3-32B", "name": "Qwen3 32B", "tags": ["chat","pic2text"] } ] }
  → { "created": 12, "skipped": 3, "rows": [ { "providerId", "tag", "status": "created"|"exists" } ] }
```

`ModelImportApplier` (in `AdminModelsService`): one `BMODELS` row per tag (as the catalog does for multi-capability models),
`BSERVICE = OpenAICompatible` (`BJSON.meta.endpoint = <name>`, resolved later by `resolveForModel()`) or `ollama`, `BPROVID = providerId`,
`BNAME`, `BTAG`, `BJSON.meta.import = {source, importedAt, lastSeenAt}`, `BSELECTABLE = 1, BACTIVE = 1, BISDEFAULT = 0` **on create
only**. Existing rows: only `BJSON.meta.import.lastSeenAt` is touched; toggles, prices, names never (C6). Full OpenAPI, then
`make -C frontend generate-schemas`. Admin only; unknown endpoint → 404; unreachable endpoint → 200 with `endpointOk: false`.

### 2.4 Scheduled re-check marks vanished models unavailable (`PL35`)

Extend `app:model:health-check` (already scheduled) with a listing pass: for every distinct `BJSON.meta.import.source`, call
`ModelDiscoveryService::discover()` once; imported rows missing from the listing get a `ModelHealth` record with `state = offline`,
`source = listing`, `message = "not offered by endpoint"`; `ModelAutoDisabler::apply()` performs the existing soft-disable
(`BACTIVE = 0`, `BAUTODISABLED = 1`, row kept). A model that reappears is restored by the same evaluator. The admin catalog
(`?includeUnavailable=1`) shows the reason badge from the provider-aware catalog plan. An unreachable endpoint marks **nothing** —
only a successful listing without the model does.

### 2.5 Import UI in Models & keys (`PL36`)

`ModelsAndKeysTab.vue`: each OpenAI-compatible endpoint card gets **Import models**; the Ollama card gets **Import pulled
models**. Both open `components/admin/plugs/ModelImportDialog.vue` (< 300 lines): source, preview table (name, provider id,
editable tag chips from the fixed tag list, "already in catalog" pill), checkbox **Probe capabilities (sends two tiny requests
per model, uses tokens)**, "Select all new", Apply → `useNotification().success()` with created/skipped counts. `adminModelsApi.ts`
gains `importEndpointPreview()` / `importEndpointApply()` with generated Zod schemas. The existing bulk SQL import in
`AIModelsAdminPanel.vue` stays; its heading becomes "Import from pricing page (SQL)" so two things are not both called "import".
Namespace `aiInfra.modelImport.*` in five locales.

### 2.6 `model_preferences` bundle section (`PL37`)

`App\Bundle\Section\ModelPreferencesBundleSection implements BundleSectionInterface` (tag `app.bundle.section`, registry from
track 2 S6; this step waits for that merge and is otherwise independent). Export for the requesting user:

```json
{
  "model_preferences": {
    "defaults": { "CHAT": "anthropic:claude-sonnet-5:chat", "VECTORIZE": "ollama:bge-m3:vectorize", "RERANK": null },
    "openaiCompatibleEndpoints": [ { "name": "vllm-lab", "models": ["OpenAICompatible:Qwen/Qwen3-32B:chat"] } ],
    "webSearchProvider": "searxng"
  }
}
```

Rules from roadmap §8.1: catalog keys `service:providerId:tag`, never BIDs; endpoint **names** only — never base URLs with
credentials or any key (`provider_keys`, `plug_keys`, `openai_compatible` secrets are excluded by construction); `deny_unknown_fields`.
Import resolves each key with `ModelCatalog::findBidByKey()`; unresolvable → checklist entry `needs a model`; `webSearchProvider`
applied only when `WEB_SEARCH.USER_OVERRIDE_ALLOWED = 1`, else checklist `needs admin permission`. Instance-level `DEFAULTMODEL.*`
(owner `0`) is exported only by admins through the Operate export.

### 2.7 Docs (`PL38`)

`docs/CONFIGURATION.md`: import flow, probe cost, listing re-check; `docs/ADMIN.md`: importing from vLLM / LiteLLM / Ollama and
what "not offered by endpoint" means; `docs/OPENAI_COMPATIBLE_API.md` cross-link.

---

## 3. Tests and invariants

| Invariant | Proof in this sprint |
| --------- | -------------------- |
| C6 | `ModelImportApplierTest`: apply twice → second run `created = 0`; admin sets `BSELECTABLE = 0` and `BISDEFAULT = 1` on an imported row → re-import leaves both; `BJSON.meta.import.lastSeenAt` is the only change |
| C7 | `ModelDiscoveryServiceTest`: endpoint down → `endpointOk = false`, no exception; `ModelHealthCheckListingTest`: unreachable endpoint marks nothing |
| C8 | `/v1/models` gateway test: imported rows appear like hand-added ones; steps `backend-only` except `PL36` (`ota-candidate`) |
| C2 / C5 | No `PLUGS` or `DEFAULTMODEL` seed change in this sprint (`PlugsConfigSeederTest`, `DefaultModelConfigSeederTest` unchanged) |

Also: `ModelTagGuesserTest` (table-driven, 30 names); `CapabilityProbeTest` (recorded ok / 4xx / timeout, `skipped` when
`probe = false`); `AdminModelsImportEndpointControllerTest` (admin only, 404 unknown endpoint, both response schemas);
`ModelPreferencesBundleSectionTest` (export contains no key material — asserted against `provider_keys` / `plug_keys` values;
unknown field rejected; unresolvable key → checklist); `ModelImportDialog.spec.ts` (tag chip edit emits, probe off by default).

---

## 4. Exit criteria / demo

1. Admin registers a vLLM endpoint, clicks Import models, sees 12 rows with guessed tags, fixes one tag, applies: 12 rows created in under a minute; the chat picker offers them.
2. Import again: `created 0, skipped 12`. Toggle one model off, import again: still off.
3. Remove a model from vLLM; after the next `app:model:health-check` run the row shows "not offered by endpoint" and is soft-disabled; add it back, it recovers.
4. Export bundle contains `model_preferences` with catalog keys and no secret; import on a second instance reports `needs a model` for the missing endpoint models.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| PL32 | `feat(models): add endpoint and Ollama model discovery with name-based tag guessing` | backend-only | PL25 |
| PL33 | `feat(models): add opt-in capability probe for OpenAI-compatible model import` | backend-only | PL32 |
| PL34 | `feat(models): add import-from-endpoint preview and apply API with idempotent upserts` | backend-only | PL32, PL33 |
| PL35 | `feat(models): mark imported models not offered by their endpoint as unavailable in health check` | backend-only | PL34 |
| PL36 | `feat(admin): add model import dialog for OpenAI-compatible endpoints and Ollama` | ota-candidate | PL14, PL34 |
| PL37 | `feat(bundle): add model_preferences bundle section with catalog-key references` | backend-only | PL21, track 2 S6 |
| PL38 | `docs(admin): document model import, capability probe and endpoint re-check` | backend-only | PL36 |

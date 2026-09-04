# Sprint S3 — Web search providers

**Track 3 (AI Plugs), sprint 3 of 6.** Steps `PL16`–`PL24`.

**Goal:** An admin picks the web search provider in the UI — Brave, SearXNG (self-hosted), Tavily, Exa, Firecrawl or Perplexity —
plus an optional fallback; the next chat search uses it without a restart. Perplexity is also an ordinary chat provider in the catalog.
**Depends on:** S1 (`WebSearchRegistry`, `BraveSearchAdapter`, recorded Brave fixtures); S2 `PL14` (tabbed page).
**Unlocks:** S6 reference plugin adapter (a search adapter is the smallest a plugin can ship); S5 `model_preferences` exports the per-user override.
**Repos:** `synaplan/` (backend, frontend, `docker-compose.yml`), `synaplan-platform/` (SearXNG service block, private).
**Flag:** `PLUGS.WEB_SEARCH.PROVIDER` (default `brave`), `PLUGS.WEB_SEARCH.FALLBACK` (default empty), `PLUGS.WEB_SEARCH.USER_OVERRIDE_ALLOWED` (default `0`).

---

## 0. Why this sprint exists

Sovereign installs cannot send queries to a US API; a hoster wants to switch providers per contract; AI-native APIs return
page content that saves a fetch round-trip. After S1 the seam exists; this sprint fills it with five adapters behind one
`SearchResultSet`, so `MessageProcessor`, `WebSearchRunner` and `WebSearchTool` never learn who answered.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Plug/WebSearch/` (S1) | `WebSearchProviderInterface`, `WebSearchQuery`, `SearchResultSet`, `WebSearchCapabilities`, `WebSearchRegistry::active()` |
| `backend/src/Plug/WebSearch/Adapter/BraveSearchAdapter.php` (S1), `backend/src/Service/Search/BraveSearchService.php` | Reference adapter: options mapping (`count`, `country`, `search_lang`, `freshness`), `toLegacyArray()`, `formatResultsForAI()` layout every adapter must feed |
| `backend/src/AI/Credential/ProviderKeyStore.php`, `Controller/AdminProviderKeysController.php`, `frontend/src/services/api/providerKeysApi.ts`, `components/admin/ProviderKeyCard.vue` | `CONFIG_GROUP = 'provider_keys'`, `getKey()/saveKey()/getStatus()`, env origin, key CRUD API/UI — pattern for `PlugKeyStore` |
| `backend/src/AI/Provider/OpenAICompatibleProvider.php`, `backend/src/Model/ModelCatalog.php`, `backend/src/Seed/ModelSeeder.php` | How a chat provider and its catalog rows are added (for `PerplexityProvider`) |
| `backend/src/Service/Message/WebSearchTopicPolicy.php`, `SelfAware/PlatformCapabilityInventory.php` line 197 | Decides whether to search — untouched (C3); reports "web search on" from registry health, provider-agnostic |
| `docker-compose.yml` lines 929–943 (`tts`), `backend/tests/Fixtures/web_search/brave/` (S1) | Smallest sidecar block to copy for `searxng`; fixture layout, one directory per provider follows |

---

## 2. Developer steps

### 2.1 DTO completion and `PlugKeyStore` (`PL16`)

`WebSearchCapabilities` = `{freshness, country, language, siteFilter, fullContent, answer}` (bools). `SearchResult` gains `kind`
(`web` | `answer_citation`), `content` (full page text when `fullContent`) and `publishedAt`; `SearchResultSet` gains `meta`
(`provider`, `latencyMs`, `fellBackFrom`, provider extras) and `answer: ?ProviderAnswer {text, citations[]}`. `formatForAi()`
prints the answer block **only** when `WebSearchQuery::wantAnswer` is true (default false) — today's text is unchanged (C1).
`App\Plug\PlugKeyStore` = `ProviderKeyStore` shape over `BCONFIG` group `plug_keys` (same encryption, `ORIGIN_ENV` bootstrap from
`TAVILY_API_KEY`, `EXA_API_KEY`, `FIRECRAWL_API_KEY`; UI wins). Brave keeps `BRAVE_SEARCH_API_KEY` through the existing service;
Perplexity uses `ProviderKeyStore('perplexity')` so search adapter and chat provider share one key with independent toggles.

### 2.2 `SearxngAdapter` and compose profile `searxng` (`PL17` — ask first, recorded)

`key = searxng`, sovereignty `self-hosted`, capabilities `{freshness, language, siteFilter}`. Setting `SEARXNG.BASE_URL` ← `SEARXNG_BASE_URL` (bootstrap-only).

```text
GET {SEARXNG.BASE_URL}/search?q=<query>&format=json&language=<lang>&safesearch=1&time_range=<day|week|month|year>&pageno=1
→ 200 {"results":[{"url","title","content","engine","publishedDate","score"}], "number_of_results": n}
```

```yaml
  # Optional SearXNG meta search — enable with: docker compose --profile searxng up -d
  searxng:
    image: searxng/searxng:<tag>@sha256:<digest>
    container_name: synaplan-searxng
    profiles: [searxng]
    restart: unless-stopped
    volumes:
      - ./_devextras/searxng/settings.yml:/etc/searxng/settings.yml:ro
    environment:
      SEARXNG_BASE_URL: http://searxng:8080/
    networks:
      - synaplan-network
```

`_devextras/searxng/settings.yml` (tracked): `search.formats: [html, json]` (JSON is off by default and the adapter needs it),
`server.limiter: false` (no public exposure), `server.secret_key` from `SEARXNG_SECRET` in `.env.example`, general web engines only. No host port.

### 2.3 `TavilyAdapter`, `ExaAdapter` (`PL18`)

| Adapter | Endpoint | Capabilities | Sovereignty |
| ------- | -------- | ------------ | ----------- |
| `tavily` | `POST https://api.tavily.com/search` `{query, max_results, search_depth: "basic", include_answer: false, topic, days}` → `results[]{title,url,content,score,published_date}` | `freshness, fullContent, answer` | US cloud |
| `exa` | `POST https://api.exa.ai/search` header `x-api-key`, `{query, numResults, type: "auto", contents: {text: {maxCharacters: 4000}}, includeDomains, startPublishedDate}` → `results[]{title,url,text,publishedDate}` | `freshness, siteFilter, fullContent` | US cloud |

Both: key from `PlugKeyStore`, timeout `WEB_SEARCH.TIMEOUT_MS` (seeded `8000`, Brave's current value), `health()` = key present
(no probe call; "Test query" does the live call). Recorded fixtures in `tests/Fixtures/web_search/<key>/`.

### 2.4 `FirecrawlAdapter`, `PerplexityAdapter` (`PL19`)

`firecrawl`: `POST https://api.firecrawl.dev/v1/search` Bearer, `{query, limit, scrapeOptions: {formats: ["markdown"]}}` →
`data[]{url,title,description,markdown}`; `fullContent = true`, markdown into `SearchResult::content` truncated to
`WEB_SEARCH.MAX_CONTENT_CHARS` (seeded `4000`). US cloud. `perplexity` (master plan §12.1): `POST https://api.perplexity.ai/search`
`{query, max_results, search_recency_filter}` → `results[]{title,url,snippet,date}` as `kind = web`; `capabilities.answer = true`.
When the caller sets `wantAnswer`, the adapter additionally calls the chat completion of the catalog model bound to
`DEFAULTMODEL.WEBANSWER` **if** that binding exists (S3 seeds none — no hard-coded model name) and returns `answer.text` with
its citations as `kind = answer_citation`. US cloud.

### 2.5 `PerplexityProvider` chat provider (`PL20`)

`App\AI\Provider\PerplexityProvider implements ChatProviderInterface, ProviderMetadataInterface` — OpenAI-compatible wire format
at `https://api.perplexity.ai`, tag `app.ai.chat` in `services.yaml`, `ProviderKeyStore::SUPPORTED_PROVIDERS += 'perplexity'`.
`ModelCatalog::MODELS` gains the current Perplexity `sonar*` rows with `tag = chat` (names live in the catalog only); `ModelSeeder`
inserts them with `BSELECTABLE = 0` — seeded once, never overwritten. No `DEFAULTMODEL.*` change. Provider card appears in Models & keys.

### 2.6 Active provider, per-user override, fallback (`PL21`)

`WebSearchRegistry::active(?int $userId)`: user row `PLUGS.WEB_SEARCH.PROVIDER` (owner `userId`) wins only when
`WEB_SEARCH.USER_OVERRIDE_ALLOWED = 1`; else global; unknown key → global → `brave`. `WebSearchRegistry::search(WebSearchQuery, ?int $userId)`
wraps the call: on exception, timeout or `health()->available = false`, and a non-empty `WEB_SEARCH.FALLBACK` different from the
active key, run the fallback once, set `meta.fellBackFrom`, increment `synaplan_plugs_web_search_fallback_total{from,to}`; if the
fallback fails too, return an empty set (today's behavior on a Brave error). S1 callers switch to `WebSearchRegistry::search()` — one line each.

### 2.7 Admin API and user setting (`PL22`)

```text
GET  /api/v1/admin/plugs/web-search          → { providers: [{key,label,docsUrl,sovereignty,capabilities,health,keyStatus}], active, fallback, userOverrideAllowed }
PUT  /api/v1/admin/plugs/web-search          { active, fallback, userOverrideAllowed }          → 200 | 422 unknown key
PUT  /api/v1/admin/plugs/keys/{provider}     { key }  · DELETE …/keys/{provider}                → PlugKeyStore
POST /api/v1/admin/plugs/web-search/test     { provider, query }   → { results: [...5], answer?, latencyMs, error? }
GET  /api/v1/config/plugs/web-search         → { allowed, active, options[] }  (any user)
PUT  /api/v1/config/plugs/web-search         { provider | null }   → 403 when not allowed
```

### 2.8 Web search tab and Settings toggle (`PL23`)

Backend from `PL22`: `AdminPlugsWebSearchController`, `UserPlugsController`, full OpenAPI, Zod regenerated. `components/admin/plugs/WebSearchPlugTab.vue`: provider cards rendered from descriptors (label, docs link, **sovereignty badge**
`self-hosted` / `EU` / `US cloud` as `pill` variants defined for light and dark), capability icons, key field (`ProviderKeyCard`
style, masked), active radio, fallback select, "Test query" with result list, "Allow users to choose their provider" switch.
Settings gets `WebSearchProviderSetting.vue` (select, shown only when allowed). Namespace `aiInfra.webSearch.*` in five locales.

### 2.9 Docs (`PL24`)

`docs/CONFIGURATION.md`: provider table with keys, sovereignty, capabilities; `docs/DEVELOPMENT.md`: SearXNG profile; `docs/FEATURES.md`: provider choice.

---

## 3. Tests and invariants

| Invariant | Proof in this sprint |
| --------- | -------------------- |
| C1 | `BraveSearchAdapterContractTest` unchanged; `formatForAi()` byte-equal with `wantAnswer = false` |
| C2 | `PlugsConfigSeederTest`: `PROVIDER = brave`, `FALLBACK = ''`, `USER_OVERRIDE_ALLOWED = 0`, `TIMEOUT_MS = 8000` |
| C3 | Characterization suite; `WebSearchTopicPolicy` diff empty |
| C4 | `PlugBoundaryTest` extended with the five new HTTP clients |
| C7 | `SearxngAdapterHealthTest`: `SEARXNG.BASE_URL` empty → unavailable → fallback or empty set, never an exception in `MessageProcessor` |
| C8 | Gateway and widget suites; `PL23` classified `ota-candidate`, others `backend-only` |

Per adapter `<Key>AdapterContractTest` on recorded fixtures (result mapping, capabilities, error → empty set). `WebSearchRegistryFallbackTest`
(fallback once, metric, no loop when fallback = active). `PerplexityProviderTest` (chat mapping, key status). `UserPlugsControllerTest`
(403 when override off). Frontend `WebSearchPlugTab.spec.ts`; badge contrast checked in both themes.

---

## 4. Exit criteria / demo

1. Admin switches Brave → SearXNG in the tab; the next chat search shows SearXNG hosts in the sources; an outbound-host audit of the backend container shows no `api.search.brave.com` call.
2. Stop `searxng`; with fallback `brave` the search still answers and the tab shows the fallback counter.
3. A user with override allowed picks Tavily; another user still gets the global provider. Perplexity enabled as chat model in Models & keys works without touching the web search tab, and vice versa.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| PL16 | `feat(plugs): complete web search DTOs and add encrypted PlugKeyStore` | backend-only | PL6 |
| PL17 | `feat(plugs): add SearxngAdapter and opt-in searxng compose profile` | backend-only | PL16 |
| PL18 | `feat(plugs): add Tavily and Exa web search adapters` | backend-only | PL16 |
| PL19 | `feat(plugs): add Firecrawl and Perplexity web search adapters` | backend-only | PL16 |
| PL20 | `feat(ai): add Perplexity chat provider with catalog rows` | backend-only | PL16 |
| PL21 | `feat(plugs): resolve active web search provider with per-user override and fallback` | backend-only | PL17 |
| PL22 | `feat(plugs): add admin and user APIs for web search provider selection` | backend-only | PL21 |
| PL23 | `feat(admin): add Web search tab with sovereignty badges and user provider setting` | ota-candidate | PL14, PL22 |
| PL24 | `docs(config): document web search providers, keys and the searxng profile` | backend-only | PL23 |

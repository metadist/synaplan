# Module map — feature modules S1 / FM1

**Status:** inventory 2026-09-10, source of truth for the S2 descriptors and the FM4 ownership test.

All paths are relative to the repository root (`/wwwroot/synaplan`). Backend paths omit the `backend/` prefix only where the FQCN makes the location unambiguous; otherwise the full path is given. Line numbers refer to the working tree on 2026-09-10.

## Method

Read-only inventory. No git, docker, or build command was run; nothing outside this file was modified.

| Step | Exact search / read |
| --- | --- |
| Plan context | `Read _devextras/planning/20260910-feature-modules/00_master_plan.md`, `Read …/01_sprint_1_inventory_and_dead_weight.md` |
| DI wiring | `Read backend/config/services.yaml` (whole file); `rg -n '^    env\(' backend/config/services.yaml` (107 hits = every `parameters:` default); `rg -n "default::[A-Z_]+|%env\((bool\|int\|float\|string):?[A-Z_]+\)%|%env\([A-Z][A-Z_]+\)%" backend/config/services.yaml` (every binding); `rg -n '^\s+bind:' backend/config/services.yaml` (only hit: line 657, `WebhookController`) |
| Access control | `Read backend/config/packages/security.yaml` (`access_control` block) |
| Env files / compose | `Read backend/.env.example`, `Read backend/.env.test`, `Read docker-compose.yml` (backend `environment:` 284-376, worker 472-525) |
| Mobile policy | `Read .github/mobile-impact-policy.json` |
| Routes | `rg -n 'Route\(' <controller>` for every controller named in a module row |
| Availability checks | `rg -n 'function isEnabled|function isAvailable|function isConfigured' <class>` then `Read` the method body |
| featuresStatus | `rg -n '/features' backend/src/Controller/ConfigController.php`, then `Read` 2080-2580 |
| Capability facts | `Read backend/src/Service/SelfAware/PlatformCapabilityInventory.php`; `rg -n "tika|docling|searxng|ollama|higgsfield|google|thehive|iap|stripe|billing|SYNAPLAN_TTS|OFFICE_CONVERT|QDRANT|WHATSAPP|'[a-z_]+',$"` on the same file |
| Registries (FM5) | `rg -n 'AutowireIterator' backend/src` (12 consumer files), `Read` each constructor; `rg -n 'registerForAutoconfiguration' backend/src/Kernel.php`; `Read backend/config/services.yaml` `_instanceof` (214-242) and provider tag blocks (351-536); `rg -l 'AutoconfigureTag\(' backend/src` for attribute-tagged services; `Glob` for implementations of each tagged interface |
| Providers | `Read backend/src/AI/Provider/{Piper,Ollama,Higgsfield,Google,TheHive}Provider.php` (`getName`, `isAvailable`, `getStatus`, `getRequiredEnvVars`); `Read backend/src/AI/Credential/{ProviderKeyStore,ProviderKeyCatalog,HiggsfieldCredentialResolver}.php` |
| Seeds | `Glob backend/src/Seed/*.php`; `Read backend/src/Seed/{DefaultModelConfigSeeder,PlugsConfigSeeder,MobileConfigSeeder,SubscriptionPlanSeeder}.php`; `rg -n "'service' => '(Ollama\|Google\|TheHive\|Higgsfield\|Piper)'" backend/src/Model/ModelCatalog.php`; `rg -n 'ollama' backend/src/Seed/ModelSeeder.php`; `rg` on `backend/src/Seed/BConfigSeeder.php` and `backend/src/DataFixtures/` for module names (no hits) |
| Frontend | `rg -l '<module term>' frontend/src` per module, then `rg -n` on the hits; `rg -n 'import (…)' frontend/src` to find mount points; `rg -n 'path: ' frontend/src/router/index.ts`; `rg -n '"(…)": \{' frontend/src/i18n/en.json` + `Read` of the surrounding lines for namespace parents |
| Tests | `Glob backend/tests/**/*<Term>*.php` per module; `rg -l '<module term>' frontend/tests` |

Anything not listed in a table below was not found by these searches. "none" is written explicitly when a row is empty.

Route-name convention used below: `prefix` + `name` as Symfony concatenates them (class-level `name:` prefix, if any, followed by the method-level `name:`). "Public" means an explicit `PUBLIC_ACCESS` rule in `backend/config/packages/security.yaml`; every other `/api` route falls under the `^/api` → `IS_AUTHENTICATED_FULLY` catch-all (`security.yaml:225`).

---

## Module tables

### `tika`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\Service\File\TikaClient` (`backend/src/Service/File/TikaClient.php`, wiring `services.yaml:1044-1051`); `App\Plug\Extraction\Adapter\TikaExtractor` (`backend/src/Plug/Extraction/Adapter/TikaExtractor.php`, autotagged via `Kernel.php:91`); `TIKA_MIN_*` consumed by `App\Service\File\FileProcessor` (`services.yaml:1175-1180`). Other consumers (not owned): `FileUploadService`, `ExtractionAdminService`, `MessagePreProcessor`, `OfficeConverterClient`, `GeneratedFileRegistrar`, `MediaGenerationHandler`, `FileAnalysisRunner`, `ExtractTextRunner`, `AttachmentSearchContextResolver`, `MediaJobMessageSync`, `SystemConfigService`, `ConfigController` |
| Route names | No Tika-specific controller. Shared admin routes that read/test it: `admin_plugs_extraction_status` (GET `/api/v1/admin/plugs/extraction`, `AdminPlugsExtractionController.php:27`), `admin_plugs_extraction_chains` (PUT `/chains`, `:93`), `admin_plugs_extraction_test` (POST `/test`, `:148`); `admin_config_test` (POST `/api/v1/admin/config/test/{service}` with `service=tika`, `AdminSystemConfigController.php:201`). All admin-authenticated, none public. **Never gate:** `admin_plugs_extraction_status`, `admin_config_test` (status / connect routes) |
| Env keys | `TIKA_BASE_URL` (strict, no `parameters:` default; `services.yaml:1046`; `.env.example` `http://tika:9998`; `.env.test` `http://tika_test:9998`; compose backend `http://tika:9998`), `TIKA_TIMEOUT_MS` `'30000'` (`:146`), `TIKA_RETRIES` `'2'` (`:147`), `TIKA_RETRY_BACKOFF_MS` `'1000'` (`:148`), `TIKA_MIN_LENGTH` `'10'` (`:149`), `TIKA_MIN_ENTROPY` `'3.0'` (`:150`), `TIKA_HTTP_USER` `''` (`:93`), `TIKA_HTTP_PASS` `''` (`:94`) |
| BCONFIG keys | Group `PLUGS`: `EXTRACTION.CHAIN.document` default `structured_office,office_convert,tika,pdf_vision` (`PlugConfigService.php:23,44`; seeded by `PlugsConfigSeeder.php:40`). `tika` is listed in `PlugConfigService::BUILTIN_EXTRACTOR_KEYS` |
| Provider / plug keys | plug `key()` = `'tika'` (`TikaExtractor.php`); tag `app.plug.extractor` (auto, `Kernel.php:91`); descriptor envs `['TIKA_BASE_URL']` |
| Existing availability check | `TikaClient::isEnabled()` `backend/src/Service/File/TikaClient.php:163-166` (`!empty($this->tikaUrl) && 'disabled' !== $this->tikaUrl`); `TikaExtractor::health()` `:68-75`; `SystemConfigService::testTika()` `backend/src/Service/Admin/SystemConfigService.php:839` |
| featuresStatus() block | key `tika`, `ConfigController.php:2264-2301` — `'enabled' => true` unconditionally; URL from `$_ENV['TIKA_BASE_URL'] ?? 'http://tika:9998'` |
| Capability ids | none (no `PlatformCapabilityInventory` fact reads `TIKA_*`) |
| Frontend | `components/admin/plugs/ExtractionPlugTab.vue` (i18n `aiInfra.extraction.doclingRejectedTika`, `doclingUnavailableTika`), `components/admin/plugs/ExtractionSidecarPanel.vue` (`sidecarServices = ['tika','docling']`, `:75`), `views/AdminConfigView.vue:236-241` (`testableServices.processing: ['tika','docling']`), mounted from `views/ProviderSetupView.vue:33`; i18n namespaces `aiInfra.extraction.*`, `admin.config.*` (en/de/es/fr/tr) |
| Seeds / fixtures | `PlugsConfigSeeder.php:40` (chain string contains `tika`); no `BMODELS` row; nothing in `DataFixtures/` |
| Tests | PHPUnit: none Tika-specific found by `Glob backend/tests/**/*Tika*.php`. Vitest: `frontend/tests/unit/components/admin/plugs/ExtractionPlugTab.spec.ts`. Playwright: none |
| Mobile class | backend-only (`backend/**`); admin UI files are ota-candidate (`frontend/src/**`). No store-required pattern match |
| Notes / risks | `featuresStatus()` reports Tika as enabled even when `TIKA_BASE_URL` is empty; `TikaClient::isEnabled()` is the only honest signal. `TIKA_BASE_URL` is the one Tika key without a `parameters:` default (container boot fails without it). `TIKA_MIN_LENGTH`/`TIKA_MIN_ENTROPY` are quality thresholds used by `FileProcessor` for *all* extractors, not only Tika |

### `docling`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\Plug\Extraction\Docling\DoclingClient` (`services.yaml:1053-1056`); `App\Plug\Extraction\Adapter\DoclingExtractor` (`services.yaml:1062-1064`, `$maxBytes`); `App\Plug\Extraction\Docling\DoclingRejectedException`, `App\Plug\Extraction\Docling\DoclingUnavailableException` (`backend/src/Plug/Extraction/Docling/`) |
| Route names | Shared: `admin_plugs_extraction_status` / `admin_plugs_extraction_chains` / `admin_plugs_extraction_test` (`AdminPlugsExtractionController.php:27,93,148`); `admin_config_test` with `service=docling` (`AdminSystemConfigController.php:201`). None public. **Never gate:** `admin_plugs_extraction_status`, `admin_config_test` |
| Env keys | `DOCLING_BASE_URL` `''` (`services.yaml:152`; compose backend `${DOCLING_BASE_URL:-http://docling:5001}`), `DOCLING_TIMEOUT_MS` `'120000'` (`:153`), `DOCLING_MAX_BYTES` `'52428800'` (`:154`) |
| BCONFIG keys | Group `PLUGS`: `EXTRACTION.CHAIN.*` (`PlugConfigService.php:22-23`); default document chain does **not** include `docling` (`:44`) — it is opt-in via the admin chain editor |
| Provider / plug keys | plug `key()` = `'docling'` (`DoclingExtractor.php:30-33`); tag `app.plug.extractor` (auto, `Kernel.php:91`); descriptor envs `['DOCLING_BASE_URL']` |
| Existing availability check | `DoclingClient::isEnabled()` `backend/src/Plug/Extraction/Docling/DoclingClient.php:35-40`; `DoclingClient::health()` `:42-71` (30 s cache); `SystemConfigService::testDocling()` `SystemConfigService.php:877` |
| featuresStatus() block | key `docling`, `ConfigController.php:2303-2320` |
| Capability ids | none |
| Frontend | `components/admin/plugs/ExtractionPlugTab.vue:165-174`, `components/admin/plugs/ExtractionSidecarPanel.vue:51,75`, `views/AdminConfigView.vue:236-241`; i18n `aiInfra.extraction.*` (incl. `test.docling` en.json:2945), `admin.config.*` |
| Seeds / fixtures | none (not in default chain, no `BMODELS` row) |
| Tests | PHPUnit: `backend/tests/Unit/Plug/Extraction/DoclingExtractorContractTest.php`, `backend/tests/Unit/Plug/Extraction/DoclingExtractorHealthTest.php`. Vitest: `frontend/tests/unit/components/admin/plugs/ExtractionPlugTab.spec.ts`. Playwright: none |
| Mobile class | backend-only; admin UI ota-candidate. No store-required match |
| Notes / risks | Cleanest module candidate: single URL key, own client + adapter + health, explicit `isEnabled()`, dedicated featuresStatus block, already editable in the admin `.env` editor (`SystemConfigService.php` schema tab `processing.docling`) |

### `office_convert`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\Service\File\Office\OfficeConverterClient` (`services.yaml:1066-1069`); `App\Service\File\Office\StructuredTextExtractor` (`:1071-1073`, `OFFICE_TEXT_MAX_ROWS`); `App\Service\File\Office\DocumentCombineService` (`:1075-1078`, `OFFICE_COMBINE_MAX_FILES`); `App\Service\Document\DocumentOfficeMergeService` (`:1096-1099`); `backend/src/Service/File/Office/{OfficePdfRoutingDecorator,DocumentExportService,DocumentThumbnailGenerator,DocumentThumbnailDispatcher,DocumentCombineException,DocxStyleSheet}.php`; `App\Service\Multitask\Execution\Runner\DocumentExportRunner`; `App\Service\File\GeneratedDocumentStore` (`backend/src/Service/File/GeneratedDocumentStore.php:30`) |
| Route names | `api_files_combine` (`FileController.php:121`), `api_files_export` (`:648`, engine check `:1725`), `api_files_thumb` (`:778`) — all under `/api/v1/files` (`:48`), authenticated. Also read by `GuestChatController.php:631` (guest widget path) and `MessageClassifier.php:1010,1698`. None public. **Never gate:** none dedicated (no status/connect route exists; `runtime_config` carries the flag) |
| Env keys | `OFFICE_CONVERT_URL` `''` (`services.yaml:158`; compose backend `${OFFICE_CONVERT_URL:-http://collabora:9980}`; `.env.test` empty), `OFFICE_CONVERT_TIMEOUT_MS` `'60000'` (`:159`), `OFFICE_TEXT_MAX_ROWS` `'500'` (`:160`), `OFFICE_COMBINE_MAX_FILES` `'20'` (`:161`) |
| BCONFIG keys | Group `PLUGS`: `EXTRACTION.CHAIN.document` default contains `structured_office,office_convert` (`PlugConfigService.php:44`); `office_convert` is in `BUILTIN_EXTRACTOR_KEYS` |
| Provider / plug keys | chain key `office_convert` (not a tagged `app.plug.extractor` adapter class — it is a built-in chain step routed through `OfficePdfRoutingDecorator`) |
| Existing availability check | `OfficeConverterClient::isEnabled()` `backend/src/Service/File/Office/OfficeConverterClient.php:63-68`; `ConfigController::isOfficeConvertConfigured()` `:2533-2538` + `officeConvertUrl()` `:2526-2531`; `PlatformCapabilityInventory::officeEngineConfigured()` `:484-493` |
| featuresStatus() block | key `office-convert`, `ConfigController.php:2322-2339`. Also `runtime_config` → `features.officeConvertEnabled` (`ConfigController.php:509`, public route) |
| Capability ids | `pdf_export` (`PlatformCapabilityInventory.php:262-267`, "office engine (OFFICE_CONVERT_URL)"); `document_generation` (`:253`) is reported independently |
| Frontend | `composables/useOfficeConvertFeature.ts` (`isOfficeConvertEnabled()` reads `getConfigSync().features?.officeConvertEnabled`); consumers `components/ChatMessage.vue:1157,1197`, `components/files/FileOfficeActions.vue:98,137-139`, `components/files/FilesGrid.vue:246,355`, `components/files/DocumentPreviewModal.vue:67,94`; `services/api/httpClient.ts:150,217` (defaults); flag lives in `stores/config.ts` |
| Seeds / fixtures | `PlugsConfigSeeder.php:40` (chain string); no `BMODELS` row |
| Tests | PHPUnit: `backend/tests/Unit/Service/File/Office/{OfficePdfRoutingDecoratorTest,DocumentCombineServiceTest,DocumentExportServiceTest,OfficeConverterClientTest,DocumentThumbnailDispatcherTest,DocumentThumbnailGeneratorTest,StructuredTextExtractorTest}.php`, `backend/tests/Unit/Service/Multitask/Execution/Runner/{DocumentExportRunnerTest,DocumentCombineRunnerTest}.php`. Vitest / Playwright: none office-specific found |
| Mobile class | backend-only for PHP; UI consumers ota-candidate. **store-required pattern match:** `frontend/src/stores/config.ts` (policy line 46) carries `features.officeConvertEnabled` — any change to that file is store-required |
| Notes / risks | Not in the admin `.env` editor schema (`SystemConfigService.php:68-160` has no `OFFICE_CONVERT_*` field). Three independent "is configured" implementations (`OfficeConverterClient`, `ConfigController`, `PlatformCapabilityInventory`). The `runtime_config` flag is public and consumed by the widget/guest path, so it must stay reachable when the module is off |

### `searxng`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\Plug\WebSearch\Client\SearxngClient` (`services.yaml:1058-1060`); `App\Plug\WebSearch\Adapter\SearxngAdapter` (`backend/src/Plug/WebSearch/Adapter/SearxngAdapter.php`, autotagged `Kernel.php:92`) |
| Route names | Shared web-search admin routes under `/api/v1/admin/plugs`: `admin_plugs_web_search_status` (GET, `AdminPlugsWebSearchController.php:26`), `admin_plugs_web_search_save` (PUT, `:104`), `admin_plugs_web_search_test` (POST, `:148`), `admin_plugs_keys_save` (PUT `/keys/{provider}`, `:218`), `admin_plugs_keys_delete` (`:268`). None public. **Never gate:** `admin_plugs_web_search_status`, `admin_plugs_web_search_test` |
| Env keys | `SEARXNG_BASE_URL` `''` (`services.yaml:156`; compose backend `${SEARXNG_BASE_URL:-http://searxng:8080}`). `SEARXNG_SECRET` appears only in `backend/.env.example` (consumed by the sidecar container, not by PHP) |
| BCONFIG keys | Group `PLUGS`: `WEB_SEARCH.PROVIDER` (default `brave`, `PlugConfigService.php:31,55`), `WEB_SEARCH.FALLBACK` (`:32`); `searxng` is in `PlugConfigService::WEB_SEARCH_PROVIDERS` (`:63`) |
| Provider / plug keys | plug `key()` = `'searxng'` (`SearxngAdapter.php:29-32`); tag `app.plug.web_search` (auto, `Kernel.php:92`); descriptor envs `['SEARXNG_BASE_URL']` |
| Existing availability check | `SearxngClient::isConfigured()` `backend/src/Plug/WebSearch/Client/SearxngClient.php:29-34`; `SearxngClient::probe()` `:70-110`; `SearxngAdapter::health()` `:97-102`, `SearxngAdapter::probe()` `:104-111` |
| featuresStatus() block | none (the `web-search` block `ConfigController.php:2144-2168` reports Brave only) |
| Capability ids | none searxng-specific (`web_search` fact `PlatformCapabilityInventory.php:199` is provider-agnostic) |
| Frontend | `components/admin/plugs/WebSearchPlugTab.vue` (provider list is data-driven from `admin_plugs_web_search_status`; no literal `searxng` in the component), mounted from `views/ProviderSetupView.vue:36`; `services/api/adminPlugsApi.ts`; i18n `aiInfra.webSearch.*` (en.json:2977) |
| Seeds / fixtures | `PlugsConfigSeeder.php:48-49` seeds `WEB_SEARCH.PROVIDER=brave`, `WEB_SEARCH.FALLBACK=''` — SearXNG is never the seeded default |
| Tests | PHPUnit: `backend/tests/Unit/Plug/SearxngAdapterHealthTest.php`. Vitest: `frontend/tests/unit/components/admin/plugs/WebSearchPlugTab.spec.ts`. Playwright: none |
| Mobile class | backend-only; admin UI ota-candidate. No store-required match |
| Notes / risks | Not in the admin `.env` editor schema. No featuresStatus block and no capability fact — the only visibility is the plug status endpoint |

### `piper_tts`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\AI\Provider\PiperProvider` (`services.yaml:476-481`, `$ttsUrl: '%env(default:synaplan_tts_url_default:SYNAPLAN_TTS_URL)%'`); `App\Controller\TtsController`; `App\AI\Health\Probe\LocalProviderAvailabilityProbe` (`SERVICES = ['triton','piper']`, `:28`); `SystemConfigService` (`$defaultTtsUrl: '%synaplan_tts_url_default%'`, `services.yaml:1229-1232`). Also referenced by `ModelCatalog`, `SyncModelPricesCommand`, `DefaultModelConfigSeeder`, `StreamController`, `MessageApiFormatter`, `ProviderKeyCatalog` (excluded, `:14`), `PlatformCapabilityInventory`, `TextToSpeechProviderInterface` |
| Route names | `api_tts_stream` (GET `/api/v1/tts/stream`, `TtsController.php:17,27`, authenticated, provider-agnostic); `admin_config_test` with `service=piper` (`AdminSystemConfigController.php:201`). None public. **Never gate:** `admin_config_test` |
| Env keys | `SYNAPLAN_TTS_URL` `''` (`services.yaml:178`; parameter `synaplan_tts_url_default: 'http://localhost:10200'`; compose backend `${SYNAPLAN_TTS_URL:-http://host.docker.internal:10200}`; `.env.test` empty) |
| BCONFIG keys | `DEFAULTMODEL.TEXT2SOUND` may point at the Piper row, but the seeded default is Google (`DefaultModelConfigSeeder.php:66`) |
| Provider / plug keys | `getName()` = `'piper'` (`PiperProvider.php:54-57`); tag `app.ai.text_to_speech` (`services.yaml:476-481`) |
| Existing availability check | `PiperProvider::isAvailable()` `backend/src/AI/Provider/PiperProvider.php:100-103` (`!empty($this->ttsUrl)`); `PiperProvider::getStatus()` `:81-98` (GET `/health`); `getRequiredEnvVars()` `:105-113`; `SystemConfigService::testPiperTts()` `SystemConfigService.php:939`; `PlatformCapabilityInventory::ttsUrlConfigured()` `:466-469` |
| featuresStatus() block | none dedicated — appears as `$features['piper']` from the dynamic provider loop `ConfigController.php:2186-2244` |
| Capability ids | `text_to_speech` (`PlatformCapabilityInventory.php:180-186`, "no TTS model or SYNAPLAN_TTS_URL") |
| Frontend | `utils/AudioStreamer.ts` (consumes `/api/v1/tts/stream`), `views/AdminConfigView.vue:237` (`testableServices.ai: ['ollama','piper']`), `utils/providerIcons.ts`, `components/icons/ServiceIcon.vue:81`; i18n `admin.config.*` |
| Seeds / fixtures | `ModelCatalog.php:3432-3435` (`service => 'Piper'`, "Piper Multi-Language", tag `text2sound`) |
| Tests | PHPUnit: `backend/tests/AI/Provider/PiperProviderResolveVoiceTest.php`, `backend/tests/Unit/Service/TtsTextSanitizerTest.php`. Vitest / Playwright: none |
| Mobile class | backend-only; UI ota-candidate. No store-required match |
| Notes / risks | **Two different answers to "is Piper configured":** the yaml default `default:synaplan_tts_url_default:` makes `PiperProvider::isAvailable()` effectively always `true`, while `PlatformCapabilityInventory::ttsUrlConfigured()` reads the raw env and is `false` when `SYNAPLAN_TTS_URL` is unset. `LocalProviderAvailabilityProbe` documents this ("Piper only looks at SYNAPLAN_TTS_URL", `:15-18`) |

### `local_ai` (Ollama)

| Row | Value |
| --- | --- |
| Service ids / classes | `App\AI\Provider\OllamaProvider` (`services.yaml:372-377`, `$baseUrl: '%env(OLLAMA_BASE_URL)%'`); `App\AI\Health\Probe\OllamaModelListProbe` (`:515-517`); `App\AI\Service\OllamaModelInventory` (`backend/src/AI/Service/OllamaModelInventory.php`); `App\Service\LocalAi\LocalAiDownloadStatusService` (`:347-349`); `App\AI\Credential\ChatReadinessService` (`backend/src/AI/Credential/ChatReadinessService.php`, `isOllamaModelPulled` used at `ConfigController.php:1896`); `App\Controller\AdminModelsImportEndpointController` (`source=ollama` import). Ollama is also named in ~45 other backend files (`ModelSeeder`, `DefaultModelConfigSeeder`, `ProviderDefaultsService`, `AdminProviderKeysController`, `ProviderListCommand`, `AiProviderDisclosure`, …) |
| Route names | `api_config_local_ai_download_status` (GET `/api/v1/config/local-ai/status`, `ConfigController.php:2039`); `api_config_models_check` (GET `/models/{modelId}/check`, `:1788`, Ollama branch `:1884-1905`); `admin_models_import_endpoint_preview` / `admin_models_import_endpoint_apply` (POST `/api/v1/admin/models/import/endpoint/{preview,apply}`, `AdminModelsImportEndpointController.php:33,113`); `admin_config_test` with `service=ollama` (`:201`). None public (all under `^/api` catch-all). **Never gate:** `api_config_local_ai_download_status`, `api_config_models_check`, `admin_config_test` |
| Env keys | `OLLAMA_BASE_URL` (strict, no `parameters:` default; `services.yaml:374,517`; compose backend `${OLLAMA_BASE_URL:-}`; `.env.test` `http://ollama_test:11434`). No other Ollama key in `services.yaml` |
| BCONFIG keys | `DEFAULTMODEL.VECTORIZE = ollama:bge-m3:vectorize` (`DefaultModelConfigSeeder.php:70`); `ai.default_chat_provider` is `anthropic` (`:79`) |
| Provider / plug keys | `getName()` = `'ollama'` (`OllamaProvider.php:31-34`); tags `app.ai.chat`, `app.ai.embedding` (`services.yaml:372-377`); probe `supports('ollama')` (`OllamaModelListProbe.php:28-31`) |
| Existing availability check | `OllamaProvider::isAvailable()` `backend/src/AI/Provider/OllamaProvider.php:78-87` (live HTTP `models()->list()`); `getStatus()` `:56-76`; `getRequiredEnvVars()` `:89-97`; `OllamaModelListProbe::probe()` `:33-72` (`'' === trim($this->baseUrl)` → skipped, `:43-45`); `OllamaModelInventory::isPulled()` `:40-63` (30 s cache); `SystemConfigService::testOllama()` `:802` |
| featuresStatus() block | none dedicated — `$features['ollama']` from the dynamic loop `ConfigController.php:2186-2244`, with the URL special case at `:2210-2212` |
| Capability ids | none Ollama-specific (`chat` `:124`, `knowledge_search` `:143` are provider-agnostic) |
| Frontend | `components/setup/LocalAiDownloadCard.vue` (mounted in `views/ChatView.vue:50,529` and `components/admin/plugs/ModelsAndKeysTab.vue:3,128`), `services/api/localAiStatusApi.ts`, `components/admin/plugs/ModelsAndKeysTab.vue:88-114` (import-from-Ollama), `utils/providerHelp.ts:16,36,50`, `views/AdminConfigView.vue:237`, `stores/config.ts:304` (comment only); i18n `localAiDownload.*` (en.json:3108), `adminSetup.localAi.*` (:2900), `providerHelp.ollama` (:3095) |
| Seeds / fixtures | `ModelCatalog.php:820,856,883,907,1152,1177` (`service => 'Ollama'`: bge-m3 vectorize, gpt-oss:120b/20b chat, Qwen 3.5 35B chat, gpt-oss mem ×2); `ModelSeeder.php:55-56` test stubs (`service => 'ollama'`, ids −10/−11); `DefaultModelConfigSeeder.php:70` |
| Tests | PHPUnit: `backend/tests/AI/Provider/OllamaProviderChatTest.php`, `backend/tests/AI/Provider/OllamaProviderHasModelTest.php`, `backend/tests/Unit/AI/Health/Probe/OllamaModelListProbeTest.php`, `backend/tests/Unit/Service/LocalAi/LocalAiDownloadStatusServiceTest.php`, `backend/tests/Service/LocalAi/LocalAiDownloadStatusServiceTest.php`. Vitest: `frontend/tests/unit/components/localAiDownloadCard.spec.ts`. Playwright: `frontend/tests/e2e/tests/ollama-integration.spec.ts` (+ `stub-servers/ollama/ollama-stub-server.ts`, `helpers/ollama-stub.ts`) |
| Mobile class | backend-only; UI ota-candidate. No store-required match |
| Notes / risks | `OllamaProvider` constructor builds an SDK client and calls `ini_set('default_socket_timeout', 300)` (`:26-28`) at container build time — the heaviest provider constructor in the eager `ProviderRegistry`. `OLLAMA_BASE_URL` has no default, so the module cannot be "absent by omission"; the empty-string sentinel is handled in `OllamaModelListProbe:43-45` and `ConfigController:2210-2212` only. The test-env `ModelSeeder` stubs are keyed to `service = 'ollama'` |

### `higgsfield`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\AI\Provider\HiggsfieldProvider` (`services.yaml:468-474`); `App\AI\Credential\HiggsfieldCredentialResolver` (`:453-456`); `App\Controller\AI\HiggsfieldCredentialController`; capability interfaces `App\AI\Interface\SupportsInlineReferenceImage`, `App\AI\Interface\SupportsAsyncVideo` (`backend/src/AI/Interface/`). Also referenced by `ModelCatalog`, `DefaultModelConfigSeeder`, `SystemConfigService`, `StreamController`, `MediaGenerationHandler`, `MediaJobUsageRecorder`, `MediaCancellationStore`, `AdvanceMediaJobCommandHandler`, `ModelListProbeRegistry`, `UserProviderKeyResolver` |
| Route names | prefix `api_ai_higgsfield_credentials_` under `/api/v1/ai-providers/higgsfield/credentials`: `get` (GET), `put` (PUT/POST), `delete` (DELETE), `test` (POST `/test`) — `HiggsfieldCredentialController.php`. All authenticated (per-user), none public. **Never gate:** all four (credential / connect / test routes) |
| Env keys | `HIGGSFIELD_API_KEY` `''` (`services.yaml:75`, bound `:455,470`), `HIGGSFIELD_API_SECRET` `''` (`:76`, bound `:456,471`) |
| BCONFIG keys | per-user group `higgsfield`, settings `api_key`, `api_secret` (encrypted) — `HiggsfieldCredentialResolver::CONFIG_GROUP/SETTING_API_KEY/SETTING_API_SECRET`; `DEFAULTMODEL.IMG2VID = higgsfield:higgsfield-ai/dop/standard:text2vid` (`DefaultModelConfigSeeder.php:62`) |
| Provider / plug keys | `PROVIDER_NAME` = `'higgsfield'` (`HiggsfieldProvider.php:43`); tags `app.ai.image_generation`, `app.ai.video_generation` (`services.yaml:468-474`); MCP template `key: 'higgsfield'` (`frontend/src/config/mcpServerTemplates.ts:71`, unrelated MCP server, not the provider) |
| Existing availability check | `HiggsfieldProvider::isAvailable()` `backend/src/AI/Provider/HiggsfieldProvider.php:146-157` (`hasPlatformCredentials()`); `getStatus()` `:128-144`; `getRequiredEnvVars()` `:159-171`; `HiggsfieldCredentialResolver::resolve()` `:50-72`, `hasPlatformCredentials()` `:85-88` |
| featuresStatus() block | none dedicated — `$features['higgsfield']` from the dynamic loop `ConfigController.php:2186-2244` |
| Capability ids | none provider-specific (`image_generation` `:162`, `video_generation` `:171` are generic) |
| Frontend | `components/config/HiggsfieldConnection.vue` (mounted in `views/ConfigView.vue:17-20,81,111`), route `/ai/providers/higgsfield` name `ai-provider-higgsfield` (`router/index.ts:326-329`), `services/api/higgsfieldCredentialsApi.ts` (re-exported `services/api/index.ts:18`), `components/multitask/TaskCard.vue:67` (comment); i18n `config.providers.higgsfield.*` (en.json:1792-1793), `pageTitles.configProviderHiggsfield`, `mcp…templates.higgsfield` (:5615, MCP template) |
| Seeds / fixtures | `ModelCatalog.php:3240,3264` (text2pic: Soul Standard, Reve), `:3294,3323,3350,3377,3404` (text2vid: DoP Standard, Kling 2.1 Pro/Master, DoP Lite, DoP Turbo); `DefaultModelConfigSeeder.php:62` |
| Tests | PHPUnit: `backend/tests/Unit/AI/Provider/HiggsfieldProviderTest.php`, `backend/tests/Unit/AI/Credential/HiggsfieldCredentialResolverTest.php`, `backend/tests/Integration/Controller/HiggsfieldCredentialControllerTest.php`. Vitest / Playwright: none |
| Mobile class | backend-only; UI ota-candidate (`HiggsfieldConnection.vue`, `higgsfieldCredentialsApi.ts`, `ConfigView.vue`, `router/index.ts`). No store-required match |
| Notes / risks | Only provider with a **per-user** credential store and its own controller; the platform env pair is a fallback. `isAvailable()` looks at platform credentials only, so a user-connected Higgsfield with no platform key reports "unavailable" at registry level. In the admin `.env` editor tab `ai.media` (`SystemConfigService.php:68-160`) |

### `google_ai`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\AI\Provider\GoogleProvider` (`services.yaml:431-443`, `$projectId`, `$region 'us-central1'`, `$vertexAccessToken`); key resolution via `App\AI\Credential\ProviderKeyStore` (`:316-332`, `google` → `[GOOGLE_GEMINI_API_KEY, default::GEMINI_API_KEY, default::GOOGLE_API_KEY]`, `:322-327`); `App\AI\Credential\ProviderKeyCatalog` entry `google` (`:65-76`); `App\AI\Messages\Translator\GeminiMessagesTranslator` (`#[AutoconfigureTag('app.messages.translator')]`, `supports()` true for `google` or `gemini`, `:23,43-47`); `App\AI\StructuredOutput\GoogleJsonSchemaNormalizer` |
| Route names | Shared provider-key admin routes under `/api/v1/admin/provider-keys` (`AdminProviderKeysController.php:30`): `admin_provider_keys_list` (GET, `:44`), `admin_provider_keys_save` (PUT `/{provider}`, `:97`), `admin_provider_keys_delete` (DELETE, `:200`), `admin_provider_keys_test` (POST `/{provider}/test`, `:246`), `admin_provider_keys_apply_defaults` (POST, `:288`). None public. **Never gate:** all five (credential / test routes shared by 9 providers) |
| Env keys | `GOOGLE_GEMINI_API_KEY` `''` (`services.yaml:71`), `GOOGLE_CLOUD_PROJECT_ID` `''` (`:72`, bound `:434`), `GOOGLE_VERTEX_ACCESS_TOKEN` `''` (`:73`, bound `:437`); aliases `GEMINI_API_KEY`, `GOOGLE_API_KEY` (`default::` only, `:326-327`). `.env.test` `GOOGLE_GEMINI_API_KEY=test-key`. Not module-owned: `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` (OAuth login, `:102,108`), `IAP_GOOGLE_*` (mobile_iap) |
| BCONFIG keys | group `provider_keys`, setting `google` (encrypted, `ProviderKeyStore::CONFIG_GROUP` `:45`); `DEFAULTMODEL.TEXT2PIC`, `PIC2PIC` (`google:gemini-3.1-flash-image-preview:text2pic`), `TEXT2VID` (`google:veo-3.1-generate-preview:text2vid`), `TEXT2SOUND` (`google:gemini-2.5-flash-preview-tts:text2sound`) (`DefaultModelConfigSeeder.php:56-58,66`) |
| Provider / plug keys | `getName()` = `'google'` (`GoogleProvider.php:128-131`); tags `app.ai.chat`, `app.ai.vision`, `app.ai.image_generation`, `app.ai.video_generation`, `app.ai.text_to_speech` (`services.yaml:431-443`); `ProviderKeyStore::SUPPORTED_PROVIDERS` contains `'google'` (`:55`) |
| Existing availability check | `GoogleProvider::isAvailable()` `backend/src/AI/Provider/GoogleProvider.php:179-182` (`null !== $this->resolveApiKey()`); `getStatus()` `:162-177`; `getRequiredEnvVars()` `:184-193` (`any_of` three names); `ProviderKeyStore::getKey()` `:95-111` (15 s memo), `getStatus()` `:167` |
| featuresStatus() block | none dedicated — `$features['google']` from the dynamic loop `ConfigController.php:2186-2244` |
| Capability ids | none provider-specific |
| Frontend | `components/admin/ProviderKeyCard.vue` + `components/admin/plugs/ModelsAndKeysTab.vue` (data-driven from `admin_provider_keys_list`), `services/api/providerKeysApi.ts`, `utils/providerHelp.ts`, `utils/modelMixes.ts` (Google mix candidates); i18n `providerHelp.google` (en.json:3071), `modelMix.mixes.google` (en.json:65, "Google Gemini Mix"), `adminSetup.providers.*` |
| Seeds / fixtures | `ModelCatalog.php`: 25 rows with `service => 'Google'` (Gemini 2.5/3/3.1/3.5 chat+vision, Nano Banana image, Veo 3.1 video, TTS — `:2293-2863`); retired Imagen 4 rows `:294-317`; `DefaultModelConfigSeeder.php:56-58,66` |
| Tests | PHPUnit: `backend/tests/AI/Provider/{GoogleProviderStructuredOutputTest,GoogleProviderToolsTest,GoogleProviderTtsWavTest,GoogleProviderBlockedContentTest,GoogleProviderImagenRoutingTest,GoogleProviderMessageNormalizationTest}.php`, `backend/tests/Unit/AI/Provider/GoogleProviderAsyncVideoTest.php`, `backend/tests/Unit/AI/StructuredOutput/GoogleJsonSchemaNormalizerTest.php`, `backend/tests/Unit/AI/Messages/GeminiMessagesTranslatorTest.php`. Vitest / Playwright: none Google-specific |
| Mobile class | backend-only; UI ota-candidate. No store-required match |
| Notes / risks | Four seeded `DEFAULTMODEL` slots point at Google — gating the module on a fresh install leaves TEXT2PIC/PIC2PIC/TEXT2VID/TEXT2SOUND bound to an unavailable provider. Vertex keys (`GOOGLE_CLOUD_PROJECT_ID`, `GOOGLE_VERTEX_ACCESS_TOKEN`) are env-only and bypass `ProviderKeyStore`. Name collision risk with the `google` OAuth login and `IapPlatform` `google` — the FM4 test must match on service ids, not on the substring |

### `thehive`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\AI\Provider\TheHiveProvider` (`services.yaml:445-449`, `$apiKey: '%env(THEHIVE_API_KEY)%'`). Also referenced by `ModelCatalog`, `SyncModelPricesCommand`, `SystemConfigService`, `ModelImportService`, `ModelListProbeRegistry` |
| Route names | none dedicated. `THEHIVE_API_KEY` is **not** in `ProviderKeyStore::SUPPORTED_PROVIDERS` (`:51-61`), so the provider-keys routes do not serve it; it is editable only via the admin `.env` editor (`admin_config_values`, tab `ai.media`). **Never gate:** `admin_config_values`, `admin_config_schema` (shared editor) |
| Env keys | `THEHIVE_API_KEY` `''` (`services.yaml:74`, bound `:447`; `.env.test` `test-key`) |
| BCONFIG keys | none |
| Provider / plug keys | `getName()` = `'thehive'` (`TheHiveProvider.php:52-55`); tag `app.ai.image_generation` (`services.yaml:445-449`) |
| Existing availability check | `TheHiveProvider::isAvailable()` `backend/src/AI/Provider/TheHiveProvider.php:96-99` (`!empty($this->apiKey)`); `getStatus()` `:79-94`; `getRequiredEnvVars()` `:101-109` |
| featuresStatus() block | none dedicated — `$features['thehive']` from the dynamic loop `ConfigController.php:2186-2244`; also the `image-gen` block `:2170-2184` counts image providers generically |
| Capability ids | none provider-specific |
| Frontend | none TheHive-specific — the only hit of a case-insensitive `rg -n thehive frontend/src` is the icon mapping `utils/providerIcons.ts:101`; no component, view, API client, or i18n namespace; models appear only through the generic model lists |
| Seeds / fixtures | `ModelCatalog.php:3119,3143,3165,3188,3210` (`service => 'TheHive'`: Flux Schnell, Flux Schnell Enhanced, SDXL, SDXL Enhanced, Custom Emoji — all `text2pic`) |
| Tests | none found (`Glob backend/tests/**/*TheHive*.php` empty; no Vitest/Playwright hit) |
| Mobile class | backend-only (`utils/providerIcons.ts` is ota-candidate). No store-required match |
| Notes / risks | Smallest provider module: one class, one env key, zero tests. Constructor-injected key means UI changes to the `.env` editor need a container restart to apply (unlike `ProviderKeyStore` providers) |

### `stripe_billing`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\Service\BillingService` (`services.yaml:912-915`); `App\Controller\SubscriptionController` (`:956-965`); `App\Controller\StripeWebhookController` (`:967-974`); `App\Service\PremiumFeatureGate`; `App\Repository\TopupRepository`; entities `App\Entity\Subscription`, `App\Entity\Topup`, `App\Entity\User` (billing columns). Also referenced by `RateLimitService`, `UsageStatsService`, `UsageStatsController`, `UserRepository`, `Observability\EventScrubber`, `Service\Embedding\EmbeddingModelChangeGuard` |
| Route names | under `/api/v1/subscription` (`SubscriptionController.php:32`): `subscription_plans` (GET, `:145`, **public** `security.yaml:212`), `subscription_checkout` (POST, `:260`), `subscription_budget` (GET, `:464`), `subscription_topup` (POST, `:515`), `subscription_status` (GET, `:649`), `subscription_sync` (POST, `:764`), `subscription_portal` (POST, `:918`), `subscription_cancel` (POST, `:999`); `stripe_webhook` (POST `/api/v1/stripe/webhook`, `StripeWebhookController.php:30,55`, **public** `security.yaml:202`). **Never gate:** `subscription_plans` (already returns `stripeConfigured`/`iapConfigured` flags, `:250-253`), `subscription_status`, `stripe_webhook` |
| Env keys | `STRIPE_SECRET_KEY` `''` (`services.yaml:111`), `STRIPE_WEBHOOK_SECRET` `''` (`:112`), `STRIPE_PRICE_PRO` `''` (`:113`), `STRIPE_PRICE_TEAM` `''` (`:114`), `STRIPE_PRICE_BUSINESS` `''` (`:115`), `STRIPE_PAYMENT_METHODS` `''` (`:116`), `STRIPE_AUTOMATIC_TAX` `'false'` (`:175`). `STRIPE_PUBLISHABLE_KEY` appears only in `backend/.env.example`. `.env.test` sets fake `STRIPE_*` values. Compose: comes from `backend/.env` via `env_file` (`docker-compose.yml:354-356` comment) |
| BCONFIG keys | none (plans live in the `BSUBSCRIPTIONS` table, not `BCONFIG`) |
| Provider / plug keys | `BillingService::SOURCE_STRIPE = 'stripe'` (`BillingService.php:15`) |
| Existing availability check | `BillingService::isEnabled()` `backend/src/Service/BillingService.php:50-64` (real secret key, non-placeholder `STRIPE_PRICE_PRO`); called in `SubscriptionController.php:233,250,315,506,563,789,946,1015` (503 when off) and `PlatformCapabilityInventory.php:403` |
| featuresStatus() block | none. `runtime_config` → `billing.enabled` (`ConfigController.php:654-656`, public) |
| Capability ids | none as a fact; `billingService->isEnabled()` is included in the self-aware report (`PlatformCapabilityInventory.php:403`) |
| Frontend | `views/SubscriptionView.vue`, `views/SubscriptionSuccessView.vue`, `views/SubscriptionCancelView.vue` (routes `router/index.ts:561,568,575`), `services/api/subscriptionApi.ts`, `services/api/adminSubscriptionsApi.ts`, `composables/useSubscriptionPurchase.ts`, `composables/usePaywallPrompt.ts`, `components/subscription/SubscriptionPaywallModal.vue` (mounted `views/ChatView.vue:615`), `components/admin/AdminSubscriptionsPanel.vue`, `components/common/LimitReachedModal.vue`, `components/StorageQuotaWidget.vue`, `stores/config.ts` (`billing.enabled`); i18n `subscription.*` (en.json:2747), `nav.subscription` |
| Seeds / fixtures | `backend/src/Seed/SubscriptionPlanSeeder.php` (PRO/TEAM/BUSINESS rows + cost budgets, `:34-58`) |
| Tests | PHPUnit: `backend/tests/Unit/Service/BillingServiceTest.php`, `backend/tests/Integration/Stripe/{SubscriptionControllerStripeOutboundTest,SubscriptionControllerEndpointTest,StripeWebhookControllerTest}.php` (+ `Mock/StripeSignatureHelper.php`, `Mock/StripeMockHttpClient.php`), `backend/tests/Service/Admin/AdminSubscriptionsServiceTest.php`, `backend/tests/Service/Seed/SubscriptionPlanSeederTest.php`, `backend/tests/Unit/Service/UsageStatsServiceDeriveSubscriptionStatusTest.php`. Vitest: `frontend/tests/unit/views/SubscriptionView{Benefits,NativeGuard,StoreNote,Preselect}.spec.ts`, `components/subscription/SubscriptionPaywallModal.spec.ts`, `composables/usePaywallPrompt.spec.ts`, `components/AdminSubscriptionsPanel.spec.ts`. Playwright: `frontend/tests/e2e/tests/subscription.spec.ts`, `subscription-lifecycle.spec.ts` (+ `helpers/billing.ts`, `helpers/webhook.ts`) |
| Mobile class | backend-only for PHP. **store-required** for every listed frontend file: matches `frontend/src/**/*Subscription*`, `subscription*`, `subscription/**`, `*Paywall*`, `frontend/src/stores/config.ts` (policy lines 31-33, 40, 46) |
| Notes / risks | `SubscriptionController` already serves both Stripe and IAP (`iapConfigured`, `:253`); splitting `stripe_billing` from `mobile_iap` at the route level is not possible without touching `subscription_plans`. `PremiumFeatureGate`/`RateLimitService` read billing state on the hot path — the module cannot be "absent", only "not configured" |

### `mobile_iap`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\Service\IapPricingService` (`services.yaml:918-926`); `App\Service\Iap\AppleStoreKitVerifier` (`:934-944`) + alias `App\Service\Iap\AppleReceiptVerifierInterface` (`:945`); `App\Service\Iap\GooglePlayVerifier` (`:948-951`) + alias `App\Service\Iap\GooglePlayVerifierInterface` (`:952`); `App\Service\MobilePurchaseService`; `App\Controller\MobilePurchaseController`; `backend/src/Service/Iap/{IapPlatform,RedeemResult,IapEntitlement,IapEntitlementState}.php`, `Exception/{IapException,IapNotConfiguredException,IapVerificationException,IapConflictException}.php` |
| Route names | under `/api/v1/iap` (`MobilePurchaseController.php:33`): `iap_verify` (POST `/verify`, `:43`, authenticated), `iap_apple_notifications` (POST, `:146`, **public** `security.yaml:208`), `iap_google_notifications` (POST, `:195`, **public** `security.yaml:209`). Shared: `subscription_plans` exposes `iapConfigured` (`SubscriptionController.php:253`). **Never gate:** both notification routes (return 503 when unconfigured so the stores retry, `:156,206`), `subscription_plans` |
| Env keys | `IAP_PRODUCT_PRO` `''` (`services.yaml:121`), `IAP_PRODUCT_TEAM` `''` (`:122`), `IAP_PRODUCT_BUSINESS` `''` (`:123`), `IAP_PRICE_MARKUP_PERCENT` `'30'` (`:126`), `IAP_STORE_PRICE_PRO` `'24.99'` (`:129`), `IAP_STORE_PRICE_TEAM` `'64.99'` (`:130`), `IAP_STORE_PRICE_BUSINESS` `'129.99'` (`:131`); `default::`-only: `IAP_APPLE_BUNDLE_ID` (`:936`), `IAP_APPLE_APP_APPLE_ID` (`:941`), `IAP_APPLE_ENVIRONMENT` (`:942`), `IAP_APPLE_ROOT_CERTS_DIR` (`:943`), `IAP_GOOGLE_PACKAGE_NAME` (`:950`), `IAP_GOOGLE_SERVICE_ACCOUNT_JSON` (`:951`) — all present in `backend/.env.example` |
| BCONFIG keys | none IAP-specific. Related: group `MobileVersionService::GROUP` seeded by `MobileConfigSeeder.php:27-35` (`MIN_APP_VERSION`, `UPDATE_ENFORCE_AFTER`, `IOS_APP_URL`, `ANDROID_APP_URL`) — forced-update, not IAP |
| Provider / plug keys | `BillingService::SOURCE_APPLE = 'apple'`, `SOURCE_GOOGLE = 'google'` (`BillingService.php:16-17`); `App\Service\Iap\IapPlatform` enum |
| Existing availability check | `IapPricingService::isConfigured()` `backend/src/Service/IapPricingService.php:166-169` (`[] !== productCatalogue()`); `AppleStoreKitVerifier::isConfigured()` `backend/src/Service/Iap/AppleStoreKitVerifier.php:61-64` (`bundleId` + root certs); `GooglePlayVerifier::isConfigured()` `backend/src/Service/Iap/GooglePlayVerifier.php:43-48` (`packageName`, `serviceAccountJsonPath`, `is_file`); `IapNotConfiguredException` → 503 (`MobilePurchaseController.php:116,171,218`) |
| featuresStatus() block | none. `runtime_config` → `mobile` block (`ConfigController.php:645-651`) |
| Capability ids | none |
| Frontend | `services/nativeIap.ts`, `services/iapPostAuthRedemption.ts`, `services/api/nativeServer.ts`, `components/guest/PendingPurchaseBanner.vue`, `composables/useSubscriptionPurchase.ts` (shared with Stripe), `views/SubscriptionView.vue` (native guard / store note); i18n `iap.*` (en.json:5524) |
| Seeds / fixtures | none IAP-specific (`SubscriptionPlanSeeder` rows are shared with Stripe) |
| Tests | PHPUnit: `backend/tests/Unit/Service/IapPricingServiceTest.php`, `backend/tests/Unit/Service/MobilePurchaseServiceTest.php`, `backend/tests/Unit/Service/Iap/AppleStoreKitVerifierConfigTest.php`, `backend/tests/Controller/MobilePurchaseNotificationControllerTest.php`. Vitest: `frontend/tests/unit/services/{nativeIap,iapPostAuthRedemption,nativeServerPurchaseGate}.spec.ts`, `views/SubscriptionViewNativeGuard.spec.ts`, `views/SubscriptionViewStoreNote.spec.ts`. Playwright: none |
| Mobile class | backend-only for PHP. **store-required** for every listed frontend file: `frontend/src/**/*Iap*`, `iap*`, `*Purchase*`, `*Native*`, `frontend/src/services/native*.ts`, `frontend/src/services/api/native*.ts` (policy lines 28-30, 36, 44, 53, 56) |
| Notes / risks | Three separate `isConfigured()` truths (pricing, Apple, Google). The two notification webhooks must answer 503 (not 404) when unconfigured — a generic `feature_not_configured` 404 gate would make Apple/Google stop retrying. `GooglePlayVerifier::isConfigured()` does filesystem I/O (`is_file`) |

### `whatsapp`

| Row | Value |
| --- | --- |
| Service ids / classes | `App\Service\WhatsAppService` (`services.yaml:644-653`; `$whatsappAccessToken`, `$whatsappEnabled`, `$whatsappUserId: 2`, `$appUrl`, `$whatsappGraphApiBaseUrl`); `App\Controller\WebhookController` (`bind: $whatsappWebhookVerifyToken`, `:656-658` — the only `bind:` in the file); `App\Controller\WhatsAppAssistantController`; `App\Controller\PhoneVerificationController`; `App\Service\WhatsApp\WhatsAppAgentBinding`; `App\DTO\WhatsApp\IncomingMessageDto`; `PlatformCapabilityInventory` (autowired `WHATSAPP_ENABLED`/`WHATSAPP_ACCESS_TOKEN`, `:108-111`). ~50 further files mention WhatsApp as a channel value (`MessageProcessor`, `MessageForwardingService`, `OutboundChannelMedia`, `Text2SoundRunner`, `EmailMeRunner`, `ApiKeyScope`, `SetupStateService`, …) |
| Route names | `api_webhooks_whatsapp_verify` (GET `/api/v1/webhooks/whatsapp`, `WebhookController.php:692`) and `api_webhooks_whatsapp` (POST, `:725`) — **public** `security.yaml:199`; `api_whatsapp_assistant_get` / `api_whatsapp_assistant_put` (`/api/v1/channels/whatsapp/assistant`, `WhatsAppAssistantController.php:25,36,64`, authenticated); `api_phone_verify_request` (`:28`), `api_phone_verify_confirm` (`:198`), `api_phone_verify_remove` (`:314`), `api_phone_verify_status` (`:354`) under `/api/v1/user/verify-phone` (`PhoneVerificationController.php:17`, authenticated). **Never gate:** `api_webhooks_whatsapp_verify` (Meta handshake), `api_phone_verify_status` (returns `whatsapp_available`, `:390`) |
| Env keys | `WHATSAPP_ACCESS_TOKEN` `''` (`services.yaml:132`, bound `:648`), `WHATSAPP_WEBHOOK_VERIFY_TOKEN` `''` (`:133`, bound `:658`), `WHATSAPP_ENABLED` `'false'` (`:134`, bound `bool:` `:649`); `WHATSAPP_GRAPH_API_BASE_URL` (`default::` only, `:653`; `.env.example`; `.env.test` `http://whatsapp-stub:3999`). `.env.test`: `WHATSAPP_ENABLED=true`, `WHATSAPP_ACCESS_TOKEN=stub`. Compose backend passes all three |
| BCONFIG keys | per-user group `WHATSAPP` (`WhatsAppAgentBinding::CONFIG_GROUP`, `:15`) — bound assistant id |
| Provider / plug keys | none (channel, not a provider); capability fact id `channel_whatsapp` |
| Existing availability check | `WhatsAppService::isAvailable()` `backend/src/Service/WhatsAppService.php:128-131` (`$this->enabled && !empty($this->accessToken)`); used by `PhoneVerificationController.php:66,390`; `PlatformCapabilityInventory.php:329-331` re-implements the same test |
| featuresStatus() block | none |
| Capability ids | `channel_whatsapp` (`PlatformCapabilityInventory.php:329-341`); `phone_calls` in `KNOWN_ABSENT` names WhatsApp as the alternative (`:58-63`) |
| Frontend | `components/config/InboundConfiguration.vue` (mounted `views/ConfigView.vue:79`), `components/config/PhoneVerification.vue` (mounted `InboundConfiguration.vue:152`), `services/api/whatsappAssistantApi.ts`, `components/assistants/{AssistantPublishSection,BuilderTriggers}.vue`, `utils/channelSource.ts`, `components/files/FileSourceBadge.vue`, `components/MessageAudio.vue`, `composables/useAudioPlayback.ts`, `utils/messageMapper.ts`, `stores/chats.ts`, `stores/mediaJobs.ts`; i18n `config.phoneVerification.*` (en.json:2461), `channels.*` (:2590), `…whatsapp` labels (:1307, :2568) |
| Seeds / fixtures | none (no `BConfigSeeder`/`DataFixtures` hit; `$whatsappUserId: 2` is a hard-wired yaml constant, not a seed) |
| Tests | PHPUnit: `backend/tests/Service/WhatsAppServiceTest.php`, `backend/tests/Unit/Service/WhatsApp/WhatsAppAgentBindingTest.php`. Vitest: `frontend/tests/unit/components/assistants/{AssistantBuilder,BuilderTriggers}.spec.ts`, `stores/chats.spec.ts`, `utils/channelSource.spec.ts`, `components/files/FileSourceBadge.spec.ts`, `mediaTypes.spec.ts`. Playwright: `frontend/tests/e2e/tests/whatsapp.spec.ts` (+ `stub-servers/whatsapp/whatsapp-stub-server.ts`, `helpers/whatsapp-stub.ts`, `helpers/whatsapp-payload.ts`, `config/integration-data.ts`) |
| Mobile class | backend-only; UI ota-candidate. No store-required match |
| Notes / risks | `WhatsAppService` has 16 required service dependencies plus 3 optional ones, incl. `MessageProcessor`, `FileProcessor`, `AiFacade`, `UserMemoryService`, `EntityManagerInterface` (`WhatsAppService.php:91-116`) — the heaviest single service in the map. `WebhookController` is shared with the email and generic webhooks, so gating by route name, not by controller. The test suite forces `WHATSAPP_ENABLED=true` against a stub |

---

## Core (deliberately not a module in v1)

Every `env(X)` default in `backend/config/services.yaml` `parameters:` (lines 22-205; 107 keys) plus the one `bind:` (line 657, already claimed by `whatsapp`). Keys owned by a module above are listed first for the count; everything else is core.

### Module-owned `parameters:` defaults (39)

| Module | Keys (line) |
| --- | --- |
| `tika` (7) | `TIKA_HTTP_USER` (93), `TIKA_HTTP_PASS` (94), `TIKA_TIMEOUT_MS` (146), `TIKA_RETRIES` (147), `TIKA_RETRY_BACKOFF_MS` (148), `TIKA_MIN_LENGTH` (149), `TIKA_MIN_ENTROPY` (150) |
| `docling` (3) | `DOCLING_BASE_URL` (152), `DOCLING_TIMEOUT_MS` (153), `DOCLING_MAX_BYTES` (154) |
| `office_convert` (4) | `OFFICE_CONVERT_URL` (158), `OFFICE_CONVERT_TIMEOUT_MS` (159), `OFFICE_TEXT_MAX_ROWS` (160), `OFFICE_COMBINE_MAX_FILES` (161) |
| `searxng` (1) | `SEARXNG_BASE_URL` (156) |
| `piper_tts` (1) | `SYNAPLAN_TTS_URL` (178) |
| `local_ai` (0) | none with a default (`OLLAMA_BASE_URL` is strictly bound, see below) |
| `higgsfield` (2) | `HIGGSFIELD_API_KEY` (75), `HIGGSFIELD_API_SECRET` (76) |
| `google_ai` (3) | `GOOGLE_GEMINI_API_KEY` (71), `GOOGLE_CLOUD_PROJECT_ID` (72), `GOOGLE_VERTEX_ACCESS_TOKEN` (73) |
| `thehive` (1) | `THEHIVE_API_KEY` (74) |
| `stripe_billing` (7) | `STRIPE_SECRET_KEY` (111), `STRIPE_WEBHOOK_SECRET` (112), `STRIPE_PRICE_PRO` (113), `STRIPE_PRICE_TEAM` (114), `STRIPE_PRICE_BUSINESS` (115), `STRIPE_PAYMENT_METHODS` (116), `STRIPE_AUTOMATIC_TAX` (175) |
| `mobile_iap` (7) | `IAP_PRODUCT_PRO` (121), `IAP_PRODUCT_TEAM` (122), `IAP_PRODUCT_BUSINESS` (123), `IAP_PRICE_MARKUP_PERCENT` (126), `IAP_STORE_PRICE_PRO` (129), `IAP_STORE_PRICE_TEAM` (130), `IAP_STORE_PRICE_BUSINESS` (131) |
| `whatsapp` (3) | `WHATSAPP_ACCESS_TOKEN` (132), `WHATSAPP_WEBHOOK_VERIFY_TOKEN` (133), `WHATSAPP_ENABLED` (134) |

### Core `parameters:` defaults (68)

| Key (line) | Default | Feature | Why it stays core in v1 |
| --- | --- | --- | --- |
| `SYNAPLAN_PLATFORM` (22) | `'selfhost'` | deployment flavour (`:1278`) | Identity of the install; read by branding/setup, never optional |
| `OIDC_ADMIN_ROLES` (25) | role list | OIDC/SSO | Authentication transport; mobile store-required area |
| `OIDC_ROLE_CLAIMS` (29) | claim paths | OIDC/SSO | same |
| `OIDC_SCOPES` (32) | `'openid email profile offline_access'` | OIDC/SSO | same |
| `NATIVE_DEEPLINK_SCHEME` (37) | `'com.synaplan.app'` | native app deep link (`:1011`) | Auth transport to the mobile shell; store-required |
| `APPLE_APP_BUNDLE_ID` (41) | `'com.synaplan.app'` | Sign in with Apple (`:873`) | Auth; store-required |
| `REALTIME_ENABLED` (49) | `'false'` | Centrifugo realtime (`:592-602`) | Infra toggle with its own compose service; candidate for a v2 module |
| `REALTIME_API_URL` (50) | `''` | Centrifugo | same |
| `REALTIME_API_KEY` (51) | `''` | Centrifugo | same |
| `REALTIME_TOKEN_SECRET` (52) | `''` | Centrifugo | same |
| `MISTRAL_API_KEY` (56) | `''` | cloud provider key (`ProviderKeyStore`, `:328`) | Cloud chat providers are the product core; keys managed by one store |
| `LOG_FORMAT` (61) | `''` | logging | Observability |
| `ANTHROPIC_API_KEY` (67) | `''` | cloud provider key (`:319`) | same as Mistral |
| `OPENAI_API_KEY` (68) | `''` | cloud provider key (`:320`) | same |
| `OPENAI_STORE_RESPONSES` (69) | `'false'` | OpenAI provider option (`:363`) | Provider tuning |
| `GROQ_API_KEY` (70) | `''` | cloud provider key (`:321`) | same |
| `HUGGINGFACE_API_KEY` (77) | `''` | cloud provider key (`:330`) | same |
| `TRUSTEDTOKENS_API_KEY` (79) | `''` | cloud provider key (`:329`) | same |
| `XAI_API_KEY` (81) | `''` | cloud provider key (`:331`) | same |
| `PERPLEXITY_API_KEY` (82) | `''` | cloud provider key (`:332`) + web-search adapter | same |
| `TAVILY_API_KEY` (83) | `''` | web-search plug key (`PlugKeyStore`, `:337`) | Web search is one plug with six adapters; per-adapter modules deferred |
| `EXA_API_KEY` (84) | `''` | web-search plug key (`:338`) | same |
| `FIRECRAWL_API_KEY` (85) | `''` | web-search plug key (`:339`) | same |
| `JINA_API_KEY` (86) | `''` | rerank plug key (`:340`) | Rerank plug, same reasoning |
| `COHERE_API_KEY` (87) | `''` | rerank plug key (`:341`) | same |
| `VOYAGE_API_KEY` (88) | `''` | rerank plug key (`:342`) | same |
| `BRAVE_SEARCH_API_KEY` (89) | `''` | Brave web search (`:625`) | Seeded default web-search provider; candidate for a v2 module together with `BRAVE_SEARCH_*` |
| `DISCORD_WEBHOOK_URL` (90) | `''` | ops notifications (`:640`) | Operator alerting |
| `GMAIL_USERNAME` (91) | `''` | email channel IMAP (`:772`) | `channel_email`; candidate for a v2 module |
| `GMAIL_PASSWORD` (92) | `''` | email channel IMAP (`:773`) | same |
| `VECTOR_STORAGE_PROVIDER` (95) | `''` | vector store selection (`:1242`) | RAG storage backbone |
| `QDRANT_URL` (96) | `''` | Qdrant (`:1194,1208,1243`) | Memories/RAG; already has `memory-service` featuresStatus block; candidate for a v2 module |
| `QDRANT_MEMORIES_COLLECTION` (97) | `''` | Qdrant | same |
| `QDRANT_DOCUMENTS_COLLECTION` (98) | `''` | Qdrant | same |
| `QDRANT_DIGESTS_COLLECTION` (99) | `''` | Qdrant | same |
| `QDRANT_ROUTING_ANCHORS_COLLECTION` (100) | `''` | Qdrant | same |
| `TRITON_SERVER_URL` (101) | `''` | Triton provider (`:381`) | Self-hosted inference sibling of Ollama; candidate for a v2 module |
| `GOOGLE_CLIENT_ID` (102) | `''` | Google OAuth login (`:847,984`) | Auth |
| `GITHUB_CLIENT_ID` (103) | `''` | GitHub OAuth login (`:853,985`) | Auth |
| `OIDC_CLIENT_ID` (104) | `''` | OIDC | Auth |
| `OIDC_BEARER_AUDIENCE` (105) | `''` | OIDC bearer | Auth |
| `OIDC_DISCOVERY_URL` (106) | `''` | OIDC | Auth |
| `RECAPTCHA_SECRET_KEY` (107) | `''` | reCAPTCHA (`:1032`) | Registration protection |
| `GOOGLE_CLIENT_SECRET` (108) | `''` | Google OAuth login | Auth |
| `GITHUB_CLIENT_SECRET` (109) | `''` | GitHub OAuth login | Auth |
| `OIDC_CLIENT_SECRET` (110) | `''` | OIDC | Auth |
| `CLOUDFLARE_ACCOUNT_ID` (135) | `''` | Cloudflare embedding provider (`:500,509`) | Embedding fallback path |
| `CLOUDFLARE_API_TOKEN` (136) | `''` | Cloudflare embedding provider | same |
| `EMBEDDING_FALLBACK_PROVIDER` (137) | `''` | embedding router (`:541`) | RAG backbone |
| `RASTERIZE_DPI` (162) | `'150'` | PDF rasterize / `pdf_vision` (`:1169`) | Built-in extractor step, no sidecar |
| `RASTERIZE_PAGE_CAP` (163) | `'10'` | PDF rasterize | same |
| `RASTERIZE_TIMEOUT_MS` (164) | `'30000'` | PDF rasterize | same |
| `WHISPER_ENABLED` (165) | `'true'` | local whisper.cpp STT (`App\Service\WhisperService`, `:614-620`) | Ships inside the base image; has its own `whisper` featuresStatus block; candidate for a v2 module |
| `WHISPER_DEFAULT_MODEL` (166) | `'base'` | whisper | same |
| `WHISPER_BINARY` (167) | `/usr/local/bin/whisper` | whisper | same |
| `WHISPER_MODELS_PATH` (168) | `%kernel.project_dir%/var/whisper` | whisper | same |
| `FFMPEG_BINARY` (169) | `/usr/bin/ffmpeg` | media transcoding (`WhisperService` `:619`, `FileProcessor` `:1180`) | Shared by whisper and file processing |
| `BRAVE_SEARCH_ENABLED` (170) | `'false'` | Brave web search (`:627`) | see `BRAVE_SEARCH_API_KEY` |
| `BRAVE_SEARCH_API_URL` (171) | Brave endpoint | Brave | same |
| `BRAVE_SEARCH_COUNT` (172) | `'10'` | Brave | same |
| `BRAVE_SEARCH_COUNTRY` (173) | `'us'` | Brave | same |
| `BRAVE_SEARCH_SEARCH_LANG` (174) | `'en'` | Brave | same |
| `DEFAULT_USER_PLUGINS` (183) | `'[]'` | plugin defaults | Plugin system core |
| `GUEST_MAX_SESSIONS_PER_IP` (189) | `'5'` | guest widget limits (`:1041`) | Widget core |
| `MCP_ALLOWED_HOSTS` (195) | `''` | MCP host allow-list (`:665`) | Security boundary |
| `BOOTSTRAP_ADMIN_EMAIL` (199) | `''` | first-admin bootstrap (`:784`) | Install bootstrap |
| `BOOTSTRAP_ADMIN_PASSWORD` (200) | `''` | first-admin bootstrap (`:785`) | same |
| `BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE` (205) | `'false'` | first-admin bootstrap (`:786`) | same |

### Env keys bound without a `parameters:` default (22)

Strict bindings (container boot fails if unset) and `default::`-only aliases, found by the binding search. Listed for completeness because the FM4 test will see them.

| Key | Binding | Owner |
| --- | --- | --- |
| `APP_URL` | strict, many (`:10,652,663,…`) | core |
| `FRONTEND_URL` | strict (`:822,830,842,909,962,1010`) | core |
| `APP_SECRET` | strict (`:797,994,999,1005`) | core |
| `REDIS_DSN` | strict (`:552,566`) | core |
| `OLLAMA_BASE_URL` | strict (`:374,517`) | `local_ai` |
| `TIKA_BASE_URL` | strict (`:1046`) | `tika` |
| `GEMINI_API_KEY`, `GOOGLE_API_KEY` | `default::` (`:326-327`) | `google_ai` |
| `MESSAGES_GATEWAY_UPSTREAM_URL` | `default::` (`:462`) | core (Anthropic-compatible gateway) |
| `WHATSAPP_GRAPH_API_BASE_URL` | `default::` (`:653`) | `whatsapp` |
| `APPLE_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY` | `string:default::` (`:860-872,989`) | core (Sign in with Apple) |
| `OIDC_AUTO_REDIRECT` | `string:default::` (`:878,988`) | core |
| `AUTH_COOKIE_SECURE` | `string:default::` (`:1023`) | core |
| `IAP_APPLE_BUNDLE_ID`, `IAP_APPLE_APP_APPLE_ID`, `IAP_APPLE_ENVIRONMENT`, `IAP_APPLE_ROOT_CERTS_DIR` | `default::` (`:936-943`) | `mobile_iap` |
| `IAP_GOOGLE_PACKAGE_NAME`, `IAP_GOOGLE_SERVICE_ACCOUNT_JSON` | `default::` (`:950-951`) | `mobile_iap` |

### Totals

| Scope | Total env keys | Module-owned | Core |
| --- | --- | --- | --- |
| `parameters:` `env()` defaults (services.yaml:22-205) | 107 | 39 | 68 |
| bound without a `parameters:` default | 22 | 11 | 11 |
| **All env keys referenced by services.yaml** | **129** | **50** | **79** |

The single `bind:` (`services.yaml:657`, `$whatsappWebhookVerifyToken`) is counted inside `WHATSAPP_WEBHOOK_VERIFY_TOKEN` above; it introduces no extra key.

### Reconciliation with the FM4 test (`backend/tests/Architecture/ModuleOwnershipTest.php`)

The test's parser sees **132** keys, three more than the binding search above: `APP_VERSION`, `RECAPTCHA_ENABLED`, `RECAPTCHA_MIN_SCORE` (bound behind `bool:` / `float:` processors). All three are core (`application`, `auth`). Two keys are classified differently from the module tables on purpose:

| Key | Table above | Test | Why |
| --- | --- | --- | --- |
| `OFFICE_TEXT_MAX_ROWS` | `office_convert` | core `document_pipeline` | Consumed only by `StructuredTextExtractor`, which is pure PhpOffice (`backend/src/Service/File/Office/StructuredTextExtractor.php:7-11`) and works without Collabora |
| `OFFICE_COMBINE_MAX_FILES` | `office_convert` | core `document_pipeline` | `DocumentOfficeMergeService` never uses the converter; `DocumentCombineService` needs the engine only for mixed-format combos (`DocumentCombineService.php:73`, `$needsEngine`) and degrades otherwise |

`TIKA_MIN_LENGTH` / `TIKA_MIN_ENTROPY` stay under `tika` by name in both places, with the caveat noted in the `tika` row (FileProcessor applies them to every extractor). The S2 `office_convert` descriptor's `configuredBy()` therefore lists `OFFICE_CONVERT_URL` and `OFFICE_CONVERT_TIMEOUT_MS` only.

---

## Eager-registry audit (FM5)

All 12 `#[AutowireIterator]` consumers found by `rg -n 'AutowireIterator' backend/src`. "Eager" = the constructor iterates the tagged iterable and therefore instantiates every tagged service when the registry itself is built. "Lazy" = the iterable is stored and only walked on first use (Symfony injects a `RewindableGenerator`, so instantiation is deferred until iteration).

| # | Registry (file:lines) | Tag | (a) eager / lazy | (b) tagged services | (c) index key → tag attribute? | (d) cheap / heavy | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `App\AI\Service\ProviderRegistry` (`backend/src/AI/Service/ProviderRegistry.php:30-78`) | `app.ai.chat`, `app.ai.embedding`, `app.ai.vision`, `app.ai.image_generation`, `app.ai.video_generation`, `app.ai.speech_to_text`, `app.ai.text_to_speech`, `app.ai.file_analysis` (8 iterators) | **eager** — eight `foreach` loops at `:53-77` index by `$provider->getName()` | 18 provider classes (table below), 43 tag registrations in `services.yaml:351-536` (+7 for `TestProvider` in `when@dev`/`when@test`) | Yes — `getName()` returns a constant per class; a tag attribute `key: <name>` on every `app.ai.*` tag would let `ServiceLocator`/indexed iterator resolve it without instantiation | **heavy** — `OllamaProvider` builds an SDK client + `ini_set` (`OllamaProvider.php:26-28`); every provider is constructed even when its key is empty; `getProvidersMetadata()` (`:324-355`) and `getProvider()` (`:160`, calls `isAvailable()` → live HTTP for Ollama) run on request paths | **make lazy in S3 FM13** |
| 2 | `App\Plug\WebSearch\WebSearchRegistry` (`backend/src/Plug/WebSearch/WebSearchRegistry.php:24-34`) | `app.plug.web_search` (auto, `Kernel.php:92`) | **eager** — `foreach` at `:29-34`, index by `key()` | 6: `FirecrawlAdapter`, `TavilyAdapter`, `BraveSearchAdapter`, `SearxngAdapter`, `PerplexityAdapter`, `ExaAdapter` (`backend/src/Plug/WebSearch/Adapter/`) | Yes — `key()` is a constant string | cheap-to-medium — adapters take `HttpClientInterface` + `PlugKeyStore`/client; `SearxngAdapter` takes `SearxngClient` (no I/O in constructor) | leave as is (cheap) — revisit only if plugin adapters grow |
| 3 | `App\Plug\Rerank\RerankRegistry` (`backend/src/Plug/Rerank/RerankRegistry.php:29-39`) | `app.plug.rerank` (auto, `Kernel.php:93`) | **eager** — `foreach` at `:34-39`, index by `key()` | 2: `HttpRerankAdapter`, `LlmReranker` | Yes — `key()` constant | cheap | leave as is (cheap) |
| 4 | `App\Plug\Extraction\ExtractionRegistry` (`backend/src/Plug/Extraction/ExtractionRegistry.php:25-35`) | `app.plug.extractor` (auto, `Kernel.php:91`) | **eager** — `foreach` at `:30-35`, index by `key()` | 3: `DoclingExtractor`, `TikaExtractor`, `NativeTextExtractor` (`backend/src/Plug/Extraction/Adapter/`) | Yes — `key()` constant | cheap — clients are injected, no I/O until `health()`/`extract()` | leave as is (cheap) |
| 5 | `App\AI\Messages\MessagesGateway` (`backend/src/AI/Messages/MessagesGateway.php:127-128`) | `app.messages.translator` | **lazy** — `private iterable $translators = []` stored, walked on use | 3: `AnthropicPassthroughTranslator` (yaml tag `services.yaml`), `GeminiMessagesTranslator`, `OpenAiMessagesTranslator` (both `#[AutoconfigureTag]`) | n/a — lookup is `supports(providerName)`, not a static key | cheap | leave as is (already lazy) |
| 6 | `App\Bundle\BundleSectionRegistry` (`backend/src/Bundle/BundleSectionRegistry.php:18-28`) | `app.bundle.section` | **eager** — `foreach` at `:21-28`, index by `kind()` | 4: `McpServersBundleSection`, `CustomToolsBundleSection`, `PromptBundleSection`, `AgentBundleSection` (`backend/src/Bundle/Section/`) | Yes — `kind()` constant | cheap (repositories only) | leave as is (cheap) |
| 7 | `App\Service\Multitask\Skill\SkillCatalog` (`backend/src/Service/Multitask/Skill/SkillCatalog.php:39-48`) | `app.multitask.runner` (`_instanceof`, `services.yaml:220-221`) | **eager** — `foreach` at `:43-48`, calls `describe()` on each runner | 17 `TaskRunner` implementations (`backend/src/Service/Multitask/Execution/Runner/`) | Partially — `describe()` returns a structured skill description, not a scalar key; the *capability id* could be a tag attribute, the description could not | medium — 17 runners, several inject `AiFacade`/media services; constructed twice (also by `RunnerRegistry`) | make lazy in S3 FM13 (share one locator with #8) |
| 8 | `App\Service\Multitask\Execution\RunnerRegistry` (`backend/src/Service/Multitask/Execution/RunnerRegistry.php:26-33`) | `app.multitask.runner` | **eager** — `foreach` at `:29-33`, index by `supportedCapabilities()` (list) | 17 (same set as #7) | Yes — capability ids are constants; a repeated tag attribute per capability would map 1:n | medium (see #7) | make lazy in S3 FM13 (share one locator with #7) |
| 9 | `App\Service\Document\Tool\DocumentToolRegistry` (`backend/src/Service/Document/Tool/DocumentToolRegistry.php:18-21`) | `app.document_tool` (`_instanceof`, `services.yaml:234-235`) | **eager** — `iterator_to_array` at `:21`, lookup by `name()` | 26 concrete `AbstractDocumentTool` subclasses | Yes — `name()` constant | medium — 26 objects, mostly light, but instantiated on every request that touches the registry | make lazy in S3 FM13 (low priority) |
| 10 | `App\Service\Tool\ToolRegistry` (`backend/src/Service/Tool/ToolRegistry.php:16-18`) | `app.tool.source` | **lazy** — `private iterable $sources` stored | 6 tool sources | n/a — sources are enumerated, not keyed | cheap | leave as is (already lazy) |
| 11 | `App\Service\Message\InferenceRouter` (`backend/src/Service/Message/InferenceRouter.php:31-39`) | `app.message.handler` | **eager** — `foreach` at `:37-39`, index by `getName()` | 3: `ChatHandler`, `FileAnalysisHandler`, `MediaGenerationHandler` (`backend/src/Service/Message/Handler/`) | Yes — `getName()` constant | heavy per handler (each pulls `AiFacade`, media/file services) but always needed on the chat hot path | leave as is (needed on every chat request; laziness buys nothing) |
| 12 | `App\Service\Iam\ResourceKind\ResourceKindRegistry` (`backend/src/Service/Iam/ResourceKind/ResourceKindRegistry.php:19-27`) | `app.iam.resource_kind` (`_instanceof`, `services.yaml:236-237`; `PluginResourceKind.php` excluded `:252`) | **eager** — `foreach` at `:23-27`, index by `key()` | 7 `ShareableResourceKindInterface` implementations | Yes — `key()` constant | cheap | leave as is (cheap) |

Summary of verdicts: make lazy in S3 FM13 — `ProviderRegistry` (#1, the FM13 target), `SkillCatalog` + `RunnerRegistry` (#7/#8, one shared locator), `DocumentToolRegistry` (#9, low priority). Leave as is — `WebSearchRegistry`, `RerankRegistry`, `ExtractionRegistry`, `BundleSectionRegistry`, `InferenceRouter`, `ResourceKindRegistry` (cheap or hot-path), `MessagesGateway`, `ToolRegistry` (already lazy).

### ProviderRegistry — the 18 provider classes

`getName()` values read from each class; tags from `backend/config/services.yaml:351-536` (and `when@dev`/`when@test` for `TestProvider`).

| Class (`backend/src/AI/Provider/`) | `getName()` | `app.ai.*` tags | Constructor cost note |
| --- | --- | --- | --- |
| `AnthropicProvider` | `anthropic` | chat, vision | key via `ProviderKeyStore` |
| `OpenAIProvider` | `openai` | chat, embedding, vision, image_generation, speech_to_text, text_to_speech | key via `ProviderKeyStore`; `$storeResponses` (`:363`) |
| `OllamaProvider` | `ollama` | chat, embedding | builds SDK client + `ini_set` (`OllamaProvider.php:26-28`) — **module `local_ai`** |
| `TritonProvider` | `triton` | chat, embedding | gRPC stub, no connect (`LocalProviderAvailabilityProbe.php:16-17`) |
| `GroqProvider` | `groq` | chat, vision, speech_to_text | key via store |
| `MistralProvider` | `mistral` | chat, vision, speech_to_text, text_to_speech | key via store |
| `TrustedTokensProvider` | `trustedtokens` | chat, vision | key via store |
| `XaiProvider` | `xai` | chat, vision, image_generation, video_generation, text_to_speech, speech_to_text | key via store |
| `PerplexityProvider` | `perplexity` | chat | key via store |
| `GoogleProvider` | `google` | chat, vision, image_generation, video_generation, text_to_speech | key via store + env `$projectId`/`$vertexAccessToken` — **module `google_ai`** |
| `TheHiveProvider` | `thehive` | image_generation | env `$apiKey` — **module `thehive`** |
| `HiggsfieldProvider` | `higgsfield` | image_generation, video_generation | `HiggsfieldCredentialResolver` — **module `higgsfield`** |
| `PiperProvider` | `piper` | text_to_speech | `$ttsUrl` with yaml default — **module `piper_tts`** |
| `WhisperProvider` | `whisper` | speech_to_text | no yaml arguments (`services.yaml:483-485`); binary paths are bound on `App\Service\WhisperService` (`:614-620`) |
| `HuggingFaceProvider` | `huggingface` | chat, vision, embedding, image_generation, video_generation | key via store |
| `CloudflareProvider` | `cloudflare` | embedding | `$accountId`, `$apiToken` (`:500-501`) |
| `OpenAICompatibleProvider` | `openaicompatible` (`OpenAiCompatibleEndpointRegistry::PROVIDER_NAME`, `backend/src/AI/Credential/OpenAiCompatibleEndpointRegistry.php:36`) | chat, embedding, vision | `OpenAiCompatibleEndpointRegistry` |
| `TestProvider` | `test` | chat, embedding, vision, image_generation, speech_to_text, text_to_speech, file_analysis (`when@dev`, `when@test` only) | stub |

Five of the 18 providers belong to a v1 module (`local_ai`, `google_ai`, `thehive`, `higgsfield`, `piper_tts`); the other 13 stay core and are the reason `ProviderRegistry` itself cannot be gated, only made lazy.

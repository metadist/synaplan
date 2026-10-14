# Seams — where the three god classes split

Measured on `main`, 2026-10-14, from private-method names, call graphs
inside the public methods, and constructor types. Line numbers are a
snapshot; grep the method name. Target class names are proposals, the
cluster boundaries are the point. Every target lands in the V1 domain
folder named in the table.

---

## 1. `Service/Message/Handler/ChatHandler.php` — 4,607 lines, 39 ctor params

Public surface: `getName()`, `handle()` (673 lines), `handleStream()` (802
lines), `humanizeFileMarkersForModel()`, `dispatchPendingMemoryExtraction()`.
`handle()` calls 47 distinct private helpers, `handleStream()` 44, **42 are
shared** — the two methods are one pipeline written twice (buffered vs.
streamed). Decision §0 #3: collapse into `run(ChatRequest, ChatSink)`.

### Clusters → target classes

| Target (folder) | Private methods today | Ctor deps that move with it |
| --------------- | --------------------- | --------------------------- |
| `ChatGenerationOptionsResolver` (`Message/`) | `applyRuntimeProfile`, `applyProfileGenerationOptions`, `applyReasoningLevel`, `applyLocalThinkingBudget`, `rejectCutOffAnswer`, `degradeToUsableModel` | `ModelConfigService`, `ModelRepository`, `StructuredOutputConfig` |
| `ChatSystemPromptBuilder` (`Prompt/`) | `getSystemPrompt`, `buildLocationContext`, `buildTimeContext`, `buildDocumentImageContext`, `buildMediaEditContext`, `decorateSelfAwarePrompt`, `appendPluginContext`, `appendOfficePdfExportHint`, `appendOfficeToolsHint`, `formatConversationSummaryForPrompt`, `formatQuotedReferenceForPrompt`, `conversationFilePromptContext`, `documentInFocus`, `modelSupportsSystemMessages`, `prependSystemPromptToFirstUserMessage` | `PromptService`, `PromptRepository`, `TimeContextBuilder`, `DocumentImageCatalog`, `DocumentImageReferenceResolver`, `ConversationFileCatalog` |
| `ChatKnowledgeContextLoader` (`RAG/`) | `ragSettings`, `assistantShouldSearchKnowledge`, `agentRagScopes`, `loadRagContext`, `createSharedVectorResolver`, `formatSearchResultsForPrompt`, `docsNotFoundReply`, `slashDocsCitationInstruction` | `VectorSearchService`, `KnowledgeContextFormatter`, `RateLimitService` (RAG call budget) |
| `ChatMemoryContextLoader` (`Memory/`) | `loadMemoriesContext`, `createMemoryVectorResolver`, `loadDigestContext`, `loadFeedbackContext`, `searchFeedback`, `filterByScore`, `verbatimPromptMessageIds`, `isOmittedAssistantTurn` | `UserMemoryService`, `FeedbackConfigService`, `DigestSearchService`, `MessageDigestConfig` |
| `ChatMessageAssembler` (`Message/`) | `buildMessages`, `buildStreamingMessages`, `buildCurrentMessageContent`, `fitAttachmentText`, `buildMultimodalContent`, `isEmptyAssistantContent`, `loadPreviousResponseId`, `normalizeThreadForQueue`, `humanizeFileMarkersForModel` (becomes public here) | `ToolCallingTranslator`, `ToolCallParser` |
| `VisionAttachmentEncoder` (`Media/`) | `hasAttachedImages`, `extractImageDataUrls`, `detectFileType`, `isVisionSupportedImage`, `getImageMimeType`, `imageToBase64DataUrl`, `estimateBase64Length`, `downscaleImageForVision`, `resolveVisionFallbackModel`, `generatedMediaReferences`, `generatedImagesForVision`, `generatedImageProvenanceNotice`, `shouldIncludeGeneratedImages` | `VisionModelResolver`, `GeneratedImageVisionFlag`, `UserUploadPathBuilder`, `$uploadDir` |
| `ChatDocumentOutputHandler` (`Document/`) | `extractFileGenerationData`, `tryDocumentToolsEdit`, `applyDocumentEditResult`, `storeGeneratedDocument`, `storeGeneratedFile`, `getMimeTypeForExtension` | `DocumentGeneratorService`, `EntityManagerInterface` (file rows) |
| `RoutingHandoffResolver` (`Message/`) | `routingHandoffTools`, `routingHandoffFor`, `slashHintReply` | `RoutingToolset` |
| `PendingMemoryExtraction` (`Memory/`) | `buildPendingMemoryExtraction`, `dispatchPendingMemoryExtraction` | `MemoryExtractionDispatcher`, `PerfPipelineFlag` |
| **stays in `ChatHandler`** | `notify`, the stage order, sink handling | `AiFacade`, `LoggerInterface` + the nine collaborators above = 11; drop to ≤ 10 by folding `RoutingHandoffResolver` into `ChatMessageAssembler` if needed |

`getMimeTypeForExtension` and `storeGeneratedFile` also exist in
`StreamController` (`storeGeneratedFileInStream`, `getMimeTypeForExtension`)
— one `ChatDocumentOutputHandler` serves both (G6 then G9).

### Order inside the chat track

- **G5** — the three *context* builders (system prompt, knowledge, memory).
  They are read-only, produce strings/arrays, and are the easiest to snapshot.
- **G6** — message assembly, vision encoding, document output. These touch
  the filesystem and `AiFacade` input shape; the prompt-assembly snapshot
  from G2 is the guard.
- **G7** — the sink. `handle()` becomes `run(..., new BufferedChatSink())`,
  `handleStream()` becomes `run(..., new StreamingChatSink($callback))`.
  Delete the duplicate body. Snapshot unchanged, E2E green, done.

---

## 2. `Controller/StreamController.php` — 4,430 lines, 36 ctor params

Routes: `POST|GET /api/v1/messages/stream` (`streamMessage`, **2,008
lines**), `/save-cancelled`, `/stop-stream`, `/cancel-node`.
`handleNonStreamingRequest()` (~450 lines) is the buffered twin of the
stream body.

### Clusters → target classes

| Target (folder) | Private methods today | Ctor deps that move |
| --------------- | --------------------- | ------------------- |
| `SseWriter` (`Realtime/` or `Message/`) | `sendSSE`, `sanitizeUtf8`; the 18 fixed event names become `public const`; `writeStatus(string $name, array $payload)` passes the ~27 pipeline status names through **unchanged** (the status callback at `StreamController.php:1444–1459` forwards `$statusUpdate['status']` as the event name — that behaviour is part of the contract) | `LoggerInterface` |
| `StreamRequestResolver` (`Chat/`) | `resolveStreamParams`, `parseIncognitoHistory`, `isTruthyFlag`, `resolveRunOwnerKey`, `resolveIntendedChatModelForStream`, `resolveEffectiveChatModelId`, `normalizeModelId`, `resolvePinnedAgentId`, `denyAgentRuntime`, `denyDraftIfNotOwner`, `denyUnlessOwnsTrack`, `optionalChatId`, `resolveUiLanguage`, `isValidatedTestMode`, `suppressUnparseableOfficemakerEnvelope` | `GuestSessionService`, `GuestChatConfig`, `WidgetService`, `WidgetSessionService`, `ModelConfigService`, `AgentConfig`, `AgentService`, `AgentRuntimeResolver`, `RateLimitService`, `PremiumFeatureGate` |
| `StreamPersistence` (`Chat/`) | `persistRagSources`, `persistClassificationSortingMeta`, `persistTaskPlanDefinition`, `persistOriginalMediaMeta`, `persistTaskPlanFiles`, `persistChatErrorMeta`, `stampAgentId`, `applyRagGroupKey`, `storeGeneratedDocumentInStream`, `storeGeneratedFileInStream`, `getMimeTypeForExtension`, `safeMessageFileType` | `EntityManagerInterface`, `DocumentGeneratorService`, `DocumentImageReferenceResolver`, `GeneratedFileRegistrar`, `UserUploadPathBuilder`, `$uploadDir`, `MediaJobService`, `MediaJobMessageSync`, `MediaCancellationStore` |
| `StreamUsagePayloadBuilder` (`Usage/`) | `buildAiModelsPayload`, `buildChatUsagePayload`, `buildExtraUsageEntry`, `appendContextUsage` | `UsageStatsService`, `UsageTaximeterConfig` |
| `VoiceReplyStep` (`Media/`) | `processVoiceReply`, `emitVoiceReplyFailed` | `AiFacade` (TTS), `PromptService` |
| `ChatStreamOrchestrator` (`Chat/`) | the body of `streamMessage` as named steps: `startRun` → `process` → `persist` → `usage` → `voiceReply` → `complete`; `formatSearchResultsForSse`, `isCancelledResult`, `dispatchDeferredMemoryExtraction`, `presentChatFailure`, `resolveErrorLanguage` | `MessageProcessor`, `ChatRunService`, `ChatTitleService`, `MessageForwardingService`, `MemoryExtractionDispatcher`, `ConversationSummaryRefreshDispatcher`, `ChatErrorPresenter`, `ChatErrorNotifier` + the five collaborators above |
| **stays in `StreamController`** | four route methods, `#[CurrentUser]`, HTTP status mapping | `StreamRequestResolver`, `ChatStreamOrchestrator`, `SseWriter` |

### Order inside the stream track

- **G8** — `SseWriter` and `StreamRequestResolver`. Pure moves; the golden
  SSE transcript from G2 proves nothing changed on the wire.
- **G9** — `ChatStreamOrchestrator`. Cut the 2,008 lines at the existing
  comment banners into named private steps first (same class), run the
  gate, then move the class out. `handleNonStreamingRequest` becomes the
  orchestrator with a buffered sink — the same object `ChatHandler` gets in
  G7, so G9 depends on G7.

---

## 3. `Controller/ConfigController.php` — 2,572 lines, 50 ctor params

Routes (15): `/memory-service/check`, `/runtime` (**654 lines, 62 response
keys**), `/capabilities`, `/models`, `/models/defaults` (GET/POST/reset),
`/routing/planner-model` (GET/POST), `/routing/summary-model` (GET/POST),
`/models/{modelId}/check`, `/local-ai/status`, `/features`.

### `/runtime` → `RuntimeConfigAssembler` + contributors (G3)

```php
interface RuntimeConfigContributorInterface
{
    /** @return array<string, mixed> top-level keys this contributor owns */
    public function contribute(RuntimeConfigContext $ctx): array;
}
```

Tagged `app.runtime_config.contributor`; the assembler merges, asserts no
two contributors claim the same key (a test), and returns the array the
controller serialises. The OpenAPI annotation stays on `runtime()`.

`runtime()` uses **38 members** today (35 constructor dependencies plus the
helpers `moduleStates()`, `getInternalIp()`, `json()`). Grouped by the
concern they describe:

| Contributor (folder) | Ctor members that move (as named in the constructor today) | Count |
| -------------------- | ---------------------------------------------------------- | ----- |
| `AuthRuntimeConfig` (`Auth/`) | `registrationConfig`, `guestChatConfig`, `mailerConfig`, `demoLoginHint`, `setupState`, `configRepository` (recaptcha + misc keys) | 6 |
| `BillingRuntimeConfig` (`Billing/`) | `billingService`, `usageTaximeterConfig`, `mobileVersionService` | 3 |
| `ChatRuntimeConfig` (`Chat/`) | `chatActionPolicy`, `chatReadiness`, `chatWelcomeConfig`, `progressNarrationConfig`, `selfAwareConfig`, `searchInterpreter`, `documentToolsConfig`, `toolsConfig`, `workflowsConfig` | 9 |
| `ModulesRuntimeConfig` (`Module/`) | `modules` (ModuleRegistry), `moduleGate`, `pluginManager`, `computeConfig`, `bundleConfig`, `providerRegistry`, `getInternalIp()` | 6 |
| `FeaturesRuntimeConfig` (`Feature/`) | `iamConfig`, `savedTaskConfig`, `desktopAgentConfig`, `agentConfig` | 4 |
| `BrandingRuntimeConfig` (`Branding/`) | `brandingService`, `marketingNewsConfig`, `platformLinksConfig`, `aiProviderDisclosure`, `clientContextResolver` | 5 |
| `SpeechRuntimeConfig` (`Stt/`) | `webSpeechConfig`, `whisperService` | 2 |

The response-key → contributor assignment is fixed when the G2 snapshot
exists: each contributor owns a disjoint key set, the merge test asserts
the union equals the snapshot's 62 keys, and `configRepository` goes to
whichever contributor reads a key from it (it may be injected into two).

### The other routes → five services (G4)

Per-route member usage, measured:

| Route | Members used (excluding `json()` / `isGranted()`) |
| ----- | ------------------------------------------------- |
| `GET /models` | `chatReadiness`, `costCalculationService`, `groupPolicyService`, `groupRepository`, `layeredConfigResolver`, `modelConfigService`, `modelRepository`, `providerRegistry` + helpers `groupLimitSummary()`, `withLockedDefaultVisible()` |
| `GET /models/defaults` | `configRepository`, `iamConfig`, `layeredConfigResolver`, `modelConfigService`, `modelRepository` + `instanceDefaults()`, `reportedDefaults()` |
| `POST /models/defaults` | `capabilityInventory`, `configRepository`, `em`, `embeddingChangeGuard`, `embeddingMetadata`, `groupPolicyService`, `iamConfig`, `layeredConfigResolver`, `modelRepository` + `replacedDefaults()`, `reportedDefaults()`, `resolveCurrentVectorizeModelId()` |
| `POST /models/defaults/reset` | `modelConfigService` |
| `/routing/planner-model`, `/routing/summary-model` (GET+POST) | `configRepository`, `em`, `modelConfigService`, `modelRepository` |
| `GET /models/{modelId}/check` | `chatReadiness`, `configRepository`, `modelRepository`, `providerRegistry` + `envValue()`, `evaluateProviderRequiredConfiguration()`, `findProviderForModelService()` |
| `GET /memory-service/check` | `memoryService`, `qdrantUrl` |
| `GET /local-ai/status` | `localAiDownloadStatus` |
| `GET /capabilities` | `capabilityService` |
| `GET /features` | `featureStatusReporter` |

| Target (folder) | Routes | Ctor deps | Count |
| --------------- | ------ | --------- | ----- |
| `ModelListingService` (`Model/`) | `GET /models` | `chatReadiness`, `costCalculationService`, `groupPolicyService`, `groupRepository`, `layeredConfigResolver`, `modelConfigService`, `modelRepository`, `providerRegistry` | 8 |
| `ModelDefaultsService` (`Model/`) | `GET/POST /models/defaults`, `POST /models/defaults/reset` | `configRepository`, `iamConfig`, `layeredConfigResolver`, `modelConfigService`, `modelRepository`, `groupPolicyService`, `em`, `VectorizeModelChangePolicy` | 8 |
| `VectorizeModelChangePolicy` (`Embedding/`) | the POST-only embedding-change path | `embeddingChangeGuard`, `embeddingMetadata`, `capabilityInventory` (+ `resolveCurrentVectorizeModelId()`) | 3 |
| `RoutingModelsService` (`Model/`) | `/routing/planner-model`, `/routing/summary-model` | `configRepository`, `em`, `modelConfigService`, `modelRepository` | 4 |
| `ModelCheckService` (`ModelDiscovery/`) | `/models/{modelId}/check`, `/memory-service/check`, `/local-ai/status` | `chatReadiness`, `configRepository`, `modelRepository`, `providerRegistry`, `memoryService`, `qdrantUrl`, `localAiDownloadStatus` (+ the three `evaluate*/find*/envValue` helpers) | 7 |

`isGranted()` stays in the controller; the services receive the resolved
`bool $isAdmin` as a method argument.

`ConfigController` ends with **7 dependencies**: `RuntimeConfigAssembler`,
`ModelListingService`, `ModelDefaultsService`, `RoutingModelsService`,
`ModelCheckService`, `CapabilityService` (`/capabilities`, already a
service) and `FeatureStatusReporter` (`/features`, already a service).
Routes, OpenAPI annotations and `isGranted()` only.

---

## 4. Checklist for every extraction PR

- [ ] Characterization snapshot for the class is green on `main` before the branch.
- [ ] New class: `final readonly`, one concern, ≤ 10 ctor params, in the V1 domain folder, unit test alongside.
- [ ] Old class: the moved private methods are deleted, not kept as forwarders.
- [ ] Snapshot unchanged (or the change is a bug fix called out in the PR).
- [ ] `make -C frontend generate-schemas` → zero diff.
- [ ] Ratchet allow-list entries updated: the shrunken class's numbers go down, the new class is not on the list.
- [ ] `STATUS.md` row.

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
| `SseWriter` (`Realtime/` or `Message/`) | `sendSSE`, `sanitizeUtf8`; the 18 event names become `public const` | `LoggerInterface` |
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

| Contributor (folder) | Keys (today's names) | Ctor deps that move |
| -------------------- | -------------------- | ------------------- |
| `AuthRuntimeConfig` (`Auth/`) | `auth.*`, `recaptcha.*`, `demoLogin`, `setup.*` | `RegistrationConfig`, `GuestChatConfig`, `MailerConfig`, `DemoLoginHint`, `SetupStateService`, recaptcha site key |
| `BillingRuntimeConfig` (`Billing/`) | `billing.*`, `taximeter`, `premium`, `mobile.*` | `BillingService`, `UsageTaximeterConfig`, `MobileVersionService` |
| `ModulesRuntimeConfig` (`Module/`) | `modules`, `officeConvertEnabled`, `localAi.*`, `plugins` | `ModuleRegistry`, `ModuleGateConfig`, `FeatureStatusReporter`, `LocalAiDownloadStatusService`, `PluginManager` |
| `FeaturesRuntimeConfig` (`Feature/`) | `features.*`, `iam.*`, `savedTasks`, `desktop`, `agents`, `progressNarration`, `chatReadiness`, `capabilities` | `IamConfig`, `SavedTaskConfig`, `DesktopAgentConfig`, `AgentConfig`, `ProgressNarrationConfig`, `ChatReadinessService`, `CapabilityService` |
| `BrandingRuntimeConfig` (`Branding/`) | `branding.*`, `marketingNews`, `platformLinks`, `aiProviderDisclosure`, `client.*` | `BrandingService`, `MarketingNewsConfig`, `PlatformLinksConfig`, `AiProviderDisclosure`, `ClientContextResolver` |
| `SpeechRuntimeConfig` (`Stt/`) | `speech.*`, `whisper`, `tts` | `WebSpeechConfig`, `WhisperService` |

Key names above are indicative — the G2 response snapshot is the exact list
and the merge test enforces that the union of contributor keys equals it.

### The other routes → three services (G4)

| Target (folder) | Routes | Ctor deps that move |
| --------------- | ------ | ------------------- |
| `ModelDefaultsService` (`Model/`) | `GET/POST /models/defaults`, `POST /models/defaults/reset`, `GET /models` | `ConfigRepository`, `ModelRepository`, `ProviderRegistry`, `ModelConfigService`, `EmbeddingModelChangeGuard`, `EmbeddingMetadataService`, `UserMemoryService`, `EntityManagerInterface` |
| `RoutingModelsService` (`Model/`) | `/routing/planner-model`, `/routing/summary-model` | `ConfigRepository`, `ModelRepository`, `ModelConfigService` |
| `ModelCheckService` (`ModelDiscovery/`) | `/models/{modelId}/check`, `/memory-service/check`, `/local-ai/status` | `ProviderRegistry`, `UserMemoryService`, `LocalAiDownloadStatusService` |

`ConfigController` ends with the assembler, the three services and the
`FeatureStatusReporter` for `/features` and `/capabilities`: five
dependencies, routes and annotations only.

---

## 4. Checklist for every extraction PR

- [ ] Characterization snapshot for the class is green on `main` before the branch.
- [ ] New class: `final readonly`, one concern, ≤ 10 ctor params, in the V1 domain folder, unit test alongside.
- [ ] Old class: the moved private methods are deleted, not kept as forwarders.
- [ ] Snapshot unchanged (or the change is a bug fix called out in the PR).
- [ ] `make -C frontend generate-schemas` → zero diff.
- [ ] Ratchet allow-list entries updated: the shrunken class's numbers go down, the new class is not on the list.
- [ ] `STATUS.md` row.

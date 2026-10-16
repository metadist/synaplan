# Seams — where the three fixes cut

Measured on `main`, 2026-10-15, from method names, call sites inside the
public methods and constructor types. Line numbers are a snapshot; grep the
method name. Class names are the plan; the boundaries are the decision.

---

## 1. Fix 1 — one owner per rule

| Copy | Delete | Callers use |
| ---- | ------ | ----------- |
| status event | `notify()` in `MediaGenerationHandler`, `FileAnalysisHandler`, `MessagePreProcessor`, `InferenceRouter`; the payload part of `MessageProcessor::notify()` | `ProgressCallback::notify()`; `MessageProcessor` keeps its `info` log next to the call |
| media memory job | `MediaGenerationHandler::maybeDispatchMemoryExtraction()` body, `normalizeThreadForQueue()` | `PendingMemoryExtraction::build($message, '', $thread, [], $disabledByRequest, !$user->isMemoriesEnabled(), [], $incognito)` + `dispatch()`; the empty-text guard and the try/catch around the queue stay in the handler. Constructor loses `MessageBusInterface` and `PerfPipelineFlag` |
| model tag | `MessageClassifier::getModelTag()` | `ModelConfigService::getModelTag($id) ?? 'chat'` |
| locale list | `SUPPORTED_LOCALES` in `FileAnalysisHandler`, `ChatErrorPresenter`, `GeneratedMediaTextRenderer`, `TelegramInboundService` | `AccountLanguage::SUPPORTED` |
| in-class twins | `MessageClassifier::lastAssistantGeneratedMedia`, `threadHasGeneratedMedia`, `checkModelOverride`; `MessageProcessor::readJiraLinks` | one parameterised method each (`lastAssistantMessageWith(callable)`, `threadHas(callable)`, `override(string $key)`, `readLinkedPages(reader)`) |

Not merged: `mapModelTagToTopic` / `mapTopicToIntent` (classifier) and
`mapModelTagToTopic` / `mapTopicToIntentForAgain` (processor). They disagree;
see master plan §3.

---

## 2. Fix 2 — the twins

### `FileAnalysisHandler` — 2,046 lines, 11 ctor params

The twins differ only in transport and log wording (diff, 2026-10-15):
`chat()` vs. `chatStream()`; the answer returned as `content` vs. pushed to
`$streamCallback`; ` (streaming)` in the log message. The streamed audio log
reports the system prompt length as `transcript_length` — a log-only slip
that disappears with the twin.

| Target (`App\Service\Message\FileAnalysis\`) | Methods today | Ctor deps that move |
| -------------------------------------------- | ------------- | ------------------- |
| `AnalysisReply` (+ buffered / streamed) | the `content` vs. `$streamCallback(...)` difference in every twin; the `chat()` vs. `chatStream()` call | — |
| `AnalysisFileResolver` | `resolveFiles`, `filesFromMessage`, `filesFromConversation`, `filesFromThreadEntities`, `buildFileInfoFromConversation`, `buildFileInfoFromEntity`, `buildFileInfo`, `resolveFileType`, `normalizeRelativeUploadPath`, `conversationProgressMessage`, `logFilesInfo`, `describeFileList` | `ConversationFileCatalog`, `$uploadDir`, `LoggerInterface` |
| `DocumentAnalysis` | `handleDocumentsWithChatModel` (+ twin), `fitDocumentsToModel`, `chatWithOverflowRetry`, `buildDocumentsSystemPrompt`, `buildDocumentsUserPrompt`, `answerOutputTokens` | `AiFacade`, `ModelConfigService`, `ContextCondenser`, `ModelContextWindow` |
| `VoiceMessageReply` | `handleAudioWithChatModel` (+ twin), `buildAudioConversationalPrompt`, `joinAudioTranscripts`, `isGenericAudioPlaceholder` | `AiFacade`, `ModelConfigService` |
| `ImageAnalysis` | `handleImagesWithVisionModel` (+ twin), `analyzeImageBatch`, `combineImageAnalyses`, `throwIfImageBatchFullyFailed` | `AiFacade`, `ModelConfigService` |
| `FileAnalysisFailureCopy` | `buildDocumentExtractionError`, `buildAudioTranscriptionError`, `audioFailedCopy`, `buildVideoTranscriptionError`, `userFacingAnalysisError`, `fallbackImageFailureCopy`, `normalizeLocale`, `trans` | `TranslatorInterface`, `ChatFailureClassifier`, `ChatErrorPresenter` |
| **stays** | `getName`, `handle`, `handleStream`, `routeFiles` and its `wrap*` adapters, `buildLanguageDirective`, `resolvePromptAiModel` | the six collaborators + `WhisperService`, `LoggerInterface` |

### `MessageProcessor` — 2,111 lines, 21 ctor params

`processStream()` starts at line 89, `process()` at 685. Call sites of every
private helper were counted in both bodies; a helper called from both is
shared code today.

| Target (`App\Service\Message\Processing\`) | Methods / blocks today | Ctor deps that move |
| ------------------------------------------ | ---------------------- | ------------------- |
| `TurnClassificationResolver` | the classification block of each entry (fixed prompt, Again, forced image description, widget, classifier), `classifyOptions`, `afterClassify`, `applyAgentPinToOptions`, `classificationSummary`, `mapTopicToIntentForAgain`, `mapModelTagToTopic`, the prompt-metadata block | `MessageClassifier`, `ModelConfigService`, `AgentConfig`, `PromptService` |
| `TurnHistoryLoader` | the history block of each entry, `buildIncognitoHistory`, `activeChatHistory`, `applyRollingSummary` | `MessageRepository`, `ConversationSummaryService` |
| `LinkedContentReader` | `maybeFetchUrlContent`, `readConfluenceLinks`, `readJiraLinks`, `suppressSearchAfterConfluenceRead`, `linkOnlyMessageWasRead`, `linkedPageContext` | `UrlContentService`, `ConfluenceLinkedPageReader`, `JiraLinkedIssueReader` |
| `TurnWebSearch` | the search block of each entry, `userRequestedSearch`, `triggerReasonFor`, `threadContextForSearch`, `resolveSearchLanguage`, `deepenSearchResults`, `formatSearchResultsForClient`, `webSearchMetaString` | `WebSearchGateway`, `SearchQueryGenerator`, `AttachmentSearchContextResolver`, `WebResearchService`, `SearchResultRepository` |
| `TurnDispatcher` | the routing block of each entry, `applyRuntimeToolFlags`, `mcpServerAllowlist`, `isMultitaskRoutingEnabled`, `maybeShadowPlan`, `applyEffectiveClassification`, `tagSavedTaskRun` | `InferenceRouter`, `MultitaskRoutingConfig`, `TaskPlanner`, `TaskPlanStore`, `TaskPlanExecutor` |
| **stays** | `process()`, `processStream()`, `notify()` | `MessagePreProcessor`, the five stages, `LoggerInterface` = 7 |

All 21 constructor dependencies are assigned. `MessageProcessorTest` builds
the processor positionally; K7 builds it from real stages over the same
mocks, so its behavioural cases keep running unchanged.

---

## 3. Fix 3 — lists in the container

### `SeedAllCommand` — 35 steps

Each seeder implements `Seed\SeederInterface` (`label()`, `description()`,
`seed()`) and carries `#[AsTaggedItem(priority: …)]` in today's order:
models → model retirements → prompts → defaults → rate limits →
subscriptions → multitask → mcp → media jobs → branding → mobile →
marketing news → usage taximeter → progress narration → updates →
messages gateway → saved tasks → file context → desktop agent → iam →
agents → bundle → platform links → self-aware → structured output →
embedding router → native tool routing → document tools → smart search →
plugs → tools → workflows → compute → module gates → demo widget.
A test pins the label order. `--help` is built from `label()` +
`description()`, so it cannot drift again.

### `UserDeletionService` — 27 erase calls + 5 after-commit purges

| Eraser (`App\Service\Iam\Erasure\`) | In the transaction (today's order) | After commit |
| ----------------------------------- | ---------------------------------- | ------------ |
| `AccessCredentialsEraser` | verification tokens, auth tokens, API keys, sessions | — |
| `KnowledgeEraser` | RAG documents | memory index, digest index (Qdrant) |
| `UsageLogEraser` | use logs | — |
| `WidgetEraser` | widgets | — |
| `ShareEraser` | shares | — |
| `AgentEraser` | agents (returns the external ids) | purge agent externals |
| `SavedTaskEraser` | saved tasks, saved-task runs | — |
| `ConversationEraser` | chats, messages, email verification attempts | — |
| `FileEraser` | files | upload directories |
| `InboxEraser` | inbound email handlers | — |
| `SettingsEraser` | configs, prompts, saved prompts, plugin data, topups, MCP server configs, revectorize runs | — |
| `MemoryEraser` | memories (SQL), message digests, search index | — |
| `IdentityEraser` | group memberships, external identities, Telegram connection | Telegram webhook |

Priorities keep today's call order exactly; the order test pins it. The
**coverage test** reads Doctrine metadata, collects every entity with a
user-owner column, and requires each to be named by one eraser's `covers()`
or by an explicit "cascade" / "kept, with reason" entry in the test.
`deleteUser()` keeps the transaction, the user row and the logs; it loses
the 34 private methods and 32 of its 36 constructor parameters.

<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\Exception\VisionModelRequiredException;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanner;
use App\Service\Multitask\TaskPlanStore;
use App\Service\PerfTimer;
use App\Service\PromptService;
use App\Service\Search\BraveSearchService;
use App\Service\UrlContentService;
use Psr\Log\LoggerInterface;

/**
 * Message Processor with Status Callbacks.
 *
 * Orchestrates the complete message processing pipeline:
 * 1. Preprocessing (file download, parsing)
 * 2. Classification (sorting, topic detection)
 * 3. Web Search (if needed)
 * 4. Inference (AI response generation)
 *
 * Provides status callbacks for frontend feedback
 */
final readonly class MessageProcessor
{
    public function __construct(
        private MessageRepository $messageRepository,
        private ?SearchResultRepository $searchResultRepository,
        private MessagePreProcessor $preProcessor,
        private MessageClassifier $classifier,
        private InferenceRouter $router,
        private ModelConfigService $modelConfigService,
        private PromptService $promptService,
        private BraveSearchService $braveSearchService,
        private SearchQueryGenerator $searchQueryGenerator,
        private UrlContentService $urlContentService,
        private LoggerInterface $logger,
        private MultitaskRoutingConfig $multitaskConfig,
        private TaskPlanner $taskPlanner,
        private TaskPlanStore $taskPlanStore,
        private TaskPlanExecutor $taskPlanExecutor,
    ) {
    }

    /**
     * Process a message with streaming support.
     *
     * @param Message       $message        The message to process
     * @param callable      $streamCallback Callback for response chunks
     * @param callable|null $statusCallback Callback for status updates
     * @param array         $options        Processing options (e.g., reasoning, temperature)
     *
     * @return array Processing result with metadata
     */
    public function processStream(Message $message, callable $streamCallback, ?callable $statusCallback = null, array $options = []): array
    {
        $this->notify($statusCallback, 'started', 'Message processing started');

        $perfTimer = $options['perf_timer'] ?? null;
        if (!$perfTimer instanceof PerfTimer) {
            $perfTimer = new PerfTimer();
            $options['perf_timer'] = $perfTimer;
        }

        try {
            // Step 1: Preprocessing (modifies Message entity in-place)
            $this->notify($statusCallback, 'preprocessing', 'Downloading and parsing files...');

            $perfTimer->start('preprocess');
            $message = $this->preProcessor->process($message);
            $perfTimer->stop('preprocess');
            $preprocessed = ['hasFiles' => $message->getFile() > 0];

            if ($message->getFile() > 0 && $message->getFileText()) {
                $this->notify($statusCallback, 'preprocessing', 'File processed and text extracted');
            }

            $isAgainRequest = !empty($options['is_again']);

            // Check if this is a Widget request with fixed task prompt
            // If so, skip classification entirely and use the fixed prompt
            $hasFixedPrompt = isset($options['fixed_task_prompt']) && !empty($options['fixed_task_prompt']);

            // Step 2: Classification (Sorting) - skip if "Again" or Widget with fixed prompt
            $sortingModelId = null;
            $sortingProvider = null;
            $sortingModelName = null;
            $conversationHistory = [];

            $promptMetadata = [];

            if ($hasFixedPrompt) {
                $isWidget = !empty($options['is_widget_mode']);
                $source = $isWidget ? 'widget' : 'api';

                $this->logger->info('MessageProcessor: Fixed task prompt mode', [
                    'task_prompt' => $options['fixed_task_prompt'],
                    'source' => $source,
                ]);

                // Skip AI classification for fixed prompt mode -- we already know the topic
                // and don't need an expensive AI call that depends on the user's SORT model.
                // Set language to 'auto' so the AI responds in whatever language the user writes.
                $detectedLanguage = $message->getLanguage();
                if (!$detectedLanguage || 'NN' === $detectedLanguage) {
                    $detectedLanguage = 'auto';
                }

                $classification = [
                    'topic' => $options['fixed_task_prompt'],
                    'language' => $detectedLanguage,
                    'source' => $source,
                    'intent' => 'chat',
                    'is_widget_mode' => $isWidget,
                ];

                // Pass widget's configured model as override so ChatHandler uses it
                if (!empty($options['widget_model_id'])) {
                    $classification['override_model_id'] = (int) $options['widget_model_id'];
                }

                $this->logger->info('MessageProcessor: Using fixed prompt (skipped AI classification)', [
                    'language' => $detectedLanguage,
                    'fixed_topic' => $options['fixed_task_prompt'],
                    'source' => $source,
                    'widget_model_id' => $options['widget_model_id'] ?? null,
                ]);

                $this->notify($statusCallback, 'classified', sprintf(
                    '%s: Using fixed prompt',
                    ucfirst($source)
                ));

                if (!empty($options['rag_group_key'])) {
                    $classification['rag_group_key'] = $options['rag_group_key'];
                }
                if (!empty($options['rag_limit'])) {
                    $classification['rag_limit'] = (int) $options['rag_limit'];
                }
                if (isset($options['rag_min_score'])) {
                    $classification['rag_min_score'] = (float) $options['rag_min_score'];
                }
            } elseif (!empty($options['is_widget_mode'])) {
                // Widget Mode without fixed prompt: still disable memories
                $perfTimer->start('classify');
                $classification = $this->classifier->classify($message, $conversationHistory);
                $perfTimer->stop('classify');
                $classification['is_widget_mode'] = true;
            } elseif ($isAgainRequest) {
                // Skip sorting but preserve/override topic & language for routing
                $topic = strtolower($message->getTopic() ?: '');
                $language = $message->getLanguage();
                $language = ($language && 'NN' !== $language) ? $language : 'en';

                if ('' === $topic || 'unknown' === $topic) {
                    $topic = 'chat';
                }

                if (!empty($options['model_id'])) {
                    $modelTag = $this->modelConfigService->getModelTag((int) $options['model_id']);
                    if ($modelTag) {
                        $topic = $this->mapModelTagToTopic($modelTag, $topic);
                    }
                }

                $this->logger->info('MessageProcessor: Skipping classification (Again request)', [
                    'specified_model_id' => $options['model_id'],
                    'topic' => $topic,
                    'language' => $language,
                ]);

                $this->notify($statusCallback, 'classified', 'Using previously selected model (skipped classification)');

                $classification = [
                    'topic' => $topic,
                    'language' => $language,
                    'source' => 'again',
                    'intent' => $this->mapTopicToIntentForAgain($topic),
                    'model_id' => $options['model_id'],
                ];
            } else {
                // Normal flow: Run classification
                $sortingModelId = $this->modelConfigService->getDefaultModel('SORT', $message->getUserId());
                if ($sortingModelId) {
                    $sortingProvider = $this->modelConfigService->getProviderForModel($sortingModelId);
                    $sortingModelName = $this->modelConfigService->getModelName($sortingModelId);
                }

                $this->notify($statusCallback, 'classifying', 'Analyzing message intent...', [
                    'model_id' => $sortingModelId,
                    'provider' => $sortingProvider,
                    'model_name' => $sortingModelName,
                ]);
            }

            // Get conversation history for context - STREAMING VERSION
            // Priority: Use chatId if available (chat window context), otherwise fall back to trackingId
            $perfTimer->start('history');
            if ($message->getChatId()) {
                $conversationHistory = $this->messageRepository->findChatHistory(
                    $message->getUserId(),
                    $message->getChatId(),
                    30,      // Max 30 messages
                    15000    // Max ~15k chars (~4k tokens)
                );
                $this->logger->debug('Using chat history for streaming', [
                    'chat_id' => $message->getChatId(),
                    'history_count' => count($conversationHistory),
                ]);
            } else {
                // Fallback for legacy messages without chatId
                $conversationHistory = $this->messageRepository->findConversationHistory(
                    $message->getUserId(),
                    $message->getTrackingId(),
                    10
                );
                $this->logger->debug('Using legacy trackingId history for streaming', [
                    'tracking_id' => $message->getTrackingId(),
                    'history_count' => count($conversationHistory),
                ]);
            }
            $perfTimer->stop('history');

            if (!$isAgainRequest && !$hasFixedPrompt) {
                if (!empty($options['force_image_description'])) {
                    // Force image description mode (used by WhatsApp for images)
                    $classification = [
                        'topic' => 'general', // Used to be analyzefile, but ChatHandler handles vision now
                        'language' => $message->getLanguage() ?: 'en',
                        'source' => 'forced_image_description',
                        'intent' => 'chat',
                    ];
                    $this->logger->info('MessageProcessor: Forcing image description mode');
                } else {
                    // Run classification (override_model_id is NOT passed to classifier;
                    // it's added to classification below so ChatHandler can use it)
                    $perfTimer->start('classify');
                    $classification = $this->classifier->classify($message, $conversationHistory);
                    $perfTimer->stop('classify');
                }

                // IMPORTANT: Save sorting model info separately (don't pass to ChatHandler!)
                $sortingModelId = $classification['model_id'] ?? null;
                $sortingProvider = $classification['provider'] ?? null;
                $sortingModelName = $classification['model_name'] ?? null;

                // Remove sorting model info from classification
                unset($classification['model_id']);
                unset($classification['provider']);
                unset($classification['model_name']);

                // User-selected model from dropdown → pass through as override_model_id
                if (!empty($options['override_model_id'])) {
                    $classification['override_model_id'] = (int) $options['override_model_id'];
                }

                $this->notify($statusCallback, 'classified', sprintf(
                    'Topic: %s, Language: %s, Source: %s',
                    $classification['topic'],
                    $classification['language'],
                    $classification['source']
                ), [
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'source' => $classification['source'],
                    'sorting_model_id' => $sortingModelId,
                    'sorting_provider' => $sortingProvider,
                    'sorting_model_name' => $sortingModelName,
                ]);

                // Shadow mode (Sprint 1): generate + persist a task plan for
                // real traffic WITHOUT executing it. The legacy path above still
                // answers the user. Inert unless MULTITASK_SHADOW_MODE is on, and
                // wrapped so it can never affect the turn. Runs only on the
                // normal-classification branch (never widget/fixed-prompt/again).
                $this->maybeShadowPlan($message, $conversationHistory);
            }

            // User-selected knowledge-base folder (RAG group key) from the chat
            // composer. Scope this turn's retrieval to that group regardless of
            // the classification path above (normal chat, again, widget, fixed
            // prompt) so "add a knowledge group to the chat" works everywhere.
            if (!empty($options['rag_group_key'])) {
                $classification['rag_group_key'] = $options['rag_group_key'];
            }
            if (!empty($options['rag_limit'])) {
                $classification['rag_limit'] = (int) $options['rag_limit'];
            }
            if (isset($options['rag_min_score'])) {
                $classification['rag_min_score'] = (float) $options['rag_min_score'];
            }

            // Step 2.3: Load Prompt Metadata and apply tool restrictions
            $topic = $classification['topic'] ?? 'general';
            $perfTimer->start('prompt');
            $promptData = $this->promptService->getPromptWithMetadata($topic, $message->getUserId(), $classification['language'] ?? 'en');
            $perfTimer->stop('prompt');
            $promptMetadata = $promptData['metadata'] ?? [];

            // Stash the resolved prompt bundle in options so ChatHandler can reuse it
            // without redoing the DB lookup + deserialize. See Phase 1b in the plan.
            if (null !== $promptData) {
                $options['resolved_prompt_data'] = $promptData;
            }

            // Step 2.5: Web Search
            //
            // Web-search decision (trust the model):
            //   (a) Prompt opts in (`tool_internet=true`)        → always search.
            //   (b) Asset/document-generation topic              → never search.
            //   (c) Prompt opts out (`tool_internet=false`)      → never search.
            //   (d) Otherwise → trust the classifier's BWEBSEARCH vote. The AI
            //       sorter judges whether the message needs live information;
            //       the fast-path (no model call) carries no vote, so trivial
            //       chats stay fast and skip the search round-trip.
            $searchResults = null;
            $topic = $classification['topic'] ?? 'general';
            $promptToolInternet = $promptMetadata['tool_internet'] ?? null;
            $classifierVote = $classification['web_search'] ?? null;
            $userRequestedSearch = $this->userRequestedSearch($options);
            $messageText = $message->getText();
            $shouldSearch = WebSearchTopicPolicy::shouldSearch($topic, $userRequestedSearch, $promptToolInternet, $classifierVote, $messageText);
            $triggerReason = $this->triggerReasonFor($topic, $userRequestedSearch, $promptToolInternet, $classifierVote, $messageText, $shouldSearch);

            // Consolidated decision log: lets us diagnose "search didn't trigger"
            // reports without correlating multiple log lines from different services.
            $braveEnabled = $this->braveSearchService->isEnabled();
            $this->logger->info('MessageProcessor: Web search decision', [
                'message_id' => $message->getId(),
                'should_search' => $shouldSearch,
                'trigger_reason' => $triggerReason,
                'user_requested_search' => $userRequestedSearch,
                'prompt_tool_internet' => $promptToolInternet,
                'classifier_web_search_hint' => $classification['web_search'] ?? null,
                'classification_source' => $classification['source'] ?? null,
                'classification_topic' => $topic,
                'brave_enabled' => $braveEnabled,
            ]);

            if ($shouldSearch && !$braveEnabled) {
                $this->logger->warning('MessageProcessor: Web search requested but Brave Search is disabled', [
                    'message_id' => $message->getId(),
                    'trigger_reason' => $triggerReason,
                ]);
            }

            if ($shouldSearch && $braveEnabled) {
                $this->notify($statusCallback, 'searching', 'Searching the web...');

                try {
                    // Generate optimized search query using AI
                    $perfTimer->start('search_query');
                    $searchQuery = $this->searchQueryGenerator->generate(
                        $message->getText(),
                        $message->getUserId()
                    );
                    $perfTimer->stop('search_query');

                    // Get language from classification (e.g., "de", "en", "fr")
                    // Use it directly as both search_lang and country (ISO 639-1 codes)
                    $language = $classification['language'] ?? 'en';

                    // Use language code as country code (most languages match their country code)
                    // Brave Search will handle it gracefully and fall back if needed
                    $country = strtolower($language);

                    $this->logger->info('🔍 Performing web search', [
                        'original_question' => $message->getText(),
                        'optimized_query' => $searchQuery,
                        'language' => $language,
                        'country' => $country,
                        'message_id' => $message->getId(),
                    ]);

                    // Pass language and country to search service
                    $perfTimer->start('search_brave');
                    $searchResults = $this->braveSearchService->search($searchQuery, [
                        'country' => $country,
                        'search_lang' => $language,
                    ]);
                    $perfTimer->stop('search_brave');

                    // Save search results to database
                    if ($searchResults && !empty($searchResults['results']) && $this->searchResultRepository) {
                        $this->searchResultRepository->saveSearchResults($message, $searchResults, $searchQuery);

                        // Surface the actual sources (not just the count) the
                        // moment the search returns, so the client can render the
                        // "sources" box within seconds — while the answer is still
                        // generating — instead of waiting for the final `complete`
                        // event. Shape matches StreamController::formatSearchResultsForSse().
                        $this->notify($statusCallback, 'search_complete', sprintf(
                            'Found %d web results',
                            count($searchResults['results'])
                        ), [
                            'results_count' => count($searchResults['results']),
                            'query' => $searchQuery,
                            'results' => $this->formatSearchResultsForClient($searchResults['results']),
                        ]);
                    } else {
                        $this->logger->warning('No search results found or repository not available', [
                            'query' => $searchQuery,
                            'has_repository' => null !== $this->searchResultRepository,
                        ]);
                        $searchResults = null; // Reset to null if no results
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Web search failed', [
                        'error' => $e->getMessage(),
                        'message_id' => $message->getId(),
                    ]);

                    // Continue processing even if search fails
                    $this->notify($statusCallback, 'search_failed', 'Web search failed, continuing without results');
                }
            }

            // Step 2.7: URL Content Extraction (if tool_url_screenshot enabled)
            if ($promptMetadata['tool_url_screenshot'] ?? false) {
                $urls = $this->urlContentService->extractUrls($message->getText());
                if (!empty($urls)) {
                    $this->notify($statusCallback, 'fetching_urls', sprintf('Fetching content from %d URL(s)...', count($urls)));

                    $urlContentResults = $this->urlContentService->fetchMultiple($urls);
                    $successCount = count(array_filter($urlContentResults, static fn ($r) => $r->success));

                    if ($successCount > 0) {
                        $classification['url_content'] = $this->urlContentService->formatForPrompt($urlContentResults);
                        $this->notify($statusCallback, 'urls_fetched', sprintf('Extracted content from %d URL(s)', $successCount));
                    }
                }
            }

            // Step 3: Inference (AI Response) mit STREAMING
            // Get chat model info to display during generation
            $chatModelId = $this->modelConfigService->getDefaultModel('CHAT', $message->getUserId());
            $chatProvider = null;
            $chatModelName = null;
            if ($chatModelId) {
                $chatProvider = $this->modelConfigService->getProviderForModel($chatModelId);
                $chatModelName = $this->modelConfigService->getModelName($chatModelId);
            }

            $this->notify($statusCallback, 'generating', 'Generating response...', [
                'model_id' => $chatModelId,
                'provider' => $chatProvider,
                'model_name' => $chatModelName,
            ]);

            // Use routeStream instead of route, pass options through
            // Include search results if available
            if ($searchResults) {
                $options['search_results'] = $searchResults;
            }

            $perfTimer->start('handler_total');
            // MULTITASK_ROUTING_ENABLED (Sprint 2): route execution through the
            // task-plan executor. For single-node plans it delegates to the same
            // InferenceRouter with the same classification — behaviour is
            // identical; it only additionally persists the executed plan.
            // (Single $cls alias keeps the PHPStan "might not be defined" count stable.)
            $cls = $classification;
            $response = $this->isMultitaskRoutingEnabled($message)
                ? $this->taskPlanExecutor->executeStream($message, $conversationHistory, $cls, $streamCallback, $statusCallback, $options)
                : $this->router->routeStream($message, $conversationHistory, $cls, $streamCallback, $statusCallback, $options);
            $perfTimer->stop('handler_total');

            // Re-add sorting model info to result (for StreamController to save)
            $classification['sorting_model_id'] = $sortingModelId;
            $classification['sorting_provider'] = $sortingProvider;
            $classification['sorting_model_name'] = $sortingModelName;

            // Note: content is streamed, not returned
            return [
                'success' => true,
                'classification' => $classification,
                'response' => $response,
                'preprocessed' => $preprocessed,
                'search_results' => $searchResults, // Include search results in return
            ];
        } catch (VisionModelRequiredException $e) {
            $this->logger->warning('Vision-capable model required for image attachments', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_hint' => VisionModelRequiredException::HINT_CODE,
                'classification' => $classification ?? null,
            ];
        } catch (\App\AI\Exception\ProviderException $e) {
            // Handle ProviderException specially to preserve context (install instructions, etc.)
            $this->logger->error('AI Provider failed', [
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
                'context' => $e->getContext(),
            ]);

            $errorResult = [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
                'classification' => $classification ?? null,
            ];

            // Include context data (install_command, suggested_models) if available
            if ($context = $e->getContext()) {
                $errorResult['context'] = $context;
            }

            return $errorResult;
        } catch (\Exception $e) {
            $this->logger->error('Message processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'classification' => $classification ?? null,
            ];
        }
    }

    /**
     * Process a message with status callbacks.
     *
     * @param Message       $message        The message to process
     * @param callable|null $statusCallback Callback for status updates
     *
     * @return array Processing result
     */
    /**
     * Process a message with status callbacks.
     *
     * @param Message       $message        The message to process
     * @param array         $options        Processing options (e.g., fixed_task_prompt, model_id)
     * @param callable|null $statusCallback Callback for status updates
     *
     * @return array Processing result
     */
    public function process(Message $message, array $options = [], ?callable $statusCallback = null): array
    {
        // Backward compatibility: allow passing callback as 2nd argument
        if (is_callable($options) && null === $statusCallback) {
            $statusCallback = $options;
            $options = [];
        }

        $this->notify($statusCallback, 'started', 'Message processing started');

        try {
            // Step 1: Preprocessing (modifies Message entity in-place)
            $this->notify($statusCallback, 'preprocessing', 'Downloading and parsing files...');

            $message = $this->preProcessor->process($message);
            $preprocessed = ['hasFiles' => $message->getFile() > 0];

            if ($message->getFile() > 0 && $message->getFileText()) {
                $this->notify($statusCallback, 'preprocessing', 'File processed and text extracted');
            }

            // Step 2: Classification (Sorting)
            $sortingModelId = null;
            $sortingProvider = null;
            $sortingModelName = null;
            $isAgainRequest = !empty($options['is_again']);
            $hasFixedPrompt = isset($options['fixed_task_prompt']) && !empty($options['fixed_task_prompt']);
            $languageOverride = $options['language'] ?? null;

            if (!$hasFixedPrompt && !$isAgainRequest) {
                $sortingModelId = $this->modelConfigService->getDefaultModel('SORT', $message->getUserId());
                if ($sortingModelId) {
                    $sortingProvider = $this->modelConfigService->getProviderForModel($sortingModelId);
                    $sortingModelName = $this->modelConfigService->getModelName($sortingModelId);
                }

                $this->notify($statusCallback, 'classifying', 'Analyzing message intent...', [
                    'model_id' => $sortingModelId,
                    'provider' => $sortingProvider,
                    'model_name' => $sortingModelName,
                ]);
            }

            // Get conversation history for context - NON-STREAMING VERSION
            // Priority: Use chatId if available (chat window context), otherwise fall back to trackingId
            if ($message->getChatId()) {
                $conversationHistory = $this->messageRepository->findChatHistory(
                    $message->getUserId(),
                    $message->getChatId(),
                    30,      // Max 30 messages
                    15000    // Max ~15k chars (~4k tokens)
                );
                $this->logger->debug('Using chat history for non-streaming', [
                    'chat_id' => $message->getChatId(),
                    'history_count' => count($conversationHistory),
                ]);
            } else {
                // Fallback for legacy messages without chatId
                $conversationHistory = $this->messageRepository->findConversationHistory(
                    $message->getUserId(),
                    $message->getTrackingId(),
                    10
                );
                $this->logger->debug('Using legacy trackingId history for non-streaming', [
                    'tracking_id' => $message->getTrackingId(),
                    'history_count' => count($conversationHistory),
                ]);
            }

            if ($hasFixedPrompt) {
                $isWidget = !empty($options['is_widget_mode']);
                $source = $isWidget ? 'widget' : 'api';

                $this->logger->info('MessageProcessor: Using fixed task prompt', [
                    'task_prompt' => $options['fixed_task_prompt'],
                    'source' => $source,
                ]);

                $classification = [
                    'topic' => $options['fixed_task_prompt'],
                    'language' => $languageOverride ?? 'en',
                    'source' => $source,
                    'intent' => 'chat',
                    'is_widget_mode' => $isWidget,
                ];

                if (!empty($options['widget_model_id'])) {
                    $classification['override_model_id'] = (int) $options['widget_model_id'];
                }

                $this->notify($statusCallback, 'classified', sprintf('Using %s task prompt (skipped classification)', $source), [
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'source' => $classification['source'],
                ]);
            } elseif (!empty($options['force_image_description'])) {
                // Force image description mode (used by WhatsApp for images)
                $classification = [
                    'topic' => 'general', // Used to be analyzefile, but ChatHandler handles vision now
                    'language' => $languageOverride ?? 'en',
                    'source' => 'forced_image_description',
                    'intent' => 'chat',
                ];
                $this->logger->info('MessageProcessor: Forcing image description mode (non-streaming)');

                $this->notify($statusCallback, 'classified', 'Forced image description mode', [
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'source' => $classification['source'],
                ]);
            } elseif ($isAgainRequest) {
                $resolvedTopic = strtolower($message->getTopic() ?: '');
                if ('' === $resolvedTopic || 'unknown' === $resolvedTopic) {
                    $resolvedTopic = 'chat';
                }

                $resolvedLanguage = $languageOverride ?? ($message->getLanguage() && 'NN' !== $message->getLanguage() ? $message->getLanguage() : 'en');

                if (!empty($options['model_id'])) {
                    $modelTag = $this->modelConfigService->getModelTag((int) $options['model_id']);
                    if ($modelTag) {
                        $resolvedTopic = $this->mapModelTagToTopic($modelTag, $resolvedTopic);
                    }
                }

                $this->logger->info('MessageProcessor: Skipping classification (Again request)', [
                    'specified_model_id' => $options['model_id'],
                    'topic' => $resolvedTopic,
                    'language' => $resolvedLanguage,
                ]);

                $classification = [
                    'topic' => $resolvedTopic,
                    'language' => $resolvedLanguage,
                    'source' => 'again',
                    'model_id' => $options['model_id'],
                    'intent' => $this->mapTopicToIntentForAgain($resolvedTopic),
                ];

                $this->notify($statusCallback, 'classified', 'Using previously selected model (skipped classification)', [
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'source' => $classification['source'],
                    'model_id' => $classification['model_id'],
                ]);
            } else {
                $classification = $this->classifier->classify($message, $conversationHistory);

                // IMPORTANT: Save sorting model info separately (don't pass to ChatHandler).
                $sortingModelId = $classification['model_id'] ?? null;
                $sortingProvider = $classification['provider'] ?? null;
                $sortingModelName = $classification['model_name'] ?? null;

                unset($classification['model_id']);
                unset($classification['provider']);
                unset($classification['model_name']);

                // User-selected model from dropdown → pass through as override_model_id
                if (!empty($options['override_model_id'])) {
                    $classification['override_model_id'] = (int) $options['override_model_id'];
                }

                $this->notify($statusCallback, 'classified', sprintf(
                    'Topic: %s, Language: %s, Source: %s',
                    $classification['topic'],
                    $classification['language'],
                    $classification['source']
                ), [
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'source' => $classification['source'],
                    'sorting_model_id' => $sortingModelId,
                    'sorting_provider' => $sortingProvider,
                    'sorting_model_name' => $sortingModelName,
                ]);

                // Shadow mode (Sprint 1): see processStream() for rationale.
                // Inert unless MULTITASK_SHADOW_MODE is on; never affects the turn.
                $this->maybeShadowPlan($message, $conversationHistory);
            }

            if (isset($classification['prompt_metadata']) && is_array($classification['prompt_metadata'])) {
                $promptMetadata = $classification['prompt_metadata'];
            }

            if (empty($promptMetadata) && !empty($classification['topic'])) {
                $promptData = $this->promptService->getPromptWithMetadata($classification['topic'], $message->getUserId());
                if ($promptData) {
                    $promptMetadata = $promptData['metadata'] ?? [];
                    $classification['prompt_metadata'] = $promptMetadata;
                }
            }

            if (!empty($promptMetadata)) {
                $options['prompt_metadata'] = $promptMetadata;
            }

            $searchResults = null;
            $topic = $classification['topic'] ?? 'general';
            $promptToolInternet = $promptMetadata['tool_internet'] ?? null;
            $classifierVote = $classification['web_search'] ?? null;
            $userRequestedSearch = $this->userRequestedSearch($options);
            $messageText = $message->getText();
            $shouldSearch = WebSearchTopicPolicy::shouldSearch($topic, $userRequestedSearch, $promptToolInternet, $classifierVote, $messageText);
            $triggerReason = $this->triggerReasonFor($topic, $userRequestedSearch, $promptToolInternet, $classifierVote, $messageText, $shouldSearch);

            $braveEnabled = $this->braveSearchService->isEnabled();
            $this->logger->info('MessageProcessor: Web search decision', [
                'message_id' => $message->getId(),
                'should_search' => $shouldSearch,
                'trigger_reason' => $triggerReason,
                'user_requested_search' => $userRequestedSearch,
                'prompt_tool_internet' => $promptToolInternet,
                'classifier_web_search_hint' => $classification['web_search'] ?? null,
                'classification_source' => $classification['source'] ?? null,
                'classification_topic' => $topic,
                'brave_enabled' => $braveEnabled,
                'pipeline' => 'process',
            ]);

            if ($shouldSearch && !$braveEnabled) {
                $this->logger->warning('MessageProcessor: Web search requested but Brave Search is disabled', [
                    'message_id' => $message->getId(),
                    'trigger_reason' => $triggerReason,
                    'pipeline' => 'process',
                ]);
            }

            if ($shouldSearch && $braveEnabled) {
                $this->notify($statusCallback, 'searching', 'Searching the web...');

                try {
                    $searchQuery = $this->searchQueryGenerator->generate(
                        $message->getText(),
                        $message->getUserId()
                    );

                    $language = $classification['language'] ?? 'en';
                    $country = strtolower($language);

                    $this->logger->info('🔍 Performing web search', [
                        'original_question' => $message->getText(),
                        'optimized_query' => $searchQuery,
                        'language' => $language,
                        'country' => $country,
                        'message_id' => $message->getId(),
                    ]);

                    $searchResults = $this->braveSearchService->search($searchQuery, [
                        'country' => $country,
                        'search_lang' => $language,
                    ]);

                    if ($searchResults && !empty($searchResults['results']) && $this->searchResultRepository) {
                        $this->searchResultRepository->saveSearchResults($message, $searchResults, $searchQuery);

                        // Surface the actual sources (not just the count) the
                        // moment the search returns, so the client can render the
                        // "sources" box within seconds — while the answer is still
                        // generating — instead of waiting for the final `complete`
                        // event. Shape matches StreamController::formatSearchResultsForSse().
                        $this->notify($statusCallback, 'search_complete', sprintf(
                            'Found %d web results',
                            count($searchResults['results'])
                        ), [
                            'results_count' => count($searchResults['results']),
                            'query' => $searchQuery,
                            'results' => $this->formatSearchResultsForClient($searchResults['results']),
                        ]);
                    } else {
                        $this->logger->warning('No search results found or repository not available', [
                            'query' => $searchQuery,
                            'has_repository' => null !== $this->searchResultRepository,
                        ]);
                        $searchResults = null;
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Web search failed', [
                        'error' => $e->getMessage(),
                        'message_id' => $message->getId(),
                    ]);

                    $this->notify($statusCallback, 'search_failed', 'Web search failed, continuing without results');
                }
            }

            if ($searchResults) {
                $classification['search_results'] = $searchResults;
            }

            // Step 2.7: URL Content Extraction (if tool_url_screenshot enabled)
            if (!empty($promptMetadata['tool_url_screenshot'])) {
                $urls = $this->urlContentService->extractUrls($message->getText());
                if (!empty($urls)) {
                    $this->notify($statusCallback, 'fetching_urls', sprintf('Fetching content from %d URL(s)...', count($urls)));

                    $urlContentResults = $this->urlContentService->fetchMultiple($urls);
                    $successCount = count(array_filter($urlContentResults, static fn ($r) => $r->success));

                    if ($successCount > 0) {
                        $classification['url_content'] = $this->urlContentService->formatForPrompt($urlContentResults);
                        $this->notify($statusCallback, 'urls_fetched', sprintf('Extracted content from %d URL(s)', $successCount));
                    }
                }
            }

            // Step 3: Inference (AI Response)
            // Get chat model info to display during generation
            $chatModelId = $this->modelConfigService->getDefaultModel('CHAT', $message->getUserId());
            $chatProvider = null;
            $chatModelName = null;
            if ($chatModelId) {
                $chatProvider = $this->modelConfigService->getProviderForModel($chatModelId);
                $chatModelName = $this->modelConfigService->getModelName($chatModelId);
            }

            $this->notify($statusCallback, 'generating', 'Generating response...', [
                'model_id' => $chatModelId,
                'provider' => $chatProvider,
                'model_name' => $chatModelName,
            ]);

            // Forward the processing options exactly like processStream() does:
            // without them the DAG runners get an EMPTY NodeContext options array,
            // so track_id/node_id never reach MediaGenerationHandler — async node
            // jobs are then created without their node binding and the per-card
            // Stop / terminal card heal (#1239) cannot address them.
            $clsForRoute = $classification;
            $response = $this->isMultitaskRoutingEnabled($message)
                ? $this->taskPlanExecutor->execute($message, $conversationHistory, $clsForRoute, $statusCallback, $options)
                : $this->router->route($message, $conversationHistory, $clsForRoute, $statusCallback, $options);

            $this->notify($statusCallback, 'complete', 'Response generated', [
                'provider' => $response['metadata']['provider'] ?? 'unknown',
                'model' => $response['metadata']['model'] ?? 'unknown',
            ]);

            $classification['sorting_model_id'] = $sortingModelId;
            $classification['sorting_provider'] = $sortingProvider;
            $classification['sorting_model_name'] = $sortingModelName;

            if (isset($classification['search_results'])) {
                unset($classification['search_results']);
            }

            return [
                'success' => true,
                'response' => $response,
                'classification' => $classification,
                'preprocessing' => $preprocessed,
                'search_results' => $searchResults,
            ];
        } catch (VisionModelRequiredException $e) {
            $this->logger->warning('Vision-capable model required for image attachments', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->notify($statusCallback, 'error', $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_hint' => VisionModelRequiredException::HINT_CODE,
                'classification' => $classification ?? null,
            ];
        } catch (\App\AI\Exception\ProviderException $e) {
            // Handle ProviderException specially to preserve context (install instructions, etc.)
            $this->logger->error('AI Provider failed', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
                'context' => $e->getContext(),
            ]);

            $this->notify($statusCallback, 'error', $e->getMessage());

            $errorResult = [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
                'classification' => $classification ?? null,
            ];

            // Include context data (install_command, suggested_models) if available
            if ($context = $e->getContext()) {
                $errorResult['context'] = $context;
            }

            return $errorResult;
        } catch (\Throwable $e) {
            $errorDetails = [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];

            $this->logger->error('Message processing failed', $errorDetails);

            $this->notify($statusCallback, 'error', $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $errorDetails,
                'classification' => $classification ?? null,
            ];
        }
    }

    /**
     * Send status notification to callback.
     */
    /**
     * Human-readable reason for the consolidated web-search decision log.
     *
     * Mirrors the precedence in {@see WebSearchTopicPolicy::shouldSearch()}
     * so the log line directly explains the decision without a reader
     * having to consult two services.
     */
    private function triggerReasonFor(?string $topic, bool $userRequestedSearch, ?bool $promptToolInternet, ?bool $classifierVote, ?string $messageText, bool $shouldSearch): string
    {
        if (!$shouldSearch) {
            // Mirror the real precedence in WebSearchTopicPolicy::shouldSearch():
            // the prompt opt-out is the hard disable and must be reported first.
            if (false === $promptToolInternet) {
                return 'disabled_by_prompt_tool_internet';
            }

            if (WebSearchTopicPolicy::isNonWebSearchTopic($topic)) {
                return 'non_web_search_topic';
            }

            if (true === $classifierVote && WebSearchTopicPolicy::isTrivialConversational($messageText)) {
                return 'suppressed_trivial_conversation';
            }

            return 'classifier_vote_no_search';
        }

        if ($userRequestedSearch) {
            return 'user_requested_search';
        }

        if (true === $promptToolInternet) {
            return 'prompt_tool_internet_opt_in';
        }

        return 'classifier_vote_search';
    }

    /**
     * Resolve the explicit per-message web-search request from the processing
     * options. The streaming pipeline carries it as `web_search` (the chat
     * toggle / `/search` command, set by StreamController) while the legacy
     * non-streaming path uses `force_web_search`; accept either so an explicit
     * user request reliably forces a search.
     *
     * @param array<string, mixed> $options
     */
    private function userRequestedSearch(array $options): bool
    {
        return (bool) ($options['web_search'] ?? false)
            || (bool) ($options['force_web_search'] ?? false);
    }

    /**
     * Shadow-mode task planning (Sprint 1).
     *
     * When MULTITASK_SHADOW_MODE is on, generate a task plan for the message and
     * persist it to BMESSAGE_TASKS for analysis — but DO NOT execute it; the
     * legacy pipeline still produces the user's answer. Entirely best-effort:
     * any error is logged and swallowed so the user turn is never affected.
     *
     * Resolution uses the effective user id (email/WhatsApp remapping parity).
     */
    /**
     * Whether the task-plan executor should run for this message, resolved for
     * the EFFECTIVE user id (email/WhatsApp remapping parity with model
     * selection). Defaults to false on any error so a lookup glitch can never
     * break a turn.
     */
    private function isMultitaskRoutingEnabled(Message $message): bool
    {
        try {
            $userId = $this->modelConfigService->getEffectiveUserIdForMessage($message);

            return $this->multitaskConfig->isRoutingEnabled($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('MessageProcessor: routing-flag lookup failed, using legacy path', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function maybeShadowPlan(Message $message, array $conversationHistory): void
    {
        try {
            if (!$this->multitaskConfig->isShadowMode()) {
                return;
            }

            $userId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
            $result = $this->taskPlanner->plan($message, $conversationHistory, $userId);

            $messageId = $message->getId();
            if (null !== $messageId) {
                $this->taskPlanStore->persist($messageId, $result->plan, $result->modelId);
            }

            $this->logger->info('MessageProcessor: shadow task plan generated', [
                'message_id' => $messageId,
                'fallback' => $result->fallback,
                'node_count' => count($result->plan->nodes),
                'capabilities' => array_map(static fn ($n) => $n->capability->value, $result->plan->nodes),
                'plan_model_id' => $result->modelId,
                'validation_errors' => $result->errors,
            ]);
        } catch (\Throwable $e) {
            // Shadow mode must never affect the turn.
            $this->logger->warning('MessageProcessor: shadow task planning failed (ignored)', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map raw Brave results into the client source-card shape. Kept in sync with
     * {@see \App\Controller\StreamController::formatSearchResultsForSse()} so the
     * early `search_complete` payload and the final `complete` payload render
     * identically.
     *
     * @return list<array<string, mixed>>
     */
    private function formatSearchResultsForClient(mixed $results): array
    {
        if (!is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $profile = $result['profile'] ?? null;
            $out[] = [
                'title' => is_string($result['title'] ?? null) ? $result['title'] : '',
                'url' => is_string($result['url'] ?? null) ? $result['url'] : '',
                'description' => is_string($result['description'] ?? null) ? $result['description'] : '',
                'published' => $result['age'] ?? null,
                'source' => is_array($profile) ? ($profile['name'] ?? null) : null,
                'thumbnail' => $result['thumbnail'] ?? null,
            ];
        }

        return $out;
    }

    private function notify(?callable $callback, string $status, string $message, array $metadata = []): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'metadata' => $metadata,
                'timestamp' => microtime(true),
            ]);
        }

        $this->logger->info('MessageProcessor status', [
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    private function mapTopicToIntentForAgain(string $topic): string
    {
        return match ($topic) {
            'mediamaker', 'text2pic', 'text2vid', 'text2sound' => 'image_generation',
            'pic2text', 'analyze' => 'file_analysis',
            'officemaker' => 'document_generation',
            default => 'chat',
        };
    }

    private function mapModelTagToTopic(string $modelTag, string $fallback): string
    {
        return match (strtolower($modelTag)) {
            'text2pic', 'text2vid', 'text2sound' => 'mediamaker',
            'pic2text', 'analyze', 'vision' => 'general',
            'document', 'officemaker', 'text2doc' => 'officemaker',
            default => $fallback ?: 'chat',
        };
    }
}

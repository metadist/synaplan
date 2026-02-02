<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\Search\BraveSearchService;
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
class MessageProcessor
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
        private LoggerInterface $logger,
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

        try {
            // Step 1: Preprocessing (modifies Message entity in-place)
            $this->notify($statusCallback, 'preprocessing', 'Downloading and parsing files...');

            $message = $this->preProcessor->process($message);
            $preprocessed = ['hasFiles' => $message->getFile() > 0];

            if ($message->getFile() > 0 && $message->getFileText()) {
                $this->notify($statusCallback, 'preprocessing', 'File processed and text extracted');
            }

            // Check if this is "Again" functionality (model explicitly specified)
            // If so, skip classification to save time and API calls
            $isAgainRequest = isset($options['model_id']) && $options['model_id'];

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
                // Widget Mode: Use fixed task prompt, no classification needed
                $this->logger->info('MessageProcessor: Using fixed task prompt (Widget mode)', [
                    'task_prompt' => $options['fixed_task_prompt'],
                ]);

                $this->notify($statusCallback, 'classified', 'Using widget task prompt (skipped classification)');

                // Minimal classification with fixed topic
                $classification = [
                    'topic' => $options['fixed_task_prompt'],
                    'language' => 'en', // Default, could be enhanced
                    'source' => 'widget',
                    'is_widget_mode' => true, // Disable memories for widget
                ];

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
                $classification = $this->classifier->classify($message, $conversationHistory);
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
                // Get sorting model info to display during classification
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

            if (!$isAgainRequest && !$hasFixedPrompt) {
                if (!empty($options['force_image_description'])) {
                    // Force image description mode (used by WhatsApp for images)
                    $classification = [
                        'topic' => 'analyzefile',
                        'language' => $message->getLanguage() ?: 'en',
                        'source' => 'forced_image_description',
                        'intent' => 'file_analysis',
                    ];
                    $this->logger->info('MessageProcessor: Forcing image description mode');
                } else {
                    // Run classification
                    $classification = $this->classifier->classify($message, $conversationHistory);
                }

                // IMPORTANT: Save sorting model info separately (don't pass to ChatHandler!)
                $sortingModelId = $classification['model_id'] ?? null;
                $sortingProvider = $classification['provider'] ?? null;
                $sortingModelName = $classification['model_name'] ?? null;

                // Remove sorting model info from classification
                unset($classification['model_id']);
                unset($classification['provider']);
                unset($classification['model_name']);

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
            }

            // Step 2.3: Load Prompt Metadata and apply tool restrictions
            $topic = $classification['topic'] ?? 'general';
            $promptData = $this->promptService->getPromptWithMetadata($topic, $message->getUserId(), $classification['language'] ?? 'en');
            $promptMetadata = $promptData['metadata'] ?? [];

            // Apply tool restrictions from prompt metadata
            // If prompt explicitly DISABLES a tool, override frontend request
            if (isset($promptMetadata['tool_internet_search']) && !$promptMetadata['tool_internet_search']) {
                $options['web_search'] = false;
            }

            // TODO: Add similar logic for files_search and url_screenshot when implemented

            // Step 2.5: Web Search (if requested or AI-classified)
            $searchResults = null;
            $shouldSearch = $options['web_search'] ?? false;

            // Check if AI classifier detected search intent automatically
            if (!$shouldSearch && isset($classification['web_search'])) {
                $shouldSearch = (bool) $classification['web_search'];

                if ($shouldSearch) {
                    $this->logger->info('🤖 AI Classifier activated web search automatically', [
                        'message_id' => $message->getId(),
                        'classification' => $classification,
                    ]);
                }
            }

            // Also check if classifier set a search-related topic (legacy fallback)
            if (!$shouldSearch && isset($classification['source'])) {
                $source = $classification['source'];
                $shouldSearch = in_array($source, ['tools:search', 'tools:web'], true);
            }

            if ($shouldSearch && $this->braveSearchService->isEnabled()) {
                $this->notify($statusCallback, 'searching', 'Searching the web...');

                try {
                    // Generate optimized search query using AI
                    $searchQuery = $this->searchQueryGenerator->generate(
                        $message->getText(),
                        $message->getUserId()
                    );

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
                    $searchResults = $this->braveSearchService->search($searchQuery, [
                        'country' => $country,
                        'search_lang' => $language,
                    ]);

                    // Save search results to database
                    if ($searchResults && !empty($searchResults['results']) && $this->searchResultRepository) {
                        $this->searchResultRepository->saveSearchResults($message, $searchResults, $searchQuery);

                        $this->notify($statusCallback, 'search_complete', sprintf(
                            'Found %d web results',
                            count($searchResults['results'])
                        ), [
                            'results_count' => count($searchResults['results']),
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

            $response = $this->router->routeStream($message, $conversationHistory, $classification, $streamCallback, $statusCallback, $options);

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
            $isAgainRequest = isset($options['model_id']) && $options['model_id'];
            $hasFixedPrompt = isset($options['fixed_task_prompt']) && !empty($options['fixed_task_prompt']);
            $languageOverride = $options['language'] ?? null;

            if (!$hasFixedPrompt && !$isAgainRequest) {
                // Get sorting model info to display during classification
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
                $this->logger->info('MessageProcessor: Using fixed task prompt (Widget mode)', [
                    'task_prompt' => $options['fixed_task_prompt'],
                ]);

                $classification = [
                    'topic' => $options['fixed_task_prompt'],
                    'language' => $languageOverride ?? 'en',
                    'source' => 'widget',
                    'intent' => 'chat',
                ];

                $this->notify($statusCallback, 'classified', 'Using widget task prompt (skipped classification)', [
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'source' => $classification['source'],
                ]);
            } elseif (!empty($options['force_image_description'])) {
                // Force image description mode (used by WhatsApp for images)
                $classification = [
                    'topic' => 'analyzefile',
                    'language' => $languageOverride ?? 'en',
                    'source' => 'forced_image_description',
                    'intent' => 'file_analysis',
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

                $this->notify($statusCallback, 'classified', sprintf(
                    'Topic: %s, Language: %s, Source: %s',
                    $classification['topic'],
                    $classification['language'],
                    $classification['source']
                ), [
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'source' => $classification['source'],
                    'model_id' => $classification['model_id'] ?? null,
                    'provider' => $classification['provider'] ?? null,
                    'model_name' => $classification['model_name'] ?? null,
                ]);
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
            $shouldSearch = isset($options['force_web_search']) ? (bool) $options['force_web_search'] : false;

            if (!$shouldSearch && ($promptMetadata['tool_internet'] ?? false)) {
                $this->logger->info('MessageProcessor: Prompt metadata requests internet search', [
                    'topic' => $classification['topic'] ?? 'unknown',
                ]);
                $shouldSearch = true;
            }

            if (!$shouldSearch && isset($classification['web_search'])) {
                $shouldSearch = (bool) $classification['web_search'];

                if ($shouldSearch) {
                    $this->logger->info('🤖 AI Classifier activated web search automatically', [
                        'message_id' => $message->getId(),
                        'classification' => $classification,
                    ]);
                }
            }

            if (!$shouldSearch && isset($classification['source'])) {
                $source = $classification['source'];
                $shouldSearch = in_array($source, ['tools:search', 'tools:web'], true);
            }

            if ($shouldSearch && $this->braveSearchService->isEnabled()) {
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

                        $this->notify($statusCallback, 'search_complete', sprintf(
                            'Found %d web results',
                            count($searchResults['results'])
                        ), [
                            'results_count' => count($searchResults['results']),
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

            $response = $this->router->route($message, $conversationHistory, $classification, $statusCallback);

            $this->notify($statusCallback, 'complete', 'Response generated', [
                'provider' => $response['metadata']['provider'] ?? 'unknown',
                'model' => $response['metadata']['model'] ?? 'unknown',
            ]);

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
        } catch (\App\AI\Exception\ProviderException $e) {
            // Handle ProviderException specially to preserve context (install instructions, etc.)
            $this->logger->error('AI Provider failed', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
                'context' => $e->getContext(),
            ]);

            error_log('🔴 AI PROVIDER FAILED: '.$e->getMessage());

            $this->notify($statusCallback, 'error', $e->getMessage());

            $errorResult = [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
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

            // Also dump to stderr for immediate visibility
            error_log('🔴 MESSAGE PROCESSING FAILED: '.$e->getMessage());
            error_log('File: '.$e->getFile().':'.$e->getLine());

            $this->notify($statusCallback, 'error', $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $errorDetails,
            ];
        }
    }

    /**
     * Send status notification to callback.
     */
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
            'analyzefile', 'pic2text', 'analyze' => 'file_analysis',
            'officemaker' => 'document_generation',
            default => 'chat',
        };
    }

    private function mapModelTagToTopic(string $modelTag, string $fallback): string
    {
        return match (strtolower($modelTag)) {
            'text2pic', 'text2vid', 'text2sound' => 'mediamaker',
            'pic2text', 'analyze', 'vision' => 'analyzefile',
            'document', 'officemaker', 'text2doc' => 'officemaker',
            default => $fallback ?: 'chat',
        };
    }
}

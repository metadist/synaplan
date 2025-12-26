<?php

namespace App\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\File\UserUploadPathBuilder;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Chat Handler - Normaler Konversations-Chat.
 *
 * Uses user-defined model from BCONFIG or falls back to global default
 */
#[AutoconfigureTag('app.message.handler')]
class ChatHandler implements MessageHandlerInterface
{
    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private PromptService $promptService,
        private ModelConfigService $modelConfigService,
        private ModelRepository $modelRepository,
        private LoggerInterface $logger,
        private VectorSearchService $vectorSearchService,
        private EntityManagerInterface $em,
        private string $uploadDir,
        private UserUploadPathBuilder $userUploadPathBuilder,
    ) {
    }

    public function getName(): string
    {
        return 'chat';
    }

    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
    ): array {
        $this->notify($progressCallback, 'generating', 'Generating response...');

        $topic = $classification['topic'] ?? 'general';
        $language = $classification['language'] ?? 'en';

        $promptData = $this->promptService->getPromptWithMetadata($topic, $message->getUserId(), $language);
        $promptMetadata = $promptData['metadata'] ?? [];

        $searchResults = $classification['search_results'] ?? null;
        if (array_key_exists('search_results', $classification)) {
            unset($classification['search_results']);
        }

        $ragGroupKey = $classification['rag_group_key'] ?? null;
        $ragLimit = isset($classification['rag_limit']) ? max(1, (int) $classification['rag_limit']) : 5;
        $ragMinScore = isset($classification['rag_min_score']) ? max(0.0, min(1.0, (float) $classification['rag_min_score'])) : 0.3;
        $ragContext = $this->loadRagContext($message, $topic, $ragGroupKey, $ragLimit, $ragMinScore);

        // Determine model: Again override > prompt metadata > classification override > DB default
        $modelId = null;
        $provider = null;
        $modelName = null;

        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = (int) $classification['model_id'];
            $this->logger->info('ChatHandler: Using user-selected model (Again)', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
            ]);
        } elseif (isset($promptMetadata['aiModel']) && (int) $promptMetadata['aiModel'] > 0) {
            $modelId = (int) $promptMetadata['aiModel'];
            $this->logger->info('ChatHandler: Using prompt metadata model', [
                'model_id' => $modelId,
                'topic' => $topic,
                'user_id' => $message->getUserId(),
            ]);
        } elseif (isset($classification['override_model_id']) && $classification['override_model_id']) {
            $modelId = (int) $classification['override_model_id'];
            $this->logger->info('ChatHandler: Using classification override model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
            ]);
        } else {
            $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
            $modelId = $this->modelConfigService->getDefaultModel('CHAT', $effectiveUserId);
            $this->logger->info('ChatHandler: Using DB default model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
                'effective_user_id' => $effectiveUserId,
            ]);
        }

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);

            $this->logger->info('ChatHandler: Resolved model', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName,
            ]);
        }

        $systemPrompt = 'You are the Synaplan.com AI assistant. Please answer in the language of the user.';
        if ($promptData && isset($promptData['prompt'])) {
            $systemPrompt = $promptData['prompt']->getPrompt();
            $this->logger->info('ChatHandler: Using custom prompt content', [
                'topic' => $topic,
                'prompt_length' => strlen($systemPrompt),
            ]);
        }

        if (!empty($ragContext)) {
            $systemPrompt .= $ragContext;
            $this->logger->info('ChatHandler: RAG context appended to system prompt', [
                'topic' => $topic,
                'rag_context_length' => strlen($ragContext),
            ]);
        }

        if ($modelId) {
            $model = $this->modelRepository->find($modelId);
            if ($model) {
                $json = $model->getJson();
                if (isset($json['supportsStreaming']) && false === $json['supportsStreaming']) {
                    $systemPrompt = null;
                }
            }
        }

        $messages = $this->buildMessages($systemPrompt, $thread, $message, [
            'search_results' => $searchResults,
            'rag_context' => $ragContext,
        ]);

        $response = $this->aiFacade->chat(
            $messages,
            $message->getUserId(),
            [
                'provider' => $provider,
                'model' => $modelName,
                'stream' => false, // Später: streaming über callback
                'temperature' => 0.7,
            ]
        );

        $this->notify($progressCallback, 'generating', 'Response generated.');

        // Extract structured data from JSON response if present
        $content = $response['content'];
        $metadata = [
            'provider' => $response['provider'] ?? 'unknown',
            'model' => $response['model'] ?? 'unknown',
            'tokens' => $response['usage'] ?? [],
        ];

        // NEW: Check for file generation format first (for OfficeM maker)
        $fileData = $this->extractFileGenerationData($content);
        if (null !== $fileData) {
            $this->logger->info('ChatHandler: Detected AI file generation');

            // Store the file
            $generatedFile = $this->storeGeneratedFile($fileData, $message);

            if ($generatedFile) {
                // Attach file to message
                $message->addFile($generatedFile);
                $this->em->flush();

                // Return message key for translation in frontend
                $content = "__FILE_GENERATED__:{$fileData['filename']}";

                $metadata['generated_file'] = [
                    'id' => $generatedFile->getId(),
                    'filename' => $generatedFile->getFileName(),
                    'path' => $generatedFile->getFilePath(),
                    'size' => $generatedFile->getFileSize(),
                    'type' => $generatedFile->getFileType(),
                ];

                $this->logger->info('ChatHandler: File generation successful', [
                    'file_id' => $generatedFile->getId(),
                    'filename' => $generatedFile->getFileName(),
                ]);
            } else {
                $content = '__FILE_GENERATION_FAILED__';
                $this->logger->error('ChatHandler: File generation failed');
            }
        }
        // Legacy: Check for old JSON format (BTEXT, BFILE, BFILETEXT)
        // This is only for backward compatibility with old AI responses
        // New responses return plain text directly
        elseif (is_string($content) && str_starts_with(trim($content), '{')) {
            try {
                $jsonData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

                // Extract BTEXT as main content
                if (isset($jsonData['BTEXT'])) {
                    $content = $jsonData['BTEXT'];
                    $this->logger->info('ChatHandler: Extracted BTEXT from legacy JSON response');
                }

                // Extract file information (legacy format)
                if (!empty($jsonData['BFILE']) && !empty($jsonData['BFILETEXT'])) {
                    $metadata['file'] = [
                        'path' => $jsonData['BFILETEXT'],
                        'type' => $this->detectFileType($jsonData['BFILETEXT']),
                    ];
                    $this->logger->info('ChatHandler: Extracted file data from legacy format', $metadata['file']);
                }

                // Extract web search results/links
                if (!empty($jsonData['BLINKS'])) {
                    $metadata['links'] = $jsonData['BLINKS'];
                    $this->logger->info('ChatHandler: Extracted links');
                }
            } catch (\JsonException $e) {
                // Not valid JSON, use content as-is
                $this->logger->debug('ChatHandler: Response not JSON', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'content' => $content,
            'metadata' => array_merge($metadata, [
                'model_id' => $modelId, // Include resolved model_id for storage
            ]),
        ];
    }

    /**
     * Handle with streaming support.
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $this->notify($progressCallback, 'generating', 'Generating response...');

        // Load prompt WITH metadata based on topic from classification
        $topic = $classification['topic'] ?? 'general';
        $promptData = $this->promptService->getPromptWithMetadata($topic, $message->getUserId(), $classification['language'] ?? 'en');

        $promptMetadata = $promptData['metadata'] ?? [];

        $this->logger->info('ChatHandler: Loaded prompt metadata', [
            'topic' => $topic,
            'metadata' => $promptMetadata,
            'user_id' => $message->getUserId(),
        ]);

        // ✨ NEW: Load RAG context for task prompt (if files are associated)
        $ragContext = '';
        $ragResultsCount = 0;

        $ragGroupKey = $options['rag_group_key'] ?? ($classification['rag_group_key'] ?? null);
        $ragLimit = isset($options['rag_limit']) ? max(1, (int) $options['rag_limit']) : 5;
        $ragMinScore = isset($options['rag_min_score']) ? max(0.0, min(1.0, (float) $options['rag_min_score'])) : 0.3;

        if (!$ragGroupKey && 'general' !== $topic) {
            $ragGroupKey = "TASKPROMPT:{$topic}";
        }

        $ragResults = [];

        if (!empty($message->getText()) && $ragGroupKey) {
            try {
                error_log('🔍 ChatHandler: Attempting to load RAG context for topic: '.$topic.' (groupKey: '.$ragGroupKey.')');

                $ragResults = $this->vectorSearchService->semanticSearch(
                    $message->getText(),
                    $message->getUserId(),
                    $ragGroupKey,
                    limit: $ragLimit,
                    minScore: $ragMinScore
                );

                error_log('🔍 ChatHandler: RAG search returned '.count($ragResults).' results');

                if (empty($ragResults) && 'general' !== $topic) {
                    $fallbackGroupKey = "TASKPROMPT:{$topic}";
                    if ($fallbackGroupKey !== $ragGroupKey) {
                        error_log('🔄 ChatHandler: RAG fallback search with groupKey: '.$fallbackGroupKey);
                        $ragResults = $this->vectorSearchService->semanticSearch(
                            $message->getText(),
                            $message->getUserId(),
                            $fallbackGroupKey,
                            limit: $ragLimit,
                            minScore: $ragMinScore
                        );
                        error_log('🔍 ChatHandler: RAG fallback returned '.count($ragResults).' results');

                        if (!empty($ragResults)) {
                            $ragGroupKey = $fallbackGroupKey;
                        }
                    }
                }

                if (!empty($ragResults)) {
                    $ragContext = "\n\n## Knowledge Base Context (relevant to your task):\n";
                    foreach ($ragResults as $idx => $result) {
                        $ragContext .= sprintf(
                            "[Source %d] %s\n",
                            $idx + 1,
                            trim($result['chunk_text'])
                        );
                        error_log('🔍 ChatHandler: RAG chunk '.($idx + 1).': '.substr($result['chunk_text'], 0, 100).'...');
                    }
                    $ragContext .= "\nUse this context to provide accurate and specific answers.\n";
                    $ragResultsCount = count($ragResults);

                    error_log('🔍 ChatHandler: RAG context loaded, total length: '.strlen($ragContext));

                    $this->logger->info('ChatHandler: RAG context loaded', [
                        'topic' => $topic,
                        'chunks_found' => $ragResultsCount,
                        'user_id' => $message->getUserId(),
                        'group_key' => $ragGroupKey,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('❌ ChatHandler: RAG context loading failed: '.$e->getMessage());
                error_log('❌ Stack trace: '.$e->getTraceAsString());

                $this->logger->warning('ChatHandler: RAG context loading failed', [
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                    'group_key' => $ragGroupKey,
                ]);
                // Continue without RAG context
            }
        } else {
            error_log(sprintf(
                '🔍 ChatHandler: Skipping RAG - groupKey: %s, topic: %s, text empty: %s',
                $ragGroupKey ?? 'none',
                $topic,
                empty($message->getText()) ? 'yes' : 'no'
            ));
        }

        // Get model - Priority: User-selected (Again) > Prompt Metadata > Classification override > DB default
        $modelId = null;
        $provider = null;
        $modelName = null;

        // 1. Check if user explicitly selected a model (e.g., via "Again" function)
        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = $classification['model_id'];
            $this->logger->info('ChatHandler: Using user-selected model (Again)', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
            ]);
        }
        // 2. Check if prompt metadata defines a model (and it's not AUTOMATED = -1)
        elseif (isset($promptMetadata['aiModel']) && $promptMetadata['aiModel'] > 0) {
            $modelId = $promptMetadata['aiModel'];
            $this->logger->info('ChatHandler: Using prompt metadata model', [
                'model_id' => $modelId,
                'topic' => $topic,
                'user_id' => $message->getUserId(),
            ]);
        }
        // 3. Check if classification provides a model override
        elseif (isset($classification['override_model_id']) && $classification['override_model_id']) {
            $modelId = $classification['override_model_id'];
            $this->logger->info('ChatHandler: Using classification override model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
            ]);
        }
        // 4. Fall back to user's default model from DB
        else {
            $effectiveUserId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
            $modelId = $this->modelConfigService->getDefaultModel('CHAT', $effectiveUserId);
            $this->logger->info('ChatHandler: Using DB default model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId(),
                'effective_user_id' => $effectiveUserId,
            ]);
        }

        // Simple system prompt for streaming (like old system)
        $systemPrompt = 'You are the Synaplan.com AI assistant. Please answer in the language of the user.';

        // Use prompt content from metadata if available
        if ($promptData && isset($promptData['prompt'])) {
            $systemPrompt = $promptData['prompt']->getPrompt();
            $this->logger->info('ChatHandler: Using custom prompt content', [
                'topic' => $topic,
                'prompt_length' => strlen($systemPrompt),
            ]);
        }

        // ✨ NEW: Append RAG context to system prompt if available
        if (!empty($ragContext)) {
            $systemPrompt .= $ragContext;
            $this->logger->info('ChatHandler: RAG context appended to system prompt', [
                'topic' => $topic,
                'rag_context_length' => strlen($ragContext),
            ]);
        }

        // Check if model supports system messages (o1 models don't)
        if ($modelId) {
            $model = $this->modelRepository->find($modelId);
            if ($model) {
                $json = $model->getJson();
                // o1 models (non-streaming) don't support system messages
                if (isset($json['supportsStreaming']) && false === $json['supportsStreaming']) {
                    // Don't use system message - it will be prepended to first user message instead
                    $systemPrompt = null;
                }
            }
        }

        // Conversation History bauen (TEXT only for streaming)
        $messages = $this->buildStreamingMessages($systemPrompt, $thread, $message, $options);

        // Resolve model ID to provider + model name + features
        $modelFeatures = [];
        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);

            // Get model features from DB
            $model = $this->modelRepository->find($modelId);
            if ($model) {
                $modelFeatures = $model->getFeatures();
            }

            error_log('🟢 ChatHandler RESOLVED CHAT MODEL: '.$provider.' / '.$modelName.' (ID: '.$modelId.')');

            $this->logger->info('ChatHandler: Resolved model for streaming', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName,
                'features' => $modelFeatures,
            ]);
        }

        // AI streaming aufrufen - merge processing options with model config
        $aiOptions = array_merge([
            'provider' => $provider,
            'model' => $modelName,
            'temperature' => 0.7,
            'modelFeatures' => $modelFeatures, // Pass features to provider
        ], $options); // Options from frontend (e.g., reasoning: true/false)

        // Log reasoning option
        error_log('🧠 ChatHandler: Reasoning option = '.($aiOptions['reasoning'] ?? 'NOT SET'));

        $this->logger->info('🔵 ChatHandler: Calling AiFacade chatStream', [
            'provider' => $provider,
            'model' => $modelName,
            'user_id' => $message->getUserId(),
            'reasoning' => $aiOptions['reasoning'] ?? false,
        ]);

        $metadata = $this->aiFacade->chatStream(
            $messages,
            $streamCallback,
            $message->getUserId(),
            $aiOptions
        );

        $this->logger->info('🔵 ChatHandler: AiFacade chatStream returned');

        $this->notify($progressCallback, 'generating', 'Response generated.');

        return [
            'metadata' => [
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'model_id' => $modelId, // Include resolved model_id for storage
                'tokens' => $metadata['usage'] ?? [],
            ],
        ];
    }

    private function getSystemPrompt(int $userId, string $language): string
    {
        $prompt = $this->promptRepository->findOneBy([
            'ownerId' => $userId,
            'language' => $language,
        ]);

        if ($prompt) {
            return $prompt->getPrompt();
        }

        // Global Default Prompt
        $prompt = $this->promptRepository->findOneBy([
            'ownerId' => 0,
            'language' => $language,
        ]);

        if ($prompt) {
            return $prompt->getPrompt();
        }

        // Hardcoded Fallback
        return 'You are a helpful AI assistant. Respond in a friendly and professional manner.';
    }

    /**
     * Build messages for streaming (TEXT only, no JSON)
     * Like old system: topicPrompt with $stream = true.
     */
    private function buildStreamingMessages(?string $systemPrompt, array $thread, Message $currentMessage, array $options = []): array
    {
        $messages = [];

        // Add system message if supported (o1 models don't support it)
        if (null !== $systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        // Thread Messages hinzufügen (letzte N Messages)
        // IMPORTANT: Exclude the current message from the thread to avoid duplicates
        foreach ($thread as $msg) {
            // Skip if this is the current message (already added at the end)
            if ($msg->getId() === $currentMessage->getId()) {
                continue;
            }

            $role = 'IN' === $msg->getDirection() ? 'user' : 'assistant';
            $content = $msg->getText();

            // File Text inkludieren wenn vorhanden (Legacy + NEW MessageFiles)
            $allFilesText = $msg->getAllFilesText(); // NEW: combines legacy + File texts
            if (!empty($allFilesText)) {
                $fileInfo = '';
                if ($msg->getFiles()->count() > 0) {
                    $fileInfo = $msg->getFiles()->count().' file(s)';
                } elseif ($msg->getFileType()) {
                    $fileInfo = $msg->getFileType().' file';
                }

                $content .= "\n\n\n---\n\n\nUser provided $fileInfo:\n\n".
                           substr($allFilesText, 0, 10000). // Increased limit for multiple files
                           "\n\n";
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        // Aktuelle Message
        $content = $currentMessage->getText();
        $allFilesText = $currentMessage->getAllFilesText(); // NEW: combines all files

        $this->logger->info('🔍 ChatHandler: File text debug', [
            'message_id' => $currentMessage->getId(),
            'has_legacy_file' => $currentMessage->getFile() > 0,
            'legacy_file_text_length' => strlen($currentMessage->getFileText() ?? ''),
            'files_collection_count' => $currentMessage->getFiles()->count(),
            'all_files_text_length' => strlen($allFilesText),
            'all_files_text_preview' => substr($allFilesText, 0, 200),
        ]);

        if (!empty($allFilesText)) {
            $fileInfo = '';
            if ($currentMessage->getFiles()->count() > 0) {
                $fileInfo = $currentMessage->getFiles()->count().' file(s)';
            } elseif ($currentMessage->getFileType()) {
                $fileInfo = $currentMessage->getFileType().' file';
            }

            $content .= "\n\n\n---\n\n\nUser provided $fileInfo:\n\n".
                       substr($allFilesText, 0, 10000). // Increased limit
                       "\n\n";

            $this->logger->info('✅ ChatHandler: File text added to prompt', [
                'file_info' => $fileInfo,
                'content_length' => strlen($content),
            ]);
        } else {
            $this->logger->warning('⚠️ ChatHandler: No file text found!', [
                'message_id' => $currentMessage->getId(),
                'file_flag' => $currentMessage->getFile(),
            ]);
        }

        // Add web search results if available
        if (isset($options['search_results']) && !empty($options['search_results']['results'])) {
            $searchContext = $this->formatSearchResultsForPrompt($options['search_results']);
            $content .= "\n\n".$searchContext;

            $this->logger->info('✅ ChatHandler: Web search results added to prompt', [
                'results_count' => count($options['search_results']['results']),
                'query' => $options['search_results']['query'],
            ]);
        }

        $messages[] = [
            'role' => 'user',
            'content' => $content,
        ];

        return $messages;
    }

    /**
     * Build messages for non-streaming (JSON format)
     * Like old system: topicPrompt with $stream = false.
     */
    private function loadRagContext(
        Message $message,
        string $topic,
        ?string $groupKey = null,
        int $limit = 5,
        float $minScore = 0.3,
    ): string {
        if (empty($message->getText())) {
            $this->logger->debug('ChatHandler: Skipping RAG context (empty text)', [
                'topic' => $topic,
                'has_text' => false,
            ]);

            return '';
        }

        if (!$groupKey) {
            if ('general' === $topic) {
                $this->logger->debug('ChatHandler: Skipping RAG context (general topic, no group key)', [
                    'topic' => $topic,
                ]);

                return '';
            }

            $groupKey = "TASKPROMPT:{$topic}";
        }

        try {
            error_log('🔍 ChatHandler: Attempting to load RAG context for topic: '.$topic.' (groupKey: '.$groupKey.')');
            error_log('🔍 ChatHandler: Searching RAG with groupKey: '.$groupKey.', query: '.substr($message->getText(), 0, 100));

            $ragResults = $this->vectorSearchService->semanticSearch(
                $message->getText(),
                $message->getUserId(),
                $groupKey,
                limit: $limit,
                minScore: $minScore
            );

            error_log('🔍 ChatHandler: RAG search returned '.count($ragResults).' results');

            if (empty($ragResults) && 'general' !== $topic) {
                $fallbackGroupKey = "TASKPROMPT:{$topic}";
                if ($fallbackGroupKey !== $groupKey) {
                    error_log('🔄 ChatHandler: RAG fallback search with groupKey: '.$fallbackGroupKey);
                    $ragResults = $this->vectorSearchService->semanticSearch(
                        $message->getText(),
                        $message->getUserId(),
                        $fallbackGroupKey,
                        limit: $limit,
                        minScore: $minScore
                    );
                    error_log('🔍 ChatHandler: RAG fallback returned '.count($ragResults).' results');

                    if (!empty($ragResults)) {
                        $groupKey = $fallbackGroupKey;
                    }
                }
            }

            if (empty($ragResults)) {
                return '';
            }

            $ragContext = "\n\n## Knowledge Base Context (relevant to your task):\n";
            foreach ($ragResults as $idx => $result) {
                $ragContext .= sprintf(
                    "[Source %d] %s\n",
                    $idx + 1,
                    trim($result['chunk_text'])
                );
                error_log('🔍 ChatHandler: RAG chunk '.($idx + 1).': '.substr($result['chunk_text'], 0, 100).'...');
            }
            $ragContext .= "\nUse this context to provide accurate and specific answers.\n";

            $this->logger->info('ChatHandler: RAG context loaded', [
                'topic' => $topic,
                'chunks_found' => count($ragResults),
                'user_id' => $message->getUserId(),
                'group_key' => $groupKey,
            ]);

            return $ragContext;
        } catch (\Throwable $e) {
            error_log('❌ ChatHandler: RAG context loading failed: '.$e->getMessage());
            error_log('❌ Stack trace: '.$e->getTraceAsString());

            $this->logger->warning('ChatHandler: RAG context loading failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
                'group_key' => $groupKey,
            ]);

            return '';
        }
    }

    private function buildMessages(?string $systemPrompt, array $thread, Message $currentMessage, array $options = []): array
    {
        $messages = [];
        if (null !== $systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        // Thread Messages (JSON encoded wie im alten System)
        foreach ($thread as $msg) {
            $role = 'IN' === $msg->getDirection() ? 'user' : 'assistant';
            $content = $msg->getText();

            $messages[] = [
                'role' => $role,
                'content' => '['.$msg->getDateTime().']: '.$content,
            ];
        }

        // Aktuelle Message als JSON
        $msgArr = [
            'BUNIXTIMES' => $currentMessage->getUnixTimestamp(),
            'BDATETIME' => $currentMessage->getDateTime(),
            'BFILEPATH' => $currentMessage->getFilePath() ?: '',
            'BFILETYPE' => $currentMessage->getFileType() ?: '',
            'BTOPIC' => $currentMessage->getTopic(),
            'BLANG' => $currentMessage->getLanguage(),
            'BTEXT' => $currentMessage->getText(),
            'BFILETEXT' => $currentMessage->getFileText() ?: '',
        ];

        $ragContext = $options['rag_context'] ?? '';
        if (null === $systemPrompt && !empty($ragContext)) {
            $msgArr['BTEXT'] .= "\n\n".trim($ragContext);
        }

        if (isset($options['search_results']) && !empty($options['search_results']['results'])) {
            $searchContext = $this->formatSearchResultsForPrompt($options['search_results']);
            $msgArr['BTEXT'] .= "\n\n".$searchContext;

            $this->logger->info('ChatHandler: Web search results appended to BTEXT', [
                'results_count' => count($options['search_results']['results']),
                'query' => $options['search_results']['query'] ?? '',
            ]);
        }

        $messages[] = [
            'role' => 'user',
            'content' => json_encode($msgArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        return $messages;
    }

    private function notify(?callable $callback, string $status, string $message): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'timestamp' => time(),
            ]);
        }
    }

    private function detectFileType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $videoExtensions = ['mp4', 'webm', 'ogg', 'mov', 'avi'];
        $audioExtensions = ['mp3', 'wav', 'ogg', 'flac'];
        $docExtensions = ['pdf', 'doc', 'docx', 'txt', 'xlsx', 'pptx'];

        if (in_array($extension, $imageExtensions)) {
            return 'image';
        }
        if (in_array($extension, $videoExtensions)) {
            return 'video';
        }
        if (in_array($extension, $audioExtensions)) {
            return 'audio';
        }
        if (in_array($extension, $docExtensions)) {
            return 'document';
        }

        return 'file';
    }

    /**
     * Format web search results for AI prompt.
     */
    private function formatSearchResultsForPrompt(array $searchResults): string
    {
        if (empty($searchResults['results'])) {
            return '';
        }

        $formatted = "\n\n---\n\n\n";
        $formatted .= "🌐 Web Search Results (Query: \"{$searchResults['query']}\")\n\n";
        $formatted .= "I found the following information from recent web searches:\n\n";

        foreach ($searchResults['results'] as $index => $result) {
            $num = $index + 1;
            $formatted .= "[{$num}] **{$result['title']}**\n";
            $formatted .= "Source: {$result['url']}\n";

            if (!empty($result['description'])) {
                $formatted .= "Summary: {$result['description']}\n";
            }

            if (!empty($result['age'])) {
                $formatted .= "Published: {$result['age']}\n";
            }

            // Add extra snippets for more context
            if (!empty($result['extra_snippets'])) {
                $formatted .= "Additional context:\n";
                foreach (array_slice($result['extra_snippets'], 0, 2) as $snippet) {
                    $formatted .= '  • '.strip_tags($snippet)."\n";
                }
            }

            $formatted .= "\n";
        }

        $formatted .= "\nPlease use this information to answer the user's question. Cite sources using [1], [2], etc. when referencing specific information.\n\n";

        return $formatted;
    }

    /**
     * Parse AI response and extract file generation data if present
     * Format: { "BFILEPATH": "filename.ext", "BFILETEXT": "file content" }
     * Also handles JSON wrapped in markdown code blocks: ```json ... ```.
     *
     * @return array|null ['filename' => string, 'content' => string, 'extension' => string] or null
     */
    private function extractFileGenerationData(string $content): ?array
    {
        // Check if content looks like JSON or is wrapped in markdown code blocks
        $jsonContent = trim($content);

        // Extract JSON from markdown code blocks if present (```json ... ``` or ``` ... ```)
        if (preg_match('/```(?:json)?\s*\n(.*?)\n```/s', $content, $matches)) {
            $jsonContent = trim($matches[1]);
            $this->logger->info('ChatHandler: Extracted JSON from markdown code block');
        }

        if (!str_starts_with($jsonContent, '{')) {
            return null;
        }

        try {
            $jsonData = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);

            // Check for file generation format
            if (isset($jsonData['BFILEPATH']) && isset($jsonData['BFILETEXT'])) {
                $filename = trim($jsonData['BFILEPATH']);
                $fileContent = $jsonData['BFILETEXT'];

                if (empty($filename) || empty($fileContent)) {
                    return null;
                }

                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                $this->logger->info('ChatHandler: Detected file generation', [
                    'filename' => $filename,
                    'extension' => $extension,
                    'content_length' => strlen($fileContent),
                ]);

                return [
                    'filename' => $filename,
                    'content' => $fileContent,
                    'extension' => $extension,
                ];
            }

            return null;
        } catch (\JsonException $e) {
            // Not JSON or invalid format
            $this->logger->debug('ChatHandler: Content is not valid JSON for file generation', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Store AI-generated file in the file system and create File entity.
     *
     * @param array   $fileData ['filename' => string, 'content' => string, 'extension' => string]
     * @param Message $message  The message that triggered the generation
     *
     * @return File|null The created File entity or null on error
     */
    private function storeGeneratedFile(array $fileData, Message $message): ?File
    {
        $userId = $message->getUserId();
        $filename = $fileData['filename'];
        $content = $fileData['content'];
        $extension = $fileData['extension'];

        try {
            // Generate storage path similar to FileStorageService
            $year = date('Y');
            $month = date('m');
            $timestamp = time();

            // Sanitize filename
            $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
            $sanitized = preg_replace('/_+/', '_', $sanitized);

            // Add timestamp to prevent collisions
            $basename = pathinfo($sanitized, PATHINFO_FILENAME);
            $finalFilename = $basename.'_'.$timestamp.'.'.$extension;

            // Create relative path
            $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
            $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$finalFilename;
            $absolutePath = $this->uploadDir.'/'.$relativePath;

            // Create directory if not exists
            $dir = dirname($absolutePath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $this->logger->error('ChatHandler: Failed to create directory', ['dir' => $dir]);

                    return null;
                }
            }

            // Write file content
            if (!file_put_contents($absolutePath, $content)) {
                $this->logger->error('ChatHandler: Failed to write file', ['path' => $absolutePath]);

                return null;
            }

            // Detect MIME type
            $mimeType = $this->getMimeTypeForExtension($extension);

            // Create File entity
            $file = new File();
            $file->setUserId($userId);
            $file->setFilePath($relativePath);
            $file->setFileType($extension);
            $file->setFileName($filename);
            $file->setFileSize(strlen($content));
            $file->setFileMime($mimeType);
            $file->setFileText($content); // Store content as text for searchability
            $file->setStatus('generated');

            $this->em->persist($file);
            $this->em->flush();

            $this->logger->info('ChatHandler: File generated and stored successfully', [
                'file_id' => $file->getId(),
                'filename' => $filename,
                'path' => $relativePath,
                'size' => $file->getFileSize(),
            ]);

            return $file;
        } catch (\Throwable $e) {
            $this->logger->error('ChatHandler: Failed to store generated file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Get MIME type for file extension.
     */
    private function getMimeTypeForExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'html' => 'text/html',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}

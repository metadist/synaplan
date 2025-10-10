<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Message\MessagePreProcessor;
use App\Service\Message\MessageClassifier;
use App\Service\Message\InferenceRouter;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Message Processor with Status Callbacks
 * 
 * Orchestrates the complete message processing pipeline:
 * 1. Preprocessing (file download, parsing)
 * 2. Classification (sorting, topic detection)
 * 3. Inference (AI response generation)
 * 
 * Provides status callbacks for frontend feedback
 */
class MessageProcessor
{
    public function __construct(
        private MessageRepository $messageRepository,
        private MessagePreProcessor $preProcessor,
        private MessageClassifier $classifier,
        private InferenceRouter $router,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger
    ) {}

    /**
     * Process a message with streaming support
     * 
     * @param Message $message The message to process
     * @param callable $streamCallback Callback for response chunks
     * @param callable|null $statusCallback Callback for status updates
     * @param array $options Processing options (e.g., reasoning, temperature)
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

            // Step 2: Classification (Sorting)
            // Get sorting model info to display during classification
            $sortingModelId = $this->modelConfigService->getDefaultModel('SORT', $message->getUserId());
            $sortingProvider = null;
            $sortingModelName = null;
            if ($sortingModelId) {
                $sortingProvider = $this->modelConfigService->getProviderForModel($sortingModelId);
                $sortingModelName = $this->modelConfigService->getModelName($sortingModelId);
            }
            
            $this->notify($statusCallback, 'classifying', 'Analyzing message intent...', [
                'model_id' => $sortingModelId,
                'provider' => $sortingProvider,
                'model_name' => $sortingModelName
            ]);
            
            // Get conversation history for context
            $conversationHistory = $this->messageRepository->findConversationHistory(
                $message->getUserId(),
                $message->getTrackingId(),
                10
            );

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
                'model_name' => $classification['model_name'] ?? null
            ]);

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
                'model_name' => $chatModelName
            ]);
            
            // Use routeStream instead of route, pass options through
            $response = $this->router->routeStream($message, $conversationHistory, $classification, $streamCallback, $statusCallback, $options);
            
            // Note: content is streamed, not returned
            return [
                'success' => true,
                'classification' => $classification,
                'response' => $response,
                'preprocessed' => $preprocessed,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Message processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process a message with status callbacks
     * 
     * @param Message $message The message to process
     * @param callable|null $statusCallback Callback for status updates
     * @return array Processing result
     */
    public function process(Message $message, ?callable $statusCallback = null): array
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

            // Step 2: Classification (Sorting)
            // Get sorting model info to display during classification
            $sortingModelId = $this->modelConfigService->getDefaultModel('SORT', $message->getUserId());
            $sortingProvider = null;
            $sortingModelName = null;
            if ($sortingModelId) {
                $sortingProvider = $this->modelConfigService->getProviderForModel($sortingModelId);
                $sortingModelName = $this->modelConfigService->getModelName($sortingModelId);
            }
            
            $this->notify($statusCallback, 'classifying', 'Analyzing message intent...', [
                'model_id' => $sortingModelId,
                'provider' => $sortingProvider,
                'model_name' => $sortingModelName
            ]);
            
            // Get conversation history for context
            $conversationHistory = $this->messageRepository->findConversationHistory(
                $message->getUserId(),
                $message->getTrackingId(),
                10
            );

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
                'model_name' => $classification['model_name'] ?? null
            ]);

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
                'model_name' => $chatModelName
            ]);
            
            $response = $this->router->route($message, $conversationHistory, $classification, $statusCallback);
            
            $this->notify($statusCallback, 'complete', 'Response generated', [
                'provider' => $response['metadata']['provider'] ?? 'unknown',
                'model' => $response['metadata']['model'] ?? 'unknown',
            ]);

            return [
                'success' => true,
                'response' => $response,
                'classification' => $classification,
                'preprocessing' => $preprocessed
            ];

        } catch (\Throwable $e) {
            $errorDetails = [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
            
            $this->logger->error('Message processing failed', $errorDetails);
            
            // Also dump to stderr for immediate visibility
            error_log('🔴 MESSAGE PROCESSING FAILED: ' . $e->getMessage());
            error_log('File: ' . $e->getFile() . ':' . $e->getLine());

            $this->notify($statusCallback, 'error', $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $errorDetails
            ];
        }
    }

    /**
     * Send status notification to callback
     */
    private function notify(?callable $callback, string $status, string $message, array $metadata = []): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'metadata' => $metadata,
                'timestamp' => microtime(true)
            ]);
        }

        $this->logger->info('MessageProcessor status', [
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata
        ]);
    }
}


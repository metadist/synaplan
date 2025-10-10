<?php

namespace App\Service\Message\Handler;

use App\Entity\Message;
use App\Repository\PromptRepository;
use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Chat Handler - Normaler Konversations-Chat
 * 
 * Uses user-defined model from BCONFIG or falls back to global default
 */
#[AutoconfigureTag('app.message.handler')]
class ChatHandler implements MessageHandlerInterface
{
    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger
    ) {}

    public function getName(): string
    {
        return 'chat';
    }

    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null
    ): array {
        $this->notify($progressCallback, 'generating', 'Generating response...');

        // System Prompt laden
        $systemPrompt = $this->getSystemPrompt($message->getUserId(), $classification['language']);

        // Conversation History bauen
        $messages = $this->buildMessages($systemPrompt, $thread, $message);

        // Get model - Priority: User-selected (Again) > Classification override > DB default
        $modelId = null;
        $provider = null;
        $modelName = null;
        
        // 1. Check if user explicitly selected a model (e.g., via "Again" function)
        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = $classification['model_id'];
            $this->logger->info('ChatHandler: Using user-selected model (Again)', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId()
            ]);
        }
        // 2. Check if classification provides a model override
        elseif (isset($classification['override_model_id']) && $classification['override_model_id']) {
            $modelId = $classification['override_model_id'];
            $this->logger->info('ChatHandler: Using classification override model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId()
            ]);
        }
        // 3. Fall back to user's default model from DB
        else {
            $modelId = $this->modelConfigService->getDefaultModel('CHAT', $message->getUserId());
            $this->logger->info('ChatHandler: Using DB default model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId()
            ]);
        }
        
        // Resolve model ID to provider + model name
        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
            
            $this->logger->info('ChatHandler: Resolved model', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName
            ]);
        }

        // AI aufrufen
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

        return [
            'content' => $response['content'],
            'metadata' => [
                'provider' => $response['provider'] ?? 'unknown',
                'model' => $response['model'] ?? 'unknown',
                'tokens' => $response['usage'] ?? [],
            ],
        ];
    }

    /**
     * Handle with streaming support
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = []
    ): array {
        $this->notify($progressCallback, 'generating', 'Generating response...');

        // System Prompt laden
        $systemPrompt = $this->getSystemPrompt($message->getUserId(), $classification['language']);

        // Conversation History bauen
        $messages = $this->buildMessages($systemPrompt, $thread, $message);

        // Get model - Priority: User-selected (Again) > Classification override > DB default
        $modelId = null;
        $provider = null;
        $modelName = null;
        
        // 1. Check if user explicitly selected a model (e.g., via "Again" function)
        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = $classification['model_id'];
            $this->logger->info('ChatHandler: Using user-selected model (Again)', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId()
            ]);
        }
        // 2. Check if classification provides a model override
        elseif (isset($classification['override_model_id']) && $classification['override_model_id']) {
            $modelId = $classification['override_model_id'];
            $this->logger->info('ChatHandler: Using classification override model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId()
            ]);
        }
        // 3. Fall back to user's default model from DB
        else {
            $modelId = $this->modelConfigService->getDefaultModel('CHAT', $message->getUserId());
            $this->logger->info('ChatHandler: Using DB default model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId()
            ]);
        }
        
        // Resolve model ID to provider + model name
        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
            
            $this->logger->info('ChatHandler: Resolved model for streaming', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName
            ]);
        }

        // AI streaming aufrufen - merge processing options with model config
        $aiOptions = array_merge([
            'provider' => $provider,
            'model' => $modelName,
            'temperature' => 0.7,
        ], $options); // Options from frontend (e.g., reasoning: true/false)
        
        $metadata = $this->aiFacade->chatStream(
            $messages,
            $streamCallback,
            $message->getUserId(),
            $aiOptions
        );

        $this->notify($progressCallback, 'generating', 'Response generated.');

        return [
            'metadata' => [
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'tokens' => $metadata['usage'] ?? [],
            ],
        ];
    }

    private function getSystemPrompt(int $userId, string $language): string
    {
        // User-spezifischen System Prompt laden (aus BPROMPTS)
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
        return "You are a helpful AI assistant. Respond in a friendly and professional manner.";
    }

    private function buildMessages(string $systemPrompt, array $thread, Message $currentMessage): array
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // Thread Messages hinzufügen (letzte N Messages)
        foreach ($thread as $msg) {
            $role = $msg->getDirection() === 'IN' ? 'user' : 'assistant';
            $content = $msg->getText();
            
            // File Text inkludieren wenn vorhanden
            if ($msg->getFileText()) {
                $content .= "\n\n[File: " . $msg->getFilePath() . "]\n" . substr($msg->getFileText(), 0, 3000);
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        // Aktuelle Message
        $content = $currentMessage->getText();
        if ($currentMessage->getFileText()) {
            $content .= "\n\n[File: " . $currentMessage->getFilePath() . "]\n" . substr($currentMessage->getFileText(), 0, 3000);
        }

        $messages[] = [
            'role' => 'user',
            'content' => $content,
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
}


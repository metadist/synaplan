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

        // Extract structured data from JSON response if present
        $content = $response['content'];
        $metadata = [
            'provider' => $response['provider'] ?? 'unknown',
            'model' => $response['model'] ?? 'unknown',
            'tokens' => $response['usage'] ?? [],
        ];
        
        if (is_string($content) && str_starts_with(trim($content), '{')) {
            try {
                $jsonData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                
                // Extract BTEXT as main content
                if (isset($jsonData['BTEXT'])) {
                    $content = $jsonData['BTEXT'];
                    $this->logger->info('ChatHandler: Extracted BTEXT from JSON response');
                }
                
                // Extract file information
                if (!empty($jsonData['BFILE']) && !empty($jsonData['BFILETEXT'])) {
                    $metadata['file'] = [
                        'path' => $jsonData['BFILETEXT'],
                        'type' => $this->detectFileType($jsonData['BFILETEXT']),
                    ];
                    $this->logger->info('ChatHandler: Extracted file data', $metadata['file']);
                }
                
                // Extract web search results/links
                if (!empty($jsonData['BLINKS'])) {
                    $metadata['links'] = $jsonData['BLINKS'];
                    $this->logger->info('ChatHandler: Extracted links');
                }
                
            } catch (\JsonException $e) {
                // Not valid JSON, use content as-is
                $this->logger->debug('ChatHandler: Response not JSON', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'content' => $content,
            'metadata' => $metadata,
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

        // Simple system prompt for streaming (like old system)
        $systemPrompt = 'You are the Synaplan.com AI assistant. Please answer in the language of the user.';

        // Conversation History bauen (TEXT only for streaming)
        $messages = $this->buildStreamingMessages($systemPrompt, $thread, $message);

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
        
        $this->logger->info('🔵 ChatHandler: Calling AiFacade chatStream', [
            'provider' => $provider,
            'model' => $modelName,
            'user_id' => $message->getUserId()
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
        return "You are a helpful AI assistant. Respond in a friendly and professional manner.";
    }

    /**
     * Build messages for streaming (TEXT only, no JSON)
     * Like old system: topicPrompt with $stream = true
     */
    private function buildStreamingMessages(string $systemPrompt, array $thread, Message $currentMessage): array
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // Thread Messages hinzufügen (letzte N Messages)
        foreach ($thread as $msg) {
            $role = $msg->getDirection() === 'IN' ? 'user' : 'assistant';
            $content = $msg->getText();
            
            // File Text inkludieren wenn vorhanden (wie altes System)
            if ($msg->getFileText()) {
                $content .= "\n\n\n---\n\n\nUser provided a file: " . 
                           $msg->getFileType() . 
                           ", saying: '" . 
                           substr($msg->getFileText(), 0, 3000) . 
                           "'\n\n";
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        // Aktuelle Message
        $content = $currentMessage->getText();
        if ($currentMessage->getFileText()) {
            $content .= "\n\n\n---\n\n\nUser provided a file: " . 
                       $currentMessage->getFileType() . 
                       ", saying: '" . 
                       substr($currentMessage->getFileText(), 0, 3000) . 
                       "'\n\n";
        }

        $messages[] = [
            'role' => 'user',
            'content' => $content,
        ];

        return $messages;
    }
    
    /**
     * Build messages for non-streaming (JSON format)
     * Like old system: topicPrompt with $stream = false
     */
    private function buildMessages(string $systemPrompt, array $thread, Message $currentMessage): array
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // Thread Messages (JSON encoded wie im alten System)
        foreach ($thread as $msg) {
            $role = $msg->getDirection() === 'IN' ? 'user' : 'assistant';
            $content = $msg->getText();

            $messages[] = [
                'role' => $role,
                'content' => '[' . $msg->getDateTime() . ']: ' . $content,
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
}


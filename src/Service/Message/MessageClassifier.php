<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Repository\MessageMetaRepository;
use Psr\Log\LoggerInterface;

/**
 * Message Classifier
 * 
 * High-level classifier that handles:
 * 1. Check for "Again" function (user-selected AI/prompt via BMESSAGEMETA)
 * 2. Check for tool commands (e.g., /pic, /vid, /search)
 * 3. Use MessageSorter for AI-based classification
 * 
 * Workflow from legacy:
 * - If BMESSAGEMETA has PROMPTID set → use that directly (skip sorting)
 * - If message starts with "/" → tool command
 * - Otherwise → use AI sorting
 */
class MessageClassifier
{
    private const TOOL_COMMANDS = [
        '/pic' => 'tools:pic',
        '/vid' => 'tools:vid',
        '/search' => 'tools:search',
        '/lang' => 'tools:lang',
        '/web' => 'tools:web',
        '/list' => 'tools:list',
        '/docs' => 'tools:filesort',
    ];

    public function __construct(
        private MessageSorter $messageSorter,
        private MessageMetaRepository $messageMetaRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Classify message and determine routing
     * 
     * @param Message $message Message entity
     * @param array $conversationHistory Previous messages
     * @return array ['topic' => string, 'language' => string, 'source' => string, 'skip_sorting' => bool]
     */
    public function classify(Message $message, array $conversationHistory = []): array
    {
        $userId = $message->getUserId();
        $messageId = $message->getId();
        $text = $message->getText();

        $this->logger->info('MessageClassifier: Starting classification', [
            'message_id' => $messageId,
            'user_id' => $userId,
            'has_text' => !empty($text)
        ]);

        // 1. Check for "Again" function - user-selected AI/prompt
        $promptOverride = $this->checkPromptOverride($messageId);
        $modelOverride = $this->checkModelOverride($messageId);
        
        if ($promptOverride) {
            $this->logger->info('MessageClassifier: Using prompt override (Again function)', [
                'message_id' => $messageId,
                'prompt_id' => $promptOverride,
                'model_id' => $modelOverride
            ]);

            $result = [
                'topic' => $promptOverride,
                'language' => $message->getLanguage() ?: 'en',
                'source' => 'prompt_override',
                'skip_sorting' => true
            ];
            
            // Add model_id if user explicitly selected a model (Again)
            if ($modelOverride) {
                $result['model_id'] = $modelOverride;
            }
            
            return $result;
        }

        // 2. Check for tool commands
        if (!empty($text) && str_starts_with($text, '/')) {
            $toolTopic = $this->detectToolCommand($text);
            if ($toolTopic) {
                $this->logger->info('MessageClassifier: Tool command detected', [
                    'message_id' => $messageId,
                    'tool' => $toolTopic
                ]);

                return [
                    'topic' => $toolTopic,
                    'language' => $message->getLanguage() ?: 'en',
                    'source' => 'tool_command',
                    'skip_sorting' => true
                ];
            }
        }

        // 3. Use AI-based sorting
        $messageData = $this->buildMessageData($message);
        $result = $this->messageSorter->classify($messageData, $conversationHistory, $userId);

        $this->logger->info('MessageClassifier: AI classification complete', [
            'message_id' => $messageId,
            'topic' => $result['topic'],
            'language' => $result['language'],
            'model_id' => $result['model_id'] ?? null
        ]);

        return [
            'topic' => $result['topic'],
            'language' => $result['language'],
            'source' => 'ai_sorting',
            'skip_sorting' => false,
            'model_id' => $result['model_id'] ?? null,
            'provider' => $result['provider'] ?? null,
            'model_name' => $result['model_name'] ?? null
        ];
    }

    /**
     * Check for prompt override (Again function)
     * Returns prompt ID if set, null otherwise
     */
    private function checkPromptOverride(int $messageId): ?string
    {
        $meta = $this->messageMetaRepository->findOneBy([
            'messageId' => $messageId,
            'metaKey' => 'PROMPTID'
        ]);

        if ($meta && !empty($meta->getMetaValue()) && $meta->getMetaValue() !== 'tools:sort') {
            return $meta->getMetaValue();
        }

        return null;
    }

    /**
     * Check for model override (Again function with specific model)
     * Returns model ID if set, null otherwise
     */
    private function checkModelOverride(int $messageId): ?int
    {
        $meta = $this->messageMetaRepository->findOneBy([
            'messageId' => $messageId,
            'metaKey' => 'MODEL_ID'
        ]);

        if ($meta && !empty($meta->getMetaValue())) {
            return (int) $meta->getMetaValue();
        }

        return null;
    }

    /**
     * Detect tool command from text
     */
    private function detectToolCommand(string $text): ?string
    {
        foreach (self::TOOL_COMMANDS as $command => $topic) {
            if (str_starts_with($text, $command)) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * Build message data array for sorter
     */
    private function buildMessageData(Message $message): array
    {
        return [
            'BDATETIME' => $message->getDateTime(),
            'BFILEPATH' => $message->getFilePath(),
            'BTOPIC' => $message->getTopic() ?: '',
            'BLANG' => $message->getLanguage() ?: 'en',
            'BTEXT' => $message->getText(),
            'BFILETEXT' => $message->getFileText() ?: '',
            'BFILE' => $message->getFile()
        ];
    }
}

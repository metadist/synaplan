<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Repository\MessageMetaRepository;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Message Classifier.
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
        private ModelConfigService $modelConfigService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Classify message and determine routing.
     *
     * @param Message $message             Message entity
     * @param array   $conversationHistory Previous messages
     *
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
            'has_text' => !empty($text),
        ]);

        // 1. Check for "Again" function - user-selected AI/prompt
        $promptOverride = $this->checkPromptOverride($messageId);
        $modelOverride = $this->checkModelOverride($messageId);

        if ($modelOverride && !$promptOverride) {
            // "Again" with model but no prompt override
            // Detect model type from model tag and set topic accordingly
            $modelTag = $this->getModelTag($modelOverride);
            $topic = $this->mapModelTagToTopic($modelTag, $message->getTopic());
            $intent = $this->mapTopicToIntent($topic);

            $this->logger->info('MessageClassifier: Using model override with auto-detected topic', [
                'message_id' => $messageId,
                'model_id' => $modelOverride,
                'model_tag' => $modelTag,
                'detected_topic' => $topic,
                'intent' => $intent,
            ]);

            return [
                'topic' => $topic,
                'language' => $message->getLanguage() ?: 'en',
                'intent' => $intent,
                'source' => 'model_override_auto',
                'skip_sorting' => true,
                'model_id' => $modelOverride,
            ];
        }

        if ($promptOverride) {
            $this->logger->info('MessageClassifier: Using prompt override (Again function)', [
                'message_id' => $messageId,
                'prompt_id' => $promptOverride,
                'model_id' => $modelOverride,
            ]);

            $result = [
                'topic' => $promptOverride,
                'language' => $message->getLanguage() ?: 'en',
                'intent' => $this->mapTopicToIntent($promptOverride),
                'source' => 'prompt_override',
                'skip_sorting' => true,
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
                    'tool' => $toolTopic,
                ]);

                return [
                    'topic' => $toolTopic,
                    'language' => $message->getLanguage() ?: 'en',
                    'intent' => $this->mapTopicToIntent($toolTopic),
                    'source' => 'tool_command',
                    'skip_sorting' => true,
                ];
            }
        }

        // 3. Check for image attachments (force file analysis)
        // This prevents images from being routed to text2pic or chat without vision
        if ($this->hasImages($message)) {
            $this->logger->info('MessageClassifier: Image detected, forcing file analysis', [
                'message_id' => $messageId,
            ]);

            return [
                'topic' => 'analyzefile',
                'language' => $message->getLanguage() ?: 'en',
                'intent' => 'file_analysis',
                'source' => 'image_attachment',
                'skip_sorting' => true,
            ];
        }

        // 4. Use AI-based sorting
        $messageData = $this->buildMessageData($message);
        $result = $this->messageSorter->classify($messageData, $conversationHistory, $userId);

        $this->logger->info('MessageClassifier: AI classification complete', [
            'message_id' => $messageId,
            'topic' => $result['topic'],
            'language' => $result['language'],
            'web_search' => $result['web_search'] ?? false,
            'media_type' => $result['media_type'] ?? null,
            'duration' => $result['duration'] ?? null,
            'raw_ai_response' => $result['raw_response'] ?? 'N/A',
        ]);

        $classification = [
            'topic' => $result['topic'],
            'language' => $result['language'],
            'web_search' => $result['web_search'] ?? false,
            'source' => 'ai_sorting',
            'skip_sorting' => false,
            'intent' => $this->mapTopicToIntent($result['topic']), // Map topic to intent for routing
        ];

        // Pass through media_type if detected (for mediamaker topic)
        $mediaType = $result['media_type'] ?? null;
        if (null !== $mediaType) {
            $classification['media_type'] = $mediaType;
        }

        // Pass through duration if detected (for video generation)
        $duration = $result['duration'] ?? null;
        if (null !== $duration) {
            $classification['duration'] = $duration;
        }

        return $classification;
    }

    /**
     * Check for prompt override (Again function)
     * Returns prompt ID if set, null otherwise.
     */
    private function checkPromptOverride(int $messageId): ?string
    {
        $meta = $this->messageMetaRepository->findOneBy([
            'messageId' => $messageId,
            'metaKey' => 'PROMPTID',
        ]);

        if ($meta && !empty($meta->getMetaValue()) && 'tools:sort' !== $meta->getMetaValue()) {
            return $meta->getMetaValue();
        }

        return null;
    }

    /**
     * Check for model override (Again function with specific model)
     * Returns model ID if set, null otherwise.
     */
    private function checkModelOverride(int $messageId): ?int
    {
        $meta = $this->messageMetaRepository->findOneBy([
            'messageId' => $messageId,
            'metaKey' => 'MODEL_ID',
        ]);

        if ($meta && !empty($meta->getMetaValue())) {
            return (int) $meta->getMetaValue();
        }

        return null;
    }

    /**
     * Detect tool command from text.
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
     * Check if message has image attachments.
     */
    private function hasImages(Message $message): bool
    {
        // Check new-style file attachments
        foreach ($message->getFiles() as $file) {
            if (str_starts_with($file->getFileMime(), 'image/')) {
                return true;
            }
        }

        // Check legacy file type (stores extensions like 'jpg', 'png', not 'image')
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'image'];
        if (in_array($message->getFileType(), $imageExtensions, true)) {
            return true;
        }

        // Check file path extension as fallback
        $filePath = $message->getFilePath();
        if (!empty($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (in_array($ext, $imageExtensions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build message data array for sorter.
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
            'BFILE' => $message->getFile(),
            'BWEBSEARCH' => 0, // Initialize for AI to set
        ];
    }

    /**
     * Map topic to intent for handler routing.
     */
    private function mapTopicToIntent(string $topic): string
    {
        // Map BPROMPTS topics to InferenceRouter intents
        $topicToIntent = [
            // Media generation
            'mediamaker' => 'image_generation', // Handles images, videos, and audio
            'text2pic' => 'image_generation',
            'text2vid' => 'image_generation',
            'text2sound' => 'image_generation',
            'tools:pic' => 'image_generation', // /pic command
            'tools:vid' => 'image_generation', // /vid command

            // Document/Office generation
            'officemaker' => 'document_generation',

            // Analysis
            'analyzefile' => 'file_analysis',
            'pic2text' => 'file_analysis',
            'analyze' => 'file_analysis',

            // Chat/General
            'general' => 'chat',
            'chat' => 'chat',

            // Add more mappings as needed
        ];

        return $topicToIntent[$topic] ?? 'chat'; // Default to chat
    }

    /**
     * Get model tag (capability) from model ID.
     */
    private function getModelTag(int $modelId): string
    {
        $model = $this->em->getRepository(\App\Entity\Model::class)->find($modelId);
        if ($model) {
            return $model->getTag();
        }

        return 'chat'; // fallback
    }

    /**
     * Map model tag to appropriate topic.
     */
    private function mapModelTagToTopic(string $modelTag, ?string $fallbackTopic): string
    {
        $tagToTopicMap = [
            'text2pic' => 'mediamaker',
            'text2vid' => 'mediamaker',
            'text2sound' => 'mediamaker',
            'pic2text' => 'analyzefile',
            'analyze' => 'analyzefile',
            'chat' => 'general',
            'vectorize' => 'general',
        ];

        return $tagToTopicMap[$modelTag] ?? ($fallbackTopic ?: 'general');
    }
}

<?php

namespace App\Service\Message;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\ConfigRepository;
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
 * 3. Document or audio attachment → analyzefile / file_analysis (skip sorting, #595)
 * 4. Use MessageSorter for AI-based classification
 *
 * Workflow from legacy:
 * - If BMESSAGEMETA has PROMPTID set → use that directly (skip sorting)
 * - If message starts with "/" → tool command
 * - If attached document/audio → analyzefile
 * - Otherwise → use AI sorting
 */
final readonly class MessageClassifier
{
    private const TOOL_COMMANDS = [
        '/pic' => 'tools:pic',
        '/vid' => 'tools:vid',
        '/tts' => 'tools:tts',
        '/search' => 'tools:search',
        '/lang' => 'tools:lang',
        '/web' => 'tools:web',
        '/list' => 'tools:list',
        '/docs' => 'tools:filesort',
    ];

    public function __construct(
        private MessageSorter $messageSorter,
        private SynapseRouter $synapseRouter,
        private MessageMetaRepository $messageMetaRepository,
        private ModelConfigService $modelConfigService,
        private ConfigRepository $configRepository,
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
    public function classify(Message $message, array $conversationHistory = [], ?int $overrideModelId = null): array
    {
        $userId = $message->getUserId();
        $messageId = $message->getId();
        $text = $message->getText();

        $this->logger->info('MessageClassifier: Starting classification', [
            'message_id' => $messageId,
            'user_id' => $userId,
            'has_text' => !empty($text),
            'override_model_id' => $overrideModelId,
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

        // 3. Document / audio attachments → FileAnalysisHandler (ANALYZE model), before AI sorting (#595)
        if ($this->messageHasDocumentOrAudioAttachment($message)) {
            $this->logger->info('MessageClassifier: Forcing analyzefile route (document or audio attachment)', [
                'message_id' => $messageId,
            ]);

            return [
                'topic' => 'analyzefile',
                'language' => $message->getLanguage() ?: 'en',
                'intent' => 'file_analysis',
                'source' => 'attachment_document_or_audio',
                'skip_sorting' => true,
            ];
        }

        // 4. Use Synapse Routing (embedding-based with AI fallback)
        $messageData = $this->buildMessageData($message);
        $result = $this->isSynapseEnabled()
            ? $this->synapseRouter->route($messageData, $conversationHistory, $userId)
            : $this->messageSorter->classify($messageData, $conversationHistory, $userId);

        $source = $result['source'] ?? 'ai_sorting';

        $this->logger->info('MessageClassifier: Classification complete', [
            'message_id' => $messageId,
            'topic' => $result['topic'],
            'language' => $result['language'],
            'web_search' => $result['web_search'] ?? false,
            'media_type' => $result['media_type'] ?? null,
            'duration' => $result['duration'] ?? null,
            'resolution' => $result['resolution'] ?? null,
            'source' => $source,
            'synapse_score' => $result['synapse_score'] ?? null,
            'raw_ai_response' => $result['raw_response'] ?? 'N/A',
        ]);

        $classification = [
            'topic' => $result['topic'],
            'language' => $result['language'],
            'web_search' => $result['web_search'] ?? false,
            'source' => $source,
            'skip_sorting' => false,
            'intent' => $this->mapTopicToIntent($result['topic']),
            'model_id' => $result['sorting_model_id'] ?? null,
            'provider' => $result['sorting_provider'] ?? null,
            'model_name' => $result['sorting_model_name'] ?? null,
        ];

        if ($overrideModelId) {
            $classification['override_model_id'] = $overrideModelId;
        }

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

        // Pass through resolution if detected (for video generation).
        // Already validated by MessageSorter against the supported enum, so the
        // handler can forward it to the provider as-is.
        $resolution = $result['resolution'] ?? null;
        if (is_string($resolution) && '' !== $resolution) {
            $classification['resolution'] = $resolution;
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
     * Build message data array for sorter.
     */
    private function buildMessageData(Message $message): array
    {
        $data = [
            'BDATETIME' => $message->getDateTime(),
            'BFILEPATH' => $message->getFilePath(),
            'BTOPIC' => $message->getTopic() ?: '',
            'BLANG' => $message->getLanguage() ?: 'en',
            'BTEXT' => $message->getText(),
            'BFILETEXT' => $message->getFileText() ?: '',
            'BFILE' => $message->getFile(),
            'BWEBSEARCH' => 0,
        ];

        $fileType = $message->getFileType();
        if ('' !== $fileType) {
            $data['BFILETYPE'] = $fileType;
        }

        $attachedFiles = $message->getFiles();
        if ($attachedFiles->count() > 0) {
            $types = [];
            foreach ($attachedFiles as $file) {
                $types[] = $file->getFileType() ?: $file->getFileMime();
            }
            $data['BATTACHED_FILES'] = implode(', ', $types);
            $data['BATTACHED_COUNT'] = $attachedFiles->count();
        }

        return $data;
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
            'tools:tts' => 'image_generation', // /tts command (audio via MediaGenerationHandler)

            // Document/Office generation
            'officemaker' => 'document_generation',

            // Analysis
            'pic2text' => 'file_analysis',
            'analyze' => 'file_analysis',
            'analyzefile' => 'file_analysis',

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
            'pic2text' => 'general', // Vision is handled by ChatHandler
            'analyze' => 'general',
            'chat' => 'general',
            'vectorize' => 'general',
        ];

        return $tagToTopicMap[$modelTag] ?? ($fallbackTopic ?: 'general');
    }

    /**
     * True if the message has at least one attached document or audio file (not images only).
     * Routes to FileAnalysisHandler so ANALYZE default model is used (#595).
     */
    private function messageHasDocumentOrAudioAttachment(Message $message): bool
    {
        $files = $message->getFiles();
        if ($files->count() > 0) {
            foreach ($files as $file) {
                if ($this->attachedFileIsDocumentOrAudio($file)) {
                    return true;
                }
            }

            return false;
        }

        if ($message->getFile() > 0 && '' !== (string) $message->getFilePath()) {
            $ext = strtolower(pathinfo($message->getFilePath(), PATHINFO_EXTENSION));

            return in_array($ext, MessagePreProcessor::DOCUMENT_EXTENSIONS, true)
                || in_array($ext, MessagePreProcessor::AUDIO_EXTENSIONS, true);
        }

        return false;
    }

    private function attachedFileIsDocumentOrAudio(File $file): bool
    {
        $fromType = strtolower($file->getFileType() ?: '');
        $fromName = strtolower(pathinfo($file->getFileName(), PATHINFO_EXTENSION));
        $ext = '' !== $fromType ? $fromType : $fromName;

        if (in_array($ext, MessagePreProcessor::IMAGE_EXTENSIONS, true)) {
            return false;
        }

        return in_array($ext, MessagePreProcessor::DOCUMENT_EXTENSIONS, true)
            || in_array($ext, MessagePreProcessor::AUDIO_EXTENSIONS, true);
    }

    public function isSynapseEnabled(): bool
    {
        $value = $this->configRepository->getValue(0, 'QDRANT_SEARCH', 'SYNAPSE_ROUTING_ENABLED');

        if (null === $value) {
            return true;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }
}

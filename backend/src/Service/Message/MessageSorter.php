<?php

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Repository\PromptRepository;
use App\Service\DiscordNotificationService;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use Psr\Log\LoggerInterface;

/**
 * Message Sorter/Classifier.
 *
 * Classifies incoming messages using AI and sorting prompt from database.
 * Determines BTOPIC (category) and BLANG (language) for proper routing.
 *
 * Workflow:
 * 1. Load sorting prompt from BPROMPTS (tools:sort)
 * 2. Load available topics from BPROMPTS
 * 3. Build prompt with [DYNAMICLIST] and [KEYLIST] replacements
 * 4. Call AI with user message + conversation history
 * 5. Parse AI response (JSON) for BTOPIC and BLANG
 */
class MessageSorter
{
    /**
     * Supported languages for message classification.
     */
    private const SUPPORTED_LANGUAGES = ['de', 'en', 'it', 'es', 'fr', 'nl', 'pt', 'ru', 'sv', 'tr'];

    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private PromptService $promptService,
        private LoggerInterface $logger,
        private DiscordNotificationService $discord,
    ) {
    }

    /**
     * Classify message and determine topic + language.
     *
     * @param array    $messageData         Message data (BTEXT, BFILETEXT, etc.)
     * @param array    $conversationHistory Previous messages in thread
     * @param int|null $userId              User ID for model config
     *
     * @return array ['topic' => string, 'language' => string, 'raw_response' => string]
     */
    public function classify(array $messageData, array $conversationHistory = [], ?int $userId = null): array
    {
        $this->logger->info('MessageSorter: Starting classification', [
            'user_id' => $userId,
            'has_file' => !empty($messageData['BFILETEXT']),
            'history_count' => count($conversationHistory),
        ]);

        // STEP 1: Check for rule-based routing (user-defined task prompts with selection rules)
        if ($userId) {
            $ruleBasedTopic = $this->checkRuleBasedRouting($messageData, $conversationHistory, $userId);
            if ($ruleBasedTopic) {
                $this->logger->info('MessageSorter: ✅ Rule-based routing matched', [
                    'topic' => $ruleBasedTopic,
                    'user_id' => $userId,
                ]);

                $promptMetadata = [];
                $promptData = $this->promptService->getPromptWithMetadata($ruleBasedTopic, $userId);
                if ($promptData) {
                    $promptMetadata = $promptData['metadata'] ?? [];
                }

                return [
                    'topic' => $ruleBasedTopic,
                    'language' => $messageData['BLANG'] ?? 'en',
                    'web_search' => $promptMetadata['tool_internet'] ?? false,
                    'raw_response' => 'Rule-based routing',
                    'prompt_metadata' => $promptMetadata,
                    'model_id' => null,
                    'provider' => null,
                    'model_name' => null,
                ];
            }
        }

        // STEP 2: Get sorting prompt
        $sortingPrompt = $this->promptRepository->findByTopic('tools:sort', 0);

        if (!$sortingPrompt) {
            $this->logger->error('MessageSorter: Sorting prompt not found');

            return [
                'topic' => 'general',
                'language' => 'en',
                'raw_response' => '',
            ];
        }

        // Get all available topics (exclude tools:* internal topics)
        // Include user-specific prompts if userId is provided
        // Load prompts for ALL supported languages to ensure user prompts are included
        $topics = $this->promptRepository->getAllTopics(0, $userId, excludeTools: true);

        // Get topics with descriptions - system prompts are always included
        // regardless of language, user prompts are filtered by 'en' default
        $topicsWithDesc = $this->promptRepository->getTopicsWithDescriptions(0, 'en', $userId, excludeTools: true);

        // Build dynamic list and key list for prompt
        $dynamicList = $this->buildDynamicList($topicsWithDesc);
        $keyList = implode(' | ', array_map(fn ($t) => '"'.$t.'"', $topics));
        $langList = implode(' | ', array_map(fn ($l) => '"'.$l.'"', self::SUPPORTED_LANGUAGES));

        $this->logger->info('MessageSorter: Dynamic list built', [
            'user_id' => $userId,
            'topics_count' => count($topics),
            'topics' => $topics,
            'descriptions_count' => count($topicsWithDesc),
            'dynamic_list' => substr($dynamicList, 0, 500),
            'key_list' => $keyList,
        ]);

        // Replace placeholders in sorting prompt
        $promptText = $sortingPrompt->getPrompt();
        $promptText = str_replace('[DYNAMICLIST]', $dynamicList, $promptText);
        $promptText = str_replace('[KEYLIST]', $keyList, $promptText);
        $promptText = str_replace('[LANGLIST]', $langList, $promptText);

        // Build messages array for AI
        $messages = [
            ['role' => 'system', 'content' => $promptText],
        ];

        // Add conversation history (truncated)
        foreach ($conversationHistory as $msg) {
            if ('IN' === $msg->getDirection()) {
                $msgText = $msg->getText();
                if ($msg->getFileText()) {
                    $msgText .= ' User provided a file: '.$msg->getFileType().', saying: \''.substr($msg->getFileText(), 0, 200).'\'';
                }
                $messages[] = ['role' => 'user', 'content' => $msgText];
            } elseif ('OUT' === $msg->getDirection()) {
                // Truncate assistant responses
                $assistantText = substr($msg->getText(), 0, 200);
                if (strlen($msg->getText()) > 200) {
                    $assistantText .= '...';
                }
                $messages[] = ['role' => 'assistant', 'content' => '['.$msg->getId().'] '.$assistantText];
            }
        }

        // Add current message as JSON
        $currentMessageJson = json_encode($messageData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $messages[] = ['role' => 'user', 'content' => $currentMessageJson];

        // Get user's preferred sorting model from DB
        $modelId = $this->modelConfigService->getDefaultModel('SORT', $userId);
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
        }

        try {
            // Call AI for sorting
            $response = $this->aiFacade->chat($messages, $userId, [
                'provider' => $provider,
                'model' => $modelName,
                'temperature' => 0.1, // Low temperature for consistent classification
                'max_tokens' => 1024,
            ]);

            $aiResponse = $response['content'];

            $this->logger->info('MessageSorter: AI response received', [
                'provider' => $response['provider'],
                'response_length' => strlen($aiResponse),
                'raw_response' => $aiResponse,
            ]);

            // Parse JSON response
            $parsed = $this->parseResponse($aiResponse, $messageData);

            $this->logger->info('MessageSorter: ✅ Classification result', [
                'topic' => $parsed['topic'],
                'language' => $parsed['language'],
                'web_search' => $parsed['web_search'] ?? false,
                'media_type' => $parsed['media_type'] ?? null,
                'duration' => $parsed['duration'] ?? null,
                'raw_ai_response' => $aiResponse,
            ]);

            // Send Discord notification for debugging classification issues
            $this->discord->notifyClassification(
                $messageData['BTEXT'] ?? '',
                [
                    'topic' => $parsed['topic'],
                    'language' => $parsed['language'],
                    'media_type' => $parsed['media_type'] ?? null,
                    'duration' => $parsed['duration'] ?? null,
                    'raw_response' => $aiResponse,
                ],
                $userId
            );

            $promptMetadata = [];
            if (!empty($parsed['topic'])) {
                $promptData = $this->promptService->getPromptWithMetadata($parsed['topic'], $userId ?? 0);
                if ($promptData) {
                    $promptMetadata = $promptData['metadata'] ?? [];
                }
            }

            $webSearch = $parsed['web_search'] ?? null;
            if (null === $webSearch && ($promptMetadata['tool_internet'] ?? false)) {
                $webSearch = true;
            }

            return [
                'topic' => $parsed['topic'],
                'language' => $parsed['language'],
                'web_search' => $webSearch ?? false,
                'media_type' => $parsed['media_type'] ?? null,
                'duration' => $parsed['duration'] ?? null,
                'raw_response' => $aiResponse,
                'prompt_metadata' => $promptMetadata,
                // Don't return model_id/provider/model_name from sorting - they are for internal use only
                // The ChatHandler should use its own model selection logic
            ];
        } catch (\App\AI\Exception\ProviderException $e) {
            // Re-throw ProviderException to preserve install instructions
            $this->logger->error('MessageSorter: AI Provider failed', [
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
                'context' => $e->getContext(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('MessageSorter: Classification failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'topic' => 'general',
                'language' => 'en',
                'raw_response' => '',
            ];
        }
    }

    /**
     * Check for rule-based routing using user-defined task prompts with selection rules
     * This happens BEFORE AI sorting and takes priority.
     *
     * @param array $messageData         Message data
     * @param array $conversationHistory Conversation history
     * @param int   $userId              User ID
     *
     * @return string|null Topic if matched, null otherwise
     */
    private function checkRuleBasedRouting(array $messageData, array $conversationHistory, int $userId): ?string
    {
        $messageText = $messageData['BTEXT'] ?? '';

        if (empty($messageText)) {
            return null;
        }

        // Get all prompts with selection rules (user-specific + system)
        // System prompts are always included regardless of language
        $prompts = $this->promptRepository->findPromptsWithSelectionRules($userId, 'en');

        $this->logger->info('MessageSorter: Checking rule-based routing', [
            'user_id' => $userId,
            'prompts_with_rules' => count($prompts),
            'message_text' => substr($messageText, 0, 100),
        ]);

        // Check each prompt's selection rules
        foreach ($prompts as $prompt) {
            $selectionRules = $prompt->getSelectionRules();

            if ($this->promptService->matchesSelectionRules($selectionRules, $messageText, $conversationHistory)) {
                $this->logger->info('MessageSorter: Selection rules matched', [
                    'topic' => $prompt->getTopic(),
                    'rules' => substr($selectionRules ?? '', 0, 100),
                ]);

                return $prompt->getTopic();
            }
        }

        return null;
    }

    /**
     * Build dynamic list of topics with descriptions.
     */
    private function buildDynamicList(array $topicsWithDesc): string
    {
        $list = [];
        foreach ($topicsWithDesc as $item) {
            $list[] = "- \"{$item['topic']}\": {$item['description']}";
        }

        return implode("\n", $list);
    }

    /**
     * Parse AI response JSON.
     */
    private function parseResponse(string $response, array $originalData): array
    {
        // Try to extract JSON from response
        $response = trim($response);

        // Remove markdown code blocks if present
        if (str_starts_with($response, '```')) {
            $response = preg_replace('/^```(?:json)?\s*/', '', $response);
            $response = preg_replace('/\s*```$/', '', $response);
            $response = trim($response);
        }

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            // Parse BWEBSEARCH (can be 0, 1, true, false)
            $webSearch = false;
            if (isset($data['BWEBSEARCH'])) {
                $webSearch = (bool) $data['BWEBSEARCH'];
            }

            // Parse BMEDIA for mediamaker topic (image, video, audio)
            $mediaType = null;
            if (isset($data['BMEDIA']) && is_string($data['BMEDIA'])) {
                $mediaType = $this->normalizeMediaType($data['BMEDIA']);
            }

            // Parse BDURATION for video generation (integer seconds)
            $duration = null;
            if (isset($data['BDURATION']) && is_numeric($data['BDURATION'])) {
                $duration = (int) $data['BDURATION'];
                // Sanity check: duration should be between 1 and 120 seconds
                if ($duration < 1 || $duration > 120) {
                    $duration = null;
                }
            }

            return [
                'topic' => $data['BTOPIC'] ?? $originalData['BTOPIC'] ?? 'general',
                'language' => $data['BLANG'] ?? $originalData['BLANG'] ?? 'en',
                'web_search' => $webSearch,
                'media_type' => $mediaType,
                'duration' => $duration,
            ];
        } catch (\JsonException $e) {
            $this->logger->warning('MessageSorter: Failed to parse JSON response', [
                'error' => $e->getMessage(),
                'response' => substr($response, 0, 200),
            ]);

            // Fallback to original values or defaults
            return [
                'topic' => $originalData['BTOPIC'] ?? 'general',
                'language' => $originalData['BLANG'] ?? 'en',
                'web_search' => false,
                'media_type' => null,
                'duration' => null,
            ];
        }
    }

    /**
     * Normalize media type string to standard values.
     */
    private function normalizeMediaType(string $value): ?string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'audio', 'sound', 'voice', 'tts', 'text2sound', 'speech', 'mp3', 'wav', 'ogg' => 'audio',
            'video', 'vid', 'text2vid', 'film', 'clip', 'animation' => 'video',
            'image', 'img', 'picture', 'pic', 'text2pic', 'photo' => 'image',
            default => null,
        };
    }
}

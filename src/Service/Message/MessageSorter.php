<?php

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Message Sorter/Classifier
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
     * Supported languages for message classification
     */
    private const SUPPORTED_LANGUAGES = ['de', 'en', 'it', 'es', 'fr', 'nl', 'pt', 'ru', 'sv', 'tr'];

    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger
    ) {}

    /**
     * Classify message and determine topic + language
     * 
     * @param array $messageData Message data (BTEXT, BFILETEXT, etc.)
     * @param array $conversationHistory Previous messages in thread
     * @param int|null $userId User ID for model config
     * @return array ['topic' => string, 'language' => string, 'raw_response' => string]
     */
    public function classify(array $messageData, array $conversationHistory = [], ?int $userId = null): array
    {
        $this->logger->info('MessageSorter: Starting classification', [
            'user_id' => $userId,
            'has_file' => !empty($messageData['BFILETEXT']),
            'history_count' => count($conversationHistory)
        ]);

        // Get sorting prompt
        $sortingPrompt = $this->promptRepository->findByTopic('tools:sort', 0, 'en');
        
        if (!$sortingPrompt) {
            $this->logger->error('MessageSorter: Sorting prompt not found');
            return [
                'topic' => 'general',
                'language' => 'en',
                'raw_response' => ''
            ];
        }

        // Get all available topics (exclude tools:* internal topics)
        $topics = $this->promptRepository->getAllTopics(0, excludeTools: true);
        $topicsWithDesc = $this->promptRepository->getTopicsWithDescriptions(0, 'en', excludeTools: true);

        // Build dynamic list and key list for prompt
        $dynamicList = $this->buildDynamicList($topicsWithDesc);
        $keyList = implode(' | ', array_map(fn($t) => '"' . $t . '"', $topics));
        $langList = implode(' | ', array_map(fn($l) => '"' . $l . '"', self::SUPPORTED_LANGUAGES));

        // Replace placeholders in sorting prompt
        $promptText = $sortingPrompt->getPrompt();
        $promptText = str_replace('[DYNAMICLIST]', $dynamicList, $promptText);
        $promptText = str_replace('[KEYLIST]', $keyList, $promptText);
        $promptText = str_replace('[LANGLIST]', $langList, $promptText);

        // Build messages array for AI
        $messages = [
            ['role' => 'system', 'content' => $promptText]
        ];

        // Add conversation history (truncated)
        foreach ($conversationHistory as $msg) {
            if ($msg->getDirection() === 'IN') {
                $msgText = $msg->getText();
                if ($msg->getFileText()) {
                    $msgText .= ' User provided a file: ' . $msg->getFileType() . ', saying: \'' . substr($msg->getFileText(), 0, 200) . '\'';
                }
                $messages[] = ['role' => 'user', 'content' => $msgText];
            } elseif ($msg->getDirection() === 'OUT') {
                // Truncate assistant responses
                $assistantText = substr($msg->getText(), 0, 200);
                if (strlen($msg->getText()) > 200) {
                    $assistantText .= '...';
                }
                $messages[] = ['role' => 'assistant', 'content' => '[' . $msg->getId() . '] ' . $assistantText];
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
                'max_tokens' => 1024
            ]);

            $aiResponse = $response['content'];

            $this->logger->info('MessageSorter: AI response received', [
                'provider' => $response['provider'],
                'response_length' => strlen($aiResponse)
            ]);

            // Parse JSON response
            $parsed = $this->parseResponse($aiResponse, $messageData);

            return [
                'topic' => $parsed['topic'],
                'language' => $parsed['language'],
                'raw_response' => $aiResponse,
                'model_id' => $modelId,
                'provider' => $provider,
                'model_name' => $modelName
            ];

        } catch (\Throwable $e) {
            $this->logger->error('MessageSorter: Classification failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'topic' => 'general',
                'language' => 'en',
                'raw_response' => ''
            ];
        }
    }

    /**
     * Build dynamic list of topics with descriptions
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
     * Parse AI response JSON
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
            
            return [
                'topic' => $data['BTOPIC'] ?? $originalData['BTOPIC'] ?? 'general',
                'language' => $data['BLANG'] ?? $originalData['BLANG'] ?? 'en'
            ];
        } catch (\JsonException $e) {
            $this->logger->warning('MessageSorter: Failed to parse JSON response', [
                'error' => $e->getMessage(),
                'response' => substr($response, 0, 200)
            ]);

            // Fallback to original values or defaults
            return [
                'topic' => $originalData['BTOPIC'] ?? 'general',
                'language' => $originalData['BLANG'] ?? 'en'
            ];
        }
    }
}


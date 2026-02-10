<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\PromptRepository;
use Psr\Log\LoggerInterface;

/**
 * Service for extracting memories from conversations using AI.
 *
 * Uses AI-based extraction with prompts from database.
 */
readonly class MemoryExtractionService
{
    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private PromptRepository $promptRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Analyze conversation and extract memories using AI.
     *
     * AI decides what's memory-worthy and whether to create/update/skip memories.
     *
     * @param Message $message             Current user message
     * @param array   $conversationHistory Recent messages for context
     * @param array   $existingMemories    Already loaded memories (with IDs for updates)
     *
     * @return array Array of memory actions: [['action' => 'create'|'update'|'delete', 'memory_id' => int|null, 'category' => string|null, 'key' => string|null, 'value' => string|null], ...]
     */
    public function analyzeAndExtract(Message $message, array $conversationHistory, array $existingMemories = []): array
    {
        $this->logger->info('Memory extraction triggered (AI-based with update capability)', [
            'message_id' => $message->getId(),
            'user_id' => $message->getUserId(),
            'existing_memories_count' => count($existingMemories),
        ]);

        // Extract via AI (AI decides: create, update, or skip)
        return $this->extractMemoriesViaAi($message, $conversationHistory, $existingMemories);
    }

    /**
     * Extract memories using AI.
     *
     * @return array Array of memories
     */
    private function extractMemoriesViaAi(Message $message, array $conversationHistory, array $existingMemories = []): array
    {
        // Build context from conversation history (last 5 messages)
        $contextMessages = array_slice($conversationHistory, -5);
        $contextText = '';
        foreach ($contextMessages as $msg) {
            // Handle both Message objects and arrays
            if ($msg instanceof Message) {
                $role = 'IN' === $msg->getDirection() ? 'user' : 'assistant';
                $content = $msg->getText();
            } else {
                $role = $msg['role'] ?? 'user';
                $content = $msg['content'] ?? '';
            }
            $contextText .= "{$role}: {$content}\n";
        }

        // System prompt for memory extraction (always English)
        $systemPrompt = $this->getExtractionPrompt();

        // Build existing memories context with category, key, and ID
        $existingMemoriesText = '';
        if (!empty($existingMemories)) {
            $existingMemoriesText = "\n\nExisting User Memories (with IDs for updates):\n";
            foreach ($existingMemories as $memory) {
                $id = $memory['id'] ?? 'unknown';
                $category = $memory['category'] ?? 'unknown';
                $key = $memory['key'] ?? 'unknown';
                $value = $memory['value'] ?? 'unknown';
                $existingMemoriesText .= sprintf(
                    "ID:%s [%s] %s: %s\n",
                    $id,
                    $category,
                    $key,
                    $value
                );
            }
            $existingMemoriesText .= "\nYOUR JOB: Decide for each new information:\n";
            $existingMemoriesText .= "- If it's TRULY NEW → action: 'create'\n";
            $existingMemoriesText .= "- If it UPDATES existing info → action: 'update' (include memory_id)\n";
            $existingMemoriesText .= "- If ALREADY COVERED → don't include it\n";
        } else {
            // 🎯 CRITICAL: When NO memories exist, explicitly tell AI to create new ones!
            $existingMemoriesText = "\n\n✨ NO EXISTING MEMORIES YET!\n";
            $existingMemoriesText .= "This is a FRESH START. Extract ALL relevant information you find.\n";
            $existingMemoriesText .= "Use action: 'create' for everything worth remembering.\n";
        }

        // AI call
        $userPrompt = <<<PROMPT
Conversation:
{$contextText}

Current Message:
{$message->getText()}
{$existingMemoriesText}

RULES:
- Only save facts the user states about THEMSELVES (name, age, preferences, skills, etc.)
- Questions about topics are NOT memories ("Who is X?" → null, "Is Y true?" → null)
- One fact per memory, short keys (snake_case, <= 24 chars)
- create = new fact, update = changed fact (needs memory_id), delete = user asks to forget (needs memory_id)
- Already stored and unchanged → return []
PROMPT;

        try {
            $extractionConfig = $this->getExtractionModelConfig($message->getUserId());

            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                $message->getUserId(),
                [
                    'temperature' => 0.3, // Low temperature for consistent extraction
                    'model' => $extractionConfig['model'],
                    'provider' => $extractionConfig['provider'],
                ]
            );

            $content = $response['content'] ?? '';

            $this->logger->debug('Memory extraction AI response received', [
                'message_id' => $message->getId(),
                'provider' => $extractionConfig['provider'],
                'model' => $extractionConfig['model'],
                'content_length' => strlen($content),
                'existing_memories_count' => count($existingMemories),
            ]);

            // Parse JSON response (now includes actions)
            $memoryActions = $this->parseMemoriesFromResponse($content);

            $actionTypes = array_count_values(array_map(
                static fn (array $action): string => (string) ($action['action'] ?? 'unknown'),
                $memoryActions
            ));

            $this->logger->info('Memory actions extracted', [
                'message_id' => $message->getId(),
                'count' => count($memoryActions),
                'action_types' => $actionTypes,
            ]);

            return $memoryActions;
        } catch (\Throwable $e) {
            $this->logger->error('Memory extraction failed', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get system prompt for memory extraction from database.
     *
     * Always uses English prompt (language-agnostic AI instructions).
     */
    private function getExtractionPrompt(): string
    {
        // Load English prompt from database
        $prompt = $this->promptRepository->findOneBy([
            'topic' => 'tools:memory_extraction',
            'language' => 'en',
            'ownerId' => 0, // System prompt
        ]);

        if ($prompt) {
            return $prompt->getPrompt();
        }

        // Fallback if not in DB (should not happen after fixtures)
        $this->logger->warning('Memory extraction prompt not found in DB, using fallback');

        return 'Extract important user preferences and information. Return JSON array or null if nothing worth remembering.';
    }

    /**
     * Parse memories from AI response.
     *
     * Handles both JSON array and null response.
     * Now supports action field for create/update/delete decisions.
     *
     * @return array Array of memory actions
     */
    private function parseMemoriesFromResponse(string $content): array
    {
        $content = trim($content);

        // Check for explicit null response
        if ('null' === strtolower($content) || '' === $content) {
            return [];
        }

        // Try to find JSON in response (handles markdown code blocks)
        $jsonPattern = '/\[[\s\S]*?\]/';
        if (preg_match($jsonPattern, $content, $matches)) {
            $jsonString = $matches[0];
        } else {
            $jsonString = $content;
        }

        try {
            $memories = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($memories)) {
                return [];
            }

            // Validate structure and handle actions
            $validated = [];
            foreach ($memories as $memory) {
                $action = $memory['action'] ?? 'create';

                if ('delete' === $action) {
                    $memoryId = $memory['memory_id'] ?? null;
                    if (null === $memoryId) {
                        $this->logger->warning('Delete action missing memory_id, skipping', [
                            'memory' => $memory,
                        ]);
                        continue;
                    }

                    $validated[] = [
                        'action' => 'delete',
                        'memory_id' => (int) $memoryId,
                    ];
                    continue;
                }

                if (!isset($memory['category'], $memory['key'], $memory['value'])) {
                    continue;
                }

                if (mb_strlen($memory['key']) < 3 || mb_strlen($memory['value']) < 5) {
                    continue;
                }

                $validatedMemory = [
                    'action' => $action,
                    'category' => $memory['category'],
                    'key' => $memory['key'],
                    'value' => $memory['value'],
                ];

                // For updates, memory_id is required
                if ('update' === $action) {
                    $memoryId = $memory['memory_id'] ?? null;
                    if (null === $memoryId) {
                        $this->logger->warning('Update action missing memory_id, treating as create', [
                            'memory' => $memory,
                        ]);
                        $validatedMemory['action'] = 'create';
                    } else {
                        $validatedMemory['memory_id'] = (int) $memoryId;
                    }
                }

                $validated[] = $validatedMemory;
            }

            return $validated;
        } catch (\JsonException $e) {
            // If parsing fails, check if it's a null response
            if (false !== stripos($content, 'null')) {
                return [];
            }

            $this->logger->warning('Failed to parse memories JSON', [
                'content' => $content,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get model and provider for memory extraction.
     *
     * Resolves both the model name AND the matching provider from the model ID
     * to avoid provider/model mismatch (e.g., sending an OpenAI model to Groq).
     *
     * @return array{model: string|null, provider: string|null}
     */
    private function getExtractionModelConfig(int $userId): array
    {
        // Try to get user's default chat model
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $userId);

        if ($modelId) {
            $model = $this->modelConfigService->getModelName($modelId);
            $provider = $this->modelConfigService->getProviderForModel($modelId);

            $this->logger->debug('Memory extraction model resolved', [
                'user_id' => $userId,
                'model_id' => $modelId,
                'model' => $model,
                'provider' => $provider,
            ]);

            return ['model' => $model, 'provider' => $provider];
        }

        return ['model' => null, 'provider' => null]; // Use system default
    }
}

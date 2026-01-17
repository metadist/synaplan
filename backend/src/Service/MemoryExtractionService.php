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
final readonly class MemoryExtractionService
{
    public function __construct(
        private AiFacade $aiFacade,
        private UserMemoryService $memoryService,
        private ModelConfigService $modelConfigService,
        private PromptRepository $promptRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Analyze conversation and extract memories using AI.
     *
     * AI decides what's memory-worthy - no heuristic filters!
     *
     * @param Message $message             Current user message
     * @param array   $conversationHistory Recent messages for context
     * @param array   $existingMemories    Already loaded memories (to avoid duplicates)
     *
     * @return array Array of extracted memories: [['category' => string, 'key' => string, 'value' => string], ...]
     */
    public function analyzeAndExtract(Message $message, array $conversationHistory, array $existingMemories = []): array
    {
        $this->logger->info('Memory extraction triggered (AI-based)', [
            'message_id' => $message->getId(),
            'user_id' => $message->getUserId(),
            'existing_memories_count' => count($existingMemories),
        ]);

        // Extract via AI (no pre-filtering!)
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

        // Build existing memories context
        $existingMemoriesText = '';
        if (!empty($existingMemories)) {
            $existingMemoriesText = "\n\nExisting User Memories (DO NOT duplicate these):\n";
            foreach ($existingMemories as $memory) {
                $existingMemoriesText .= sprintf(
                    "- %s: %s\n",
                    $memory['key'] ?? 'unknown',
                    $memory['value'] ?? 'unknown'
                );
            }
        }

        // AI call
        $userPrompt = <<<PROMPT
Conversation Context:
{$contextText}

Current Message:
{$message->getText()}
{$existingMemoriesText}

Extract NEW memories from this conversation in JSON format. Do NOT duplicate existing memories listed above.
PROMPT;

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                $message->getUserId(),
                [
                    'temperature' => 0.3, // Low temperature for consistent extraction
                    'model' => $this->getExtractionModel($message->getUserId()),
                ]
            );

            $content = $response['content'] ?? '';

            $this->logger->debug('MemoryExtractionService: AI response received', [
                'message_id' => $message->getId(),
                'content_preview' => substr($content, 0, 200),
            ]);

            // Parse JSON response
            $memories = $this->parseMemoriesFromResponse($content);

            $this->logger->info('Memories extracted', [
                'message_id' => $message->getId(),
                'count' => count($memories),
                'memories' => $memories,
            ]);

            return $memories;
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
            'topic' => 'memory_extraction',
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
     *
     * @return array Array of memories
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

            // Validate structure
            $validated = [];
            foreach ($memories as $memory) {
                if (!isset($memory['category'], $memory['key'], $memory['value'])) {
                    continue;
                }

                if (mb_strlen($memory['key']) < 3 || mb_strlen($memory['value']) < 5) {
                    continue;
                }

                $validated[] = $memory;
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
     * Get model for memory extraction (fast model preferred).
     */
    private function getExtractionModel(int $userId): ?string
    {
        // Try to get user's default chat model
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $userId);

        if ($modelId) {
            $model = $this->modelConfigService->getModelName($modelId);

            return $model;
        }

        return null; // Use system default
    }
}

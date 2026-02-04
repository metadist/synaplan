<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Service\AiFacade;
use App\DTO\UserMemoryDTO;
use App\Entity\User;
use App\Repository\PromptRepository;
use Psr\Log\LoggerInterface;

final readonly class FeedbackExampleService
{
    private const MAX_SUMMARY_LENGTH = 500;
    private const NAMESPACE_FALSE_POSITIVE = 'feedback_false_positive';
    private const NAMESPACE_POSITIVE = 'feedback_positive';

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private UserMemoryService $memoryService,
        private PromptRepository $promptRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * List all feedback examples for a user.
     *
     * @param string $type Filter: 'false_positive', 'positive', or 'all'
     *
     * @return array<array{id: int, type: string, value: string, messageId: ?int, created: int, updated: int}>
     */
    public function listFeedbacks(User $user, string $type = 'all'): array
    {
        if (!$this->memoryService->isAvailable()) {
            // Return empty array if service is unavailable (graceful degradation)
            $this->logger->warning('FeedbackExampleService: Memory service (Vector DB) unavailable, returning empty list');

            return [];
        }

        $feedbacks = [];

        try {
            // Load false positives
            if ('all' === $type || 'false_positive' === $type) {
                $falsePositives = $this->memoryService->scrollMemories(
                    $user->getId(),
                    'feedback_negative',
                    1000,
                    self::NAMESPACE_FALSE_POSITIVE
                );
                foreach ($falsePositives as $fp) {
                    $feedbacks[] = $this->mapMemoryToFeedback($fp, 'false_positive');
                }
            }

            // Load positive examples
            if ('all' === $type || 'positive' === $type) {
                $positives = $this->memoryService->scrollMemories(
                    $user->getId(),
                    'feedback_positive',
                    1000,
                    self::NAMESPACE_POSITIVE
                );
                foreach ($positives as $p) {
                    $feedbacks[] = $this->mapMemoryToFeedback($p, 'positive');
                }
            }

            // Filter out empty values and sort by created desc
            $feedbacks = array_filter($feedbacks, fn ($f) => !empty($f['value']));
            usort($feedbacks, fn ($a, $b) => $b['created'] <=> $a['created']);
        } catch (\Throwable $e) {
            $this->logger->warning('FeedbackExampleService: Failed to load feedbacks', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            // Return empty array on error (graceful degradation)
            return [];
        }

        return $feedbacks;
    }

    /**
     * Map a memory array from Qdrant to a feedback array.
     * Handles different data structures that may come from the service.
     */
    private function mapMemoryToFeedback(array $memory, string $type): array
    {
        // Try different field names that the Qdrant service might use
        $id = $memory['id'] ?? $memory['point_id'] ?? 0;
        // Extract numeric ID from point_id format (e.g., "mem_1_123456" -> 123456)
        if (is_string($id) && str_starts_with($id, 'mem_')) {
            $parts = explode('_', $id);
            $id = (int) end($parts);
        }

        // Value can be in different fields
        $value = $memory['value']
            ?? $memory['text']
            ?? $memory['content']
            ?? ($memory['payload']['value'] ?? '')
            ?? ($memory['payload']['text'] ?? '')
            ?? '';

        $messageId = $memory['messageId']
            ?? $memory['message_id']
            ?? ($memory['payload']['message_id'] ?? null)
            ?? null;

        $created = $memory['created']
            ?? $memory['created_at']
            ?? ($memory['payload']['created'] ?? 0)
            ?? 0;

        $updated = $memory['updated']
            ?? $memory['updated_at']
            ?? ($memory['payload']['updated'] ?? 0)
            ?? 0;

        return [
            'id' => (int) $id,
            'type' => $type,
            'value' => (string) $value,
            'messageId' => $messageId ? (int) $messageId : null,
            'created' => (int) $created,
            'updated' => (int) $updated,
        ];
    }

    /**
     * Update a feedback example.
     */
    public function updateFeedback(User $user, int $id, string $value): array
    {
        if (!$this->memoryService->isAvailable()) {
            throw new \RuntimeException('Memory service unavailable');
        }

        // Try to find the feedback in either namespace
        $memory = $this->memoryService->getMemoryById($id, $user);

        if (!$memory) {
            throw new \InvalidArgumentException('Feedback not found');
        }

        // Determine the type based on namespace/category
        $type = 'feedback_negative' === $memory->category ? 'false_positive' : 'positive';
        $namespace = 'false_positive' === $type ? self::NAMESPACE_FALSE_POSITIVE : self::NAMESPACE_POSITIVE;

        // Update the memory
        $updated = $this->memoryService->updateMemory(
            $id,
            $user,
            $value,
            'user_edited',
            $memory->messageId,
            null, // key stays the same
            null, // category stays the same
            $namespace
        );

        return [
            'id' => $updated->id,
            'type' => $type,
            'value' => $updated->value,
            'messageId' => $updated->messageId,
            'created' => $updated->created,
            'updated' => $updated->updated,
        ];
    }

    /**
     * Delete a feedback example.
     */
    public function deleteFeedback(User $user, int $id): void
    {
        if (!$this->memoryService->isAvailable()) {
            throw new \RuntimeException('Memory service unavailable');
        }

        // Verify ownership
        $memory = $this->memoryService->getMemoryById($id, $user);

        if (!$memory) {
            throw new \InvalidArgumentException('Feedback not found');
        }

        $namespace = 'feedback_negative' === $memory->category ? self::NAMESPACE_FALSE_POSITIVE : self::NAMESPACE_POSITIVE;

        $this->memoryService->deleteMemory($id, $user, $namespace);
    }

    public function previewFalsePositive(User $user, string $text, ?string $userMessage = null): array
    {
        $summary = $this->summarizeFalsePositive($user, $text, $userMessage);
        $summary = $this->normalizeSummary($summary, $text);
        $correction = $this->suggestCorrection($user, $text, $userMessage);
        $correction = $this->normalizeSummary($correction, $text);

        return [
            'summary' => $summary,
            'correction' => $correction,
        ];
    }

    public function createFalsePositive(User $user, string $summary, ?int $messageId = null): UserMemoryDTO
    {
        if (!$this->memoryService->isAvailable()) {
            throw new \RuntimeException('Memory service unavailable');
        }

        return $this->memoryService->createMemory(
            $user,
            'feedback_negative',
            'false_positive',
            $summary,
            'user_created',
            $messageId,
            self::NAMESPACE_FALSE_POSITIVE
        );
    }

    public function createPositive(User $user, string $text, ?int $messageId = null): UserMemoryDTO
    {
        if (!$this->memoryService->isAvailable()) {
            throw new \RuntimeException('Memory service unavailable');
        }

        $value = $this->normalizeSummary($text, $text);

        return $this->memoryService->createMemory(
            $user,
            'feedback_positive',
            'positive_example',
            $value,
            'user_created',
            $messageId,
            self::NAMESPACE_POSITIVE
        );
    }

    /**
     * Regenerate a correction based on a false claim and previous incorrect correction.
     * The AI will generate a better, more accurate correction in the same language as the false claim.
     */
    public function regenerateCorrection(User $user, string $falseClaim, string $oldCorrection): string
    {
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
        $provider = $modelId ? $this->modelConfigService->getProviderForModel($modelId) : null;
        $modelName = $modelId ? $this->modelConfigService->getModelName($modelId) : null;

        $systemPrompt = <<<'PROMPT'
You are a fact-checking assistant. Your task is to provide accurate corrections for false claims.

Rules:
1. ALWAYS respond in the SAME LANGUAGE as the false claim
2. Provide a factually accurate correction
3. Be concise - one or two sentences maximum
4. If a previous correction was provided and it was incorrect, improve upon it
5. Output ONLY the corrected statement, nothing else
PROMPT;

        $userPrompt = "FALSE CLAIM:\n{$falseClaim}";
        if (!empty($oldCorrection)) {
            $userPrompt .= "\n\nPREVIOUS INCORRECT CORRECTION:\n{$oldCorrection}\n\nPlease provide a better, accurate correction.";
        } else {
            $userPrompt .= "\n\nProvide the correct statement.";
        }

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                $user->getId(),
                array_filter([
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.3,
                ])
            );

            $correction = trim((string) ($response['content'] ?? ''));

            return $this->normalizeSummary($correction, $falseClaim);
        } catch (\Throwable $e) {
            $this->logger->warning('Correction regeneration failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function summarizeFalsePositive(User $user, string $text, ?string $userMessage = null): string
    {
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
        $provider = $modelId ? $this->modelConfigService->getProviderForModel($modelId) : null;
        $modelName = $modelId ? $this->modelConfigService->getModelName($modelId) : null;

        $systemPrompt = $this->getFalsePositivePrompt();

        // Build context with user question if available
        $contextPart = '';
        if ($userMessage) {
            $contextPart = <<<CONTEXT
User question:
{$userMessage}

CONTEXT;
        }

        $userPrompt = <<<PROMPT
{$contextPart}AI response (marked as incorrect by the user):
{$text}

Summarize the false or undesirable claim in one sentence. If the AI response is vague or lacks content, use the user question to understand what topic the AI should have addressed.
IMPORTANT: Respond in the SAME LANGUAGE as the text above. If the text is in German, respond in German. If in English, respond in English.
PROMPT;

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                $user->getId(),
                array_filter([
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.2,
                ])
            );

            return trim((string) ($response['content'] ?? ''));
        } catch (\Throwable $e) {
            $this->logger->warning('False-positive summarization failed, using fallback', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function suggestCorrection(User $user, string $text, ?string $userMessage = null): string
    {
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
        $provider = $modelId ? $this->modelConfigService->getProviderForModel($modelId) : null;
        $modelName = $modelId ? $this->modelConfigService->getModelName($modelId) : null;

        $systemPrompt = $this->getFalsePositiveCorrectionPrompt();

        // Build context with user question if available
        $contextPart = '';
        if ($userMessage) {
            $contextPart = <<<CONTEXT
User question:
{$userMessage}

CONTEXT;
        }

        $userPrompt = <<<PROMPT
{$contextPart}AI response (marked as incorrect by the user):
{$text}

Provide the corrected statement in one sentence. If the AI response was vague, provide a proper answer to the user's question.
IMPORTANT: Respond in the SAME LANGUAGE as the text above. If the text is in German, respond in German. If in English, respond in English.
PROMPT;

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                $user->getId(),
                array_filter([
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.2,
                ])
            );

            return trim((string) ($response['content'] ?? ''));
        } catch (\Throwable $e) {
            $this->logger->warning('False-positive correction failed, using fallback', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function normalizeSummary(string $summary, string $fallback): string
    {
        $summary = trim($summary);
        if ('' === $summary) {
            $summary = trim($fallback);
        }

        $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;

        if (mb_strlen($summary) < 5) {
            $summary = trim($fallback);
        }

        if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            $summary = mb_substr($summary, 0, self::MAX_SUMMARY_LENGTH);
        }

        return $summary;
    }

    private function getFalsePositivePrompt(): string
    {
        $prompt = $this->promptRepository->findOneBy([
            'topic' => 'tools:feedback_false_positive_summary',
            'language' => 'en',
            'ownerId' => 0,
        ]);

        if ($prompt) {
            return $prompt->getPrompt();
        }

        $this->logger->warning('False-positive prompt not found, using fallback');

        return 'Summarize the false or undesirable claim in one concise sentence. Output only the sentence. ALWAYS respond in the same language as the input text.';
    }

    private function getFalsePositiveCorrectionPrompt(): string
    {
        $prompt = $this->promptRepository->findOneBy([
            'topic' => 'tools:feedback_false_positive_correction',
            'language' => 'en',
            'ownerId' => 0,
        ]);

        if ($prompt) {
            return $prompt->getPrompt();
        }

        $this->logger->warning('False-positive correction prompt not found, using fallback');

        return 'Correct the false statement in one concise sentence. Output only the corrected sentence. ALWAYS respond in the same language as the input text.';
    }
}

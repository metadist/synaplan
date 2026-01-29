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

    public function previewFalsePositive(User $user, string $text): array
    {
        $summary = $this->summarizeFalsePositive($user, $text);
        $summary = $this->normalizeSummary($summary, $text);
        $correction = $this->suggestCorrection($user, $text);
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

    private function summarizeFalsePositive(User $user, string $text): string
    {
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
        $provider = $modelId ? $this->modelConfigService->getProviderForModel($modelId) : null;
        $modelName = $modelId ? $this->modelConfigService->getModelName($modelId) : null;

        $systemPrompt = $this->getFalsePositivePrompt();

        $userPrompt = <<<PROMPT
Text:
{$text}

Summarize the false or undesirable claim in one sentence.
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

    private function suggestCorrection(User $user, string $text): string
    {
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
        $provider = $modelId ? $this->modelConfigService->getProviderForModel($modelId) : null;
        $modelName = $modelId ? $this->modelConfigService->getModelName($modelId) : null;

        $systemPrompt = $this->getFalsePositiveCorrectionPrompt();

        $userPrompt = <<<PROMPT
Text:
{$text}

Provide the corrected statement in one sentence.
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
            'topic' => 'feedback_false_positive_summary',
            'language' => 'en',
            'ownerId' => 0,
        ]);

        if ($prompt) {
            return $prompt->getPrompt();
        }

        $this->logger->warning('False-positive prompt not found, using fallback');

        return 'Summarize the false or undesirable claim in one concise sentence. Output only the sentence.';
    }

    private function getFalsePositiveCorrectionPrompt(): string
    {
        $prompt = $this->promptRepository->findOneBy([
            'topic' => 'feedback_false_positive_correction',
            'language' => 'en',
            'ownerId' => 0,
        ]);

        if ($prompt) {
            return $prompt->getPrompt();
        }

        $this->logger->warning('False-positive correction prompt not found, using fallback');

        return 'Correct the false statement in one concise sentence. Output only the corrected sentence.';
    }
}


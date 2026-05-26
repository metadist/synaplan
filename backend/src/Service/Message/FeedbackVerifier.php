<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\RoutingFeedback;
use App\Repository\ConfigRepository;
use App\Repository\PromptRepository;
use App\Repository\RoutingFeedbackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies routing feedback submissions using an AI model and enforces rate limits.
 *
 * Flow:
 * 1. Rate limit check (configurable, default 5/min per user)
 * 2. AI verification: asks a configurable LLM whether the feedback is plausible
 * 3. Persists feedback with verified/rejected status
 *
 * Only verified feedbacks are included in training data exports.
 */
final readonly class FeedbackVerifier
{
    private const DEFAULT_RATE_LIMIT = 5;
    private const RATE_WINDOW_SECONDS = 60;

    public function __construct(
        private AiFacade $aiFacade,
        private RoutingFeedbackRepository $feedbackRepository,
        private PromptRepository $promptRepository,
        private ConfigRepository $configRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Submit and verify a routing feedback correction.
     *
     * @return array{success: bool, status: string, reason: ?string, error: ?string}
     */
    public function submitAndVerify(
        int $userId,
        int $messageId,
        string $originalTopic,
        string $suggestedTopic,
        string $messageText,
    ): array {
        // Rate limit check
        if ($this->isRateLimited($userId)) {
            return [
                'success' => false,
                'status' => 'rate_limited',
                'reason' => null,
                'error' => 'Too many feedback submissions. Please wait before trying again.',
            ];
        }

        $this->incrementRateCounter($userId);

        // Skip verification if disabled
        if (!$this->isVerificationEnabled()) {
            return $this->persistFeedback(
                $userId,
                $messageId,
                $originalTopic,
                $suggestedTopic,
                RoutingFeedback::STATUS_VERIFIED,
                'Verification disabled — auto-accepted',
            );
        }

        // Get description of the suggested topic for context
        $topicDescription = $this->getTopicDescription($suggestedTopic);

        // AI verification
        $verification = $this->verifyWithAi($messageText, $suggestedTopic, $topicDescription);

        $status = $verification['verified']
            ? RoutingFeedback::STATUS_VERIFIED
            : RoutingFeedback::STATUS_REJECTED;

        return $this->persistFeedback(
            $userId,
            $messageId,
            $originalTopic,
            $suggestedTopic,
            $status,
            $verification['reason'],
        );
    }

    /**
     * Check if user has exceeded the rate limit.
     */
    private function isRateLimited(int $userId): bool
    {
        $count = $this->feedbackRepository->countRecentByUser($userId, self::RATE_WINDOW_SECONDS);
        $limit = $this->getRateLimit();

        return $count >= $limit;
    }

    private function incrementRateCounter(int $userId): void
    {
        // The DB-based counting in countRecentByUser is sufficient for rate limiting.
        // No additional cache counter needed since we persist immediately.
    }

    /**
     * Ask the AI model to verify whether the feedback makes sense.
     *
     * @return array{verified: bool, reason: string}
     */
    private function verifyWithAi(string $messageText, string $suggestedTopic, string $topicDescription): array
    {
        $prompt = sprintf(
            'You are a routing verification assistant. Check if the following routing feedback is plausible. '
            .'A user says this message should be routed to use case "%s" (%s). '
            .'Message: "%s". '
            .'Respond ONLY with JSON: {"verified": true/false, "reason": "brief explanation in English"}',
            $suggestedTopic,
            $topicDescription ?: 'no description available',
            mb_substr($messageText, 0, 500),
        );

        try {
            $modelConfig = $this->getVerificationModelConfig();

            $response = $this->aiFacade->chat(
                [['role' => 'user', 'content' => $prompt]],
                null,
                array_filter($modelConfig, static fn ($v) => null !== $v),
            );

            $content = trim($response['content'] ?? '');

            return $this->parseVerificationResponse($content);
        } catch (\Throwable $e) {
            $this->logger->warning('FeedbackVerifier: AI verification failed, auto-accepting', [
                'error' => $e->getMessage(),
            ]);

            return ['verified' => true, 'reason' => 'Verification unavailable — auto-accepted'];
        }
    }

    /**
     * Parse the JSON response from the verification LLM.
     *
     * @return array{verified: bool, reason: string}
     */
    private function parseVerificationResponse(string $content): array
    {
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['verified'])) {
            $this->logger->debug('FeedbackVerifier: Could not parse AI response, auto-accepting', [
                'raw' => mb_substr($content, 0, 200),
            ]);

            return ['verified' => true, 'reason' => 'Parse error — auto-accepted'];
        }

        return [
            'verified' => (bool) $data['verified'],
            'reason' => (string) ($data['reason'] ?? 'No reason provided'),
        ];
    }

    /**
     * @return array{success: bool, status: string, reason: ?string, error: ?string}
     */
    private function persistFeedback(
        int $userId,
        int $messageId,
        string $originalTopic,
        string $suggestedTopic,
        string $status,
        ?string $reason,
    ): array {
        $feedback = new RoutingFeedback();
        $feedback->setUserId($userId);
        $feedback->setMessageId($messageId);
        $feedback->setOriginalTopic($originalTopic);
        $feedback->setSuggestedTopic($suggestedTopic);
        $feedback->setStatus($status);
        $feedback->setVerificationReason($reason);

        $this->em->persist($feedback);
        $this->em->flush();

        // Forward verified feedback to external router for online learning
        if (RoutingFeedback::STATUS_VERIFIED === $status) {
            $this->logger->info('FeedbackVerifier: Feedback verified and persisted', [
                'user_id' => $userId,
                'message_id' => $messageId,
                'suggested_topic' => $suggestedTopic,
            ]);
        }

        return [
            'success' => true,
            'status' => $status,
            'reason' => $reason,
            'error' => null,
        ];
    }

    private function getTopicDescription(string $topic): string
    {
        $prompt = $this->promptRepository->findByTopic($topic, 0);

        return $prompt?->getShortDescription() ?? '';
    }

    private function isVerificationEnabled(): bool
    {
        $value = $this->configRepository->getValue(0, 'ROUTER', 'FEEDBACK_VERIFICATION_ENABLED');

        if (null === $value) {
            return true;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * @return array{provider?: ?string, model?: ?string}
     */
    private function getVerificationModelConfig(): array
    {
        $modelSetting = $this->configRepository->getValue(0, 'ROUTER', 'FEEDBACK_VERIFICATION_MODEL');

        if (null === $modelSetting || '' === $modelSetting) {
            return [];
        }

        // Format: "provider:model_name" or just "model_name"
        if (str_contains($modelSetting, ':')) {
            [$provider, $model] = explode(':', $modelSetting, 2);

            return ['provider' => $provider, 'model' => $model];
        }

        return ['model' => $modelSetting];
    }

    private function getRateLimit(): int
    {
        $value = $this->configRepository->getValue(0, 'ROUTER', 'FEEDBACK_RATE_LIMIT_PER_MINUTE');

        if (null !== $value && is_numeric($value)) {
            return (int) $value;
        }

        return self::DEFAULT_RATE_LIMIT;
    }
}

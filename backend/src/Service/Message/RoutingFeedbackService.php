<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Repository\MessageRepository;
use Psr\Log\LoggerInterface;

/**
 * Handles routing feedback corrections from users.
 *
 * When a user reports that a message was routed to the wrong use case,
 * this service:
 * 1. Validates the message belongs to the user
 * 2. Delegates to FeedbackVerifier for rate limiting + AI verification
 * 3. Only verified feedback is persisted and forwarded for training
 */
final readonly class RoutingFeedbackService
{
    public function __construct(
        private FeedbackVerifier $feedbackVerifier,
        private RouterClient $routerClient,
        private MessageRepository $messageRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{success: bool, error: ?string, status?: string, reason?: ?string}
     */
    public function submitFeedback(int $messageId, string $correctUseCase, int $userId): array
    {
        $message = $this->messageRepository->find($messageId);

        if (null === $message) {
            return ['success' => false, 'error' => 'Message not found'];
        }

        if ($message->getUserId() !== $userId) {
            return ['success' => false, 'error' => 'Message does not belong to user'];
        }

        $messageText = $message->getText();
        if (empty($messageText)) {
            return ['success' => false, 'error' => 'Message has no text content'];
        }

        $originalTopic = $message->getTopic() ?: 'unknown';

        $this->logger->info('RoutingFeedback: User correction received', [
            'message_id' => $messageId,
            'user_id' => $userId,
            'original' => $originalTopic,
            'suggested' => $correctUseCase,
        ]);

        // Verify and persist via FeedbackVerifier (rate limit + AI check)
        $result = $this->feedbackVerifier->submitAndVerify(
            userId: $userId,
            messageId: $messageId,
            originalTopic: $originalTopic,
            suggestedTopic: $correctUseCase,
            messageText: $messageText,
        );

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error']];
        }

        // Forward verified feedback to external router for online learning
        if ('verified' === $result['status']) {
            $this->routerClient->submitFeedback(
                $messageText,
                $originalTopic,
                $correctUseCase,
                $userId,
            );
        }

        return [
            'success' => true,
            'error' => null,
            'status' => $result['status'],
            'reason' => $result['reason'],
        ];
    }

    /**
     * Get available use case labels for the feedback dropdown.
     *
     * @return list<string>
     */
    public function getAvailableUseCases(): array
    {
        $fromRouter = $this->routerClient->getUseCases();

        if (!empty($fromRouter)) {
            return $fromRouter;
        }

        return [
            'text_chat',
            'coding',
            'image_generation',
            'video_generation',
            'audio_generation',
            'file_generation',
            'file_analysis',
            'email_send',
            'web_search',
            'summarize',
        ];
    }
}

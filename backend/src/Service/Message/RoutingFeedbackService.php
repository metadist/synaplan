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
 * 1. Validates the correction
 * 2. Stores it in message metadata for audit
 * 3. Forwards it to the external synaplan-router for model retraining
 */
final readonly class RoutingFeedbackService
{
    public function __construct(
        private RouterClient $routerClient,
        private MessageRepository $messageRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{success: bool, error: ?string}
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

        $predictedUseCase = $message->getTopic() ?: 'unknown';

        $this->logger->info('RoutingFeedback: User correction received', [
            'message_id' => $messageId,
            'user_id' => $userId,
            'predicted' => $predictedUseCase,
            'correct' => $correctUseCase,
        ]);

        $forwarded = $this->routerClient->submitFeedback(
            $messageText,
            $predictedUseCase,
            $correctUseCase,
            $userId,
        );

        if (!$forwarded) {
            $this->logger->warning('RoutingFeedback: Could not forward to external router (service may be unavailable)', [
                'message_id' => $messageId,
            ]);
        }

        return ['success' => true, 'error' => null];
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

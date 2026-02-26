<?php

namespace App\Service;

use App\Entity\Message;
use App\Repository\MessageRepository;

final readonly class EmailWebhookIdempotencyService
{
    public function __construct(
        private MessageRepository $messageRepository,
    ) {
    }

    /**
     * @return array{existing: ?Message, fingerprint: string, normalized_message_id: ?string}
     */
    public function findDuplicate(
        string $fromEmail,
        string $toEmail,
        string $subject,
        string $body,
        mixed $messageId,
    ): array {
        $normalizedFrom = strtolower(trim($fromEmail));
        $normalizedTo = strtolower(trim($toEmail));
        $normalizedMessageId = $this->normalizeExternalMessageId($messageId);
        $fingerprint = $this->buildEmailFingerprint($normalizedFrom, $normalizedTo, $subject, $body);

        if ($normalizedMessageId) {
            $existing = $this->messageRepository->findLatestIncomingEmailByExternalId($normalizedMessageId, $normalizedFrom);
        } else {
            $existing = $this->messageRepository->findRecentIncomingEmailByFingerprint($fingerprint, 180);
        }

        return [
            'existing' => $existing,
            'fingerprint' => $fingerprint,
            'normalized_message_id' => $normalizedMessageId,
        ];
    }

    private function normalizeExternalMessageId(mixed $messageId): ?string
    {
        if (!is_string($messageId)) {
            return null;
        }

        $normalized = trim($messageId);

        return '' === $normalized ? null : strtolower($normalized);
    }

    private function buildEmailFingerprint(string $fromEmail, string $toEmail, string $subject, string $body): string
    {
        return hash('sha256', $fromEmail."\n".$toEmail."\n".trim($subject)."\n".trim($body));
    }
}

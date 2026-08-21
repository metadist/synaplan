<?php

declare(strict_types=1);

namespace App\Service\Microsoft;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use Psr\Log\LoggerInterface;

/**
 * Sends mail FROM the user's own connected Microsoft 365 mailbox (delegated
 * `Mail.Send`) — the message lands in their Outlook Sent items, unlike the
 * system-SMTP path of {@see \App\Service\InternalEmailService}.
 *
 * Used as the preferred transport for `email_me` when the account owner has a
 * connected, send-capable M365 account; callers fall back to the internal
 * SMTP path when {@see isAvailableFor()} is false or the send fails.
 */
final readonly class M365MailSender
{
    /**
     * Graph's simple-attachment ceiling is ~3 MB per file; anything larger
     * needs an upload session, which is out of scope for result mails —
     * callers fall back to SMTP instead.
     */
    public const MAX_ATTACHMENT_BYTES = 3 * 1024 * 1024;

    public function __construct(
        private GraphClient $graph,
        private ConnectionRepository $connections,
        private LoggerInterface $logger,
    ) {
    }

    public function isAvailableFor(int $ownerId): bool
    {
        return null !== $this->sendCapableConnection($ownerId);
    }

    /**
     * Send a result mail from the owner's M365 mailbox.
     *
     * @param list<array{path: string, type: string|null}> $attachments absolute paths inside the uploads dir
     *
     * @throws GraphException    when Graph refuses the send
     * @throws \RuntimeException when no send-capable connection exists or an attachment exceeds the Graph limit
     */
    public function sendTaskResultEmail(int $ownerId, string $to, string $subject, string $body, array $attachments = []): void
    {
        $connection = $this->sendCapableConnection($ownerId);
        if (null === $connection) {
            throw new \RuntimeException('No send-capable Microsoft 365 connection for this account');
        }

        $graphAttachments = [];
        foreach ($attachments as $attachment) {
            $path = $attachment['path'];
            $size = is_file($path) ? (filesize($path) ?: 0) : 0;
            if (0 === $size) {
                continue;
            }
            if ($size > self::MAX_ATTACHMENT_BYTES) {
                throw new \RuntimeException(sprintf('Attachment %s exceeds the Microsoft Graph simple-attachment limit', basename($path)));
            }
            $content = file_get_contents($path);
            if (false === $content) {
                continue;
            }
            $graphAttachments[] = [
                'name' => basename($path),
                'contentBytes' => base64_encode($content),
                'contentType' => $this->contentType($path, $attachment['type']),
            ];
        }

        $this->graph->sendMail($connection, [$to], $subject, $body, $graphAttachments);

        $this->logger->info('M365MailSender: result mail sent from the connected mailbox', [
            'owner_id' => $ownerId,
            'connection_id' => $connection->getId(),
            'attachment_count' => count($graphAttachments),
        ]);
    }

    /**
     * The owner's first connected M365 account whose consent includes
     * `Mail.Send`. Pre-expansion consents lack the scope and are skipped —
     * callers then use the internal SMTP path, never a Graph 403.
     */
    private function sendCapableConnection(int $ownerId): ?Connection
    {
        foreach ($this->connections->findByOwner($ownerId) as $connection) {
            if (Connection::TYPE_M365 !== $connection->getType()) {
                continue;
            }
            if (Connection::STATUS_REAUTH_REQUIRED === $connection->getStatus()
                || Connection::STATUS_DISCONNECTED === $connection->getStatus()) {
                continue;
            }
            if (in_array(MicrosoftOAuthConfig::SCOPE_MAIL_SEND, $connection->getScopes() ?? [], true)) {
                return $connection;
            }
        }

        return null;
    }

    private function contentType(string $path, ?string $declaredType): string
    {
        if (null !== $declaredType && str_contains($declaredType, '/')) {
            return $declaredType;
        }

        $detected = mime_content_type($path);

        return false !== $detected ? $detected : 'application/octet-stream';
    }
}

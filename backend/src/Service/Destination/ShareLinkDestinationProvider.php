<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Repository\MessageRepository;

final readonly class ShareLinkDestinationProvider implements DestinationProvider
{
    public function __construct(
        private MessageRepository $messages,
    ) {
    }

    public function id(): string
    {
        return 'share_link';
    }

    public function send(ShareableFile $file, array $params): DestinationResult
    {
        $messageId = $params['message_id'] ?? null;
        if (!is_int($messageId) && !is_numeric($messageId)) {
            return DestinationResult::failure(DestinationFailureCode::NotFound, [
                'target' => $file->name,
                'connection' => 'share link',
            ]);
        }

        $message = $this->messages->findUserFileMessage((int) $messageId, $file->ownerId);
        if (null === $message) {
            return DestinationResult::failure(DestinationFailureCode::NotFound, [
                'target' => $file->name,
                'connection' => 'share link',
            ]);
        }

        $expiryDays = isset($params['expiry_days']) ? (int) $params['expiry_days'] : 7;
        $message->setPublic(true);
        $token = $message->generateShareToken();
        if ($expiryDays > 0) {
            $message->setShareExpires(time() + ($expiryDays * 24 * 60 * 60));
        }
        $this->messages->flush();

        return DestinationResult::success('/up/'.$message->getFilePath(), [
            'connection' => 'share link',
            'share_token' => $token,
        ]);
    }
}

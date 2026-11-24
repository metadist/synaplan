<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\File;
use App\Repository\MessageRepository;

/**
 * Decides whether the caller of a streamed turn may attach a file.
 *
 * Every guest on the trial streams as the same processing user, so the
 * owner of a file says nothing about which guest it belongs to. A guest
 * has no upload route: the only files that are theirs are the ones already
 * in the chat their session opened.
 */
final readonly class StreamAttachmentGate
{
    public function __construct(
        private MessageRepository $messages,
    ) {
    }

    public function allows(StreamCaller $caller, File $file): bool
    {
        if (!$caller->isGuest()) {
            return $caller->mayAttach($file);
        }

        $chatId = $caller->guestSession?->getChatId();
        $fileId = $file->getId();

        return null !== $chatId && null !== $fileId && $this->messages->isFileInChat($chatId, $fileId);
    }
}

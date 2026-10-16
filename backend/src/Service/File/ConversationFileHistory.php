<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * API-facing list of every file a conversation still has in hand.
 *
 * The catalog is the resolver ({@see ConversationFileCatalog}); this class
 * only shapes that list for HTTP so Chat and the Messages/API clients share
 * one file-history contract.
 */
final readonly class ConversationFileHistory
{
    public function __construct(
        private ConversationFileCatalog $catalog,
    ) {
    }

    /**
     * @return list<array{
     *     id: int|null,
     *     reference: string,
     *     name: string,
     *     category: string,
     *     origin: string,
     *     fileType: string,
     *     messageId: int|null,
     *     hasText: bool
     * }>
     */
    public function forChat(int $userId, int $chatId): array
    {
        return array_map(
            $this->toApi(...),
            $this->catalog->buildForChat($userId, $chatId, requireOnDisk: false),
        );
    }

    /**
     * @return array{
     *     id: int|null,
     *     reference: string,
     *     name: string,
     *     category: string,
     *     origin: string,
     *     fileType: string,
     *     messageId: int|null,
     *     hasText: bool
     * }
     */
    public function toApi(ConversationFile $file): array
    {
        $extension = strtolower(pathinfo($file->relativePath, PATHINFO_EXTENSION));

        return [
            'id' => $file->fileId,
            'reference' => $file->reference,
            'name' => $file->displayName,
            'category' => $file->category,
            'origin' => $file->origin,
            'fileType' => '' !== $extension ? $extension : $file->category,
            'messageId' => $file->messageId,
            'hasText' => '' !== trim($file->extractedText),
        ];
    }
}

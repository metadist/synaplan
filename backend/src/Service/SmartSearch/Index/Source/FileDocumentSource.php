<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index\Source;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\SmartSearch\Index\ResolvedItem;
use App\Service\SmartSearch\Index\SearchDocument;
use App\Service\SmartSearch\Index\SearchDocumentSourceInterface;

/**
 * Files: the display name plus the start of the extracted text. Deep matches
 * inside long documents come from the RAG chunks in the semantic tier.
 */
final readonly class FileDocumentSource implements SearchDocumentSourceInterface
{
    public const KIND = 'file';
    private const TRACKED_FIELDS = ['fileName', 'originalName', 'fileText', 'groupKey', 'ephemeral'];
    private const DEFAULT_GROUP = 'DEFAULT';

    public function __construct(
        private FileRepository $files,
    ) {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function isEnabledFor(int $userId): bool
    {
        return true;
    }

    public function refFor(object $entity, array $changeSet): ?array
    {
        if (!$entity instanceof File) {
            return null;
        }
        if ([] !== $changeSet && [] === array_intersect(self::TRACKED_FIELDS, array_keys($changeSet))) {
            return null;
        }
        $id = $entity->getId();

        return null === $id ? null : ['userId' => $entity->getUserId(), 'refId' => (string) $id];
    }

    public function build(int $userId, string $refId): ?SearchDocument
    {
        $file = $this->files->find((int) $refId);
        if (!$file instanceof File || $file->getUserId() !== $userId) {
            return null;
        }

        return $this->document($file);
    }

    public function allForUser(int $userId): iterable
    {
        foreach ($this->files->findBy(['userId' => $userId, 'ephemeral' => false]) as $file) {
            $document = $this->document($file);
            if (null !== $document) {
                yield $document;
            }
        }
    }

    public function resolve(int $userId, array $refIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $refIds), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }

        $resolved = [];
        foreach ($this->files->findBy(['userId' => $userId, 'id' => $ids, 'ephemeral' => false]) as $file) {
            $id = (string) $file->getId();
            $group = trim((string) $file->getGroupKey());
            $resolved[$id] = new ResolvedItem(
                title: $this->displayName($file),
                route: '/files?file='.$id,
                subtitle: '' === $group || self::DEFAULT_GROUP === $group ? null : $group,
            );
        }

        return $resolved;
    }

    private function document(File $file): ?SearchDocument
    {
        $id = $file->getId();
        if (null === $id || $file->isEphemeral()) {
            return null;
        }

        return new SearchDocument(
            userId: $file->getUserId(),
            kind: self::KIND,
            refId: (string) $id,
            title: $this->displayName($file),
            body: mb_substr($file->getFileText(), 0, SearchDocument::MAX_BODY_LENGTH * 2),
            updated: $file->getUpdatedAt(),
        );
    }

    private function displayName(File $file): string
    {
        $original = trim((string) $file->getOriginalName());

        return '' !== $original ? $original : $file->getFileName();
    }
}

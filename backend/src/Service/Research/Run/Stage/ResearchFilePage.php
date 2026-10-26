<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\RAG\RagScopeResolver;
use App\Service\Research\Run\Source\ResearchSourceReader;

/**
 * The text of one file, as a page the reader can open. Access is checked
 * again on open, so a share revoked mid-run closes the file.
 */
final readonly class ResearchFilePage
{
    public const NO_TEXT = 'There was no text to read.';
    public const NOT_ALLOWED = 'This file is no longer shared with you.';

    public function __construct(
        private FileRepository $files,
        private RagScopeResolver $scopes,
    ) {
    }

    public function open(int $userId, ?int $fileId): ResearchPage
    {
        $file = null !== $fileId ? $this->files->find($fileId) : null;
        if (!$file instanceof File) {
            return new ResearchPage(true, '', self::NO_TEXT, []);
        }
        if (!$this->mayRead($userId, $file)) {
            return new ResearchPage(true, '', self::NOT_ALLOWED, []);
        }
        $text = $file->getFileText();
        if ('' === trim($text)) {
            return new ResearchPage(true, '', self::NO_TEXT, []);
        }

        return new ResearchPage(false, mb_substr($text, 0, ResearchSourceReader::MAX_TEXT_CHARS), '', []);
    }

    private function mayRead(int $userId, File $file): bool
    {
        if ($file->getUserId() === $userId) {
            return true;
        }
        foreach ($this->scopes->resolve($userId, null) as $scope) {
            if ($scope->ownerId !== $file->getUserId()) {
                continue;
            }
            if (null !== $scope->groupKey && $scope->groupKey !== $file->getGroupKey()) {
                continue;
            }
            if ([] === $scope->fileIds || in_array($file->getId(), $scope->fileIds, true)) {
                return true;
            }
        }

        return false;
    }
}

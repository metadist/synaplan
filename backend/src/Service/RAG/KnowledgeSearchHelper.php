<?php

declare(strict_types=1);

namespace App\Service\RAG;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\RAG\VectorStorage\DTO\RagScope;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\Runtime\RuntimeProfile;

/**
 * The one knowledge search the Messages gateway uses.
 *
 * The first-turn injection and the `knowledge_search` / `read_document` tools
 * both come through here, so a later keyword leg has one place to land.
 * {@see searchInjected()} keeps today's injection arguments and return value.
 * The tool methods refuse a folder the caller cannot use; they do not search
 * a wider set than the injection.
 */
final readonly class KnowledgeSearchHelper
{
    public const DEFAULT_LIMIT = 5;
    public const MAX_LIMIT = 10;
    public const MIN_SCORE = 0.3;
    public const RESULT_CHAR_BUDGET = 12000;

    public const REFUSED = 'This folder is not shared with you for use.';
    public const FILE_REFUSED = 'You cannot read this file.';
    public const PASSAGE_MISSING = 'That passage is not in this file.';
    public const NO_FOLDER = 'No knowledge folder is pinned for this message.';

    private const NEIGHBOUR_LINES = 40;

    public function __construct(
        private VectorSearchService $vectorSearch,
        private RagScopeResolver $scopes,
        private VectorStorageFacade $storage,
        private FileRepository $files,
    ) {
    }

    /**
     * The injector's search, with the same arguments it used to pass itself.
     *
     * @param list<float> $vector
     *
     * @return list<array<string, mixed>>
     */
    public function searchInjected(
        int $userId,
        array $vector,
        string $query,
        ?string $ragGroupKey,
        ?RuntimeProfile $profile,
    ): array {
        $explicit = $this->injectionScopes($userId, $ragGroupKey, $profile);
        $limit = null !== $profile && null !== $profile->ragLimit ? $profile->ragLimit : self::DEFAULT_LIMIT;
        $minScore = null !== $profile && null !== $profile->ragMinScore ? $profile->ragMinScore : self::MIN_SCORE;

        return $this->vectorSearch->semanticSearchByVector(
            $userId,
            $vector,
            null === $explicit ? $ragGroupKey : null,
            $limit,
            $minScore,
            $query,
            $explicit,
        );
    }

    /**
     * @return array{hits: list<array<string, mixed>>, error: string|null}
     */
    public function searchForTool(
        int $userId,
        string $query,
        ?string $ragGroupKey,
        ?RuntimeProfile $profile,
        int $limit,
    ): array {
        $gate = $this->folderGate($userId, $ragGroupKey, $profile);
        if (null !== $gate) {
            return ['hits' => [], 'error' => $gate];
        }

        $explicit = $this->injectionScopes($userId, $ragGroupKey, $profile);
        $minScore = null !== $profile && null !== $profile->ragMinScore ? $profile->ragMinScore : self::MIN_SCORE;
        $hits = $this->vectorSearch->semanticSearch(
            $query,
            $userId,
            null === $explicit ? $ragGroupKey : null,
            max(1, min(self::MAX_LIMIT, $limit)),
            $minScore,
            $explicit,
        );

        return ['hits' => $hits, 'error' => null];
    }

    /**
     * @return array{text: string, isError: bool}
     */
    public function readForTool(
        int $userId,
        int $fileId,
        ?string $around,
        ?string $ragGroupKey,
        ?RuntimeProfile $profile,
    ): array {
        $gate = $this->folderGate($userId, $ragGroupKey, $profile);
        if (null !== $gate) {
            return ['text' => $gate, 'isError' => true];
        }

        $file = $this->files->find($fileId);
        if (!$file instanceof File || !$this->fileAllowed($file, $userId, $ragGroupKey, $profile)) {
            return ['text' => self::FILE_REFUSED, 'isError' => true];
        }

        $chunk = null;
        if (null !== $around && '' !== $around) {
            $chunk = $this->storage->findChunk($file->getUserId(), $around);
            if (!$chunk instanceof SearchResult || (int) $chunk->fileId !== $fileId) {
                return ['text' => self::PASSAGE_MISSING, 'isError' => true];
            }
        }

        $name = '' !== trim($file->getFileName()) ? $file->getFileName() : 'file';
        $body = $this->window($file->getFileText(), $chunk);

        return [
            'text' => "File: {$name}\nfile_id: {$fileId}\n\n{$body}",
            'isError' => false,
        ];
    }

    /**
     * @return list<RagScope>|null null keeps the resolver path (own folder, plus shared folders the caller can use)
     */
    private function injectionScopes(int $userId, ?string $ragGroupKey, ?RuntimeProfile $profile): ?array
    {
        if (null === $profile) {
            return null;
        }

        $scopes = [];
        foreach ($profile->ragScopes as $scope) {
            $scopes[] = new RagScope((int) $scope['ownerId'], $scope['groupKey']);
        }
        if (null !== $ragGroupKey && '' !== $ragGroupKey) {
            $scopes[] = new RagScope($userId, $ragGroupKey);
        }

        return $scopes;
    }

    /**
     * A shared folder the caller cannot use is a refusal. An assistant profile
     * keeps the injection's scopes instead, which never open that folder.
     */
    private function folderGate(int $userId, ?string $ragGroupKey, ?RuntimeProfile $profile): ?string
    {
        if (null !== $profile) {
            return null;
        }
        if (null === $ragGroupKey || '' === $ragGroupKey) {
            return self::NO_FOLDER;
        }

        $picker = RagScopeResolver::parseSharedPicker($ragGroupKey);
        if (null === $picker) {
            return null;
        }
        if (!$this->scopes->canUse($userId, $picker[0], $picker[1])) {
            return self::REFUSED;
        }

        return null;
    }

    private function fileAllowed(File $file, int $userId, ?string $ragGroupKey, ?RuntimeProfile $profile): bool
    {
        $explicit = $this->injectionScopes($userId, $ragGroupKey, $profile);
        if (null !== $explicit) {
            foreach ($explicit as $scope) {
                if ($scope->ownerId === $file->getUserId() && $scope->groupKey === $file->getGroupKey()) {
                    return true;
                }
            }

            return false;
        }

        $picker = RagScopeResolver::parseSharedPicker($ragGroupKey);
        if (null !== $picker) {
            return $file->getUserId() === $picker[0] && $file->getGroupKey() === $picker[1];
        }

        return $file->getUserId() === $userId && $file->getGroupKey() === $ragGroupKey;
    }

    private function window(string $text, ?SearchResult $chunk): string
    {
        $text = str_replace("\r\n", "\n", $text);
        if (null === $chunk) {
            return $this->clamp($text);
        }
        if ('' === trim($text)) {
            return $this->clamp($chunk->text);
        }
        if ($chunk->startLine < 1) {
            return $this->clamp($chunk->text);
        }

        $lines = explode("\n", $text);
        $start = max(0, $chunk->startLine - 1 - self::NEIGHBOUR_LINES);
        $end = min(\count($lines) - 1, max($chunk->endLine, $chunk->startLine) - 1 + self::NEIGHBOUR_LINES);
        $slice = \array_slice($lines, $start, $end - $start + 1);

        return $this->clamp(implode("\n", $slice));
    }

    private function clamp(string $text): string
    {
        if (mb_strlen($text) <= self::RESULT_CHAR_BUDGET) {
            return $text;
        }

        return mb_substr($text, 0, self::RESULT_CHAR_BUDGET);
    }
}

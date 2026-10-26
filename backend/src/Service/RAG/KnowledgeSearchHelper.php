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
 * Injection stays meaning-only. Tool search adds keywords on Qdrant,
 * with the same owner scopes, and refuses a folder the caller cannot use.
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
    public const NO_TEXT = 'This file has no readable text.';

    private const NEIGHBOUR_LINES = 40;

    public function __construct(
        private VectorSearchService $vectorSearch,
        private RagScopeResolver $scopes,
        private VectorStorageFacade $storage,
        private FileRepository $files,
        private ?DocumentKeywordSearch $keywords = null,
        private ?HybridKnowledgeSearch $hybrid = null,
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
        $gate = $this->refusal($userId, $ragGroupKey, $profile);
        if (null !== $gate) {
            return ['hits' => [], 'error' => $gate];
        }

        $explicit = $this->injectionScopes($userId, $ragGroupKey, $profile);
        $minScore = null !== $profile && null !== $profile->ragMinScore ? $profile->ragMinScore : self::MIN_SCORE;
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $groupKey = null === $explicit ? $ragGroupKey : null;
        $hits = $this->vectorSearch->semanticSearch(
            $query,
            $userId,
            $groupKey,
            $limit,
            $minScore,
            $explicit,
        );
        if (null !== $this->keywords && null !== $this->hybrid) {
            $scopes = $explicit ?? $this->scopes->resolve($userId, $groupKey);
            $hits = $this->hybrid->merge(
                $hits,
                $this->keywords->find($userId, $query, $groupKey, $scopes, $limit),
                $limit,
                $query,
            );
        }

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
        $gate = $this->refusal($userId, $ragGroupKey, $profile);
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
        if ('' === trim($body)) {
            return ['text' => self::NO_TEXT, 'isError' => true];
        }

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
            $groupKey = $scope['groupKey'];
            $scopes[] = new RagScope(
                (int) $scope['ownerId'],
                '' === $groupKey ? null : $groupKey,
                $scope['fileIds'] ?? [],
            );
        }
        $pinned = $this->pinnedScope($userId, $ragGroupKey);
        if (null !== $pinned) {
            $scopes[] = $pinned;
        }

        return $scopes;
    }

    /**
     * A header pin. `shared:{owner}:{folder}` is the owner's folder, and only
     * when the caller can use it. Any other key stays the caller's own folder.
     */
    private function pinnedScope(int $userId, ?string $ragGroupKey): ?RagScope
    {
        if (null === $ragGroupKey || '' === $ragGroupKey) {
            return null;
        }

        $picker = RagScopeResolver::parseSharedPicker($ragGroupKey);
        if (null === $picker) {
            return new RagScope($userId, $ragGroupKey);
        }
        if (!$this->scopes->canUse($userId, $picker[0], $picker[1])) {
            return null;
        }

        return new RagScope($picker[0], $picker[1]);
    }

    /**
     * Why the folder tools cannot run on this turn, or null when they can.
     *
     * A shared folder the caller cannot use is a refusal, with or without an
     * assistant. An assistant without that header keeps its own scopes.
     */
    public function refusal(int $userId, ?string $ragGroupKey, ?RuntimeProfile $profile): ?string
    {
        $picker = RagScopeResolver::parseSharedPicker($ragGroupKey);
        if (null !== $picker && !$this->scopes->canUse($userId, $picker[0], $picker[1])) {
            return self::REFUSED;
        }
        if (null !== $profile) {
            return null;
        }
        if (null === $ragGroupKey || '' === $ragGroupKey) {
            return self::NO_FOLDER;
        }

        return null;
    }

    /**
     * A file is readable when it lies in one of the scopes the search used,
     * so every file_id `knowledge_search` returns can be opened.
     */
    private function fileAllowed(File $file, int $userId, ?string $ragGroupKey, ?RuntimeProfile $profile): bool
    {
        foreach ($this->toolScopes($userId, $ragGroupKey, $profile) as $scope) {
            if ($this->scopeAllowsFile($scope, $file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<RagScope>
     */
    private function toolScopes(int $userId, ?string $ragGroupKey, ?RuntimeProfile $profile): array
    {
        $explicit = $this->injectionScopes($userId, $ragGroupKey, $profile);
        if (null !== $explicit) {
            return $explicit;
        }

        $pinned = $this->pinnedScope($userId, $ragGroupKey);

        return [
            ...(null !== $pinned ? [$pinned] : []),
            ...$this->scopes->resolve($userId, $ragGroupKey),
        ];
    }

    private function scopeAllowsFile(RagScope $scope, File $file): bool
    {
        if ($scope->ownerId !== $file->getUserId()) {
            return false;
        }
        if (null !== $scope->groupKey && $scope->groupKey !== $file->getGroupKey()) {
            return false;
        }
        if ([] === $scope->fileIds) {
            return true;
        }
        $id = $file->getId();

        return null !== $id && in_array($id, $scope->fileIds, true);
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

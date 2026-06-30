<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Psr\Log\LoggerInterface;

/**
 * Business logic for the file manager's listing + incoming triage
 * (03_file-management.md §5, §3.3). Keeps {@see \App\Controller\FileController}
 * thin: it builds the full list-row payload (provenance, vector state, group),
 * maintains BVECTORSTATE/BCHUNKCOUNT fix-on-read, and promotes incoming files.
 */
final readonly class FileListService
{
    public function __construct(
        private FileRepository $fileRepository,
        private VectorStorageFacade $vectorStorageFacade,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Build a paginated, fully-serialized file listing for a user.
     *
     * @param array{search?: ?string, file_type?: ?string, date_from?: ?int, date_to?: ?int, source?: ?string, vector_state?: ?string, origin_kind?: ?string, incoming?: ?bool, sort?: ?string} $filters
     *
     * @return array{files: array<int, array<string, mixed>>, total: int}
     */
    public function buildListing(int $userId, ?string $groupKey, int $offset, int $limit, array $filters): array
    {
        $vectorFileIds = [];
        if ($groupKey) {
            try {
                $vectorFileIds = $this->vectorStorageFacade->getFileIdsByGroupKey($userId, $groupKey);
            } catch (\Throwable $e) {
                $this->logger->warning('FileListService: Vector store group lookup failed', ['error' => $e->getMessage()]);
            }
        }

        $result = $this->fileRepository->findByUserPaginated($userId, $groupKey, $offset, $limit, $vectorFileIds, $filters);
        $files = $result['files'];

        $allChunks = $this->getUserVectorChunks($userId);
        $vectorChunkMap = $this->resolveVectorGroupKeys($files, $allChunks);

        // Fix-on-read: keep BVECTORSTATE/BCHUNKCOUNT in sync with the authoritative
        // vector store so the list renders truthfully with no per-row Qdrant call.
        $this->syncVectorState($files, $allChunks);

        $rows = array_map(
            fn (File $mf): array => $this->serializeFileRow($mf, $allChunks, $vectorChunkMap),
            $files,
        );

        return ['files' => $rows, 'total' => $result['total']];
    }

    /**
     * Promote a single incoming file out of the inbox and flush.
     */
    public function accept(File $file, int $userId, ?string $groupKey): void
    {
        $this->promote($file, $userId, $groupKey);
        $this->fileRepository->flush();
    }

    /**
     * Promote multiple incoming files out of the inbox in one flush.
     *
     * @param File[] $files
     *
     * @return int number of files promoted
     */
    public function acceptMany(array $files, int $userId, ?string $groupKey): int
    {
        $accepted = 0;
        foreach ($files as $file) {
            $this->promote($file, $userId, $groupKey);
            ++$accepted;
        }
        if ($accepted > 0) {
            $this->fileRepository->flush();
        }

        return $accepted;
    }

    /**
     * Optionally file an incoming file in a group, then clear the incoming flag
     * and staging path (03_file-management.md §3.3). Does not flush — callers
     * flush once per request.
     */
    private function promote(File $file, int $userId, ?string $groupKey): void
    {
        if (null !== $groupKey) {
            $file->setGroupKey($groupKey);
            try {
                $this->vectorStorageFacade->updateGroupKey($userId, (int) $file->getId(), $groupKey);
            } catch (\Throwable $e) {
                $this->logger->warning('FileListService: vector group update on accept failed', ['error' => $e->getMessage()]);
            }
        }

        $file->setIncoming(false);
        $file->setStagePath(null);
        $this->fileRepository->save($file, false);
    }

    /**
     * Build the list-row payload for a single file (03_file-management.md §5),
     * including provenance, vector state, group and generated-media fields.
     *
     * @param array<int, array{chunks: int, groupKey: string|null}> $allChunks
     * @param array<int, string>                                    $vectorChunkMap
     *
     * @return array<string, mixed>
     */
    private function serializeFileRow(File $mf, array $allChunks, array $vectorChunkMap): array
    {
        $chunkCount = (int) ($allChunks[$mf->getId()]['chunks'] ?? 0);
        $groupKey = $mf->getGroupKey() ?: ($vectorChunkMap[$mf->getId()] ?? null);

        return [
            'id' => $mf->getId(),
            'filename' => $mf->getFileName(),
            'display_name' => $mf->getDisplayName(),
            'original_name' => $mf->getOriginalName(),
            'path' => $mf->getFilePath(),
            'file_type' => $mf->getFileType(),
            'file_size' => $mf->getFileSize(),
            'mime' => $mf->getFileMime(),
            'status' => $mf->getStatus(),
            'source' => $mf->getSource(),
            'origin_kind' => $mf->getOriginKind(),
            'incoming' => $mf->isIncoming(),
            'message_id' => $mf->getMessageId(),
            'provider' => $mf->getProvider(),
            'thumb_url' => null !== $mf->getThumbPath() ? '/api/v1/files/'.$mf->getId().'/thumb' : null,
            'text_preview' => mb_substr($mf->getFileText(), 0, 200),
            'uploaded_at' => $mf->getCreatedAt(),
            'uploaded_date' => date('Y-m-d H:i:s', $mf->getCreatedAt()),
            'group_key' => $groupKey,
            'chunks' => $chunkCount,
            'chunk_count' => $chunkCount,
            'vector_state' => $mf->getVectorState(),
            'is_vectorized' => $chunkCount > 0,
        ];
    }

    /**
     * Fetch the vector chunk map for a user (fileId => ['chunks' => int, 'groupKey' => string|null]).
     * Returns an empty map if the vector store is unavailable.
     *
     * @return array<int, array{chunks: int, groupKey: string|null}>
     */
    private function getUserVectorChunks(int $userId): array
    {
        try {
            return $this->vectorStorageFacade->getFilesWithChunks($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('FileListService: Vector store chunk lookup failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Resolve group keys from the vector store for legacy files missing the DB
     * column, and lazily backfill them.
     *
     * @param File[]                                                $files
     * @param array<int, array{chunks: int, groupKey: string|null}> $allChunks
     *
     * @return array<int, string>
     */
    private function resolveVectorGroupKeys(array $files, array $allChunks): array
    {
        $legacyFileIds = array_filter(
            array_map(fn (File $mf) => null === $mf->getGroupKey() ? $mf->getId() : null, $files)
        );

        if (empty($legacyFileIds)) {
            return [];
        }

        $vectorChunkMap = [];
        foreach ($legacyFileIds as $fid) {
            if (isset($allChunks[$fid]) && !empty($allChunks[$fid]['groupKey'])) {
                $vectorChunkMap[$fid] = $allChunks[$fid]['groupKey'];
            }
        }

        if (!empty($vectorChunkMap)) {
            try {
                $this->fileRepository->backfillGroupKeys($files, $vectorChunkMap);
            } catch (\Throwable $e) {
                $this->logger->warning('FileListService: Lazy backfill failed', ['error' => $e->getMessage()]);
            }
        }

        return $vectorChunkMap;
    }

    /**
     * Fix-on-read maintenance of BVECTORSTATE/BCHUNKCOUNT from the authoritative
     * vector store, persisted once so later list reads are cheap.
     *
     * @param File[]                                                $files
     * @param array<int, array{chunks: int, groupKey: string|null}> $allChunks
     */
    private function syncVectorState(array $files, array $allChunks): void
    {
        $changed = false;
        foreach ($files as $file) {
            $chunkCount = (int) ($allChunks[$file->getId()]['chunks'] ?? 0);
            $derived = $this->deriveVectorState($file, $chunkCount);

            if ($file->getChunkCount() !== $chunkCount || $file->getVectorState() !== $derived) {
                $file->setChunkCount($chunkCount);
                $file->setVectorState($derived);
                $this->fileRepository->save($file, false);
                $changed = true;
            }
        }

        if ($changed) {
            try {
                $this->fileRepository->flush();
            } catch (\Throwable $e) {
                $this->logger->warning('FileListService: vector-state sync flush failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Derive the authoritative vector state for a file from its chunk count and
     * upload/extraction status (03_file-management.md §3.1, §4.2).
     */
    private function deriveVectorState(File $file, int $chunkCount): string
    {
        if ($file->isMedia()) {
            return File::VECTOR_STATE_NOT_APPLICABLE;
        }
        if ($chunkCount > 0) {
            return File::VECTOR_STATE_VECTORIZED;
        }

        return match ($file->getStatus()) {
            'error', 'failed' => File::VECTOR_STATE_FAILED,
            'extracting', 'vectorizing', 'processing', 'pending' => File::VECTOR_STATE_PENDING,
            default => File::VECTOR_STATE_NONE,
        };
    }
}

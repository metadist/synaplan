<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Repository\FileRepository;

/**
 * Bulk check-stale for integrations: classifies each external file the caller
 * reports as `current`, `stale` (its source changed, or it was marked stale) or
 * `missing` (never ingested), so an app can sync without one call per file.
 */
final readonly class SourceStaleCheck
{
    public function __construct(
        private FileRepository $fileRepository,
    ) {
    }

    /**
     * @param array<mixed> $items `{source_id, source_etag?}` entries as sent by the caller
     *
     * @return array{
     *     results: list<array{source_id: string, status: string, file_id: ?int, stored_etag: ?string, vector_state: ?string}>,
     *     counts: array{current: int, stale: int, missing: int}
     * }
     */
    public function check(int $userId, string $source, array $items, ?string $sourceInstance = null): array
    {
        $requested = $this->requestedEtags($items);
        $found = $this->fileRepository->findByUserSourceIds($userId, $source, array_keys($requested), $sourceInstance);

        $results = [];
        $counts = ['current' => 0, 'stale' => 0, 'missing' => 0];
        foreach ($requested as $sid => $reportedEtag) {
            $sid = (string) $sid;
            $file = $found[$sid] ?? null;
            if (null === $file) {
                $status = 'missing';
                $fileId = null;
                $storedEtag = null;
                $vectorState = null;
            } else {
                $storedEtag = $file->getSourceEtag();
                $drifted = $file->isStale()
                    || (null !== $reportedEtag && null !== $storedEtag && $reportedEtag !== $storedEtag);
                $status = $drifted ? 'stale' : 'current';
                $fileId = (int) $file->getId();
                $vectorState = $file->getVectorState();
            }

            ++$counts[$status];
            $results[] = [
                'source_id' => $sid,
                'status' => $status,
                'file_id' => $fileId,
                'stored_etag' => $storedEtag,
                'vector_state' => $vectorState,
            ];
        }

        $this->fileRepository->flush();

        return ['results' => $results, 'counts' => $counts];
    }

    /**
     * Preserves the caller's order and the first etag seen per id.
     *
     * @param array<mixed> $items
     *
     * @return array<string, ?string>
     */
    private function requestedEtags(array $items): array
    {
        $requested = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['source_id'])) {
                continue;
            }
            $sid = trim((string) $item['source_id']);
            if ('' === $sid || array_key_exists($sid, $requested)) {
                continue;
            }
            $requested[$sid] = isset($item['source_etag']) && '' !== trim((string) $item['source_etag'])
                ? trim((string) $item['source_etag'])
                : null;
        }

        return $requested;
    }
}

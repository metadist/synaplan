<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

use App\Repository\SearchIndexRepository;
use Psr\Log\LoggerInterface;

/**
 * Fills the vectors of index rows that are new, changed, or embedded by an
 * older model. Runs on the index queue only — a search request never waits
 * for it; rows without a vector are still found by keywords.
 */
final readonly class SearchIndexEmbedder
{
    private const BATCH_SIZE = 32;

    /** Title and the start of the body carry the meaning; the rest is noise. */
    private const TEXT_MAX_CHARS = 1500;

    public function __construct(
        private SearchIndexRepository $repository,
        private SearchEmbeddingModel $embeddingModel,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int number of rows that got a vector
     */
    public function embedPending(int $userId, int $maxRows = 500): int
    {
        return $this->embedPendingCounted($userId, $maxRows)['embedded'];
    }

    /**
     * Like {@see embedPending()}, and also reports the rows a failed batch
     * left without a vector, so a reindex run can tell partial from none.
     *
     * @return array{embedded: int, failed: int}
     */
    public function embedPendingCounted(int $userId, int $maxRows = 500): array
    {
        $modelId = $this->embeddingModel->modelId();
        if (null === $modelId) {
            return ['embedded' => 0, 'failed' => 0];
        }

        $done = 0;
        $embedded = 0;
        $failed = 0;
        while ($done < $maxRows) {
            $rows = $this->repository->findPendingEmbeddings($userId, $modelId, min(self::BATCH_SIZE, $maxRows - $done));
            if ([] === $rows) {
                break;
            }

            try {
                $result = $this->embeddingModel->embed(array_map(self::text(...), $rows));
            } catch (\Throwable $e) {
                $this->logger->warning('Smart Search index embedding failed, rows keep keyword search only', [
                    'user_id' => $userId,
                    'rows' => count($rows),
                    'error' => $e->getMessage(),
                ]);
                $failed += count($rows);

                break;
            }
            if (null === $result) {
                break;
            }

            foreach ($rows as $i => $row) {
                $vector = $result['vectors'][$i] ?? null;
                if (null !== $vector) {
                    $this->repository->storeEmbedding($row['id'], $row['hash'], $vector, $result['modelId']);
                    ++$embedded;
                } else {
                    ++$failed;
                }
            }
            $done += count($rows);
        }

        return ['embedded' => $embedded, 'failed' => $failed];
    }

    /**
     * @param array{title: string, body: string} $row
     */
    private static function text(array $row): string
    {
        return mb_substr(trim($row['title']."\n".$row['body']), 0, self::TEXT_MAX_CHARS);
    }
}

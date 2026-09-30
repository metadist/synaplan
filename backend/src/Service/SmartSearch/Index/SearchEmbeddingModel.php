<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

use App\AI\Service\AiFacade;
use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;

/**
 * The one embedding space of the search index. It is system-wide (never a
 * member's own VECTORIZE choice) so a single query vector can be compared
 * with every user row and with the shared catalog rows.
 */
final readonly class SearchEmbeddingModel
{
    /** Same fixed width as the documents collection (see VectorSearchService). */
    public const DIMENSION = 1024;

    public function __construct(
        private ModelConfigService $modelConfig,
        private ModelRepository $models,
        private AiFacade $aiFacade,
    ) {
    }

    public function modelId(): ?int
    {
        return $this->modelConfig->getDefaultModel('VECTORIZE');
    }

    /**
     * @param list<string> $texts
     * @param bool         $fit   false keeps the provider's native width
     *
     * @return array{modelId: int, vectors: list<list<float>>}|null null when no embedding model is usable
     */
    public function embed(array $texts, ?int $userId = null, bool $fit = true): ?array
    {
        $model = $this->model();
        if (null === $model || [] === $texts) {
            return null;
        }

        $result = $this->aiFacade->embedBatch($texts, $userId, strtolower($model->getService()), [
            'model' => $model->getProviderId(),
        ]);

        $vectors = [];
        foreach ($result['embeddings'] as $embedding) {
            $vector = array_values(array_map('floatval', $embedding));
            $vectors[] = $fit ? self::fit($vector) : $vector;
        }

        return ['modelId' => (int) $model->getId(), 'vectors' => $vectors];
    }

    /**
     * Truncates or zero-pads to {@see DIMENSION}, like the RAG ingest and
     * query paths, so every provider fits the same column.
     *
     * @param list<float> $vector
     *
     * @return list<float>
     */
    public static function fit(array $vector): array
    {
        $width = count($vector);
        if ($width > self::DIMENSION) {
            return array_slice($vector, 0, self::DIMENSION);
        }
        if ($width < self::DIMENSION) {
            return array_merge($vector, array_fill(0, self::DIMENSION - $width, 0.0));
        }

        return $vector;
    }

    private function model(): ?Model
    {
        $modelId = $this->modelId();

        return null === $modelId ? null : $this->models->find($modelId);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Service\VectorSearch\QdrantClientInterface;
use App\UseCase\UseCaseCatalog;
use Psr\Log\LoggerInterface;

/**
 * Indexes the static use case catalogue into the `synapse_use_cases` Qdrant collection.
 */
final readonly class UseCaseIndexer
{
    public function __construct(
        private QdrantClientInterface $qdrantClient,
        private AiFacade $aiFacade,
        private SynapseIndexer $synapseIndexer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{indexed: int, skipped: int, errors: int}
     */
    public function indexAllUseCases(bool $force = false): array
    {
        $indexed = 0;
        $skipped = 0;
        $errors = 0;
        $modelInfo = $this->synapseIndexer->getEmbeddingModelInfo();

        foreach (UseCaseCatalog::all() as $entry) {
            try {
                $result = $this->indexUseCaseEntry($entry, $force, $modelInfo);
                if ('indexed' === $result) {
                    ++$indexed;
                } else {
                    ++$skipped;
                }
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('UseCaseIndexer: Failed to index use case', [
                    'use_case_id' => $entry['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['indexed' => $indexed, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function buildEmbeddingText(array $entry): string
    {
        $parts = [
            sprintf('Use case: %s', $entry['id']),
            sprintf('Description: %s', $entry['shortDescription']),
        ];

        if ('' !== trim($entry['keywords'])) {
            $parts[] = sprintf('Keywords: %s', $entry['keywords']);
        }

        return implode("\n", $parts);
    }

    /**
     * @param array{id: string, shortDescription: string, keywords: string}             $entry
     * @param array{provider: ?string, model: ?string, model_id: ?int, vector_dim: int} $modelInfo
     */
    private function indexUseCaseEntry(array $entry, bool $force, array $modelInfo): string
    {
        $modelId = $modelInfo['model_id'];
        $vectorDim = $modelInfo['vector_dim'];
        $embeddingText = $this->buildEmbeddingText($entry);
        $sourceHash = $this->synapseIndexer->computeSourceHash($embeddingText, $modelId, $vectorDim);
        $pointId = $this->buildPointId($entry['id']);

        if (!$force) {
            $existing = $this->qdrantClient->getUseCase($pointId);
            $existingHash = $existing['payload']['source_hash'] ?? null;
            if (null !== $existingHash && $existingHash === $sourceHash) {
                return 'skipped';
            }
        }

        $embeddingOptions = $this->getEmbeddingOptions($modelInfo);
        $result = $this->aiFacade->embed($embeddingText, null, $embeddingOptions);
        /** @var float[] $vector */
        $vector = $result['embedding'];
        if ([] === $vector) {
            throw new \RuntimeException(sprintf('Empty embedding returned for use case "%s"', $entry['id']));
        }

        if (count($vector) !== $vectorDim) {
            $vector = count($vector) > $vectorDim
                ? array_slice($vector, 0, $vectorDim)
                : array_pad($vector, $vectorDim, 0.0);
        }

        $this->qdrantClient->upsertUseCase($pointId, $vector, [
            'use_case_id' => $entry['id'],
            'short_description' => $entry['shortDescription'],
            'keywords' => $entry['keywords'],
            'embedding_model_id' => $modelId,
            'embedding_provider' => $modelInfo['provider'],
            'embedding_model' => $modelInfo['model'],
            'vector_dim' => $vectorDim,
            'source_hash' => $sourceHash,
            'indexed_at' => date(\DATE_ATOM),
        ]);

        return 'indexed';
    }

    /**
     * @param array{provider: ?string, model: ?string, model_id: ?int, vector_dim: int} $modelInfo
     *
     * @return array{provider?: string, model?: string, instruction?: string}
     */
    private function getEmbeddingOptions(array $modelInfo): array
    {
        $options = [];
        if ($modelInfo['provider']) {
            $options['provider'] = $modelInfo['provider'];
        }
        if ($modelInfo['model']) {
            $options['model'] = $modelInfo['model'];
            if (str_contains(strtolower($modelInfo['model']), 'qwen')) {
                $options['instruction'] = 'Represent this use case description for retrieval';
            }
        }

        return $options;
    }

    private function buildPointId(string $useCaseId): string
    {
        return 'usecase_'.$useCaseId;
    }
}

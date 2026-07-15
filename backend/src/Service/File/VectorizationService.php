<?php

namespace App\Service\File;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Vectorization Service.
 *
 * Converts text chunks into vector embeddings and stores them in the configured vector storage.
 * Uses configurable embedding models from BCONFIG/BMODELS tables.
 */
final readonly class VectorizationService
{
    private const VECTOR_DIMENSION = 1024;

    public function __construct(
        private AiFacade $aiFacade,
        private TextChunker $textChunker,
        private ModelConfigService $modelConfigService,
        private VectorStorageFacade $vectorStorage,
        private RateLimitService $rateLimitService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Vectorize file content and store in RAG database.
     *
     * @param string $fileText  Extracted text from file
     * @param int    $userId    User ID
     * @param int    $messageId Message ID
     * @param string $groupKey  Custom grouping key (e.g., 'PRODUCTHELP', 'DOWNLOADS')
     * @param int    $fileType  File type (0=text, 1=image, 2=audio/video, 3=pdf, 4=doc, etc.)
     *
     * @return array ['success' => bool, 'chunks_created' => int, 'error' => string|null, 'provider' => string]
     */
    public function vectorizeAndStore(
        string $fileText,
        int $userId,
        int $messageId,
        string $groupKey = 'DEFAULT',
        int $fileType = 0,
    ): array {
        if (empty($fileText)) {
            $this->logger->warning('VectorizationService: Empty text, skipping', [
                'user_id' => $userId,
                'message_id' => $messageId,
            ]);

            return [
                'success' => false,
                'chunks_created' => 0,
                'error' => 'Empty text',
                'provider' => $this->vectorStorage->getProviderName(),
            ];
        }

        try {
            // Get user's preferred embedding model (or system default)
            $embeddingModelId = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);

            if (!$embeddingModelId) {
                $this->logger->error('VectorizationService: No embedding model configured');

                return [
                    'success' => false,
                    'chunks_created' => 0,
                    'error' => 'No embedding model configured',
                    'provider' => $this->vectorStorage->getProviderName(),
                ];
            }

            // Get model details (name, provider)
            $model = $this->em->getRepository('App\Entity\Model')->find($embeddingModelId);
            if (!$model) {
                $this->logger->error('VectorizationService: Model not found', ['model_id' => $embeddingModelId]);

                return [
                    'success' => false,
                    'chunks_created' => 0,
                    'error' => 'Model not found',
                    'provider' => $this->vectorStorage->getProviderName(),
                ];
            }

            $modelName = $model->getProviderId(); // BPROVID contains the actual model name (e.g., 'bge-m3')
            $provider = strtolower($model->getService()); // BSERVICE contains provider name, normalize to lowercase (e.g., 'ollama')

            $this->logger->info('VectorizationService: Starting vectorization', [
                'user_id' => $userId,
                'message_id' => $messageId,
                'model_id' => $embeddingModelId,
                'model_name' => $modelName,
                'provider' => $provider,
                'group_key' => $groupKey,
                'text_length' => strlen($fileText),
                'storage_provider' => $this->vectorStorage->getProviderName(),
            ]);

            // Chunk the text
            $chunks = $this->textChunker->chunkify($fileText);

            if (empty($chunks)) {
                $this->logger->warning('VectorizationService: No chunks created');

                return [
                    'success' => false,
                    'chunks_created' => 0,
                    'error' => 'No chunks created',
                    'provider' => $this->vectorStorage->getProviderName(),
                ];
            }

            $chunkTexts = array_map(fn (array $c): string => $c['content'], $chunks);

            // Embed with a per-chunk fallback: if the batch call fails (e.g. a
            // remote Ollama returns HTTP 500 because one chunk produced a NaN
            // embedding) or returns invalid vectors, embed each chunk on its own
            // and skip only the bad ones — so a single problematic chunk no
            // longer drops the whole file to zero chunks.
            $embedResult = $this->embedChunksResilient($chunkTexts, $userId, $provider, $modelName);
            $embeddings = $embedResult['embeddings'];

            if ($embedResult['failed'] > 0) {
                $this->logger->warning('VectorizationService: some chunks could not be embedded and were skipped', [
                    'user_id' => $userId,
                    'message_id' => $messageId,
                    'failed' => $embedResult['failed'],
                    'total' => count($chunkTexts),
                ]);
            }

            $user = $this->em->getRepository(User::class)->find($userId);
            if ($user) {
                $this->rateLimitService->recordUsage($user, 'EMBEDDINGS', [
                    'usage' => $embedResult['usage'],
                    'provider' => $provider,
                    'model' => $modelName,
                    'model_id' => $embeddingModelId,
                    'input_bytes' => array_sum(array_map('strlen', $chunkTexts)),
                    'source' => 'VECTORIZATION',
                ]);
            }

            $vectorChunks = [];
            $chunksCreated = 0;

            foreach ($chunks as $index => $chunk) {
                $embedding = $embeddings[$index] ?? [];

                if (empty($embedding)) {
                    $this->logger->warning('VectorizationService: Empty embedding returned', [
                        'chunk_start' => $chunk['start_line'],
                    ]);
                    continue;
                }

                $embeddingLength = count($embedding);
                if (self::VECTOR_DIMENSION !== $embeddingLength) {
                    $this->logger->warning('VectorizationService: Embedding dimension mismatch', [
                        'expected' => self::VECTOR_DIMENSION,
                        'actual' => $embeddingLength,
                        'model' => $modelName,
                        'provider' => $provider,
                    ]);

                    if ($embeddingLength > self::VECTOR_DIMENSION) {
                        $embedding = array_slice($embedding, 0, self::VECTOR_DIMENSION);
                    } else {
                        $embedding = array_pad($embedding, self::VECTOR_DIMENSION, 0.0);
                    }
                }

                $vectorChunks[] = new VectorChunk(
                    userId: $userId,
                    fileId: $messageId,
                    groupKey: $groupKey,
                    fileType: $fileType,
                    chunkIndex: $index,
                    startLine: $chunk['start_line'],
                    endLine: $chunk['end_line'],
                    text: $chunk['content'],
                    vector: array_map('floatval', $embedding),
                    embeddingModelId: $embeddingModelId,
                    embeddingProvider: $provider,
                    embeddingModelName: $modelName,
                    vectorDim: self::VECTOR_DIMENSION,
                );

                ++$chunksCreated;
            }

            // Batch store chunks via facade
            if (!empty($vectorChunks)) {
                $this->vectorStorage->storeChunkBatch($vectorChunks);
            }

            // #1344: every chunk embedding can fail (continue above) while we still
            // reach this return. Reporting success:true with chunks_created:0 lets
            // describeVectorizeAndSort set BSTATUS=vectorized for a file with zero
            // BRAG/Qdrant rows — UI shows a success toast while the brain icon stays.
            // Treat "had chunks to embed but stored none" as failure so callers do
            // not mark the file vectorized.
            if (0 === $chunksCreated) {
                $this->logger->error('VectorizationService: no chunks embedded', [
                    'user_id' => $userId,
                    'message_id' => $messageId,
                    'chunk_count' => count($chunks),
                    'failed_embeddings' => $embedResult['failed'],
                ]);

                return [
                    'success' => false,
                    'chunks_created' => 0,
                    'error' => 'No chunks could be embedded (all embeddings empty or failed)',
                    'provider' => $this->vectorStorage->getProviderName(),
                ];
            }

            $this->logger->info('VectorizationService: Vectorization complete', [
                'user_id' => $userId,
                'message_id' => $messageId,
                'chunks_created' => $chunksCreated,
                'provider' => $this->vectorStorage->getProviderName(),
            ]);

            return [
                'success' => true,
                'chunks_created' => $chunksCreated,
                'error' => null,
                'provider' => $this->vectorStorage->getProviderName(),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('VectorizationService: Vectorization failed', [
                'user_id' => $userId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'chunks_created' => 0,
                'error' => $e->getMessage(),
                'provider' => $this->vectorStorage->getProviderName(),
            ];
        }
    }

    /**
     * Embed chunk texts resiliently.
     *
     * Tries one batch request first (fast path). If that throws (e.g. the
     * remote Ollama returns HTTP 500 because a chunk produced a NaN embedding)
     * or returns incomplete/invalid vectors, it falls back to embedding each
     * chunk individually and skips only the ones that fail — so a single bad
     * chunk no longer fails the whole file.
     *
     * @param array<int, string> $chunkTexts
     *
     * @return array{embeddings: array<int, array<float>>, usage: array{prompt_tokens: int, total_tokens: int}, failed: int}
     */
    private function embedChunksResilient(array $chunkTexts, int $userId, string $provider, string $modelName): array
    {
        $options = ['model' => $modelName, 'provider' => $provider];

        // Fast path: a single batch request.
        try {
            $batch = $this->aiFacade->embedBatch($chunkTexts, $userId, $provider, $options);
            $embeddings = $batch['embeddings'];

            if (count($embeddings) === count($chunkTexts) && !$this->hasInvalidVector($embeddings)) {
                return [
                    'embeddings' => $embeddings,
                    'usage' => $batch['usage'],
                    'failed' => 0,
                ];
            }

            $this->logger->warning('VectorizationService: batch embedding incomplete/invalid, falling back to per-chunk', [
                'expected' => count($chunkTexts),
                'returned' => count($embeddings),
                'provider' => $provider,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('VectorizationService: batch embedding failed, falling back to per-chunk', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback path: embed each chunk on its own, skipping failures.
        $embeddings = [];
        $usage = ['prompt_tokens' => 0, 'total_tokens' => 0];
        $failed = 0;

        foreach ($chunkTexts as $i => $text) {
            if ('' === trim($text)) {
                $embeddings[$i] = [];
                continue;
            }

            try {
                $result = $this->aiFacade->embed($text, $userId, $options);
                $vector = $result['embedding'];

                if (empty($vector) || $this->vectorHasInvalidValue($vector)) {
                    $embeddings[$i] = [];
                    ++$failed;
                    $this->logger->warning('VectorizationService: skipping chunk with invalid embedding', ['chunk_index' => $i]);
                    continue;
                }

                $embeddings[$i] = $vector;
                $usage['prompt_tokens'] += (int) $result['usage']['prompt_tokens'];
                $usage['total_tokens'] += (int) $result['usage']['total_tokens'];
            } catch (\Throwable $e) {
                $embeddings[$i] = [];
                ++$failed;
                $this->logger->warning('VectorizationService: chunk embedding failed, skipping', [
                    'chunk_index' => $i,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['embeddings' => $embeddings, 'usage' => $usage, 'failed' => $failed];
    }

    /**
     * True if any vector in the list is empty or contains a non-finite (NaN/Inf) value.
     *
     * @param array<int, array<float>> $vectors
     */
    private function hasInvalidVector(array $vectors): bool
    {
        foreach ($vectors as $vector) {
            if (empty($vector) || $this->vectorHasInvalidValue($vector)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if a single vector contains a non-finite (NaN/Inf) value.
     *
     * @param array<float> $vector
     */
    private function vectorHasInvalidValue(array $vector): bool
    {
        foreach ($vector as $value) {
            if (!is_finite((float) $value)) {
                return true;
            }
        }

        return false;
    }
}

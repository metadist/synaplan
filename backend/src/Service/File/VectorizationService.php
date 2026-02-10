<?php

namespace App\Service\File;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorStorage\DTO\VectorChunk;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Vectorization Service.
 *
 * Converts text chunks into vector embeddings and stores them in the configured vector storage.
 * Uses configurable embedding models from BCONFIG/BMODELS tables.
 */
class VectorizationService
{
    private const VECTOR_DIMENSION = 1024;

    public function __construct(
        private AiFacade $aiFacade,
        private TextChunker $textChunker,
        private ModelConfigService $modelConfigService,
        private VectorStorageFacade $vectorStorage,
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

            $vectorChunks = [];
            $chunksCreated = 0;

            foreach ($chunks as $index => $chunk) {
                try {
                    // Get embedding vector for this chunk with correct model
                    $embedding = $this->aiFacade->embed($chunk['content'], $userId, [
                        'model' => $modelName,
                        'provider' => $provider,
                    ]);

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
                    );

                    ++$chunksCreated;
                } catch (\Throwable $e) {
                    $errorMsg = sprintf(
                        'Chunk %d-%d failed: %s',
                        $chunk['start_line'],
                        $chunk['end_line'],
                        $e->getMessage()
                    );
                    $this->logger->error('VectorizationService: '.$errorMsg);
                    // Continue with next chunk
                }
            }

            // Batch store chunks via facade
            if (!empty($vectorChunks)) {
                $this->vectorStorage->storeChunkBatch($vectorChunks);
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
}

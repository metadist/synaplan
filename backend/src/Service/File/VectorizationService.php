<?php

namespace App\Service\File;

use App\AI\Service\AiFacade;
use App\Repository\RagDocumentRepository;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Vectorization Service.
 *
 * Converts text chunks into vector embeddings and stores them in the RAG database.
 * Uses configurable embedding models from BCONFIG/BMODELS tables.
 *
 * User can configure which model to use for vectorization:
 * - System default from BCONFIG (BOWNERID=0, BGROUP='DEFAULTMODEL', BSETTING='VECTORIZE')
 * - User override from BCONFIG (BOWNERID=userId, BGROUP='DEFAULTMODEL', BSETTING='VECTORIZE')
 */
class VectorizationService
{
    private const VECTOR_DIMENSION = 1024;

    public function __construct(
        private AiFacade $aiFacade,
        private TextChunker $textChunker,
        private ModelConfigService $modelConfigService,
        private RagDocumentRepository $ragRepository,
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
     * @return array ['success' => bool, 'chunks_created' => int, 'error' => string|null]
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

            return ['success' => false, 'chunks_created' => 0, 'error' => 'Empty text'];
        }

        try {
            // Get user's preferred embedding model (or system default)
            $embeddingModelId = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);

            if (!$embeddingModelId) {
                $this->logger->error('VectorizationService: No embedding model configured');

                return ['success' => false, 'chunks_created' => 0, 'error' => 'No embedding model configured'];
            }

            // Get model details (name, provider)
            $model = $this->em->getRepository('App\Entity\Model')->find($embeddingModelId);
            if (!$model) {
                $this->logger->error('VectorizationService: Model not found', ['model_id' => $embeddingModelId]);

                return ['success' => false, 'chunks_created' => 0, 'error' => 'Model not found'];
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
            ]);

            // Chunk the text
            $chunks = $this->textChunker->chunkify($fileText);

            if (empty($chunks)) {
                $this->logger->warning('VectorizationService: No chunks created');

                return ['success' => false, 'chunks_created' => 0, 'error' => 'No chunks created'];
            }

            $chunksCreated = 0;

            foreach ($chunks as $chunk) {
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

                    // MariaDB VECTOR Type: Use native SQL with VEC_FromText()
                    // Doctrine doesn't handle VECTOR type correctly with prepared statements
                    $vectorStr = '['.implode(',', array_map('floatval', $embedding)).']';

                    $conn = $this->em->getConnection();
                    $sql = 'INSERT INTO BRAG (BUID, BMID, BGROUPKEY, BTYPE, BSTART, BEND, BTEXT, BEMBED, BCREATED)
                            VALUES (:uid, :mid, :gkey, :ftype, :start, :end, :text, VEC_FromText(:vec), :created)';

                    $conn->executeStatement($sql, [
                        'uid' => $userId,
                        'mid' => $messageId,
                        'gkey' => $groupKey,
                        'ftype' => $fileType,
                        'start' => $chunk['start_line'],
                        'end' => $chunk['end_line'],
                        'text' => $chunk['content'],
                        'vec' => $vectorStr,
                        'created' => time(),
                    ]);

                    ++$chunksCreated;
                } catch (\Throwable $e) {
                    $errorMsg = sprintf(
                        'Chunk %d-%d failed: %s | SQL: %s | Vec Length: %d',
                        $chunk['start_line'],
                        $chunk['end_line'],
                        $e->getMessage(),
                        substr($sql ?? 'N/A', 0, 100),
                        count($embedding ?? [])
                    );
                    $this->logger->error('VectorizationService: '.$errorMsg);
                    error_log('VECTORIZATION ERROR: '.$errorMsg); // Force stderr
                    // Continue with next chunk
                }
            }

            // Note: We use native SQL for VECTOR inserts, so no flush needed
            // $this->em->flush(); // Not needed with native SQL

            $this->logger->info('VectorizationService: Vectorization complete', [
                'user_id' => $userId,
                'message_id' => $messageId,
                'chunks_created' => $chunksCreated,
            ]);

            return [
                'success' => true,
                'chunks_created' => $chunksCreated,
                'error' => null,
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
            ];
        }
    }

    /**
     * Search for similar content using vector similarity.
     *
     * @param string      $query    Search query
     * @param int         $userId   User ID
     * @param string|null $groupKey Optional filter by group key
     * @param int         $limit    Max results to return
     *
     * @return array Array of similar documents
     */
    public function search(string $query, int $userId, ?string $groupKey = null, int $limit = 5): array
    {
        try {
            // Get embedding for the query
            $queryEmbedding = $this->aiFacade->embed($query, $userId);

            if (empty($queryEmbedding)) {
                $this->logger->warning('VectorizationService: Empty query embedding');

                return [];
            }

            // Search in RAG database
            return $this->ragRepository->findSimilar($queryEmbedding, $userId, $groupKey, $limit);
        } catch (\Throwable $e) {
            $this->logger->error('VectorizationService: Search failed', [
                'query' => $query,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}

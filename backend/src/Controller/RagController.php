<?php

namespace App\Controller;

use App\Service\RAG\VectorSearchService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

#[Route('/api/v1/rag', name: 'api_rag_')]
#[OA\Tag(name: 'RAG (Retrieval-Augmented Generation)')]
class RagController extends AbstractController
{
    public function __construct(
        private VectorSearchService $vectorSearchService,
        private LoggerInterface $logger
    ) {}

    #[Route('/search', name: 'search', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/rag/search',
        summary: 'Semantic search in vectorized documents',
        description: 'Search for relevant document chunks using vector similarity',
        security: [['Bearer' => []]],
        tags: ['RAG (Retrieval-Augmented Generation)']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['query'],
            properties: [
                new OA\Property(property: 'query', type: 'string', example: 'What is machine learning?'),
                new OA\Property(property: 'limit', type: 'integer', example: 10, minimum: 1, maximum: 50),
                new OA\Property(property: 'min_score', type: 'number', format: 'float', example: 0.7, minimum: 0, maximum: 1),
                new OA\Property(property: 'group_key', type: 'string', nullable: true, example: 'document_123')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Search results with relevant document chunks',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'results',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'text', type: 'string'),
                            new OA\Property(property: 'score', type: 'number', format: 'float'),
                            new OA\Property(property: 'file_id', type: 'integer'),
                            new OA\Property(property: 'file_name', type: 'string'),
                            new OA\Property(property: 'group_key', type: 'string', nullable: true)
                        ]
                    )
                ),
                new OA\Property(property: 'query', type: 'string'),
                new OA\Property(property: 'total_results', type: 'integer')
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 400, description: 'Invalid request (missing query)')]
    public function search(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['query']) || empty(trim($data['query']))) {
            return $this->json(['error' => 'Query is required'], Response::HTTP_BAD_REQUEST);
        }

        $query = trim($data['query']);
        $limit = min(50, max(1, (int)($data['limit'] ?? 10)));
        $minScore = max(0, min(1, (float)($data['min_score'] ?? 0.3)));
        $groupKey = $data['group_key'] ?? null;

        $this->logger->info('RAG Search request', [
            'user_id' => $user->getId(),
            'query' => substr($query, 0, 100),
            'limit' => $limit,
            'min_score' => $minScore,
            'group_key' => $groupKey
        ]);

        try {
            $startTime = microtime(true);
            
            $results = $this->vectorSearchService->semanticSearch(
                $query,
                $user->getId(),
                $groupKey,
                $limit,
                $minScore
            );

            $searchTime = (int)((microtime(true) - $startTime) * 1000);

            return $this->json([
                'success' => true,
                'query' => $query,
                'results' => array_map(fn($r) => [
                    'chunk_id' => $r['chunk_id'],
                    'message_id' => $r['message_id'],
                    'text' => $r['chunk_text'],
                    'score' => $r['distance'],
                    'start_line' => $r['start_line'] ?? null,
                    'end_line' => $r['end_line'] ?? null
                ], $results),
                'total_results' => count($results),
                'search_time_ms' => $searchTime,
                'parameters' => [
                    'limit' => $limit,
                    'min_score' => $minScore,
                    'group_key' => $groupKey
                ]
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('RAG Search failed', [
                'user_id' => $user->getId(),
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Search failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Find similar documents based on a specific chunk
     * 
     * GET /api/v1/rag/similar/{chunkId}
     */
    #[Route('/similar/{chunkId}', name: 'similar', methods: ['GET'])]
    public function findSimilar(
        int $chunkId,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $limit = min(50, max(1, (int)$request->query->get('limit', 10)));

        try {
            $results = $this->vectorSearchService->findSimilar(
                $chunkId,
                $user->getId(),
                $limit
            );

            return $this->json([
                'success' => true,
                'source_chunk_id' => $chunkId,
                'results' => array_map(fn($r) => [
                    'chunk_id' => $r['chunk_id'],
                    'message_id' => $r['message_id'],
                    'text' => $r['chunk_text'],
                    'score' => $r['distance']
                ], $results),
                'total_results' => count($results)
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Find similar failed', [
                'user_id' => $user->getId(),
                'chunk_id' => $chunkId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Search failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get RAG statistics
     * 
     * GET /api/v1/rag/stats
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $stats = $this->vectorSearchService->getUserStats($user->getId());

            return $this->json([
                'success' => true,
                'stats' => $stats
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('RAG stats failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to get stats'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}


<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PromptRepository;
use App\Service\Message\SynapseIndexer;
use App\Service\Message\SynapseRouter;
use App\Service\Message\TopicAliasResolver;
use App\Service\VectorSearch\QdrantClientInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin endpoints for the Synapse Routing system.
 *
 * Exposes:
 *   GET  /api/v1/admin/synapse/status   ─ overall index health, per-model counts, dim warning
 *   POST /api/v1/admin/synapse/reindex  ─ trigger sync re-index (with optional --force / --recreate)
 *   POST /api/v1/admin/synapse/dry-run  ─ test routing for a sample message text
 *
 * SECURITY: All endpoints require ROLE_ADMIN (enforced at class level).
 */
#[Route('/api/v1/admin/synapse')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin Synapse')]
final class AdminSynapseController extends AbstractController
{
    public function __construct(
        private readonly SynapseIndexer $synapseIndexer,
        private readonly SynapseRouter $synapseRouter,
        private readonly QdrantClientInterface $qdrantClient,
        private readonly PromptRepository $promptRepository,
        private readonly TopicAliasResolver $topicAliasResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Status snapshot: collection info, per-model counts, stale entries, dimension warning.
     */
    #[Route('/status', name: 'admin_synapse_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/synapse/status',
        summary: 'Synapse Routing health & index status (admin only)',
        description: 'Returns the active embedding model, Qdrant collection info, per-model index counts and a stale-warning when the indexed model differs from the active model.',
        security: [['Bearer' => []]],
        tags: ['Admin Synapse']
    )]
    #[OA\Response(
        response: 200,
        description: 'Synapse status snapshot',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(
                    property: 'activeModel',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'provider', type: 'string', nullable: true),
                        new OA\Property(property: 'model', type: 'string', nullable: true),
                        new OA\Property(property: 'modelId', type: 'integer', nullable: true),
                        new OA\Property(property: 'vectorDim', type: 'integer'),
                    ]
                ),
                new OA\Property(
                    property: 'collection',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'exists', type: 'boolean'),
                        new OA\Property(property: 'vectorDim', type: 'integer', nullable: true),
                        new OA\Property(property: 'pointsCount', type: 'integer', nullable: true),
                        new OA\Property(property: 'distance', type: 'string', nullable: true),
                    ]
                ),
                new OA\Property(property: 'totalIndexed', type: 'integer'),
                new OA\Property(property: 'staleCount', type: 'integer'),
                new OA\Property(property: 'dimensionMismatch', type: 'boolean'),
                new OA\Property(
                    property: 'perModel',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'modelId', type: 'integer', nullable: true),
                            new OA\Property(property: 'provider', type: 'string', nullable: true),
                            new OA\Property(property: 'model', type: 'string', nullable: true),
                            new OA\Property(property: 'vectorDim', type: 'integer', nullable: true),
                            new OA\Property(property: 'count', type: 'integer'),
                        ]
                    )
                ),
                new OA\Property(
                    property: 'topics',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'topic', type: 'string'),
                            new OA\Property(property: 'ownerId', type: 'integer'),
                            new OA\Property(property: 'enabled', type: 'boolean'),
                            new OA\Property(property: 'indexed', type: 'boolean'),
                            new OA\Property(property: 'stale', type: 'boolean'),
                            new OA\Property(property: 'embeddingModelId', type: 'integer', nullable: true),
                            new OA\Property(property: 'embeddingProvider', type: 'string', nullable: true),
                            new OA\Property(property: 'embeddingModel', type: 'string', nullable: true),
                            new OA\Property(property: 'vectorDim', type: 'integer', nullable: true),
                            new OA\Property(property: 'indexedAt', type: 'string', nullable: true),
                        ]
                    )
                ),
                new OA\Property(property: 'aliases', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function status(): JsonResponse
    {
        $modelInfo = $this->synapseIndexer->getEmbeddingModelInfo();
        $collectionInfo = $this->qdrantClient->getSynapseCollectionInfo();
        $points = $this->qdrantClient->scrollSynapseTopics(null, 5000);

        $perModel = [];
        $staleCount = 0;
        $indexedByPointId = [];
        $currentModelId = $modelInfo['model_id'];

        foreach ($points as $point) {
            $payload = $point['payload'];
            $key = sprintf(
                '%s|%s|%s',
                (string) ($payload['embedding_model_id'] ?? ''),
                (string) ($payload['embedding_provider'] ?? ''),
                (string) ($payload['embedding_model'] ?? ''),
            );

            if (!isset($perModel[$key])) {
                $perModel[$key] = [
                    'modelId' => isset($payload['embedding_model_id']) ? (int) $payload['embedding_model_id'] : null,
                    'provider' => $payload['embedding_provider'] ?? null,
                    'model' => $payload['embedding_model'] ?? null,
                    'vectorDim' => isset($payload['vector_dim']) ? (int) $payload['vector_dim'] : null,
                    'count' => 0,
                ];
            }
            ++$perModel[$key]['count'];

            $indexedModelId = $payload['embedding_model_id'] ?? null;
            if (null !== $indexedModelId && null !== $currentModelId && (int) $indexedModelId !== (int) $currentModelId) {
                ++$staleCount;
            }

            $indexedByPointId[$point['id']] = $payload;
        }

        $dimensionMismatch = false;
        if (null !== $collectionInfo['vector_dim']) {
            $dimensionMismatch = (int) $collectionInfo['vector_dim'] !== $modelInfo['vector_dim'];
        }

        $prompts = $this->promptRepository->findAllForUser(0, 'en', excludeDisabled: false);
        $topics = [];
        foreach ($prompts as $prompt) {
            $pointId = sprintf('synapse_%d_%s', $prompt->getOwnerId(), $prompt->getTopic());
            $payload = $indexedByPointId[$pointId] ?? null;
            $topics[] = [
                'topic' => $prompt->getTopic(),
                'ownerId' => $prompt->getOwnerId(),
                'enabled' => $prompt->isEnabled(),
                'indexed' => null !== $payload,
                'stale' => null !== $payload && null !== ($payload['embedding_model_id'] ?? null) && null !== $currentModelId
                    && (int) $payload['embedding_model_id'] !== (int) $currentModelId,
                'embeddingModelId' => isset($payload['embedding_model_id']) ? (int) $payload['embedding_model_id'] : null,
                'embeddingProvider' => $payload['embedding_provider'] ?? null,
                'embeddingModel' => $payload['embedding_model'] ?? null,
                'vectorDim' => isset($payload['vector_dim']) ? (int) $payload['vector_dim'] : null,
                'indexedAt' => $payload['indexed_at'] ?? null,
            ];
        }

        return $this->json([
            'success' => true,
            'activeModel' => [
                'provider' => $modelInfo['provider'],
                'model' => $modelInfo['model'],
                'modelId' => $modelInfo['model_id'],
                'vectorDim' => $modelInfo['vector_dim'],
            ],
            'collection' => [
                'name' => $this->qdrantClient->getSynapseCollection(),
                'exists' => $collectionInfo['exists'],
                'vectorDim' => $collectionInfo['vector_dim'],
                'pointsCount' => $collectionInfo['points_count'],
                'distance' => $collectionInfo['distance'],
            ],
            'totalIndexed' => count($points),
            'staleCount' => $staleCount,
            'dimensionMismatch' => $dimensionMismatch,
            'perModel' => array_values($perModel),
            'topics' => $topics,
            'aliases' => $this->topicAliasResolver->getAliasMap(),
        ]);
    }

    /**
     * Trigger a synchronous re-index of the synapse_topics collection.
     */
    #[Route('/reindex', name: 'admin_synapse_reindex', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/synapse/reindex',
        summary: 'Re-index synapse topics (admin only)',
        description: 'Synchronous re-indexing. With force=true the source-hash skip is bypassed. With recreate=true the Qdrant collection is dropped and recreated with the active model dimension first.',
        security: [['Bearer' => []]],
        tags: ['Admin Synapse'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'force', type: 'boolean', example: false, description: 'Bypass the source-hash skip-when-unchanged optimisation.'),
                    new OA\Property(property: 'recreate', type: 'boolean', example: false, description: 'Drop and recreate the Qdrant collection (needed when switching to a model with a different vector dimension).'),
                    new OA\Property(property: 'topic', type: 'string', nullable: true, example: 'coding', description: 'Optional: re-index only a single topic.'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Re-index result',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'recreated', type: 'boolean'),
                new OA\Property(property: 'force', type: 'boolean'),
                new OA\Property(property: 'indexed', type: 'integer'),
                new OA\Property(property: 'skipped', type: 'integer'),
                new OA\Property(property: 'errors', type: 'integer'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function reindex(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $force = (bool) ($body['force'] ?? false);
        $recreate = (bool) ($body['recreate'] ?? false);
        $topic = isset($body['topic']) && is_string($body['topic']) ? trim($body['topic']) : null;

        if ($recreate) {
            $modelInfo = $this->synapseIndexer->getEmbeddingModelInfo();
            $this->qdrantClient->recreateSynapseCollection($modelInfo['vector_dim']);
            // recreate implies force: every point must be re-uploaded
            $force = true;
        }

        $this->logger->info('Admin: synapse reindex triggered', [
            'force' => $force,
            'recreate' => $recreate,
            'topic' => $topic,
        ]);

        if (null !== $topic && '' !== $topic) {
            $result = $this->synapseIndexer->indexTopic($topic, 0, $force);

            return $this->json([
                'success' => 'missing' !== $result,
                'recreated' => $recreate,
                'force' => $force,
                'indexed' => 'indexed' === $result ? 1 : 0,
                'skipped' => 'skipped' === $result ? 1 : 0,
                'errors' => 0,
                'topic' => $topic,
                'topicResult' => $result,
            ]);
        }

        $result = $this->synapseIndexer->indexAllTopics(null, $force);

        return $this->json([
            'success' => true,
            'recreated' => $recreate,
            'force' => $force,
            'indexed' => $result['indexed'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Dry-run the SynapseRouter for a sample message.
     */
    #[Route('/dry-run', name: 'admin_synapse_dry_run', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/synapse/dry-run',
        summary: 'Dry-run Synapse Routing for a sample message (admin only)',
        description: 'Embeds the provided text and returns the Top-K Qdrant matches with stale-flag and alias resolution. No state mutation.',
        security: [['Bearer' => []]],
        tags: ['Admin Synapse'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['text'],
                properties: [
                    new OA\Property(property: 'text', type: 'string'),
                    new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 20, example: 5),
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Dry-run result', content: new OA\JsonContent(type: 'object'))]
    #[OA\Response(response: 400, description: 'Missing text field')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function dryRun(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body) || empty($body['text']) || !is_string($body['text'])) {
            return $this->json(['error' => 'Missing required field: text'], Response::HTTP_BAD_REQUEST);
        }

        $text = trim($body['text']);
        $limit = isset($body['limit']) ? max(1, min(20, (int) $body['limit'])) : 5;

        $user = $this->getUser();
        $userId = method_exists($user, 'getId') ? $user->getId() : null;

        $result = $this->synapseRouter->dryRun($text, $userId, $limit);

        return $this->json(['success' => null === $result['error']] + $result);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Plug\PlugConfigService;
use App\Plug\Rerank\RerankAdminService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/plugs')]
#[OA\Tag(name: 'Admin Plugs Rerank')]
final class AdminPlugsRerankController extends AbstractController
{
    public function __construct(
        private readonly RerankAdminService $rerankAdmin,
    ) {
    }

    #[Route('/rerank', name: 'admin_plugs_rerank_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/plugs/rerank',
        summary: 'Rerank settings, catalog models and last eval numbers',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Rerank']
    )]
    #[OA\Response(
        response: 200,
        description: 'Current rerank configuration',
        content: new OA\JsonContent(
            required: ['enabled', 'modelKey', 'multiplier', 'budgetMs', 'llmFallback', 'adapters', 'models', 'keys', 'lastEval'],
            properties: [
                new OA\Property(property: 'enabled', type: 'boolean', example: false),
                new OA\Property(property: 'modelKey', type: 'string', nullable: true, example: null),
                new OA\Property(property: 'multiplier', type: 'integer', example: 4),
                new OA\Property(property: 'budgetMs', type: 'integer', example: 800),
                new OA\Property(property: 'llmFallback', type: 'boolean', example: false),
                new OA\Property(
                    property: 'adapters',
                    type: 'array',
                    items: new OA\Items(
                        required: ['key', 'label', 'health'],
                        properties: [
                            new OA\Property(property: 'key', type: 'string', example: 'http'),
                            new OA\Property(property: 'label', type: 'string'),
                            new OA\Property(
                                property: 'health',
                                required: ['available', 'reason'],
                                properties: [
                                    new OA\Property(property: 'available', type: 'boolean'),
                                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                                ],
                                type: 'object'
                            ),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(
                    property: 'models',
                    type: 'array',
                    items: new OA\Items(
                        required: ['key', 'label', 'available', 'reason'],
                        properties: [
                            new OA\Property(property: 'key', type: 'string', example: 'jina:jina-reranker-v2-base-multilingual:rerank'),
                            new OA\Property(property: 'label', type: 'string'),
                            new OA\Property(property: 'available', type: 'boolean'),
                            new OA\Property(property: 'reason', type: 'string', nullable: true),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(
                    property: 'keys',
                    required: ['jina', 'cohere', 'voyage'],
                    properties: [
                        new OA\Property(
                            property: 'jina',
                            required: ['configured', 'source', 'origin', 'maskedKey'],
                            properties: [
                                new OA\Property(property: 'configured', type: 'boolean'),
                                new OA\Property(property: 'source', type: 'string'),
                                new OA\Property(property: 'origin', type: 'string', nullable: true),
                                new OA\Property(property: 'maskedKey', type: 'string'),
                            ],
                            type: 'object'
                        ),
                        new OA\Property(
                            property: 'cohere',
                            required: ['configured', 'source', 'origin', 'maskedKey'],
                            properties: [
                                new OA\Property(property: 'configured', type: 'boolean'),
                                new OA\Property(property: 'source', type: 'string'),
                                new OA\Property(property: 'origin', type: 'string', nullable: true),
                                new OA\Property(property: 'maskedKey', type: 'string'),
                            ],
                            type: 'object'
                        ),
                        new OA\Property(
                            property: 'voyage',
                            required: ['configured', 'source', 'origin', 'maskedKey'],
                            properties: [
                                new OA\Property(property: 'configured', type: 'boolean'),
                                new OA\Property(property: 'source', type: 'string'),
                                new OA\Property(property: 'origin', type: 'string', nullable: true),
                                new OA\Property(property: 'maskedKey', type: 'string'),
                            ],
                            type: 'object'
                        ),
                    ],
                    type: 'object'
                ),
                new OA\Property(
                    property: 'lastEval',
                    nullable: true,
                    required: ['date', 'recallOff', 'recallOn', 'p95Off', 'p95On'],
                    properties: [
                        new OA\Property(property: 'date', type: 'string', example: '2026-09-09'),
                        new OA\Property(property: 'recallOff', type: 'number'),
                        new OA\Property(property: 'recallOn', type: 'number'),
                        new OA\Property(property: 'p95Off', type: 'number'),
                        new OA\Property(property: 'p95On', type: 'number'),
                    ],
                    type: 'object'
                ),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        return $this->json($this->rerankAdmin->status());
    }

    #[Route('/rerank', name: 'admin_plugs_rerank_save', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/admin/plugs/rerank',
        summary: 'Enable rerank, bind a catalog model, set multiplier and budget',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Rerank']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['enabled', 'multiplier', 'budgetMs', 'llmFallback'],
            properties: [
                new OA\Property(property: 'enabled', type: 'boolean', example: false),
                new OA\Property(property: 'modelKey', type: 'string', nullable: true, example: 'jina:jina-reranker-v2-base-multilingual:rerank'),
                new OA\Property(property: 'multiplier', type: 'integer', example: 4),
                new OA\Property(property: 'budgetMs', type: 'integer', example: 800),
                new OA\Property(property: 'llmFallback', type: 'boolean', example: false),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 200, description: 'Updated rerank configuration')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 422, description: 'Unbound model or invalid range')]
    public function save(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\array_key_exists('enabled', $data)) {
            return $this->json(['error' => 'enabled is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->rerankAdmin->save(
                (bool) $data['enabled'],
                \is_string($data['modelKey'] ?? null) ? $data['modelKey'] : null,
                is_numeric($data['multiplier'] ?? null) ? (int) $data['multiplier'] : PlugConfigService::DEFAULT_RERANK_MULTIPLIER,
                is_numeric($data['budgetMs'] ?? null) ? (int) $data['budgetMs'] : PlugConfigService::DEFAULT_RERANK_LATENCY_MS,
                (bool) ($data['llmFallback'] ?? false),
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/rerank/test', name: 'admin_plugs_rerank_test', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/plugs/rerank/test',
        summary: 'Rerank a short query against up to 20 sample documents',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Rerank']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['query', 'documents'],
            properties: [
                new OA\Property(property: 'query', type: 'string', example: 'invoice total'),
                new OA\Property(
                    property: 'documents',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    example: ['The invoice total is 120 euro.', 'Office hours are 9 to 5.']
                ),
                new OA\Property(property: 'modelKey', type: 'string', nullable: true),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Ordered document indexes',
        content: new OA\JsonContent(
            required: ['ordered', 'provider', 'ms', 'error'],
            properties: [
                new OA\Property(
                    property: 'ordered',
                    type: 'array',
                    items: new OA\Items(
                        required: ['index', 'score'],
                        properties: [
                            new OA\Property(property: 'index', type: 'integer'),
                            new OA\Property(property: 'score', type: 'number'),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(property: 'provider', type: 'string', example: 'http:jina'),
                new OA\Property(property: 'ms', type: 'integer', example: 42),
                new OA\Property(property: 'error', type: 'string', nullable: true),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Missing query or documents')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function test(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\is_string($data['query'] ?? null) || !\is_array($data['documents'] ?? null)) {
            return $this->json(['error' => 'query and documents are required'], Response::HTTP_BAD_REQUEST);
        }

        $documents = [];
        foreach ($data['documents'] as $row) {
            if (\is_string($row)) {
                $documents[] = $row;
            }
        }

        try {
            return $this->json($this->rerankAdmin->test(
                $data['query'],
                $documents,
                \is_string($data['modelKey'] ?? null) ? $data['modelKey'] : null,
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    private function requireAdmin(?User $user): ?JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}

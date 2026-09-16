<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Import\ModelImportService;
use App\AI\Import\UnknownImportSourceException;
use App\Entity\User;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Import models an OpenAI-compatible endpoint or the local Ollama already
 * offers, instead of typing each row by hand. Distinct from the SQL
 * "import from a pricing page" flow on {@see AdminModelsController} — hence the
 * `/import/endpoint` sub-path.
 */
#[Route('/api/v1/admin/models/import/endpoint')]
#[OA\Tag(name: 'Admin Models Import')]
final class AdminModelsImportEndpointController extends AbstractController
{
    public function __construct(
        private readonly ModelImportService $importService,
    ) {
    }

    #[Route('/preview', name: 'admin_models_import_endpoint_preview', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/models/import/endpoint/preview',
        summary: 'Discover models offered by an endpoint with guessed tags',
        security: [['Bearer' => []]],
        tags: ['Admin Models Import']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['source'],
            properties: [
                new OA\Property(property: 'source', type: 'string', example: 'openai_compatible:vllm-lab'),
                new OA\Property(property: 'probe', type: 'boolean', example: false),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Discovered models',
        content: new OA\JsonContent(
            required: ['source', 'rows', 'endpointOk', 'error', 'probeCostNote'],
            properties: [
                new OA\Property(property: 'source', type: 'string', example: 'openai_compatible:vllm-lab'),
                new OA\Property(
                    property: 'rows',
                    type: 'array',
                    items: new OA\Items(
                        required: ['providerId', 'name', 'guessedTags', 'exists', 'sizeBytes', 'family', 'probe'],
                        properties: [
                            new OA\Property(property: 'providerId', type: 'string', example: 'Qwen/Qwen3-32B'),
                            new OA\Property(property: 'name', type: 'string', example: 'Qwen3 32B'),
                            new OA\Property(property: 'guessedTags', type: 'array', items: new OA\Items(type: 'string'), example: ['chat']),
                            new OA\Property(property: 'exists', type: 'boolean', example: false),
                            new OA\Property(property: 'sizeBytes', type: 'integer', nullable: true, example: null),
                            new OA\Property(property: 'family', type: 'string', nullable: true, example: null),
                            new OA\Property(
                                property: 'probe',
                                nullable: true,
                                required: ['chat', 'embeddings', 'ms'],
                                properties: [
                                    new OA\Property(property: 'chat', type: 'string', example: 'ok'),
                                    new OA\Property(property: 'embeddings', type: 'string', example: 'fail'),
                                    new OA\Property(property: 'ms', type: 'integer', example: 42),
                                ],
                                type: 'object'
                            ),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(property: 'endpointOk', type: 'boolean', example: true),
                new OA\Property(property: 'error', type: 'string', nullable: true, example: null),
                new OA\Property(property: 'probeCostNote', type: 'string', nullable: true, example: null),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Missing source')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'Unknown endpoint or source')]
    public function preview(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_string($data['source'] ?? null) || '' === trim($data['source'])) {
            return $this->json(['error' => 'source is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->importService->preview($data['source'], (bool) ($data['probe'] ?? false)));
        } catch (UnknownImportSourceException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/apply', name: 'admin_models_import_endpoint_apply', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/models/import/endpoint/apply',
        summary: 'Create catalog rows for the selected discovered models',
        security: [['Bearer' => []]],
        tags: ['Admin Models Import']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['source', 'rows'],
            properties: [
                new OA\Property(property: 'source', type: 'string', example: 'openai_compatible:vllm-lab'),
                new OA\Property(
                    property: 'rows',
                    type: 'array',
                    items: new OA\Items(
                        required: ['providerId', 'tags'],
                        properties: [
                            new OA\Property(property: 'providerId', type: 'string', example: 'Qwen/Qwen3-32B'),
                            new OA\Property(property: 'name', type: 'string', example: 'Qwen3 32B'),
                            new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'), example: ['chat', 'pic2text']),
                        ],
                        type: 'object'
                    )
                ),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Applied result',
        content: new OA\JsonContent(
            required: ['created', 'skipped', 'rows'],
            properties: [
                new OA\Property(property: 'created', type: 'integer', example: 12),
                new OA\Property(property: 'skipped', type: 'integer', example: 3),
                new OA\Property(
                    property: 'rows',
                    type: 'array',
                    items: new OA\Items(
                        required: ['providerId', 'tag', 'status'],
                        properties: [
                            new OA\Property(property: 'providerId', type: 'string', example: 'Qwen/Qwen3-32B'),
                            new OA\Property(property: 'tag', type: 'string', example: 'chat'),
                            new OA\Property(property: 'status', type: 'string', example: 'created'),
                        ],
                        type: 'object'
                    )
                ),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Missing source or rows')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'Unknown source')]
    public function apply(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_string($data['source'] ?? null) || !is_array($data['rows'] ?? null)) {
            return $this->json(['error' => 'source and rows are required'], Response::HTTP_BAD_REQUEST);
        }

        $rows = [];
        foreach ($data['rows'] as $row) {
            if (!is_array($row) || !is_string($row['providerId'] ?? null) || !is_array($row['tags'] ?? null)) {
                continue;
            }
            $tags = array_values(array_filter($row['tags'], static fn ($t): bool => is_string($t)));
            $rows[] = [
                'providerId' => $row['providerId'],
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'tags' => $tags,
            ];
        }

        try {
            return $this->json($this->importService->apply($data['source'], $rows));
        } catch (UnknownImportSourceException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
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

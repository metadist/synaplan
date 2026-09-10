<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/tools', name: 'api_tools_')]
#[OA\Tag(name: 'Tools')]
final class ToolsController extends AbstractController
{
    public function __construct(
        private ToolRegistry $registry,
        private ToolsConfig $toolsConfig,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/tools',
        summary: 'List tools the current user can call',
        tags: ['Tools'],
        parameters: [
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'custom')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Registry descriptors',
                content: new OA\JsonContent(
                    required: ['success', 'tools'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'tools', type: 'array', items: new OA\Items(
                            type: 'object',
                            required: ['name', 'title', 'description', 'sideEffect', 'source', 'policy'],
                            properties: [
                                new OA\Property(property: 'name', type: 'string', example: 'web_search'),
                                new OA\Property(property: 'title', type: 'string', example: 'Web search'),
                                new OA\Property(property: 'description', type: 'string'),
                                new OA\Property(property: 'sideEffect', type: 'string', enum: ['read', 'write', 'destructive']),
                                new OA\Property(property: 'source', type: 'string', enum: ['builtin', 'mcp', 'document', 'skill', 'plugin', 'custom']),
                                new OA\Property(property: 'policy', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'policyException', type: 'string', nullable: true, example: 'own_artefact'),
                                new OA\Property(property: 'shared', type: 'boolean', example: false),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Registry disabled'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $userId = (int) $user->getId();
        if (!$this->toolsConfig->isRegistryEnabled($userId)) {
            return $this->json(['success' => false, 'error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $sourceFilter = $request->query->get('source');
        $sourceFilter = is_string($sourceFilter) && '' !== $sourceFilter ? $sourceFilter : null;
        $tools = [];
        foreach ($this->registry->forUser($userId) as $descriptor) {
            if (null !== $sourceFilter && $descriptor->source->value !== $sourceFilter) {
                continue;
            }
            $tools[] = $descriptor->toListItem(null);
        }

        return $this->json(['success' => true, 'tools' => $tools]);
    }
}

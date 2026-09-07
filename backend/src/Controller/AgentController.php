<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentSerializer;
use App\Service\Agent\AgentService;
use App\Service\Agent\Exception\AgentDefinitionException;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Agent\Exception\AgentNotDraftException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/agents', name: 'api_agents_')]
#[OA\Tag(name: 'Agents')]
final class AgentController extends AbstractController
{
    public function __construct(
        private AgentConfig $config,
        private AgentService $service,
        private AgentSerializer $serializer,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/agents',
        summary: 'List the current user\'s assistants',
        description: 'Returns the owner\'s assistants. The list never includes the draft JSON. Returns 404 when AGENTS.ENABLED is off.',
        tags: ['Agents'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Assistant list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'agents', type: 'array', items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'slug', type: 'string', example: 'contract-review'),
                                new OA\Property(property: 'name', type: 'string', example: 'Contract review'),
                                new OA\Property(property: 'description', type: 'string', nullable: true),
                                new OA\Property(property: 'icon', type: 'string', example: ''),
                                new OA\Property(property: 'status', type: 'string', example: 'draft'),
                                new OA\Property(property: 'updatedAt', type: 'integer', example: 1757232000),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $agents = array_map(
            fn ($agent) => $this->serializer->summary($agent),
            $this->service->listOwned((int) $user->getId()),
        );

        return $this->json(['success' => true, 'agents' => $agents]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/agents',
        summary: 'Create an assistant draft',
        description: 'Creates an owner-only draft. When promptId is omitted a BPROMPTS row with topic agent:{slug} is created. When draft is omitted an empty-but-valid agent.v1 document is stored.',
        tags: ['Agents'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Contract review'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Reviews NDAs against our checklist'),
                    new OA\Property(property: 'icon', type: 'string', example: ''),
                    new OA\Property(property: 'promptId', type: 'integer', nullable: true, example: 12),
                    new OA\Property(
                        property: 'draft',
                        type: 'object',
                        description: 'Full agent.v1 document. Unknown keys are rejected.',
                        properties: [
                            new OA\Property(property: 'schema', type: 'string', example: 'agent.v1'),
                            new OA\Property(property: 'models', type: 'object', example: ['chat' => 'anthropic:claude-sonnet-4:chat', 'vision' => null, 'vectorize' => null]),
                            new OA\Property(property: 'knowledge', type: 'object'),
                            new OA\Property(property: 'tools', type: 'object'),
                            new OA\Property(property: 'skills', type: 'object'),
                            new OA\Property(property: 'parameters', type: 'object'),
                            new OA\Property(property: 'behaviour', type: 'object'),
                            new OA\Property(property: 'triggers', type: 'object'),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created assistant including the draft',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'agent', type: 'object', properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'slug', type: 'string', example: 'contract-review'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'description', type: 'string', nullable: true),
                            new OA\Property(property: 'icon', type: 'string'),
                            new OA\Property(property: 'status', type: 'string', example: 'draft'),
                            new OA\Property(property: 'promptId', type: 'integer', example: 42),
                            new OA\Property(property: 'parentId', type: 'integer', nullable: true),
                            new OA\Property(property: 'source', type: 'string', example: 'manual'),
                            new OA\Property(property: 'routable', type: 'boolean', example: false),
                            new OA\Property(property: 'publishedVersionId', type: 'integer', nullable: true),
                            new OA\Property(property: 'draft', type: 'object'),
                            new OA\Property(property: 'createdAt', type: 'integer'),
                            new OA\Property(property: 'updatedAt', type: 'integer'),
                        ]),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid payload or draft',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'error', type: 'string', example: 'Unknown key "tools.foo" in agent.v1'),
                    new OA\Property(property: 'path', type: 'string', example: 'tools.foo'),
                ])
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $data = $request->toArray();
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ('' === $name) {
            return $this->json(['error' => 'name is required'], Response::HTTP_BAD_REQUEST);
        }

        $promptId = isset($data['promptId']) ? (int) $data['promptId'] : null;
        if (null !== $promptId && $promptId < 1) {
            $promptId = null;
        }
        $draft = isset($data['draft']) && is_array($data['draft']) ? $data['draft'] : null;
        $description = is_string($data['description'] ?? null) ? $data['description'] : null;
        $icon = is_string($data['icon'] ?? null) ? $data['icon'] : null;

        try {
            $agent = $this->service->create($user, $name, $promptId, $draft, $description, $icon);
        } catch (AgentDefinitionException $e) {
            return $this->json(['error' => $e->getMessage(), 'path' => $e->path], Response::HTTP_BAD_REQUEST);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'agent' => $this->serializer->full($agent)], Response::HTTP_CREATED);
    }

    #[Route('/gallery', name: 'gallery', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/agents/gallery',
        summary: 'Gallery cards for the current user\'s assistants',
        description: 'S2 returns mine only. Cards never include the draft JSON. Shared and plugin origins arrive in later sprints.',
        tags: ['Agents'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Gallery cards',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'cards', type: 'array', items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'slug', type: 'string', example: 'contract-review'),
                                new OA\Property(property: 'name', type: 'string', example: 'Contract review'),
                                new OA\Property(property: 'description', type: 'string', nullable: true),
                                new OA\Property(property: 'icon', type: 'string', example: ''),
                                new OA\Property(property: 'status', type: 'string', example: 'draft'),
                                new OA\Property(property: 'origin', type: 'string', enum: ['mine', 'shared', 'plugin'], example: 'mine'),
                                new OA\Property(property: 'ownerName', type: 'string', example: 'Ada'),
                                new OA\Property(property: 'version', type: 'integer', nullable: true, example: null),
                                new OA\Property(property: 'updatedAt', type: 'integer', example: 1757232000),
                                new OA\Property(property: 'starterPrompts', type: 'array', items: new OA\Items(type: 'string'), example: ['Review this NDA']),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function gallery(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $ownerName = $this->serializer->displayName($user);
        $cards = array_map(
            fn ($agent) => $this->serializer->galleryCard($agent, $ownerName),
            $this->service->listOwned((int) $user->getId()),
        );

        return $this->json(['success' => true, 'cards' => $cards]);
    }

    #[Route('/{id}/clone', name: 'clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/agents/{id}/clone',
        summary: 'Clone an owned assistant into a new draft',
        description: 'Copies the draft and instruction text. Sets parentId to the source. Files in the source own-folder are not copied. Foreign ids return 404.',
        tags: ['Agents'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Cloned assistant including the draft',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'agent', type: 'object', properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 2),
                        new OA\Property(property: 'slug', type: 'string', example: 'contract-review-copy'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'icon', type: 'string'),
                        new OA\Property(property: 'status', type: 'string', example: 'draft'),
                        new OA\Property(property: 'promptId', type: 'integer', example: 43),
                        new OA\Property(property: 'parentId', type: 'integer', nullable: true, example: 1),
                        new OA\Property(property: 'source', type: 'string', example: 'manual'),
                        new OA\Property(property: 'routable', type: 'boolean', example: false),
                        new OA\Property(property: 'publishedVersionId', type: 'integer', nullable: true),
                        new OA\Property(property: 'draft', type: 'object'),
                        new OA\Property(property: 'createdAt', type: 'integer'),
                        new OA\Property(property: 'updatedAt', type: 'integer'),
                    ]),
                ])
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function clone(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        try {
            $agent = $this->service->clone($user, $id);
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        } catch (AgentDefinitionException $e) {
            return $this->json(['error' => $e->getMessage(), 'path' => $e->path], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'agent' => $this->serializer->full($agent)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/agents/{id}',
        summary: 'Get one owned assistant including its draft',
        tags: ['Agents'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Full assistant',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'agent', type: 'object', properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'slug', type: 'string', example: 'contract-review'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'icon', type: 'string'),
                        new OA\Property(property: 'status', type: 'string', example: 'draft'),
                        new OA\Property(property: 'promptId', type: 'integer', example: 42),
                        new OA\Property(property: 'parentId', type: 'integer', nullable: true),
                        new OA\Property(property: 'source', type: 'string', example: 'manual'),
                        new OA\Property(property: 'routable', type: 'boolean', example: false),
                        new OA\Property(property: 'publishedVersionId', type: 'integer', nullable: true),
                        new OA\Property(property: 'draft', type: 'object'),
                        new OA\Property(property: 'createdAt', type: 'integer'),
                        new OA\Property(property: 'updatedAt', type: 'integer'),
                    ]),
                ])
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function get(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        try {
            $agent = $this->service->requireOwned($id, (int) $user->getId());
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'agent' => $this->serializer->full($agent)]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        path: '/api/v1/agents/{id}',
        summary: 'Update an owned assistant',
        description: 'Partial update. When draft is sent it is validated as a whole agent.v1 document. routable is stored but has no classifier effect until S4.',
        tags: ['Agents'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'icon', type: 'string'),
                new OA\Property(property: 'draft', type: 'object'),
                new OA\Property(property: 'routable', type: 'boolean'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated assistant',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'agent', type: 'object', properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'slug', type: 'string', example: 'contract-review'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'icon', type: 'string'),
                        new OA\Property(property: 'status', type: 'string', example: 'draft'),
                        new OA\Property(property: 'promptId', type: 'integer', example: 42),
                        new OA\Property(property: 'parentId', type: 'integer', nullable: true),
                        new OA\Property(property: 'source', type: 'string', example: 'manual'),
                        new OA\Property(property: 'routable', type: 'boolean', example: false),
                        new OA\Property(property: 'publishedVersionId', type: 'integer', nullable: true),
                        new OA\Property(property: 'draft', type: 'object'),
                        new OA\Property(property: 'createdAt', type: 'integer'),
                        new OA\Property(property: 'updatedAt', type: 'integer'),
                    ]),
                ])
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid draft',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'error', type: 'string', example: 'Unknown key "tools.foo" in agent.v1'),
                    new OA\Property(property: 'path', type: 'string', example: 'tools.foo'),
                ])
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        try {
            $agent = $this->service->requireOwned($id, (int) $user->getId());
            $agent = $this->service->update($agent, $request->toArray());
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        } catch (AgentDefinitionException $e) {
            return $this->json(['error' => $e->getMessage(), 'path' => $e->path], Response::HTTP_BAD_REQUEST);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'agent' => $this->serializer->full($agent)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/agents/{id}',
        summary: 'Delete a draft assistant',
        description: 'Drafts only in S1. Published and archived assistants cannot be deleted yet.',
        tags: ['Agents'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 400, description: 'Not a draft'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        try {
            $agent = $this->service->requireOwned($id, (int) $user->getId());
            $this->service->delete($agent);
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        } catch (AgentNotDraftException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->config->isEnabled((int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return null;
    }
}

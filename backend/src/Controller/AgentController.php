<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bundle\BundleConfig;
use App\Bundle\BundleEnvelopeException;
use App\Bundle\BundleExporter;
use App\Bundle\BundleImporter;
use App\Bundle\BundleScope;
use App\Bundle\ImportOptions;
use App\DTO\AgentDefinitionV1;
use App\Entity\User;
use App\Repository\AgentVersionRepository;
use App\Repository\UseLogRepository;
use App\Repository\UserRepository;
use App\Service\Agent\AgentAccess;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentPublisher;
use App\Service\Agent\AgentSerializer;
use App\Service\Agent\AgentService;
use App\Service\Agent\AgentTriggerResolver;
use App\Service\Agent\Exception\AgentDefinitionException;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Agent\Exception\AgentNotDraftException;
use App\Service\Agent\Exception\AgentNothingChangedException;
use App\Service\Iam\Permission;
use Nelmio\ApiDocBundle\Attribute\Model;
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
        private AgentAccess $access,
        private AgentPublisher $publisher,
        private AgentVersionRepository $versions,
        private UseLogRepository $useLogs,
        private UserRepository $users,
        private ?AgentTriggerResolver $triggers = null,
        private ?BundleConfig $bundleConfig = null,
        private ?BundleExporter $bundleExporter = null,
        private ?BundleImporter $bundleImporter = null,
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
                    new OA\Property(property: 'draft', ref: new Model(type: AgentDefinitionV1::class), description: 'Full or partial agent.v1 document; unknown keys are rejected, missing sections take defaults.'),
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
                            new OA\Property(property: 'draft', ref: new Model(type: AgentDefinitionV1::class)),
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
        description: 'Mine plus assistants shared with the caller (origin=shared). Cards never include the draft JSON.',
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
                                new OA\Property(property: 'sharedVia', type: 'object', nullable: true, properties: [
                                    new OA\Property(property: 'type', type: 'string', example: 'group'),
                                    new OA\Property(property: 'name', type: 'string', example: 'Legal'),
                                ]),
                                new OA\Property(property: 'canEdit', type: 'boolean', example: true),
                                new OA\Property(property: 'canStartChat', type: 'boolean', example: true),
                                new OA\Property(property: 'canClone', type: 'boolean', example: true),
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

        return $this->json(['success' => true, 'cards' => $this->service->galleryCards($user)]);
    }

    #[Route('/{id}/clone', name: 'clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/agents/{id}/clone',
        summary: 'Clone a readable assistant into a new draft',
        description: 'Copies the published definition and prompt text when a version exists; the owner may clone an unpublished draft. IAM read is enough. Files are never copied. Foreign ids return 404.',
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
                        new OA\Property(property: 'draft', ref: new Model(type: AgentDefinitionV1::class)),
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
        summary: 'Get one assistant. Editors receive the draft; readers do not.',
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
                        new OA\Property(property: 'draft', ref: new Model(type: AgentDefinitionV1::class)),
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
            $agent = $this->access->require($user, $id, Permission::Read);
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = $this->access->can($user, $agent, Permission::Edit)
            ? $this->serializer->full($agent)
            : $this->serializer->publicView($agent);

        return $this->json(['success' => true, 'agent' => $payload]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        path: '/api/v1/agents/{id}',
        summary: 'Update an assistant',
        description: 'Partial update for the owner or an IAM editor. status archived|published is owner-only. When draft is sent it is validated as a whole agent.v1 document.',
        tags: ['Agents'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'icon', type: 'string'),
                new OA\Property(property: 'draft', ref: new Model(type: AgentDefinitionV1::class)),
                new OA\Property(property: 'routable', type: 'boolean'),
                new OA\Property(property: 'status', type: 'string', enum: ['archived', 'published']),
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
                        new OA\Property(property: 'draft', ref: new Model(type: AgentDefinitionV1::class)),
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
            $data = $request->toArray();
            $needsOwner = array_key_exists('status', $data);
            $agent = $needsOwner
                ? $this->service->requireOwned($id, (int) $user->getId())
                : $this->access->require($user, $id, Permission::Edit);
            $agent = $this->service->update($agent, $data);
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
        summary: 'Delete a draft or archived assistant',
        description: 'Published assistants cannot be deleted. Archived assistants delete versions and shares first.',
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

    #[Route('/{id}/publish', name: 'publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/agents/{id}/publish',
        summary: 'Publish an immutable version',
        tags: ['Agents'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'changelog', type: 'string', example: 'Clearer instructions for NDAs'),
        ])),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Published version card',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'version', type: 'object', properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 3),
                        new OA\Property(property: 'version', type: 'integer', example: 1),
                        new OA\Property(property: 'changelog', type: 'string', nullable: true, example: 'First cut'),
                        new OA\Property(property: 'publishedByName', type: 'string', example: 'Ada'),
                        new OA\Property(property: 'createdAt', type: 'integer', example: 1757232000),
                    ]),
                ])
            ),
            new OA\Response(response: 400, description: 'Invalid draft'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
            new OA\Response(response: 409, description: 'nothing_changed'),
        ]
    )]
    public function publish(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $changelog = '';
        $data = $request->toArray();
        if (isset($data['changelog']) && is_string($data['changelog'])) {
            $changelog = $data['changelog'];
        }

        try {
            $agent = $this->access->require($user, $id, Permission::Edit);
            $version = $this->publisher->publish($agent, $user, $changelog);
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        } catch (AgentNothingChangedException) {
            return $this->json(['error' => 'nothing_changed'], Response::HTTP_CONFLICT);
        } catch (AgentDefinitionException $e) {
            return $this->json(['error' => $e->getMessage(), 'path' => $e->path], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'success' => true,
            'version' => $this->serializer->versionCard($version, $this->serializer->displayName($user)),
        ]);
    }

    #[Route('/{id}/versions', name: 'versions', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/agents/{id}/versions',
        summary: 'List published versions (no definitions)',
        tags: ['Agents'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Version cards',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'versions', type: 'array', items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'version', type: 'integer'),
                        new OA\Property(property: 'changelog', type: 'string', nullable: true),
                        new OA\Property(property: 'publishedByName', type: 'string'),
                        new OA\Property(property: 'createdAt', type: 'integer'),
                    ])),
                ])
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function versions(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        try {
            $agent = $this->access->require($user, $id, Permission::Read);
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $cards = [];
        foreach ($this->versions->findAllForAgent((int) $agent->getId()) as $version) {
            $cards[] = $this->serializer->versionCard($version, $this->publisherName($version->getPublishedBy()));
        }

        return $this->json(['success' => true, 'versions' => $cards]);
    }

    #[Route('/{id}/versions/{version}', name: 'version', methods: ['GET'], requirements: ['id' => '\d+', 'version' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/agents/{id}/versions/{version}',
        summary: 'Version detail including the definition (owner or edit)',
        tags: ['Agents'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Version detail',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'version', type: 'object', properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'version', type: 'integer'),
                        new OA\Property(property: 'changelog', type: 'string', nullable: true),
                        new OA\Property(property: 'publishedByName', type: 'string'),
                        new OA\Property(property: 'createdAt', type: 'integer'),
                        new OA\Property(property: 'definition', ref: new Model(type: AgentDefinitionV1::class)),
                        new OA\Property(property: 'promptText', type: 'string'),
                    ]),
                ])
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function version(int $id, int $version, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        try {
            $agent = $this->access->require($user, $id, Permission::Edit);
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $row = $this->versions->findByAgentAndVersion((int) $agent->getId(), $version);
        if (null === $row) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'version' => $this->serializer->versionDetail($row, $this->publisherName($row->getPublishedBy())),
        ]);
    }

    #[Route('/{id}/usage', name: 'usage', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/agents/{id}/usage',
        summary: 'Owner usage totals per version and per day (no user identities)',
        tags: ['Agents'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Usage aggregates',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'byVersion', type: 'array', items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'agentVersionId', type: 'integer', nullable: true),
                        new OA\Property(property: 'version', type: 'integer', nullable: true),
                        new OA\Property(property: 'messages', type: 'integer'),
                        new OA\Property(property: 'tokens', type: 'integer'),
                        new OA\Property(property: 'cost', type: 'string'),
                        new OA\Property(property: 'distinctUsers', type: 'integer'),
                    ])),
                    new OA\Property(property: 'byDay', type: 'array', items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'day', type: 'string', example: '2026-09-07'),
                        new OA\Property(property: 'messages', type: 'integer'),
                        new OA\Property(property: 'tokens', type: 'integer'),
                        new OA\Property(property: 'cost', type: 'string'),
                    ])),
                ])
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function usage(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        try {
            $this->access->require($user, $id, Permission::Edit);
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $to = $request->query->getInt('to') ?: time();
        $from = $request->query->getInt('from') ?: ($to - 30 * 86400);
        if ($from > $to) {
            return $this->json(['error' => 'from must be before to'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true] + $this->useLogs->aggregateForAgent($id, $from, $to));
    }

    #[Route('/{id}/triggers', name: 'triggers', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/agents/{id}/triggers',
        summary: 'Resolved trigger rows for the builder',
        tags: ['Agents'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Trigger rows',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'savedTasksEnabled', type: 'boolean'),
                        new OA\Property(property: 'availableKinds', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(
                            property: 'rows',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'string'),
                                    new OA\Property(property: 'kind', type: 'string'),
                                    new OA\Property(property: 'group', type: 'string'),
                                    new OA\Property(property: 'what', type: 'string'),
                                    new OA\Property(property: 'when', type: 'string'),
                                    new OA\Property(property: 'does', type: 'string'),
                                    new OA\Property(property: 'runsAs', type: 'string'),
                                    new OA\Property(property: 'status', type: 'string'),
                                    new OA\Property(property: 'savedTaskId', type: 'integer', nullable: true),
                                    new OA\Property(property: 'target', type: 'object', additionalProperties: true),
                                    new OA\Property(property: 'lastRun', type: 'object', nullable: true, additionalProperties: true),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function triggers(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        if (null === $this->triggers) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $agent = $this->access->require($user, $id, Permission::Edit);
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true] + $this->triggers->resolve($agent, $user));
    }

    #[Route('/{id}/export', name: 'export', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/agents/{id}/export',
        summary: 'Export one assistant as a synaplan-bundle.v1 document',
        tags: ['Agents'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Bundle document'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function export(int $id, #[CurrentUser] ?User $user): Response
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        if (null === $this->bundleConfig || !$this->bundleConfig->isEnabled((int) $user->getId()) || null === $this->bundleExporter) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        try {
            $agent = $this->access->require($user, $id, Permission::Read);
        } catch (AgentNotAccessibleException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        $document = $this->bundleExporter->export(
            (int) $user->getId(),
            BundleScope::User,
            ['prompts', 'agents'],
            ['agents' => [$agent->getSlug()]],
        );
        $payload = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($payload, Response::HTTP_OK, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => sprintf('attachment; filename="%s.synaplan-bundle.json"', $agent->getSlug()),
        ]);
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/agents/import',
        summary: 'Import assistants and instructions from a synaplan-bundle.v1 document',
        tags: ['Agents'],
        responses: [
            new OA\Response(response: 200, description: 'Import result'),
            new OA\Response(response: 400, description: 'Invalid bundle'),
            new OA\Response(response: 404, description: 'Not found or feature disabled'),
        ]
    )]
    public function import(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        if (null === $this->bundleConfig || !$this->bundleConfig->isEnabled((int) $user->getId()) || null === $this->bundleImporter) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        $content = $request->getContent();
        $decoded = json_decode($content, true);
        $json = $content;
        $conflict = ImportOptions::CONFLICT_SKIP;
        if (is_array($decoded) && isset($decoded['bundle']) && is_array($decoded['bundle'])) {
            $json = json_encode($decoded['bundle'], JSON_THROW_ON_ERROR);
            if (is_string($decoded['options']['conflict'] ?? null)) {
                $conflict = $decoded['options']['conflict'];
            }
        }
        try {
            $results = $this->bundleImporter->apply($json, (int) $user->getId(), new ImportOptions($conflict));
        } catch (BundleEnvelopeException $e) {
            return $this->json(['error' => $e->getMessage(), 'path' => $e->path], Response::HTTP_BAD_REQUEST);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'createsDrafts' => true, 'results' => $results]);
    }

    private function publisherName(int $userId): string
    {
        $publisher = $this->users->find($userId);

        return $publisher instanceof User ? $this->serializer->displayName($publisher) : '';
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

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Iam\AccessGate;
use App\Service\Iam\Exception\ShareNotAllowedException;
use App\Service\Iam\Exception\UnknownResourceKindException;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ShareService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'IAM Sharing')]
final class ShareController extends AbstractController
{
    public function __construct(
        private readonly IamConfig $iamConfig,
        private readonly ShareService $shareService,
        private readonly AccessGate $accessGate,
    ) {
    }

    #[Route('/api/v1/shares', name: 'shares_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/shares',
        operationId: 'listShares',
        summary: 'List who this item is shared with',
        tags: ['IAM Sharing'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'resource', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Shares',
                content: new OA\JsonContent(
                    required: ['shares'],
                    properties: [
                        new OA\Property(
                            property: 'shares',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'kind', 'resourceId', 'subjectType', 'subjectId', 'permission'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'kind', type: 'string'),
                                    new OA\Property(property: 'resourceId', type: 'string'),
                                    new OA\Property(property: 'subjectType', type: 'string', enum: ['user', 'group', 'everyone']),
                                    new OA\Property(property: 'subjectId', type: 'integer'),
                                    new OA\Property(property: 'permission', type: 'string', enum: ['read', 'use', 'edit', 'manage']),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'email', type: 'string', nullable: true),
                                    new OA\Property(property: 'grantedBy', type: 'integer'),
                                    new OA\Property(property: 'created', type: 'integer', format: 'int64'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Feature disabled or item not found'),
        ]
    )]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $kind = (string) $request->query->get('kind', '');
        $resource = (string) $request->query->get('resource', '');
        if ('' === $kind || '' === $resource) {
            return $this->json(['error' => 'kind and resource are required.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$this->accessGate->decide($user, $kind, $resource, Permission::Manage)) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'shares' => array_map(
                $this->shareService->serializeShare(...),
                $this->shareService->listForResource($kind, $resource),
            ),
        ]);
    }

    #[Route('/api/v1/shares', name: 'shares_grant', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/shares',
        operationId: 'grantShare',
        summary: 'Share an item with a person, a group, or everyone',
        tags: ['IAM Sharing'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['kind', 'resource', 'subjectType', 'permission'],
                properties: [
                    new OA\Property(property: 'kind', type: 'string', example: 'conversation'),
                    new OA\Property(property: 'resource', type: 'string', example: '42'),
                    new OA\Property(property: 'subjectType', type: 'string', enum: ['user', 'group', 'everyone']),
                    new OA\Property(property: 'subjectId', type: 'integer', example: 3),
                    new OA\Property(property: 'permission', type: 'string', enum: ['read', 'use', 'edit', 'manage'], example: 'use'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Share created or updated',
                content: new OA\JsonContent(
                    required: ['share'],
                    properties: [
                        new OA\Property(
                            property: 'share',
                            required: ['id', 'kind', 'resourceId', 'subjectType', 'subjectId', 'permission'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'kind', type: 'string'),
                                new OA\Property(property: 'resourceId', type: 'string'),
                                new OA\Property(property: 'subjectType', type: 'string'),
                                new OA\Property(property: 'subjectId', type: 'integer'),
                                new OA\Property(property: 'permission', type: 'string'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'email', type: 'string', nullable: true),
                                new OA\Property(property: 'grantedBy', type: 'integer'),
                                new OA\Property(property: 'created', type: 'integer', format: 'int64'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 403, description: 'Not allowed'),
            new OA\Response(response: 404, description: 'Feature disabled'),
            new OA\Response(response: 422, description: 'Permission not supported for this item'),
        ]
    )]
    public function grant(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $data = $this->jsonBody($request);

        try {
            $share = $this->shareService->grant(
                $user,
                (string) ($data['kind'] ?? ''),
                (string) ($data['resource'] ?? ''),
                (string) ($data['subjectType'] ?? ''),
                (int) ($data['subjectId'] ?? 0),
                (string) ($data['permission'] ?? ''),
                (string) ($request->getClientIp() ?? ''),
            );
        } catch (ShareNotAllowedException $e) {
            $status = str_contains($e->getMessage(), 'cannot be shared')
                ? Response::HTTP_UNPROCESSABLE_ENTITY
                : Response::HTTP_FORBIDDEN;

            return $this->json(['error' => $e->getMessage()], $status);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (UnknownResourceKindException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['share' => $this->shareService->serializeShare($share)], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/shares', name: 'shares_revoke', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/shares',
        operationId: 'revokeShare',
        summary: 'Stop sharing an item with a person, a group, or everyone',
        tags: ['IAM Sharing'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'resource', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'subjectType', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'subjectId', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Removed',
                content: new OA\JsonContent(
                    required: ['success'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Not allowed'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function revoke(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $kind = (string) $request->query->get('kind', '');
        $resource = (string) $request->query->get('resource', '');
        $subjectType = (string) $request->query->get('subjectType', '');
        $subjectId = (int) $request->query->get('subjectId', 0);
        if ('' === $kind || '' === $resource || '' === $subjectType) {
            return $this->json(['error' => 'kind, resource and subjectType are required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->shareService->revoke(
                $user,
                $kind,
                $resource,
                $subjectType,
                $subjectId,
                (string) ($request->getClientIp() ?? ''),
            );
        } catch (ShareNotAllowedException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (UnknownResourceKindException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true]);
    }

    #[Route('/api/v1/iam/subjects', name: 'iam_subjects', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/iam/subjects',
        operationId: 'searchIamSubjects',
        summary: 'Search people and groups to share with',
        tags: ['IAM Sharing'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'People, groups, and everyone',
                content: new OA\JsonContent(
                    required: ['subjects'],
                    properties: [
                        new OA\Property(
                            property: 'subjects',
                            type: 'array',
                            items: new OA\Items(
                                required: ['type', 'id', 'name', 'pinned'],
                                properties: [
                                    new OA\Property(property: 'type', type: 'string', enum: ['user', 'group', 'everyone']),
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'email', type: 'string', nullable: true),
                                    new OA\Property(property: 'pinned', type: 'boolean'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function subjects(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }

        return $this->json([
            'subjects' => $this->shareService->searchSubjects((string) $request->query->get('q', '')),
        ]);
    }

    #[Route('/api/v1/me/shared', name: 'me_shared', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/me/shared',
        operationId: 'listSharedWithMe',
        summary: 'Items shared with me',
        tags: ['IAM Sharing'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Shared items',
                content: new OA\JsonContent(
                    required: ['items'],
                    properties: [
                        new OA\Property(
                            property: 'items',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'name', 'icon', 'permission'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'string'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'icon', type: 'string'),
                                    new OA\Property(property: 'meta', type: 'object'),
                                    new OA\Property(property: 'permission', type: 'string'),
                                    new OA\Property(property: 'ownerId', type: 'integer', nullable: true),
                                    new OA\Property(property: 'ownerName', type: 'string', nullable: true),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function sharedWithMe(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $kind = (string) $request->query->get('kind', '');
        if ('' === $kind) {
            return $this->json(['error' => 'kind is required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $rows = $this->shareService->listSharedWith((int) $user->getId(), $kind);
        } catch (UnknownResourceKindException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'items' => array_map(
                fn (array $row) => $this->shareService->serializeSharedCard(
                    $row['card'],
                    $row['permission'],
                    $row['ownerId'],
                ),
                $rows,
            ),
        ]);
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->iamConfig->isSharingEnabled((int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}

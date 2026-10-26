<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\AdminUsageRequest;
use App\DTO\AdminUsageUser;
use App\Service\Usage\AdminUsageService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin › Usage — the cost board: users ranked by what their model use
 * costs the platform, with the individual model requests behind each row.
 * Costs are provider costs (BUSELOG.BCOST); no message content is exposed.
 */
#[Route('/api/v1/admin/usage', name: 'admin_usage_')]
#[OA\Tag(name: 'Admin Usage')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUsageController extends AbstractController
{
    public function __construct(
        private readonly AdminUsageService $usage,
    ) {
    }

    #[Route('/users', name: 'users', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/usage/users',
        operationId: 'listAdminUsageUsers',
        summary: 'Users ranked by model cost in a period (the hit parade)',
        tags: ['Admin Usage'],
        parameters: [
            new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: AdminUsageService::PERIODS, default: '30d')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: AdminUsageService::SORTS, default: 'cost')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'One page of the ranking', content: new OA\JsonContent(
                required: ['success', 'users', 'totalUsers', 'page', 'perPage', 'totalPages', 'period', 'sort'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'users', type: 'array', items: new OA\Items(ref: new Model(type: AdminUsageUser::class))),
                    new OA\Property(property: 'totalUsers', type: 'integer', example: 137),
                    new OA\Property(property: 'page', type: 'integer', example: 1),
                    new OA\Property(property: 'perPage', type: 'integer', example: 20),
                    new OA\Property(property: 'totalPages', type: 'integer', example: 7),
                    new OA\Property(property: 'period', type: 'string', example: '30d'),
                    new OA\Property(property: 'sort', type: 'string', example: 'cost'),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function users(Request $request): JsonResponse
    {
        $result = $this->usage->users(
            AdminUsageService::periodOrDefault($request->query->get('period')),
            AdminUsageService::sortOrDefault($request->query->get('sort')),
            AdminUsageService::pageOrDefault($request->query->getInt('page') ?: null),
            AdminUsageService::perPageOrDefault($request->query->getInt('perPage') ?: null),
        );

        return $this->json(['success' => true, ...$result]);
    }

    #[Route('/users/{userId}/requests', name: 'user_requests', methods: ['GET'], requirements: ['userId' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/admin/usage/users/{userId}/requests',
        operationId: 'listAdminUsageUserRequests',
        summary: 'The individual model requests behind one cost-board row',
        tags: ['Admin Usage'],
        parameters: [
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: AdminUsageService::PERIODS, default: '30d')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'One page of requests, newest first. No prompt or answer content.', content: new OA\JsonContent(
                required: ['success', 'requests', 'total', 'page', 'perPage', 'totalPages', 'period'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'requests', type: 'array', items: new OA\Items(ref: new Model(type: AdminUsageRequest::class))),
                    new OA\Property(property: 'total', type: 'integer', example: 531),
                    new OA\Property(property: 'page', type: 'integer', example: 1),
                    new OA\Property(property: 'perPage', type: 'integer', example: 20),
                    new OA\Property(property: 'totalPages', type: 'integer', example: 27),
                    new OA\Property(property: 'period', type: 'string', example: '30d'),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function requests(int $userId, Request $request): JsonResponse
    {
        $result = $this->usage->requests(
            $userId,
            AdminUsageService::periodOrDefault($request->query->get('period')),
            AdminUsageService::pageOrDefault($request->query->getInt('page') ?: null),
            AdminUsageService::perPageOrDefault($request->query->getInt('perPage') ?: null),
        );

        return $this->json(['success' => true, ...$result]);
    }
}

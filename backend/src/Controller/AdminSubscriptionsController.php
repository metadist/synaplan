<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Admin\AdminSubscriptionsService;
use App\Service\Admin\SubscriptionNotFoundException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/subscriptions')]
#[OA\Tag(name: 'Admin Subscriptions')]
final class AdminSubscriptionsController extends AbstractController
{
    public function __construct(
        private readonly AdminSubscriptionsService $subscriptionsService,
    ) {
    }

    #[Route('', name: 'admin_subscriptions_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/subscriptions',
        summary: 'List all subscription plans',
        description: 'Get all subscription plans with their budget configuration (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin Subscriptions']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of subscription plans',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'subscriptions',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'name', type: 'string', example: 'Pro'),
                            new OA\Property(property: 'level', type: 'string', example: 'PRO'),
                            new OA\Property(property: 'priceMonthly', type: 'string', example: '19.99'),
                            new OA\Property(property: 'priceYearly', type: 'string', example: '199.00'),
                            new OA\Property(property: 'description', type: 'string', example: 'Professional plan'),
                            new OA\Property(property: 'active', type: 'boolean', example: true),
                            new OA\Property(property: 'costBudgetMonthly', type: 'string', example: '15.00'),
                            new OA\Property(property: 'costBudgetYearly', type: 'string', example: '150.00'),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'subscriptions' => $this->subscriptionsService->listSubscriptions(),
        ]);
    }

    #[Route('/{id}', name: 'admin_subscriptions_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/admin/subscriptions/{id}',
        summary: 'Update subscription budget',
        description: 'Update cost budget and active status for a subscription plan (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin Subscriptions']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'costBudgetMonthly', type: 'number', format: 'float', example: 15.0),
                new OA\Property(property: 'costBudgetYearly', type: 'number', format: 'float', example: 150.0),
                new OA\Property(property: 'active', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Subscription updated',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'subscription', type: 'object'),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'Subscription not found')]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $subscription = $this->subscriptionsService->updateSubscription($id, $data);

            return $this->json([
                'success' => true,
                'subscription' => $subscription,
            ]);
        } catch (SubscriptionNotFoundException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}

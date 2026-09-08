<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Plug\PlugConfigService;
use App\Plug\WebSearch\WebSearchRegistry;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/config/plugs')]
#[OA\Tag(name: 'User Plugs')]
final class UserPlugsController extends AbstractController
{
    public function __construct(
        private readonly PlugConfigService $plugConfig,
        private readonly WebSearchRegistry $registry,
    ) {
    }

    #[Route('/web-search', name: 'user_plugs_web_search_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/plugs/web-search',
        summary: 'Current user web search provider and whether override is allowed',
        security: [['Bearer' => []]],
        tags: ['User Plugs']
    )]
    #[OA\Response(
        response: 200,
        description: 'Allowed flag, active key and selectable options',
        content: new OA\JsonContent(
            required: ['allowed', 'active', 'options'],
            properties: [
                new OA\Property(property: 'allowed', type: 'boolean', example: false),
                new OA\Property(property: 'active', type: 'string', example: 'brave'),
                new OA\Property(
                    property: 'options',
                    type: 'array',
                    items: new OA\Items(
                        required: ['key', 'label'],
                        properties: [
                            new OA\Property(property: 'key', type: 'string', example: 'brave'),
                            new OA\Property(property: 'label', type: 'string', example: 'Brave Search'),
                        ],
                        type: 'object'
                    )
                ),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $options = [];
        foreach ($this->registry->all() as $adapter) {
            $options[] = [
                'key' => $adapter->key(),
                'label' => $adapter->descriptor()->label,
            ];
        }

        return $this->json([
            'allowed' => $this->plugConfig->isWebSearchUserOverrideAllowed(),
            'active' => $this->plugConfig->webSearchProvider((int) $user->getId()),
            'options' => $options,
        ]);
    }

    #[Route('/web-search', name: 'user_plugs_web_search_save', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/config/plugs/web-search',
        summary: 'Set or clear the per-user web search provider override',
        security: [['Bearer' => []]],
        tags: ['User Plugs']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'provider', type: 'string', nullable: true, example: 'tavily'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 200, description: 'Updated user web search status')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'User override is not allowed')]
    #[OA\Response(response: 422, description: 'Unknown provider key')]
    public function save(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'JSON body is required'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $data['provider'] ?? null;
        if (null !== $provider && !\is_string($provider)) {
            return $this->json(['error' => 'provider must be a string or null'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->plugConfig->setUserWebSearchProvider((int) $user->getId(), $provider);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->status($user);
    }
}

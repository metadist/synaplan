<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Plug\WebSearch\WebSearchAdminService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/plugs')]
#[OA\Tag(name: 'Admin Plugs Web Search')]
final class AdminPlugsWebSearchController extends AbstractController
{
    public function __construct(
        private readonly WebSearchAdminService $webSearchAdmin,
    ) {
    }

    #[Route('/web-search', name: 'admin_plugs_web_search_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/plugs/web-search',
        summary: 'List web search providers, the active key and fallback',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Web Search']
    )]
    #[OA\Response(
        response: 200,
        description: 'Web search providers and current selection',
        content: new OA\JsonContent(
            required: ['providers', 'active', 'fallback', 'userOverrideAllowed'],
            properties: [
                new OA\Property(
                    property: 'providers',
                    type: 'array',
                    items: new OA\Items(
                        required: ['key', 'label', 'docsUrl', 'sovereignty', 'pluginId', 'capabilities', 'health', 'keyStatus'],
                        properties: [
                            new OA\Property(property: 'key', type: 'string', example: 'brave'),
                            new OA\Property(property: 'label', type: 'string', example: 'Brave Search'),
                            new OA\Property(property: 'docsUrl', type: 'string', example: 'https://api-dashboard.search.brave.com/'),
                            new OA\Property(property: 'sovereignty', type: 'string', example: 'US cloud'),
                            new OA\Property(property: 'pluginId', type: 'string', nullable: true, example: null),
                            new OA\Property(
                                property: 'capabilities',
                                required: ['freshness', 'country', 'language', 'siteFilter', 'fullContent', 'answer'],
                                properties: [
                                    new OA\Property(property: 'freshness', type: 'boolean'),
                                    new OA\Property(property: 'country', type: 'boolean'),
                                    new OA\Property(property: 'language', type: 'boolean'),
                                    new OA\Property(property: 'siteFilter', type: 'boolean'),
                                    new OA\Property(property: 'fullContent', type: 'boolean'),
                                    new OA\Property(property: 'answer', type: 'boolean'),
                                ],
                                type: 'object'
                            ),
                            new OA\Property(
                                property: 'health',
                                required: ['available', 'reason'],
                                properties: [
                                    new OA\Property(property: 'available', type: 'boolean'),
                                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                                ],
                                type: 'object'
                            ),
                            new OA\Property(
                                property: 'keyStatus',
                                required: ['configured', 'source', 'origin', 'maskedKey'],
                                properties: [
                                    new OA\Property(property: 'configured', type: 'boolean'),
                                    new OA\Property(property: 'source', type: 'string', example: 'none'),
                                    new OA\Property(property: 'origin', type: 'string', nullable: true),
                                    new OA\Property(property: 'maskedKey', type: 'string'),
                                ],
                                type: 'object'
                            ),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(property: 'active', type: 'string', example: 'brave'),
                new OA\Property(property: 'fallback', type: 'string', example: ''),
                new OA\Property(property: 'userOverrideAllowed', type: 'boolean', example: false),
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

        return $this->json($this->webSearchAdmin->status());
    }

    #[Route('/web-search', name: 'admin_plugs_web_search_save', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/admin/plugs/web-search',
        summary: 'Set the active web search provider, fallback and user-override flag',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Web Search']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['active', 'fallback', 'userOverrideAllowed'],
            properties: [
                new OA\Property(property: 'active', type: 'string', example: 'searxng'),
                new OA\Property(property: 'fallback', type: 'string', example: 'brave'),
                new OA\Property(property: 'userOverrideAllowed', type: 'boolean', example: false),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 200, description: 'Updated web search status')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 422, description: 'Unknown provider key')]
    public function save(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\is_string($data['active'] ?? null)) {
            return $this->json(['error' => 'active is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->webSearchAdmin->save(
                $data['active'],
                \is_string($data['fallback'] ?? null) ? $data['fallback'] : '',
                (bool) ($data['userOverrideAllowed'] ?? false),
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/web-search/test', name: 'admin_plugs_web_search_test', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/plugs/web-search/test',
        summary: 'Run a live test query against one web search provider',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Web Search']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['provider', 'query'],
            properties: [
                new OA\Property(property: 'provider', type: 'string', example: 'brave'),
                new OA\Property(property: 'query', type: 'string', example: 'synaplan open source'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Up to five result titles',
        content: new OA\JsonContent(
            required: ['results', 'answer', 'latencyMs', 'error', 'provider', 'fellBackFrom'],
            properties: [
                new OA\Property(
                    property: 'results',
                    type: 'array',
                    items: new OA\Items(
                        required: ['title', 'url'],
                        properties: [
                            new OA\Property(property: 'title', type: 'string'),
                            new OA\Property(property: 'url', type: 'string'),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(property: 'answer', type: 'string', nullable: true),
                new OA\Property(property: 'latencyMs', type: 'integer', example: 120),
                new OA\Property(property: 'error', type: 'string', nullable: true),
                new OA\Property(property: 'provider', type: 'string', example: 'exa'),
                new OA\Property(property: 'fellBackFrom', type: 'string', nullable: true, example: null),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Missing provider or query')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 422, description: 'Unknown provider key')]
    public function test(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\is_string($data['provider'] ?? null) || !\is_string($data['query'] ?? null)) {
            return $this->json(['error' => 'provider and query are required'], Response::HTTP_BAD_REQUEST);
        }
        $query = trim($data['query']);
        if ('' === $query) {
            return $this->json(['error' => 'query must not be empty'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->webSearchAdmin->test($data['provider'], $query));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/keys/{provider}', name: 'admin_plugs_keys_save', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/admin/plugs/keys/{provider}',
        summary: 'Store an encrypted plug API key (tavily, exa, firecrawl, perplexity)',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Web Search']
    )]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['key'],
            properties: [new OA\Property(property: 'key', type: 'string')],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Updated key status (never the raw key)',
        content: new OA\JsonContent(
            required: ['configured', 'source', 'origin', 'maskedKey'],
            properties: [
                new OA\Property(property: 'configured', type: 'boolean'),
                new OA\Property(property: 'source', type: 'string'),
                new OA\Property(property: 'origin', type: 'string', nullable: true),
                new OA\Property(property: 'maskedKey', type: 'string'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 422, description: 'Unknown provider, empty key, or the provider rejected the key')]
    public function saveKey(string $provider, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\is_string($data['key'] ?? null)) {
            return $this->json(['error' => 'key is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->webSearchAdmin->saveKey($provider, $data['key']));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/keys/{provider}', name: 'admin_plugs_keys_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/admin/plugs/keys/{provider}',
        summary: 'Delete a stored plug API key',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Web Search']
    )]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(
        response: 200,
        description: 'Key status after delete',
        content: new OA\JsonContent(
            required: ['configured', 'source', 'origin', 'maskedKey'],
            properties: [
                new OA\Property(property: 'configured', type: 'boolean'),
                new OA\Property(property: 'source', type: 'string'),
                new OA\Property(property: 'origin', type: 'string', nullable: true),
                new OA\Property(property: 'maskedKey', type: 'string'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 422, description: 'Unknown provider')]
    public function deleteKey(string $provider, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        try {
            return $this->json($this->webSearchAdmin->deleteKey($provider));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
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

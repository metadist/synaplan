<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\EncryptionService;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpClientException;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Security\SsrfGuard;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Settings → Connections → MCP servers (release-4.0 plan 09 §3.2).
 *
 * User-scoped CRUD for external MCP server connections plus live tool
 * discovery ("test connection"). Auth header values are encrypted at rest and
 * NEVER serialized back to the client — responses only carry `hasAuthToken`.
 */
#[Route('/api/v1/mcp-servers', name: 'api_mcp_servers_')]
#[OA\Tag(name: 'MCP Servers')]
final class McpServerConfigController extends AbstractController
{
    public function __construct(
        private readonly McpServerConfigRepository $repository,
        private readonly EncryptionService $encryptionService,
        private readonly McpToolRegistry $toolRegistry,
        private readonly McpClientConfig $clientConfig,
        private readonly SsrfGuard $ssrfGuard,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/mcp-servers',
        summary: "List the current user's external MCP server connections",
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connections (auth values omitted)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'client_enabled', type: 'boolean', example: false),
                        new OA\Property(property: 'servers', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'Company CRM'),
                                new OA\Property(property: 'url', type: 'string', example: 'https://crm.example.com/mcp'),
                                new OA\Property(property: 'auth_header', type: 'string', example: 'Authorization'),
                                new OA\Property(property: 'has_auth_token', type: 'boolean', example: true),
                                new OA\Property(property: 'enabled', type: 'boolean', example: true),
                                new OA\Property(property: 'created', type: 'string', example: '20260702180000'),
                                new OA\Property(property: 'updated', type: 'string', example: '20260702180000'),
                            ],
                            type: 'object'
                        )),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'client_enabled' => $this->clientConfig->isClientEnabled($user->getId()),
            'servers' => array_map(
                fn (McpServerConfig $s): array => $this->serialize($s),
                $this->repository->findByUser($user->getId()),
            ),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/mcp-servers',
        summary: 'Connect a new external MCP server',
        security: [['Bearer' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'url'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Company CRM'),
                    new OA\Property(property: 'url', type: 'string', example: 'https://crm.example.com/mcp'),
                    new OA\Property(property: 'auth_header', type: 'string', example: 'Authorization'),
                    new OA\Property(property: 'auth_token', type: 'string', example: 'Bearer sk-…'),
                    new OA\Property(property: 'enabled', type: 'boolean', example: true),
                ]
            )
        ),
        tags: ['MCP Servers'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Connection created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'server',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'Company CRM'),
                                new OA\Property(property: 'url', type: 'string', example: 'https://crm.example.com/mcp'),
                                new OA\Property(property: 'auth_header', type: 'string', example: 'Authorization'),
                                new OA\Property(property: 'has_auth_token', type: 'boolean', example: true),
                                new OA\Property(property: 'enabled', type: 'boolean', example: true),
                                new OA\Property(property: 'created', type: 'string', example: '20260702180000'),
                                new OA\Property(property: 'updated', type: 'string', example: '20260702180000'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $this->payload($request);
        $error = $this->validate($data, requireAll: true);
        if (null !== $error) {
            return $this->json(['success' => false, 'error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $server = new McpServerConfig();
        $server->setUserId($user->getId());
        $this->apply($server, $data);

        $this->repository->save($server);

        return $this->json(['success' => true, 'server' => $this->serialize($server)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/mcp-servers/{id}',
        summary: 'Update an MCP server connection',
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connection updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'server',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'Company CRM'),
                                new OA\Property(property: 'url', type: 'string', example: 'https://crm.example.com/mcp'),
                                new OA\Property(property: 'auth_header', type: 'string', example: 'Authorization'),
                                new OA\Property(property: 'has_auth_token', type: 'boolean', example: true),
                                new OA\Property(property: 'enabled', type: 'boolean', example: true),
                                new OA\Property(property: 'created', type: 'string', example: '20260702180000'),
                                new OA\Property(property: 'updated', type: 'string', example: '20260702180000'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $server = $this->repository->findByIdAndUser($id, $user->getId());
        if (null === $server) {
            return $this->json(['success' => false, 'error' => 'Server not found'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->payload($request);
        $error = $this->validate($data, requireAll: false);
        if (null !== $error) {
            return $this->json(['success' => false, 'error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $this->apply($server, $data);
        $this->repository->save($server);

        return $this->json(['success' => true, 'server' => $this->serialize($server)]);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/mcp-servers/{id}',
        summary: 'Remove an MCP server connection',
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connection removed',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true)])
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $server = $this->repository->findByIdAndUser($id, $user->getId());
        if (null === $server) {
            return $this->json(['success' => false, 'error' => 'Server not found'], Response::HTTP_NOT_FOUND);
        }

        $this->repository->remove($server);

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/tools', name: 'tools', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/mcp-servers/{id}/tools',
        summary: 'List the tools discovered on a connected MCP server (cached)',
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Discovered tools',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'error', type: 'string', nullable: true, example: null),
                        new OA\Property(property: 'tools', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'name', type: 'string', example: 'search_customers'),
                                new OA\Property(property: 'description', type: 'string', example: 'Find customers by name'),
                            ],
                            type: 'object'
                        )),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function tools(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $server = $this->repository->findByIdAndUser($id, $user->getId());
        if (null === $server) {
            return $this->json(['success' => false, 'error' => 'Server not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->clientConfig->isClientEnabled($user->getId())) {
            return $this->json(['success' => false, 'error' => 'The outbound MCP client is disabled', 'tools' => []]);
        }

        return $this->json([
            'success' => true,
            'tools' => $this->publicTools($this->toolRegistry->toolsFor($server)),
        ]);
    }

    #[Route('/{id}/test', name: 'test', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/mcp-servers/{id}/test',
        summary: 'Test the connection: live initialize + tool discovery',
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connection result (success flag + tools or error)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'error', type: 'string', nullable: true, example: null),
                        new OA\Property(property: 'tools', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'name', type: 'string', example: 'search_customers'),
                                new OA\Property(property: 'description', type: 'string', example: 'Find customers by name'),
                            ],
                            type: 'object'
                        )),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function test(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $server = $this->repository->findByIdAndUser($id, $user->getId());
        if (null === $server) {
            return $this->json(['success' => false, 'error' => 'Server not found'], Response::HTTP_NOT_FOUND);
        }

        // Master switch (plan 09 §6): no outbound MCP traffic while disabled —
        // connections can be prepared, but not exercised.
        if (!$this->clientConfig->isClientEnabled($user->getId())) {
            return $this->json(['success' => false, 'error' => 'The outbound MCP client is disabled', 'tools' => []]);
        }

        try {
            $tools = $this->toolRegistry->refresh($server);
        } catch (McpClientException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->json(['success' => true, 'tools' => $this->publicTools($tools)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validate(array $data, bool $requireAll): ?string
    {
        if ($requireAll || array_key_exists('name', $data)) {
            $name = $data['name'] ?? null;
            if (!is_string($name) || '' === trim($name) || mb_strlen($name) > 255) {
                return 'A name (max 255 characters) is required';
            }
        }

        if ($requireAll || array_key_exists('url', $data)) {
            $url = $data['url'] ?? null;
            if (!is_string($url) || '' === trim($url) || mb_strlen($url) > 1024) {
                return 'A server URL is required';
            }
            if ($this->ssrfGuard->isBlockedUrl(trim($url))) {
                return 'This server URL is not allowed (must be a public http(s) endpoint)';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function apply(McpServerConfig $server, array $data): void
    {
        if (is_string($data['name'] ?? null)) {
            $server->setName(trim($data['name']));
        }
        if (is_string($data['url'] ?? null)) {
            $server->setUrl(trim($data['url']));
        }
        if (array_key_exists('auth_header', $data) && is_string($data['auth_header'])) {
            $server->setAuthHeader(trim($data['auth_header']));
        }
        // The auth value is write-only: absent key = keep the stored secret;
        // empty string = clear it; anything else = replace it.
        if (array_key_exists('auth_token', $data) && is_string($data['auth_token'])) {
            $server->setDecryptedAuthToken($data['auth_token'], $this->encryptionService);
        }
        if (array_key_exists('enabled', $data)) {
            $server->setEnabled((bool) $data['enabled']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(McpServerConfig $server): array
    {
        return [
            'id' => $server->getId(),
            'name' => $server->getName(),
            'url' => $server->getUrl(),
            'auth_header' => $server->getAuthHeader(),
            'has_auth_token' => $server->hasAuthToken(),
            'enabled' => $server->isEnabled(),
            'created' => $server->getCreated(),
            'updated' => $server->getUpdated(),
        ];
    }

    /**
     * Tool list shape for the UI (no input schemas — keep the payload lean).
     *
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>}> $tools
     *
     * @return list<array{name: string, description: string}>
     */
    private function publicTools(array $tools): array
    {
        return array_map(
            static fn (array $t): array => ['name' => $t['name'], 'description' => $t['description']],
            $tools,
        );
    }
}

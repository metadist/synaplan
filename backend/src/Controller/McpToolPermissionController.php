<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Mcp\McpToolPermissionListResponse;
use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Mcp\Permissions\McpToolPermissionException;
use App\Service\Mcp\Permissions\McpToolPermissionView;
use App\Service\Mcp\Permissions\McpToolPolicyStore;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Per-connection tool permissions: every tool a connection offers and what the
 * AI may do with it (auto, approve, block). The owner edits; a member who only
 * uses a shared connection can read the list but not change it.
 */
#[Route('/api/v1/mcp-servers/{id}', name: 'api_mcp_tool_permissions_', requirements: ['id' => '\d+'])]
#[OA\Tag(name: 'MCP Servers')]
final class McpToolPermissionController extends AbstractController
{
    public function __construct(
        private readonly McpServerConfigRepository $repository,
        private readonly McpToolRegistry $registry,
        private readonly McpClientConfig $clientConfig,
        private readonly McpToolPermissionView $view,
        private readonly McpToolPolicyStore $store,
    ) {
    }

    #[Route('/tools', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/mcp-servers/{id}/tools',
        summary: 'List the tools of a connection with the permission of each (cached discovery)',
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Tools and permissions', content: new OA\JsonContent(ref: new Model(type: McpToolPermissionListResponse::class))),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function list(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $found = $this->find($id, $user);
        if (null === $found) {
            return $this->json(['success' => false, 'error' => 'Server not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->listing($found[0], $user, $found[1]));
    }

    #[Route('/tool-permissions', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/mcp-servers/{id}/tool-permissions',
        summary: 'Set what the AI may do with one or more tools, or with a whole category',
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'tools', type: 'object', example: ['update-page' => 'approve', 'search' => 'default'], additionalProperties: new OA\AdditionalProperties(type: 'string', enum: ['auto', 'approve', 'block', 'default'])),
                new OA\Property(property: 'category', type: 'string', enum: ['read', 'write', 'destructive'], example: 'write'),
                new OA\Property(property: 'outcome', type: 'string', enum: ['auto', 'approve', 'block', 'default'], example: 'approve'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'The updated list', content: new OA\JsonContent(ref: new Model(type: McpToolPermissionListResponse::class))),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Shared connection: read only'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Unknown tool, unusable tool or unknown outcome'),
        ]
    )]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $found = $this->find($id, $user);
        if (null === $found) {
            return $this->json(['success' => false, 'error' => 'Server not found'], Response::HTTP_NOT_FOUND);
        }
        if ($found[1]) {
            return $this->json(['success' => false, 'error' => 'This connection is shared with you; only its owner can change permissions'], Response::HTTP_FORBIDDEN);
        }
        $classes = $this->view->classes($this->registry->toolsFor($found[0]));

        try {
            $this->apply($found[0], $this->body($request), $classes);
        } catch (McpToolPermissionException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->listing($found[0], $user, false));
    }

    #[Route('/tool-permissions/seen', name: 'seen', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/mcp-servers/{id}/tool-permissions/seen',
        summary: 'Mark every tool the connection offers right now as seen',
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The updated list', content: new OA\JsonContent(ref: new Model(type: McpToolPermissionListResponse::class))),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Shared connection: read only'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function seen(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $found = $this->find($id, $user);
        if (null === $found) {
            return $this->json(['success' => false, 'error' => 'Server not found'], Response::HTTP_NOT_FOUND);
        }
        if ($found[1]) {
            return $this->json(['success' => false, 'error' => 'This connection is shared with you'], Response::HTTP_FORBIDDEN);
        }
        $this->store->markSeen($found[0], $this->view->classes($this->registry->toolsFor($found[0])));

        return $this->json($this->listing($found[0], $user, false));
    }

    #[Route('/tool-permissions/notice/dismiss', name: 'dismiss_notice', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/mcp-servers/{id}/tool-permissions/notice/dismiss',
        summary: 'Close the one-time notice that writes no longer ask first',
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The updated list', content: new OA\JsonContent(ref: new Model(type: McpToolPermissionListResponse::class))),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Shared connection: read only'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function dismissNotice(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $found = $this->find($id, $user);
        if (null === $found) {
            return $this->json(['success' => false, 'error' => 'Server not found'], Response::HTTP_NOT_FOUND);
        }
        if ($found[1]) {
            return $this->json(['success' => false, 'error' => 'This connection is shared with you'], Response::HTTP_FORBIDDEN);
        }
        $this->store->dismissNotice($found[0]);

        return $this->json($this->listing($found[0], $user, false));
    }

    /**
     * The owner's own connection, or the shared one the member may read.
     *
     * @return array{0: McpServerConfig, 1: bool}|null server and whether it is shared
     */
    private function find(int $id, User $user): ?array
    {
        $own = $this->repository->findByIdAndUser($id, (int) $user->getId());
        if (null !== $own) {
            return [$own, false];
        }
        $shared = $this->registry->sharedServer($id, (int) $user->getId());

        return null !== $shared ? [$shared, true] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function listing(McpServerConfig $server, User $user, bool $shared): array
    {
        if (!$this->clientConfig->isClientEnabled((int) $user->getId())) {
            return ['success' => false, 'error' => 'The outbound MCP client is disabled', 'can_edit' => !$shared, 'notice' => false, 'tools' => []];
        }
        $tools = $this->registry->toolsFor($server);
        if ($shared) {
            $tools = array_values(array_filter($tools, static fn (array $t): bool => true === ($t['annotations']['readOnlyHint'] ?? null)));
        }

        return [
            'success' => true,
            'error' => null,
            'can_edit' => !$shared,
            'notice' => !$shared && $this->store->hasNotice($server),
            'tools' => $this->view->rows($server, (int) $user->getId(), $tools, $shared),
        ];
    }

    /**
     * @param array<string, mixed>      $body
     * @param array<string, SideEffect> $classes
     */
    private function apply(McpServerConfig $server, array $body, array $classes): void
    {
        if (isset($body['category'], $body['tools'])) {
            throw new McpToolPermissionException('Send either "tools" or "category" and "outcome", not both');
        }
        if (isset($body['category'])) {
            $category = is_string($body['category']) ? SideEffect::tryFromString($body['category']) : null;
            if (null === $category) {
                throw new McpToolPermissionException('Unknown category');
            }
            $this->store->setCategory($server, $category, $this->outcome($body['outcome'] ?? null), $classes);

            return;
        }
        $tools = $body['tools'] ?? null;
        if (!is_array($tools) || [] === $tools) {
            throw new McpToolPermissionException('Send "tools" or "category" and "outcome"');
        }
        $changes = [];
        foreach ($tools as $name => $value) {
            $changes[(string) $name] = $this->outcome($value);
        }
        $this->store->setTools($server, $changes, $classes);
    }

    private function outcome(mixed $value): ?PolicyOutcome
    {
        if ('default' === $value) {
            return null;
        }
        $outcome = is_string($value) ? PolicyOutcome::tryFrom($value) : null;
        if (null === $outcome) {
            throw new McpToolPermissionException('Unknown outcome; use auto, approve, block or default');
        }

        return $outcome;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}

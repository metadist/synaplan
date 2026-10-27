<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Mcp\McpOAuthException;
use App\Service\Mcp\Notion\NotionAppUnavailableException;
use App\Service\Mcp\Notion\NotionConnectionMissingException;
use App\Service\Mcp\Notion\NotionConnectionService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Apps → Notion. One connection, one writing switch, then chat can use it.
 */
#[Route('/api/v1/apps/notion', name: 'api_apps_notion_')]
#[OA\Tag(name: 'Notion')]
final class NotionAppController extends AbstractController
{
    public function __construct(
        private readonly NotionConnectionService $notion,
    ) {
    }

    #[Route('', name: 'status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/apps/notion',
        summary: 'Notion connection status for the signed-in user',
        description: '404 when Notion is turned off for the installation, so the app is absent.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Current Notion connection',
                content: new OA\JsonContent(
                    required: ['success', 'status', 'server_id', 'allow_write', 'write_auto', 'sharing'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'status', type: 'string', enum: ['not_connected', 'connected', 'reauth_required'], example: 'connected'),
                        new OA\Property(property: 'server_id', type: 'integer', nullable: true, example: 12),
                        new OA\Property(property: 'allow_write', type: 'boolean', example: false),
                        new OA\Property(property: 'write_auto', type: 'boolean', example: false),
                        new OA\Property(
                            property: 'sharing',
                            description: 'Organisation-wide sharing. shared_by names the admin whose connection this person can read with when they have none of their own.',
                            required: ['shared_by', 'can_share', 'shared_on'],
                            properties: [
                                new OA\Property(property: 'shared_by', type: 'string', nullable: true, example: 'Ada Lovelace'),
                                new OA\Property(property: 'can_share', type: 'boolean', example: false),
                                new OA\Property(property: 'shared_on', type: 'boolean', example: false),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off'),
        ]
    )]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            return $this->json($this->notion->status($user->getId(), $user->isAdmin()));
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/account', name: 'account', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/apps/notion/account',
        summary: 'Notion account and workspace of the connection',
        description: 'Asks Notion who the connection signs in as. Fields are null when Notion is not connected or does not answer; the call never fails for that reason.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Account details, or nulls when unknown',
                content: new OA\JsonContent(
                    required: ['success', 'workspace_name', 'user_name', 'user_email'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'workspace_name', type: 'string', nullable: true, example: 'Acme'),
                        new OA\Property(property: 'user_name', type: 'string', nullable: true, example: 'Ada Lovelace'),
                        new OA\Property(property: 'user_email', type: 'string', nullable: true, example: 'ada@example.com'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off'),
        ]
    )]
    public function account(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            return $this->json($this->notion->account($user->getId()));
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/connect', name: 'connect', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/apps/notion/connect',
        summary: 'Start the Notion sign-in',
        description: 'Creates the connection if needed and returns the Notion consent URL. The browser is sent back to /apps/notion.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Consent URL created',
                content: new OA\JsonContent(
                    required: ['success', 'authorize_url', 'server_id'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'authorize_url', type: 'string', example: 'https://mcp.notion.com/authorize?client_id=abc'),
                        new OA\Property(property: 'server_id', type: 'integer', example: 12),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Sign-in could not be started'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off'),
        ]
    )]
    public function connect(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            return $this->json($this->notion->connect($user->getId()));
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (McpOAuthException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('', name: 'writing', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/apps/notion',
        summary: 'Allow or stop the AI changing Notion',
        description: 'One switch. On: the AI may create and edit without asking each time. Off: the AI only reads. Deleting, moving and overwriting stay blocked either way.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['allow_write'],
                properties: [
                    new OA\Property(property: 'allow_write', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Writing preference saved',
                content: new OA\JsonContent(
                    required: ['success', 'status', 'server_id', 'allow_write', 'write_auto', 'sharing'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'status', type: 'string', enum: ['not_connected', 'connected', 'reauth_required']),
                        new OA\Property(property: 'server_id', type: 'integer', nullable: true),
                        new OA\Property(property: 'allow_write', type: 'boolean', example: true),
                        new OA\Property(property: 'write_auto', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'sharing',
                            description: 'Organisation-wide sharing. shared_by names the admin whose connection this person can read with when they have none of their own.',
                            required: ['shared_by', 'can_share', 'shared_on'],
                            properties: [
                                new OA\Property(property: 'shared_by', type: 'string', nullable: true, example: 'Ada Lovelace'),
                                new OA\Property(property: 'can_share', type: 'boolean', example: false),
                                new OA\Property(property: 'shared_on', type: 'boolean', example: false),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Notion is not connected, or the body is invalid'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off'),
        ]
    )]
    public function writing(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_bool($payload['allow_write'] ?? null)) {
            return $this->json(['success' => false, 'error' => 'Say whether the AI may change Notion.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->notion->setWriting($user->getId(), $payload['allow_write'], $user->isAdmin()));
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (NotionConnectionMissingException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/sharing', name: 'sharing', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/apps/notion/sharing',
        summary: 'Share your Notion connection with everyone, read-only',
        description: 'Admins only. Everyone without their own Notion connection can then let the AI read what this Notion account can read. Nobody else can change Notion through it.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['shared'],
                properties: [new OA\Property(property: 'shared', type: 'boolean', example: true)]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sharing saved; same body as GET /api/v1/apps/notion',
                content: new OA\JsonContent(
                    required: ['success', 'status', 'server_id', 'allow_write', 'write_auto', 'sharing'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'status', type: 'string', enum: ['not_connected', 'connected', 'reauth_required']),
                        new OA\Property(property: 'server_id', type: 'integer', nullable: true),
                        new OA\Property(property: 'allow_write', type: 'boolean'),
                        new OA\Property(property: 'write_auto', type: 'boolean'),
                        new OA\Property(
                            property: 'sharing',
                            description: 'Organisation-wide sharing. shared_by names the admin whose connection this person can read with when they have none of their own.',
                            required: ['shared_by', 'can_share', 'shared_on'],
                            properties: [
                                new OA\Property(property: 'shared_by', type: 'string', nullable: true, example: 'Ada Lovelace'),
                                new OA\Property(property: 'can_share', type: 'boolean', example: false),
                                new OA\Property(property: 'shared_on', type: 'boolean', example: false),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Not connected, not an admin, or the body is invalid'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off'),
        ]
    )]
    public function sharing(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_bool($payload['shared'] ?? null)) {
            return $this->json(['success' => false, 'error' => 'Say whether to share the connection.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->notion->setShared($user, $payload['shared']));
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (NotionConnectionMissingException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/shared/use', name: 'shared_use', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/apps/notion/shared/use',
        summary: 'Let chat read Notion through the shared connection',
        description: 'Turns on Notion in this person\'s general chat. Read-only: the AI never changes Notion through a shared connection.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Chat can read Notion now',
                content: new OA\JsonContent(
                    required: ['success'],
                    properties: [new OA\Property(property: 'success', type: 'boolean', example: true)]
                )
            ),
            new OA\Response(response: 400, description: 'Nobody shares a Notion connection'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off'),
        ]
    )]
    public function useShared(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->notion->useShared($user->getId());

            return $this->json(['success' => true]);
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (NotionConnectionMissingException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}

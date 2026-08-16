<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Connection\ConnectionService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/connections', name: 'api_connections_')]
#[OA\Tag(name: 'Connections')]
final class ConnectionController extends AbstractController
{
    public function __construct(
        private ConnectionService $connections,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/connections',
        summary: 'List connections for the current user (registry + existing mailbox/MCP adapters)',
        tags: ['Connections'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connection list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'connections', type: 'array', items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'string', example: 'mailbox:3'),
                                new OA\Property(property: 'source', type: 'string', example: 'inbound_email'),
                                new OA\Property(property: 'type', type: 'string', example: 'mailbox'),
                                new OA\Property(property: 'name', type: 'string', example: 'Work mailbox'),
                                new OA\Property(property: 'status', type: 'string', example: 'connected'),
                                new OA\Property(property: 'last_checked', type: 'integer', nullable: true),
                                new OA\Property(property: 'has_secret', type: 'boolean', example: true),
                                new OA\Property(property: 'manage_path', type: 'string', example: '/channels/email'),
                                new OA\Property(property: 'config', type: 'object', nullable: true),
                            ]
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
            'connections' => $this->connections->listForUser((int) $user->getId()),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/connections',
        summary: 'Create a connection. Secrets are stored in the vault and never returned.',
        tags: ['Connections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Work mailbox'),
                    new OA\Property(property: 'type', type: 'string', enum: ['generic', 'mailbox', 'mcp', 'webdav', 'webhook', 'caldav']),
                    new OA\Property(property: 'secret', type: 'string'),
                    new OA\Property(property: 'config', type: 'object'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'connection',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'string', example: '1'),
                                new OA\Property(property: 'source', type: 'string', example: 'registry'),
                                new OA\Property(property: 'type', type: 'string', example: 'webdav'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'status', type: 'string', example: 'connected'),
                                new OA\Property(property: 'last_checked', type: 'integer', nullable: true),
                                new OA\Property(property: 'has_secret', type: 'boolean', example: true),
                                new OA\Property(property: 'config', type: 'object', nullable: true),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid type'),
        ]
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->toArray();
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $type = is_string($data['type'] ?? null) ? $data['type'] : '';
        if ('' === $name || '' === $type) {
            return $this->json(['error' => 'name and type are required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $connection = $this->connections->create((int) $user->getId(), [
                'name' => $name,
                'type' => $type,
                'secret' => is_string($data['secret'] ?? null) ? $data['secret'] : '',
                'config' => is_array($data['config'] ?? null) ? $data['config'] : [],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $this->assertNoSecret($connection);

        return $this->json(['success' => true, 'connection' => $connection], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        path: '/api/v1/connections/{id}',
        summary: 'Update a registry connection. Secrets are never returned.',
        tags: ['Connections'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'connection',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'string', example: '1'),
                                new OA\Property(property: 'source', type: 'string', example: 'registry'),
                                new OA\Property(property: 'type', type: 'string', example: 'webdav'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'status', type: 'string', example: 'connected'),
                                new OA\Property(property: 'last_checked', type: 'integer', nullable: true),
                                new OA\Property(property: 'has_secret', type: 'boolean', example: true),
                                new OA\Property(property: 'config', type: 'object', nullable: true),
                            ]
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

        $connection = $this->connections->update($id, (int) $user->getId(), $request->toArray());
        if (null === $connection) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        $this->assertNoSecret($connection);

        return $this->json(['success' => true, 'connection' => $connection]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(path: '/api/v1/connections/{id}', summary: 'Delete a registry connection', tags: ['Connections'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->connections->delete($id, (int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/test', name: 'test', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/connections/{id}/test',
        summary: 'Test a registry connection',
        description: 'Types with a tester (currently Microsoft 365) are verified against the real system and report test_succeeded plus a readable error; other types only confirm that a credential is stored.',
        tags: ['Connections'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Test result',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true, description: 'The request was handled; see test_succeeded for the outcome'),
                        new OA\Property(
                            property: 'connection',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'string', example: '1'),
                                new OA\Property(property: 'source', type: 'string', example: 'registry'),
                                new OA\Property(property: 'type', type: 'string', example: 'webdav'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'status', type: 'string', example: 'connected', enum: ['never_tested', 'connected', 'error', 'reauth_required', 'disconnected']),
                                new OA\Property(property: 'last_checked', type: 'integer', nullable: true),
                                new OA\Property(property: 'has_secret', type: 'boolean', example: true),
                                new OA\Property(property: 'config', type: 'object', nullable: true),
                                new OA\Property(property: 'test_succeeded', type: 'boolean', nullable: true, description: 'Present only for types with a tester'),
                                new OA\Property(property: 'test_error', type: 'string', nullable: true, description: 'Readable reason when test_succeeded is false'),
                                new OA\Property(property: 'account', type: 'string', nullable: true, description: 'Remote account the connection points at, when the tester can report one'),
                            ]
                        ),
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

        $connection = $this->connections->test($id, (int) $user->getId());
        if (null === $connection) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'connection' => $connection]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertNoSecret(array $payload): void
    {
        foreach (['secret', 'password', 'token', 'BSECRET'] as $key) {
            if (array_key_exists($key, $payload)) {
                throw new \LogicException('Connection payload leaked a secret field');
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\NotionWatchResponse;
use App\DTO\NotionWatchState;
use App\Entity\User;
use App\Service\Mcp\Notion\NotionActionException;
use App\Service\Mcp\Notion\NotionAppUnavailableException;
use App\Service\Mcp\Notion\NotionConnectionService;
use App\Service\Mcp\Notion\NotionWatchService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Notion pages a person follows: keep a searchable copy, or start a saved
 * task when the page changes.
 */
#[Route('/api/v1/apps/notion/watches', name: 'api_apps_notion_watches_')]
#[OA\Tag(name: 'Notion')]
final class NotionWatchController extends AbstractController
{
    public function __construct(
        private readonly NotionConnectionService $notion,
        private readonly NotionWatchService $watches,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/apps/notion/watches',
        summary: 'Followed Notion pages and the saved tasks they can start',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Followed pages',
                content: new OA\JsonContent(
                    required: ['success', 'watches', 'tasks', 'limit'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'watches', type: 'array', items: new OA\Items(ref: new Model(type: NotionWatchState::class))),
                        new OA\Property(
                            property: 'tasks',
                            description: 'Saved tasks the person starts themselves; one of them can run on a change',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'name'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 3),
                                    new OA\Property(property: 'name', type: 'string', example: 'Summarise for the team'),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(property: 'limit', type: 'integer', example: 20),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->notion->assertAvailable($user->getId());
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'watches' => $this->watches->list($user->getId()),
            'tasks' => $this->watches->tasks($user->getId()),
            'limit' => NotionWatchService::MAX_PER_USER,
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/apps/notion/watches',
        summary: 'Follow a Notion page',
        description: 'Checks the page right away and then about every 15 minutes. searchable keeps a copy in a knowledge folder; task starts the chosen saved task with the page text when the page changes.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['page', 'action'],
                properties: [
                    new OA\Property(property: 'page', type: 'string', example: 'https://www.notion.so/Roadmap-0123456789abcdef0123456789abcdef'),
                    new OA\Property(property: 'action', type: 'string', enum: ['searchable', 'task'], example: 'searchable'),
                    new OA\Property(property: 'folder', type: 'string', nullable: true, example: 'Notion'),
                    new OA\Property(property: 'task_id', type: 'integer', nullable: true, example: 3),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Page followed', content: new OA\JsonContent(ref: new Model(type: NotionWatchResponse::class))),
            new OA\Response(response: 400, description: 'Not followed; the error says why'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off'),
        ]
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_string($payload['page'] ?? null) || !is_string($payload['action'] ?? null)) {
            return $this->json(['success' => false, 'error' => 'Paste the link of a Notion page and choose what happens on a change.'], Response::HTTP_BAD_REQUEST);
        }
        $folder = is_string($payload['folder'] ?? null) ? $payload['folder'] : null;
        $taskId = is_int($payload['task_id'] ?? null) ? $payload['task_id'] : null;

        try {
            $this->notion->assertAvailable($user->getId());
            $watch = $this->watches->create($user->getId(), $payload['page'], $payload['action'], $folder, $taskId);

            return $this->json(['success' => true, 'watch' => $watch], Response::HTTP_CREATED);
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (NotionActionException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/apps/notion/watches/{id}',
        summary: 'Pause or resume following a Notion page',
        description: 'Resuming checks the page right away.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['enabled'], properties: [new OA\Property(property: 'enabled', type: 'boolean', example: false)])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(ref: new Model(type: NotionWatchResponse::class))),
            new OA\Response(response: 400, description: 'Invalid body'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off, or no such followed page'),
        ]
    )]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_bool($payload['enabled'] ?? null)) {
            return $this->json(['success' => false, 'error' => 'Say whether to keep following the page.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->notion->assertAvailable($user->getId());
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
        $watch = $this->watches->setEnabled($user->getId(), $id, $payload['enabled']);

        return null === $watch
            ? $this->json(['success' => false, 'error' => 'This page is not followed any more.'], Response::HTTP_NOT_FOUND)
            : $this->json(['success' => true, 'watch' => $watch]);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/apps/notion/watches/{id}',
        summary: 'Stop following a Notion page',
        description: 'The searchable copy stays in its folder until you delete it there.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'No longer followed',
                content: new OA\JsonContent(required: ['success'], properties: [new OA\Property(property: 'success', type: 'boolean', example: true)])
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off, or no such followed page'),
        ]
    )]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->notion->assertAvailable($user->getId());
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->watches->delete($user->getId(), $id)
            ? $this->json(['success' => true])
            : $this->json(['success' => false, 'error' => 'This page is not followed any more.'], Response::HTTP_NOT_FOUND);
    }
}

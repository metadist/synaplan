<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Module\Http\ModuleGateListener;
use App\Service\Project\Item\ProjectItemPresenter;
use App\Service\Project\Item\ProjectItemService;
use App\Service\Project\ProjectException;
use App\Service\Project\ProjectsConfig;
use App\Service\Project\ProjectService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The items of a project's board: HTTP only, the rules live in
 * `App\Service\Project\Item`. Every route answers 404
 * `feature_not_configured` while `PROJECTS.ENABLED` or
 * `PROJECTS.BOARD_ENABLED` is off.
 */
#[Route('/api/v1/projects/{uuid}/items', name: 'api_projects_items_', requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
#[OA\Tag(name: 'Projects')]
final class ProjectItemController extends AbstractController
{
    private const ITEM_ID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectItemService $items,
        private readonly ProjectItemPresenter $presenter,
        private readonly ProjectsConfig $config,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/projects/{uuid}/items',
        summary: 'The items of a project\'s board that the signed-in person may see',
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['task', 'date', 'decision', 'cost', 'note'])),
            new OA\Parameter(name: 'state', in: 'query', required: false, description: 'Without it, dropped items are left out.', schema: new OA\Schema(type: 'string', enum: ['proposed', 'open', 'done', 'dropped'])),
            new OA\Parameter(name: 'waiting', in: 'query', required: false, description: '`1`: only open tasks that wait on someone else ("Waiting on others").', schema: new OA\Schema(type: 'string', enum: ['1'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Items in plan order, then by time', content: new OA\JsonContent(
                required: ['success', 'items'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectBoardItem')),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project, or the board is off (feature_not_configured)'),
            new OA\Response(response: 422, description: 'Unknown kind or state'),
        ]
    )]
    public function list(string $uuid, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);
        $kind = $request->query->get('kind');
        $state = $request->query->get('state');
        $waiting = '1' === $request->query->get('waiting');

        return $this->respond(function () use ($uuid, $user, $kind, $state, $waiting): JsonResponse {
            $project = $this->owned($uuid, $user);
            $items = $this->items->list($project, (int) $user->getId(), \is_string($kind) ? $kind : null, \is_string($state) ? $state : null, $waiting);

            return $this->json(['success' => true, 'items' => $this->presenter->items((int) $project->getId(), $items)]);
        });
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/projects/{uuid}/items',
        summary: 'Add an item to a project\'s board by hand; it is written at once (state open)',
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectBoardItemInput')),
        responses: [
            new OA\Response(response: 201, description: 'The item', content: new OA\JsonContent(
                required: ['success', 'item'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'item', ref: '#/components/schemas/ProjectBoardItem'),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project, or the board is off (feature_not_configured)'),
            new OA\Response(response: 422, description: 'A field is missing, not allowed for the kind or invalid, or the project holds PROJECTS.MAX_ITEMS items'),
        ]
    )]
    public function create(string $uuid, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);
        $body = $this->body($request);

        return $this->respond(fn (): JsonResponse => $this->json([
            'success' => true,
            'item' => $this->presenter->item($this->items->create($this->owned($uuid, $user), (int) $user->getId(), $body)),
        ], Response::HTTP_CREATED));
    }

    #[Route('/{itemId}', name: 'get', methods: ['GET'], requirements: ['itemId' => self::ITEM_ID])]
    #[OA\Get(
        path: '/api/v1/projects/{uuid}/items/{itemId}',
        summary: 'One item of a project\'s board',
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The item', content: new OA\JsonContent(
                required: ['success', 'item'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'item', ref: '#/components/schemas/ProjectBoardItem'),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project or item, or the board is off'),
        ]
    )]
    public function get(string $uuid, string $itemId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(fn (): JsonResponse => $this->json([
            'success' => true,
            'item' => $this->presenter->item($this->items->get($this->owned($uuid, $user), $itemId, (int) $user->getId())),
        ]));
    }

    #[Route('/{itemId}', name: 'update', methods: ['PATCH'], requirements: ['itemId' => self::ITEM_ID])]
    #[OA\Patch(
        path: '/api/v1/projects/{uuid}/items/{itemId}',
        summary: 'Change, finish, drop, restore, accept or reject an item',
        description: 'Only the fields sent are changed. `state`: proposed → open (accept) or dropped (reject); open → done or dropped; done → open or dropped; dropped → open. A note is never done. Moving a date moves the tasks that belong to it. The kind cannot be changed.',
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectBoardItemChange')),
        responses: [
            new OA\Response(response: 200, description: 'The changed item', content: new OA\JsonContent(
                required: ['success', 'item'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'item', ref: '#/components/schemas/ProjectBoardItem'),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project or item, or the board is off'),
            new OA\Response(response: 422, description: 'A field is not allowed for the kind or invalid, or the state change is not allowed'),
        ]
    )]
    public function update(string $uuid, string $itemId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);
        $body = $this->body($request);

        return $this->respond(fn (): JsonResponse => $this->json([
            'success' => true,
            'item' => $this->presenter->item($this->items->update($this->owned($uuid, $user), $itemId, (int) $user->getId(), $body)),
        ]));
    }

    private function owned(string $uuid, User $user): Project
    {
        return $this->projects->get($uuid, (int) $user->getId());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true);

        return \is_array($body) ? $body : [];
    }

    /**
     * @param callable(): JsonResponse $action
     */
    private function respond(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ProjectException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode());
        }
    }

    private function deny(?User $user): ?JsonResponse
    {
        if (!$this->config->isBoardEnabled()) {
            return $this->json(['error' => 'The project board is not available', 'code' => ModuleGateListener::ERROR_CODE], Response::HTTP_NOT_FOUND);
        }
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }
}

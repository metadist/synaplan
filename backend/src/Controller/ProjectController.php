<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Chat;
use App\Entity\Project;
use App\Entity\User;
use App\Module\Http\ModuleGateListener;
use App\Repository\ChatRepository;
use App\Service\Project\ProjectChatMembership;
use App\Service\Project\ProjectException;
use App\Service\Project\ProjectPresenter;
use App\Service\Project\ProjectsConfig;
use App\Service\Project\ProjectService;
use App\Service\Project\ProjectSourceService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Projects: HTTP only. The rules live in the services under
 * `App\Service\Project`. Every route answers 404 `feature_not_configured`
 * while `PROJECTS.ENABLED` is off (the module gate).
 */
#[Route('/api/v1/projects', name: 'api_projects_')]
#[OA\Tag(name: 'Projects')]
final class ProjectController extends AbstractController
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectSourceService $sources,
        private readonly ProjectChatMembership $membership,
        private readonly ProjectPresenter $presenter,
        private readonly ChatRepository $chats,
        private readonly ProjectsConfig $config,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/projects',
        summary: 'List the signed-in account\'s projects',
        tags: ['Projects'],
        responses: [
            new OA\Response(response: 200, description: 'Projects, most recently changed first', content: new OA\JsonContent(
                required: ['success', 'projects'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'projects', type: 'array', items: new OA\Items(ref: '#/components/schemas/Project')),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Projects are turned off (feature_not_configured)'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->json(['success' => true, 'projects' => array_map($this->presenter->project(...), $this->projects->list((int) $user->getId()))]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/projects',
        summary: 'Create a project',
        tags: ['Projects'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'New kitchen'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The project', content: new OA\JsonContent(
                required: ['success', 'project'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'project', ref: '#/components/schemas/Project'),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Projects are turned off (feature_not_configured)'),
            new OA\Response(response: 422, description: 'The name is empty or too long'),
        ]
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);
        $name = $this->body($request)['name'] ?? null;

        return $this->respond(fn (): JsonResponse => $this->json([
            'success' => true,
            'project' => $this->presenter->project($this->projects->create((int) $user->getId(), \is_string($name) ? $name : '')),
        ], Response::HTTP_CREATED));
    }

    #[Route('/{uuid}', name: 'get', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    #[OA\Get(
        path: '/api/v1/projects/{uuid}',
        summary: 'One project',
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The project', content: new OA\JsonContent(
                required: ['success', 'project'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'project', ref: '#/components/schemas/Project'),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project, or the feature is off'),
        ]
    )]
    public function get(string $uuid, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(fn (): JsonResponse => $this->json(['success' => true, 'project' => $this->presenter->project($this->owned($uuid, $user))]));
    }

    #[Route('/{uuid}', name: 'update', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    #[OA\Patch(
        path: '/api/v1/projects/{uuid}',
        summary: 'Change a project\'s name, description or instructions',
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 120),
            new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
            new OA\Property(property: 'instructions', type: 'string', nullable: true, description: 'Goes into the system prompt of the project\'s chats. Cut at PROJECTS.INSTRUCTIONS_MAX_CHARS.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The changed project', content: new OA\JsonContent(
                required: ['success', 'project'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'project', ref: '#/components/schemas/Project'),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project, or the feature is off'),
            new OA\Response(response: 422, description: 'A field is too long or not text'),
        ]
    )]
    public function update(string $uuid, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(fn (): JsonResponse => $this->json([
            'success' => true,
            'project' => $this->presenter->project($this->projects->update($this->owned($uuid, $user), $this->body($request))),
        ]));
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    #[OA\Delete(
        path: '/api/v1/projects/{uuid}',
        summary: 'Delete a project and its sources',
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'chats', in: 'query', required: false, description: '`keep` (default): the chats stay as plain chats and lose the sources. `delete`: they are deleted too.', schema: new OA\Schema(type: 'string', enum: ['keep', 'delete'], default: 'keep')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(required: ['success'], properties: [new OA\Property(property: 'success', type: 'boolean', example: true)])),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project, or the feature is off'),
            new OA\Response(response: 422, description: '`chats` is neither keep nor delete'),
        ]
    )]
    public function delete(string $uuid, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);
        $chats = (string) $request->query->get('chats', ProjectService::CHATS_KEEP);

        return $this->respond(function () use ($uuid, $user, $chats): JsonResponse {
            $this->projects->delete($this->owned($uuid, $user), $chats);

            return $this->json(['success' => true]);
        });
    }

    #[Route('/{uuid}/sources', name: 'sources_list', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    #[OA\Get(
        path: '/api/v1/projects/{uuid}/sources',
        summary: 'The sources of a project and where each stands',
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Sources, newest first', content: new OA\JsonContent(
                required: ['success', 'sources'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'sources', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectSource')),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project, or the feature is off'),
        ]
    )]
    public function sourcesList(string $uuid, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(fn (): JsonResponse => $this->json([
            'success' => true,
            'sources' => array_map(static fn ($s): array => $s->toArray(), $this->sources->list($this->owned($uuid, $user))),
        ]));
    }

    #[Route('/{uuid}/sources', name: 'sources_add', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    #[OA\Post(
        path: '/api/v1/projects/{uuid}/sources',
        summary: 'Add sources: files (multipart `files[]`), or JSON `{type: "link", url}`, or `{type: "text", title, text}`',
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: [
            new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(properties: [
                new OA\Property(property: 'files[]', type: 'array', items: new OA\Items(type: 'string', format: 'binary')),
            ])),
            new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['link', 'text']),
                new OA\Property(property: 'url', type: 'string', format: 'uri'),
                new OA\Property(property: 'title', type: 'string', maxLength: 200),
                new OA\Property(property: 'text', type: 'string'),
            ])),
        ]),
        responses: [
            new OA\Response(response: 201, description: 'The sources that were added; a link that could not be read comes back with state `failed` and a reason', content: new OA\JsonContent(
                required: ['success', 'sources', 'errors'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'sources', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectSource')),
                    new OA\Property(property: 'errors', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'filename', type: 'string'),
                        new OA\Property(property: 'error', type: 'string'),
                    ], type: 'object')),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project, or the feature is off'),
            new OA\Response(response: 409, description: 'A limit was reached (sources or storage)'),
            new OA\Response(response: 429, description: 'Too many sources read in a short time'),
            new OA\Response(response: 422, description: 'The request names no valid source'),
        ]
    )]
    public function sourcesAdd(string $uuid, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(function () use ($uuid, $request, $user): JsonResponse {
            $project = $this->owned($uuid, $user);
            $files = array_values(array_filter((array) $request->files->get('files', []), static fn ($f): bool => $f instanceof UploadedFile));
            if ([] !== $files) {
                $result = $this->sources->addFiles($project, $user, $files);

                return $this->json(['success' => true, 'sources' => array_map(static fn ($s): array => $s->toArray(), $result['added']), 'errors' => $result['errors']], Response::HTTP_CREATED);
            }
            $body = $this->body($request);
            $source = match ($body['type'] ?? null) {
                'link' => $this->sources->addLink($project, $user, \is_string($body['url'] ?? null) ? $body['url'] : ''),
                'text' => $this->sources->addText($project, $user, \is_string($body['title'] ?? null) ? $body['title'] : '', \is_string($body['text'] ?? null) ? $body['text'] : ''),
                default => throw ProjectException::invalid('Send files, or type "link" with url, or type "text" with title and text'),
            };

            return $this->json(['success' => true, 'sources' => [$source->toArray()], 'errors' => []], Response::HTTP_CREATED);
        });
    }

    #[Route('/{uuid}/sources/{fileId}/refresh', name: 'sources_refresh', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}', 'fileId' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/projects/{uuid}/sources/{fileId}/refresh',
        summary: 'Fetch a link source again into the same row',
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'fileId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The source after the refresh', content: new OA\JsonContent(
                required: ['success', 'source'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'source', ref: '#/components/schemas/ProjectSource'),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project or source, or the feature is off'),
            new OA\Response(response: 409, description: 'The storage limit was reached; the earlier snapshot stays'),
            new OA\Response(response: 422, description: 'The source is not a link'),
            new OA\Response(response: 429, description: 'Too many sources read in a short time'),
            new OA\Response(response: 502, description: 'The page could not be read; the earlier snapshot stays'),
        ]
    )]
    public function sourcesRefresh(string $uuid, int $fileId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(fn (): JsonResponse => $this->json([
            'success' => true,
            'source' => $this->sources->refresh($this->owned($uuid, $user), $user, $fileId)->toArray(),
        ]));
    }

    #[Route('/{uuid}/sources/{fileId}', name: 'sources_remove', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}', 'fileId' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/projects/{uuid}/sources/{fileId}',
        summary: 'Remove a source and its index entries',
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'fileId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Removed', content: new OA\JsonContent(required: ['success'], properties: [new OA\Property(property: 'success', type: 'boolean', example: true)])),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project or source, or the feature is off'),
        ]
    )]
    public function sourcesRemove(string $uuid, int $fileId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(function () use ($uuid, $fileId, $user): JsonResponse {
            $this->sources->remove($this->owned($uuid, $user), $fileId);

            return $this->json(['success' => true]);
        });
    }

    #[Route('/{uuid}/chats', name: 'chats_list', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    #[OA\Get(
        path: '/api/v1/projects/{uuid}/chats',
        summary: 'The chats in a project',
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chats, most recently changed first', content: new OA\JsonContent(
                required: ['success', 'chats'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'chats', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectChat')),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project, or the feature is off'),
        ]
    )]
    public function chatsList(string $uuid, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(function () use ($uuid, $user): JsonResponse {
            $project = $this->owned($uuid, $user);
            /** @var list<Chat> $chats */
            $chats = $this->chats->findBy(['userId' => $project->getUserId(), 'projectId' => $project->getId()], ['updatedAt' => 'DESC']);

            return $this->json(['success' => true, 'chats' => array_map($this->presenter->chat(...), $chats)]);
        });
    }

    #[Route('/{uuid}/chats', name: 'chats_create', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    #[OA\Post(
        path: '/api/v1/projects/{uuid}/chats',
        summary: 'Start a new chat inside a project',
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Tiles and grout')])),
        responses: [
            new OA\Response(response: 201, description: 'The new chat', content: new OA\JsonContent(
                required: ['success', 'chat'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'chat', ref: '#/components/schemas/ProjectChat'),
                ]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project, or the feature is off'),
        ]
    )]
    public function chatsCreate(string $uuid, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(function () use ($uuid, $request, $user): JsonResponse {
            $title = $this->body($request)['title'] ?? null;
            $chat = $this->membership->start($this->owned($uuid, $user), \is_string($title) ? $title : null);

            return $this->json(['success' => true, 'chat' => $this->presenter->chat($chat)], Response::HTTP_CREATED);
        });
    }

    #[Route('/{uuid}/chats/{chatId}', name: 'chats_join', methods: ['PUT'], requirements: ['uuid' => '[0-9a-fA-F-]{36}', 'chatId' => '\d+'])]
    #[OA\Put(
        path: '/api/v1/projects/{uuid}/chats/{chatId}',
        summary: 'Move one of the account\'s chats into the project',
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'chatId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The chat is in the project; its later turns search the sources', content: new OA\JsonContent(required: ['success'], properties: [new OA\Property(property: 'success', type: 'boolean', example: true)])),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project or chat, or the feature is off'),
            new OA\Response(response: 422, description: 'The chat is not a web chat'),
        ]
    )]
    public function chatsJoin(string $uuid, int $chatId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(function () use ($uuid, $chatId, $user): JsonResponse {
            $project = $this->owned($uuid, $user);
            $this->membership->move($this->ownChat($chatId, $user), $project->getUuid());

            return $this->json(['success' => true]);
        });
    }

    #[Route('/{uuid}/chats/{chatId}', name: 'chats_leave', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}', 'chatId' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/projects/{uuid}/chats/{chatId}',
        summary: 'Take a chat out of the project; its later turns no longer see the sources',
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'chatId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The chat is a plain chat again', content: new OA\JsonContent(required: ['success'], properties: [new OA\Property(property: 'success', type: 'boolean', example: true)])),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No such project or chat, or the feature is off'),
        ]
    )]
    public function chatsLeave(string $uuid, int $chatId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $denied = $this->deny($user)) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->respond(function () use ($uuid, $chatId, $user): JsonResponse {
            $project = $this->owned($uuid, $user);
            $chat = $this->ownChat($chatId, $user);
            if ($chat->getProjectId() !== $project->getId()) {
                throw ProjectException::notFound('Chat');
            }
            $this->membership->move($chat, null);

            return $this->json(['success' => true]);
        });
    }

    private function owned(string $uuid, User $user): Project
    {
        return $this->projects->get($uuid, (int) $user->getId());
    }

    private function ownChat(int $chatId, User $user): Chat
    {
        $chat = $this->chats->find($chatId);
        if (!$chat instanceof Chat || $chat->getUserId() !== $user->getId()) {
            throw ProjectException::notFound('Chat');
        }

        return $chat;
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

    /**
     * The flag is checked here as well as by the module gate: an install
     * whose gate row is missing must still answer 404 while projects are off.
     */
    private function deny(?User $user): ?JsonResponse
    {
        if (!$this->config->isEnabled()) {
            return $this->json(['error' => 'Projects are not available', 'code' => ModuleGateListener::ERROR_CODE], Response::HTTP_NOT_FOUND);
        }
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }
}

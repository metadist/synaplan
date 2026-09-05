<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\SavedTaskRunRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\Exception\AssistantNotSharedException;
use App\Service\Iam\ResourceKind\SavedTaskKind;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\SavedTaskDisabledException;
use App\Service\SavedTask\SavedTaskNotFoundException;
use App\Service\SavedTask\SavedTaskRunner;
use App\Service\SavedTask\SavedTaskSerializer;
use App\Service\SavedTask\SavedTaskService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/saved-tasks', name: 'api_saved_tasks_')]
#[OA\Tag(name: 'Saved Tasks')]
final class SavedTaskController extends AbstractController
{
    public function __construct(
        private SavedTaskConfig $config,
        private SavedTaskService $service,
        private SavedTaskRunner $runner,
        private SavedTaskRunRepository $runs,
        private SavedTaskSerializer $serializer,
        private ShareRepository $shareRepository,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/saved-tasks',
        summary: 'List Saved Tasks for the current user',
        tags: ['Saved Tasks'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Saved Task list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'tasks', type: 'array', items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'promptId', type: 'integer', example: 12),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'enabled', type: 'boolean'),
                                new OA\Property(property: 'triggerType', type: 'string'),
                                new OA\Property(property: 'triggerConfig', type: 'object', nullable: true),
                                new OA\Property(property: 'graph', type: 'object', nullable: true),
                                new OA\Property(property: 'allowUnattended', type: 'boolean'),
                                new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                                new OA\Property(property: 'nextRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'lastRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'consecutiveFailures', type: 'integer'),
                                new OA\Property(property: 'autoPaused', type: 'boolean'),
                                new OA\Property(property: 'summary', type: 'object', properties: [
                                    new OA\Property(property: 'key', type: 'string'),
                                    new OA\Property(property: 'params', type: 'object'),
                                ]),
                                new OA\Property(property: 'instructionPreview', type: 'string', nullable: true, description: 'First ~60 characters of the underlying instruction, for the task card.'),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $tasks = array_map(
            fn ($task) => $this->serializer->task($task),
            $this->service->listForOwner((int) $user->getId()),
        );

        return $this->json(['success' => true, 'tasks' => $tasks]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/saved-tasks',
        summary: 'Save a Task Prompt as a Saved Task',
        tags: ['Saved Tasks'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['promptId', 'name'],
                properties: [
                    new OA\Property(property: 'promptId', type: 'integer'),
                    new OA\Property(property: 'name', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created or existing task',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'task',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'promptId', type: 'integer', example: 12),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'enabled', type: 'boolean'),
                                new OA\Property(property: 'triggerType', type: 'string'),
                                new OA\Property(property: 'triggerConfig', type: 'object', nullable: true),
                                new OA\Property(property: 'graph', type: 'object', nullable: true),
                                new OA\Property(property: 'allowUnattended', type: 'boolean'),
                                new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                                new OA\Property(property: 'nextRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'lastRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'consecutiveFailures', type: 'integer'),
                                new OA\Property(property: 'autoPaused', type: 'boolean'),
                                new OA\Property(property: 'summary', type: 'object', properties: [
                                    new OA\Property(property: 'key', type: 'string'),
                                    new OA\Property(property: 'params', type: 'object'),
                                ]),
                                new OA\Property(property: 'instructionPreview', type: 'string', nullable: true, description: 'First ~60 characters of the underlying instruction, for the task card.'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid payload'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $data = $request->toArray();
        $promptId = (int) ($data['promptId'] ?? 0);
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ($promptId < 1 || '' === $name) {
            return $this->json(['error' => 'promptId and name are required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $task = $this->service->create((int) $user->getId(), $promptId, $name);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'task' => $this->serializer->task($task)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        path: '/api/v1/saved-tasks/{id}',
        summary: 'Update a Saved Task',
        tags: ['Saved Tasks'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated task',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'task',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'promptId', type: 'integer', example: 12),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'enabled', type: 'boolean'),
                                new OA\Property(property: 'triggerType', type: 'string'),
                                new OA\Property(property: 'triggerConfig', type: 'object', nullable: true),
                                new OA\Property(property: 'graph', type: 'object', nullable: true),
                                new OA\Property(property: 'allowUnattended', type: 'boolean'),
                                new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                                new OA\Property(property: 'nextRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'lastRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'consecutiveFailures', type: 'integer'),
                                new OA\Property(property: 'autoPaused', type: 'boolean'),
                                new OA\Property(property: 'summary', type: 'object', properties: [
                                    new OA\Property(property: 'key', type: 'string'),
                                    new OA\Property(property: 'params', type: 'object'),
                                ]),
                                new OA\Property(property: 'instructionPreview', type: 'string', nullable: true, description: 'First ~60 characters of the underlying instruction, for the task card.'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid payload'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $task = $this->service->getOwned($id, (int) $user->getId());
        if (null === $task) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $task = $this->service->update($task, $request->toArray());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'task' => $this->serializer->task($task)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/saved-tasks/{id}',
        summary: 'Delete a Saved Task',
        tags: ['Saved Tasks'],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $task = $this->service->getOwned($id, (int) $user->getId());
        if (null === $task) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        $this->shareRepository->deleteByResource(SavedTaskKind::KEY, (string) $id);
        $this->service->delete($task);

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/copy', name: 'copy', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/saved-tasks/{id}/copy',
        summary: 'Run a shared Saved Task as my copy',
        tags: ['Saved Tasks'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Copied task',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'task',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'promptId', type: 'integer', example: 12),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'enabled', type: 'boolean'),
                                new OA\Property(property: 'triggerType', type: 'string'),
                                new OA\Property(property: 'triggerConfig', type: 'object', nullable: true),
                                new OA\Property(property: 'graph', type: 'object', nullable: true),
                                new OA\Property(property: 'allowUnattended', type: 'boolean'),
                                new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                                new OA\Property(property: 'nextRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'lastRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'consecutiveFailures', type: 'integer'),
                                new OA\Property(property: 'autoPaused', type: 'boolean'),
                                new OA\Property(property: 'summary', type: 'object', properties: [
                                    new OA\Property(property: 'key', type: 'string'),
                                    new OA\Property(property: 'params', type: 'object'),
                                ]),
                                new OA\Property(property: 'instructionPreview', type: 'string', nullable: true),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'Assistant is not shared'),
        ]
    )]
    public function copy(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $task = $this->service->getForRead($id, $user);
        if (null === $task) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $copy = $this->service->copyForOwner($task, $user);
        } catch (SavedTaskNotFoundException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        } catch (AssistantNotSharedException) {
            return $this->json(['error' => 'iam.assistantNotShared'], Response::HTTP_CONFLICT);
        }

        return $this->json(['success' => true, 'task' => $this->serializer->task($copy)], Response::HTTP_CREATED);
    }

    #[Route('/{id}/run', name: 'run', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/saved-tasks/{id}/run',
        summary: 'Run a Saved Task now',
        tags: ['Saved Tasks'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', description: 'Optional extra instruction for this run. When omitted, the task runs its stored instruction.')]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Run result',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'task',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'promptId', type: 'integer', example: 12),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'enabled', type: 'boolean'),
                                new OA\Property(property: 'triggerType', type: 'string'),
                                new OA\Property(property: 'triggerConfig', type: 'object', nullable: true),
                                new OA\Property(property: 'graph', type: 'object', nullable: true),
                                new OA\Property(property: 'allowUnattended', type: 'boolean'),
                                new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                                new OA\Property(property: 'nextRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'lastRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'consecutiveFailures', type: 'integer'),
                                new OA\Property(property: 'autoPaused', type: 'boolean'),
                                new OA\Property(property: 'summary', type: 'object', properties: [
                                    new OA\Property(property: 'key', type: 'string'),
                                    new OA\Property(property: 'params', type: 'object'),
                                ]),
                                new OA\Property(property: 'instructionPreview', type: 'string', nullable: true, description: 'First ~60 characters of the underlying instruction, for the task card.'),
                            ]
                        ),
                        new OA\Property(
                            property: 'run',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'status', type: 'string'),
                                new OA\Property(property: 'trigger', type: 'string'),
                                new OA\Property(property: 'messageId', type: 'integer', nullable: true),
                                new OA\Property(property: 'planSnapshot', type: 'object', nullable: true),
                                new OA\Property(property: 'error', type: 'string', nullable: true),
                                new OA\Property(property: 'started', type: 'string', nullable: true),
                                new OA\Property(property: 'finished', type: 'string', nullable: true),
                                new OA\Property(property: 'created', type: 'integer'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid payload'),
            new OA\Response(response: 403, description: 'Task is disabled'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function run(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $payload = '' !== $request->getContent() ? $request->toArray() : [];
        $message = $payload['message'] ?? '';
        if (!is_string($message)) {
            return $this->json(['error' => 'message must be a string'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Blank message → the runner falls back to the task's stored instruction.
            $result = $this->runner->run((int) $user->getId(), $id, $message, 'manual');
        } catch (SavedTaskNotFoundException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        } catch (SavedTaskDisabledException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'task' => $this->serializer->task($result['task']),
            'run' => $this->serializer->run($result['run']),
        ]);
    }

    #[Route('/{id}/runs', name: 'runs', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/saved-tasks/{id}/runs',
        summary: 'List recent runs',
        tags: ['Saved Tasks'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Recent runs',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'runs', type: 'array', items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'status', type: 'string'),
                                new OA\Property(property: 'trigger', type: 'string'),
                                new OA\Property(property: 'messageId', type: 'integer', nullable: true),
                                new OA\Property(property: 'planSnapshot', type: 'object', nullable: true),
                                new OA\Property(property: 'error', type: 'string', nullable: true),
                                new OA\Property(property: 'started', type: 'string', nullable: true),
                                new OA\Property(property: 'finished', type: 'string', nullable: true),
                                new OA\Property(property: 'created', type: 'integer'),
                            ]
                        )),
                        new OA\Property(property: 'total', type: 'integer', example: 3),
                        new OA\Property(property: 'retention', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function runs(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $task = $this->service->getOwned($id, (int) $user->getId());
        if (null === $task || null === $task->getId()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));
        $offset = max(0, (int) $request->query->get('offset', 0));
        $runs = array_map(
            fn ($run) => $this->serializer->run($run),
            $this->runs->findRecentForTask($task->getId(), $limit, $offset),
        );

        return $this->json([
            'success' => true,
            'runs' => $runs,
            'total' => $this->runs->countForTask($task->getId()),
            'retention' => 'Last 50 runs or 90 days, whichever keeps more history.',
        ]);
    }

    #[Route('/{id}/resume', name: 'resume', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/saved-tasks/{id}/resume',
        summary: 'Resume an auto-paused Saved Task',
        tags: ['Saved Tasks'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resumed task',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'task',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'promptId', type: 'integer', example: 12),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'enabled', type: 'boolean'),
                                new OA\Property(property: 'triggerType', type: 'string'),
                                new OA\Property(property: 'triggerConfig', type: 'object', nullable: true),
                                new OA\Property(property: 'graph', type: 'object', nullable: true),
                                new OA\Property(property: 'allowUnattended', type: 'boolean'),
                                new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                                new OA\Property(property: 'nextRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'lastRunAt', type: 'string', nullable: true),
                                new OA\Property(property: 'consecutiveFailures', type: 'integer'),
                                new OA\Property(property: 'autoPaused', type: 'boolean'),
                                new OA\Property(property: 'summary', type: 'object', properties: [
                                    new OA\Property(property: 'key', type: 'string'),
                                    new OA\Property(property: 'params', type: 'object'),
                                ]),
                                new OA\Property(property: 'instructionPreview', type: 'string', nullable: true, description: 'First ~60 characters of the underlying instruction, for the task card.'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function resume(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $task = $this->service->getOwned($id, (int) $user->getId());
        if (null === $task) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'task' => $this->serializer->task($this->service->resume($task))]);
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->config->isEnabled((int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return null;
    }
}

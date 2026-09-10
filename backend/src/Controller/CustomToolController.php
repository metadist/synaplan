<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Tool\CustomToolResponse;
use App\DTO\Tool\OpenApiOperationPreview;
use App\Entity\CustomTool;
use App\Entity\User;
use App\Repository\CustomToolRepository;
use App\Service\Tool\Custom\CustomToolService;
use App\Service\Tool\Custom\InvalidToolTemplateException;
use App\Service\Tool\ToolsConfig;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/tools/custom', name: 'api_tools_custom_')]
#[OA\Tag(name: 'Custom Tools')]
final class CustomToolController extends AbstractController
{
    public function __construct(
        private CustomToolService $service,
        private CustomToolRepository $tools,
        private ToolsConfig $toolsConfig,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/tools/custom',
        summary: 'List custom HTTP tools for the current user',
        tags: ['Custom Tools'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Custom tools',
                content: new OA\JsonContent(
                    required: ['success', 'tools'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'tools', type: 'array', items: new OA\Items(ref: new Model(type: CustomToolResponse::class))),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Custom HTTP tools disabled'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->json(['success' => true, 'tools' => $this->service->listFor($user)]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/tools/custom',
        summary: 'Create a custom HTTP tool',
        tags: ['Custom Tools'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'spec'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'create_ticket'),
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'sideEffect', type: 'string', enum: ['read', 'write', 'destructive']),
                new OA\Property(property: 'spec', type: 'object'),
                new OA\Property(property: 'inputSchema', type: 'object', nullable: true),
                new OA\Property(property: 'credentialId', type: 'integer', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created',
                content: new OA\JsonContent(
                    required: ['success', 'tool'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'tool', ref: new Model(type: CustomToolResponse::class)),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid spec'),
        ]
    )]
    public function create(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        try {
            $tool = $this->service->create($user, $this->payload($request));
        } catch (InvalidToolTemplateException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'tool' => $this->service->toArray($tool)], Response::HTTP_CREATED);
    }

    #[Route('/import-openapi/preview', name: 'import_preview', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/tools/custom/import-openapi/preview',
        summary: 'Preview operations from an OpenAPI 3 document',
        tags: ['Custom Tools'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operations',
                content: new OA\JsonContent(
                    required: ['success', 'operations'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'operations', type: 'array', items: new OA\Items(ref: new Model(type: OpenApiOperationPreview::class))),
                        new OA\Property(property: 'dropped', type: 'integer'),
                        new OA\Property(property: 'notices', type: 'array', items: new OA\Items(type: 'string')),
                    ]
                )
            ),
        ]
    )]
    public function importPreview(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        $payload = $this->payload($request);
        $url = is_string($payload['url'] ?? null) ? $payload['url'] : null;
        $document = is_string($payload['document'] ?? null) ? $payload['document'] : null;
        try {
            $preview = $this->service->previewOpenApi($url, $document);
        } catch (InvalidToolTemplateException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, ...$preview]);
    }

    #[Route('/import-openapi/apply', name: 'import_apply', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/tools/custom/import-openapi/apply',
        summary: 'Create custom tools from selected OpenAPI operations',
        tags: ['Custom Tools'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created',
                content: new OA\JsonContent(
                    required: ['success', 'tools'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'tools', type: 'array', items: new OA\Items(ref: new Model(type: CustomToolResponse::class))),
                    ]
                )
            ),
        ]
    )]
    public function importApply(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $payload = $this->payload($request);
        $operations = is_array($payload['operations'] ?? null) ? $payload['operations'] : [];
        $credentialId = is_numeric($payload['credentialId'] ?? null) ? (int) $payload['credentialId'] : null;
        $baseUrl = is_string($payload['baseUrl'] ?? null) ? $payload['baseUrl'] : '';
        try {
            $created = $this->service->applyOpenApi($user, $operations, $credentialId, $baseUrl);
        } catch (InvalidToolTemplateException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'success' => true,
            'tools' => array_map(fn (CustomTool $tool): array => $this->service->toArray($tool), $created),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/tools/custom/{id}',
        summary: 'Get one custom tool',
        tags: ['Custom Tools'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Custom tool',
                content: new OA\JsonContent(
                    required: ['success', 'tool'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'tool', ref: new Model(type: CustomToolResponse::class)),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function get(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $tool = $this->owned($id, $user);
        if (null === $tool) {
            return $this->json(['success' => false, 'error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'tool' => $this->service->toArray($tool)]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        path: '/api/v1/tools/custom/{id}',
        summary: 'Update a custom tool',
        tags: ['Custom Tools'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(
                    required: ['success', 'tool'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'tool', ref: new Model(type: CustomToolResponse::class)),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid spec'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(int $id, #[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $tool = $this->owned($id, $user);
        if (null === $tool) {
            return $this->json(['success' => false, 'error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        try {
            $updated = $this->service->update($tool, $this->payload($request), (int) $user->getId());
        } catch (InvalidToolTemplateException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'tool' => $this->service->toArray($updated)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(path: '/api/v1/tools/custom/{id}', summary: 'Delete a custom tool', tags: ['Custom Tools'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $tool = $this->owned($id, $user);
        if (null === $tool) {
            return $this->json(['success' => false, 'error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        $this->service->delete($tool);

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/try', name: 'try', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/tools/custom/{id}/try',
        summary: 'Try a custom tool. Write-class tools are not sent.',
        tags: ['Custom Tools'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Try result',
                content: new OA\JsonContent(
                    required: ['success', 'sent'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'sent', type: 'boolean'),
                        new OA\Property(property: 'result', description: 'Present when the read-class call was sent'),
                        new OA\Property(property: 'request', description: 'Resolved request preview when the call was not sent'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function try(int $id, #[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $tool = $this->owned($id, $user);
        if (null === $tool) {
            return $this->json(['success' => false, 'error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        $payload = $this->payload($request);
        $input = is_array($payload['input'] ?? null) ? $payload['input'] : [];
        try {
            $result = $this->service->try($tool, $input, $user);
        } catch (InvalidToolTemplateException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, ...$result]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $decoded = json_decode($request->getContent() ?: '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    private function owned(int $id, User $user): ?CustomTool
    {
        return $this->tools->findOneForOwner($id, (int) $user->getId());
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->toolsConfig->isCustomHttpEnabled((int) $user->getId())) {
            return $this->json(['success' => false, 'error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return null;
    }
}

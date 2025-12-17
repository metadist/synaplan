<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Admin\AdminModelsService;
use App\Service\Admin\ModelConflictException;
use App\Service\Admin\ModelNotFoundException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/models')]
#[OA\Tag(name: 'Admin Models')]
final class AdminModelsController extends AbstractController
{
    public function __construct(
        private readonly AdminModelsService $modelsService,
    ) {
    }

    #[Route('', name: 'admin_models_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/models',
        summary: 'List all AI models',
        description: 'Get a list of all configured AI models (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin Models']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of models',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'models',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'service', type: 'string', example: 'OpenAI'),
                            new OA\Property(property: 'tag', type: 'string', example: 'chat'),
                            new OA\Property(property: 'providerId', type: 'string', example: 'gpt-4o'),
                            new OA\Property(property: 'name', type: 'string', example: 'GPT-4o'),
                            new OA\Property(property: 'selectable', type: 'integer', example: 1),
                            new OA\Property(property: 'active', type: 'integer', example: 1),
                            new OA\Property(property: 'priceIn', type: 'number', format: 'float', example: 2.5),
                            new OA\Property(property: 'inUnit', type: 'string', example: 'per1M'),
                            new OA\Property(property: 'priceOut', type: 'number', format: 'float', example: 10.0),
                            new OA\Property(property: 'outUnit', type: 'string', example: 'per1M'),
                            new OA\Property(property: 'quality', type: 'number', format: 'float', example: 9.0),
                            new OA\Property(property: 'rating', type: 'number', format: 'float', example: 0.9),
                            new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Advanced language model'),
                            new OA\Property(property: 'json', type: 'object', nullable: true),
                            new OA\Property(property: 'isSystemModel', type: 'boolean', example: false),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $models = $this->modelsService->listModels();

        return $this->json([
            'success' => true,
            'models' => array_map([$this->modelsService, 'serializeModel'], $models),
        ]);
    }

    #[Route('', name: 'admin_models_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/models',
        summary: 'Create a new AI model',
        description: 'Create a new AI model configuration (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin Models']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['service', 'tag', 'providerId', 'name'],
            properties: [
                new OA\Property(property: 'service', type: 'string', example: 'OpenAI'),
                new OA\Property(property: 'tag', type: 'string', example: 'chat'),
                new OA\Property(property: 'providerId', type: 'string', example: 'gpt-4o'),
                new OA\Property(property: 'name', type: 'string', example: 'GPT-4o'),
                new OA\Property(property: 'selectable', type: 'integer', example: 1),
                new OA\Property(property: 'active', type: 'integer', example: 1),
                new OA\Property(property: 'priceIn', type: 'number', format: 'float', example: 2.5),
                new OA\Property(property: 'inUnit', type: 'string', example: 'per1M'),
                new OA\Property(property: 'priceOut', type: 'number', format: 'float', example: 10.0),
                new OA\Property(property: 'outUnit', type: 'string', example: 'per1M'),
                new OA\Property(property: 'quality', type: 'number', format: 'float', example: 9.0),
                new OA\Property(property: 'rating', type: 'number', format: 'float', example: 0.9),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'json', type: 'object', nullable: true),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Model created')]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 409, description: 'Model already exists')]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $model = $this->modelsService->createModel($data);

            return $this->json([
                'success' => true,
                'model' => $this->modelsService->serializeModel($model),
            ], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (ModelConflictException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/{id}', name: 'admin_models_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/admin/models/{id}',
        summary: 'Update an AI model',
        description: 'Update an existing AI model configuration (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin Models']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'service', type: 'string'),
                new OA\Property(property: 'tag', type: 'string'),
                new OA\Property(property: 'providerId', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'selectable', type: 'integer'),
                new OA\Property(property: 'active', type: 'integer'),
                new OA\Property(property: 'priceIn', type: 'number', format: 'float'),
                new OA\Property(property: 'priceOut', type: 'number', format: 'float'),
                new OA\Property(property: 'quality', type: 'number', format: 'float'),
                new OA\Property(property: 'rating', type: 'number', format: 'float'),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'json', type: 'object', nullable: true),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Model updated')]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'Model not found')]
    #[OA\Response(response: 409, description: 'Conflict with existing model')]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $model = $this->modelsService->updateModel($id, $data);

            return $this->json([
                'success' => true,
                'model' => $this->modelsService->serializeModel($model),
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (ModelConflictException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/{id}', name: 'admin_models_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/admin/models/{id}',
        summary: 'Delete an AI model',
        description: 'Delete an AI model configuration (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin Models']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Model deleted')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'Model not found')]
    #[OA\Response(response: 409, description: 'Model is still in use')]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->modelsService->deleteModel($id);

            return $this->json(['success' => true]);
        } catch (ModelNotFoundException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (ModelConflictException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/import/preview', name: 'admin_models_import_preview', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/models/import/preview',
        summary: 'Preview AI-generated model import SQL',
        description: 'Generate SQL statements for importing/updating models from pricing pages (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin Models']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'urls', type: 'array', items: new OA\Items(type: 'string'), example: ['https://openai.com/pricing']),
                new OA\Property(property: 'textDump', type: 'string', example: 'Model pricing info...'),
                new OA\Property(property: 'allowDelete', type: 'boolean', example: false),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'SQL preview generated',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'sql', type: 'string'),
                new OA\Property(property: 'ai', type: 'object', properties: [
                    new OA\Property(property: 'provider', type: 'string'),
                    new OA\Property(property: 'model', type: 'string'),
                ]),
                new OA\Property(property: 'validation', type: 'object', properties: [
                    new OA\Property(property: 'ok', type: 'boolean'),
                    new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'statements', type: 'array', items: new OA\Items(type: 'string')),
                ]),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid input')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function importPreview(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $urls = $data['urls'] ?? [];
        if (!is_array($urls)) {
            return $this->json(['error' => 'urls must be an array'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->modelsService->generateImportPreview(
            $user->getId(),
            $urls,
            (string) ($data['textDump'] ?? ''),
            (bool) ($data['allowDelete'] ?? false)
        );

        return $this->json([
            'success' => true,
            'sql' => $result['sql'],
            'ai' => [
                'provider' => $result['provider'],
                'model' => $result['model'],
            ],
            'validation' => $result['validation'],
        ]);
    }

    #[Route('/import/apply', name: 'admin_models_import_apply', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/models/import/apply',
        summary: 'Apply model import SQL',
        description: 'Execute validated SQL statements to import/update models (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin Models']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['sql'],
            properties: [
                new OA\Property(property: 'sql', type: 'string', example: 'INSERT INTO BMODELS ...'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'SQL applied successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'applied', type: 'integer'),
                new OA\Property(property: 'statements', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid SQL')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function importApply(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $sql = (string) ($data['sql'] ?? '');
        if ('' === trim($sql)) {
            return $this->json(['error' => 'sql is required'], Response::HTTP_BAD_REQUEST);
        }

        $allowDelete = (bool) ($data['allowDelete'] ?? false);

        try {
            $result = $this->modelsService->applyImportSql(
                $sql,
                $allowDelete,
                $user->getId(),
                $request->getClientIp()
            );

            return $this->json([
                'success' => true,
                'applied' => $result['applied'],
                'statements' => $result['statements'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}

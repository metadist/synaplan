<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\AdminSearchConfig;
use App\Entity\User;
use App\Service\SmartSearch\Admin\SearchModelAdminService;
use App\Service\SmartSearch\Admin\SearchModelChangeException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/admin/search/config')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin Search')]
final class AdminSearchConfigController extends AbstractController
{
    public function __construct(private readonly SearchModelAdminService $admin)
    {
    }

    #[Route('', name: 'admin_search_config_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/search/config',
        operationId: 'getAdminSearchConfig',
        summary: 'Smart Search models and index status (admin only)',
        description: 'The AI model of the search palette and the embedding model of the search index: the admin choice (null = inherit), the inherited and effective model, the models to pick from with their availability, the index coverage, and the latest reindex run.',
        security: [['Bearer' => []]],
        tags: ['Admin Search'],
    )]
    #[OA\Response(response: 200, description: 'Search model configuration', content: new OA\JsonContent(ref: new Model(type: AdminSearchConfig::class)))]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function get(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['success' => true] + $this->admin->describe($user));
    }

    #[Route('', name: 'admin_search_config_put', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/admin/search/config',
        operationId: 'putAdminSearchConfig',
        summary: 'Change a Smart Search model (admin only)',
        description: 'Sets one slot. `modelId: null` returns it to inherit. The AI model applies at once. A new embedding model is probed first; then the index moves onto it in a reindex run, and a failed run restores the previous model.',
        security: [['Bearer' => []]],
        tags: ['Admin Search'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['slot', 'modelId'],
                properties: [
                    new OA\Property(property: 'slot', type: 'string', enum: ['ai', 'embed'], example: 'ai'),
                    new OA\Property(property: 'modelId', type: 'integer', nullable: true, example: 76),
                ],
            ),
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Slot changed',
        content: new OA\JsonContent(
            required: ['success', 'previousModelId', 'runId', 'config'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'previousModelId', type: 'integer', nullable: true, description: 'The admin choice before this change (null = it inherited); send it back to undo', example: null),
                new OA\Property(property: 'runId', type: 'integer', nullable: true, description: 'Reindex run started by an embedding change', example: 12),
                new OA\Property(property: 'config', ref: new Model(type: AdminSearchConfig::class)),
            ],
        ),
    )]
    #[OA\Response(
        response: 400,
        description: 'Invalid slot or model, or the embedding model did not answer a test call',
        content: new OA\JsonContent(
            required: ['error', 'reason'],
            properties: [
                new OA\Property(property: 'error', type: 'string'),
                new OA\Property(property: 'reason', type: 'string', enum: ['invalid_model', 'probe_failed']),
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 409, description: 'A reindex run is already in progress')]
    public function put(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $slot = $body['slot'] ?? null;
        $modelId = $body['modelId'] ?? null;
        if (!is_string($slot) || !array_key_exists('modelId', $body) || (null !== $modelId && !is_int($modelId))) {
            return $this->json(['error' => 'Send slot ("ai" or "embed") and modelId (integer or null).', 'reason' => SearchModelChangeException::INVALID_MODEL], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->admin->change($user, $slot, $modelId);
        } catch (SearchModelChangeException $e) {
            $status = SearchModelChangeException::RUN_IN_PROGRESS === $e->reason ? Response::HTTP_CONFLICT : Response::HTTP_BAD_REQUEST;

            return $this->json(['error' => $e->getMessage(), 'reason' => $e->reason], $status);
        }

        return $this->json(['success' => true] + $result + ['config' => ['success' => true] + $this->admin->describe($user)]);
    }
}

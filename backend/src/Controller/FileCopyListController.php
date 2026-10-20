<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PlatformLink\CloudDeliveryService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'Files')]
final class FileCopyListController extends AbstractController
{
    public function __construct(
        private CloudDeliveryService $deliveries,
    ) {
    }

    #[Route('/api/v1/files/copies', name: 'api_files_copies', methods: ['GET'], priority: 10)]
    #[OA\Get(
        path: '/api/v1/files/copies',
        operationId: 'listFileCopies',
        summary: 'Where the signed-in person\'s files were copied',
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'ids', in: 'query', required: true, description: 'Comma-separated file ids, at most 100', schema: new OA\Schema(type: 'string', example: '3,9')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Copy ledger rows for those files',
                content: new OA\JsonContent(
                    required: ['success', 'copies'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'copies',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'file_id', 'state', 'remote_path', 'open_url', 'error_code', 'created', 'client', 'host'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'file_id', type: 'integer'),
                                    new OA\Property(property: 'state', type: 'string', example: 'delivered'),
                                    new OA\Property(property: 'remote_path', type: 'string'),
                                    new OA\Property(property: 'open_url', type: 'string'),
                                    new OA\Property(property: 'error_code', type: 'string'),
                                    new OA\Property(property: 'created', type: 'integer'),
                                    new OA\Property(property: 'client', type: 'string', example: 'nextcloud'),
                                    new OA\Property(property: 'host', type: 'string', example: 'files.example.org'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ],
    )]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $ids = [];
        foreach (explode(',', (string) $request->query->get('ids', '')) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $this->json([
            'success' => true,
            'copies' => $this->deliveries->listForOwner((int) $user->getId(), $ids),
        ]);
    }
}

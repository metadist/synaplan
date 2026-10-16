<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Destination\WorkspaceFolderPushService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/compute/workspace', name: 'api_compute_workspace_push_')]
#[OA\Tag(name: 'Compute')]
final class ComputeWorkspacePushController extends AbstractController
{
    public function __construct(
        private WorkspaceFolderPushService $pusher,
    ) {
    }

    #[Route('/push', name: 'send', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/compute/workspace/push',
        summary: 'Copy one file-work folder file into the owner\'s Nextcloud or OpenCloud',
        tags: ['Compute'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['path', 'connection_id'],
                properties: [
                    new OA\Property(property: 'path', type: 'string', example: 'report.docx'),
                    new OA\Property(property: 'connection_id', type: 'integer', example: 12),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'File copied to the connected folder',
                content: new OA\JsonContent(
                    required: ['success', 'destination'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'destination', type: 'string', example: 'webdav'),
                        new OA\Property(property: 'kind', type: 'string', enum: ['nextcloud', 'opencloud'], example: 'nextcloud'),
                        new OA\Property(property: 'reference', type: 'string', nullable: true, example: 'Synaplan/report.docx'),
                        new OA\Property(property: 'context', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'path or connection_id missing'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File-work folders are off, or the file is gone'),
            new OA\Response(response: 422, description: 'The cloud folder rejected the file. Nothing was copied.'),
            new OA\Response(response: 503, description: 'The file-work sidecar did not answer. Nothing was copied.'),
        ]
    )]
    public function push(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->toArray();
        $path = is_string($data['path'] ?? null) ? trim($data['path']) : '';
        $connectionId = is_numeric($data['connection_id'] ?? null) ? (int) $data['connection_id'] : 0;
        if ('' === $path || $connectionId <= 0) {
            return $this->json(['error' => 'path and connection_id are required'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->pusher->push($user, $path, $connectionId);

        return $this->json($result['body'], $result['status']);
    }
}

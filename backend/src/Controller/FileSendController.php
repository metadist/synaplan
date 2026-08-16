<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Destination\FileSendService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/files', name: 'api_files_send_')]
#[OA\Tag(name: 'Files')]
final class FileSendController extends AbstractController
{
    public function __construct(
        private FileSendService $fileSendService,
    ) {
    }

    #[Route('/{id}/send', name: 'send', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/files/{id}/send',
        summary: 'Deliver a file to a destination (email, share link, WebDAV folder, or CalDAV calendar)',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['destination'],
                properties: [
                    new OA\Property(property: 'destination', type: 'string', enum: ['email', 'share_link', 'webdav', 'caldav'], example: 'email'),
                    new OA\Property(property: 'subject', type: 'string'),
                    new OA\Property(property: 'body', type: 'string'),
                    new OA\Property(property: 'expiry_days', type: 'integer', example: 7),
                    new OA\Property(property: 'connection_id', type: 'integer', description: 'Required for webdav/caldav: the connection to deliver through', example: 12),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'File delivered',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'destination', type: 'string', example: 'email'),
                        new OA\Property(property: 'reference', type: 'string', nullable: true),
                        new OA\Property(property: 'context', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Unknown destination'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Not the file owner'),
            new OA\Response(response: 404, description: 'File not found'),
            new OA\Response(response: 422, description: 'Destination rejected the file'),
        ]
    )]
    public function send(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->toArray();
        $destination = is_string($data['destination'] ?? null) ? $data['destination'] : '';
        if ('' === $destination) {
            return $this->json(['error' => 'destination is required'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->fileSendService->send($id, (int) $user->getId(), $destination, $data);

        return $this->json($result['body'], $result['status']);
    }
}

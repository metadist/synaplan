<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\VoiceLiveRejectedException;
use App\Service\Voice\VoiceLiveSessionService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Who is talking right now, and how an admin ends one conversation.
 */
#[Route('/api/v1/admin/voice/live', name: 'admin_voice_live_')]
#[OA\Tag(name: 'Admin')]
final class VoiceLiveAdminController extends AbstractController
{
    public function __construct(
        private readonly VoiceLiveSessionService $sessions,
    ) {
    }

    #[Route('/sessions', name: 'sessions', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/voice/live/sessions',
        summary: 'Open live conversations on this server',
        tags: ['Admin'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Open sessions. Empty when nobody is talking.',
                content: new OA\JsonContent(
                    required: ['sessions', 'webrtcOpenAi'],
                    properties: [
                        new OA\Property(property: 'webrtcOpenAi', type: 'boolean'),
                        new OA\Property(
                            property: 'sessions',
                            type: 'array',
                            items: new OA\Items(
                                required: ['sessionId', 'userEmail', 'provider', 'seconds', 'started'],
                                properties: [
                                    new OA\Property(property: 'sessionId', type: 'integer'),
                                    new OA\Property(property: 'userEmail', type: 'string'),
                                    new OA\Property(property: 'provider', type: 'string'),
                                    new OA\Property(property: 'seconds', type: 'integer'),
                                    new OA\Property(property: 'started', type: 'integer'),
                                    new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                                ],
                                type: 'object'
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function sessions(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        return $this->json([
            'sessions' => $this->sessions->adminList(),
            'webrtcOpenAi' => $this->sessions->webrtcOpenAi(),
        ]);
    }

    #[Route('/webrtc', name: 'webrtc', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/admin/voice/live/webrtc',
        summary: 'Turn the direct OpenAI connection on or off',
        tags: ['Admin'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['enabled'],
                properties: [
                    new OA\Property(property: 'enabled', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Saved. The next OpenAI conversation uses this setting. A conversation already running stays where it is.',
                content: new OA\JsonContent(
                    required: ['enabled'],
                    properties: [
                        new OA\Property(property: 'enabled', type: 'boolean'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'enabled must be a boolean'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function setWebrtc(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        try {
            $body = $request->toArray();
        } catch (JsonException) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }
        if (!is_bool($body['enabled'] ?? null)) {
            return $this->json(['error' => 'enabled must be a boolean'], 400);
        }

        $this->sessions->setWebrtcOpenAi($body['enabled']);

        return $this->json(['enabled' => $body['enabled']]);
    }

    #[Route('/sessions/{id}/kill', name: 'kill', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/admin/voice/live/sessions/{id}/kill',
        summary: 'End one live conversation',
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The conversation is ended. The gateway stops on its next tick.',
                content: new OA\JsonContent(
                    required: ['sessionId', 'status', 'endReason'],
                    properties: [
                        new OA\Property(property: 'sessionId', type: 'integer'),
                        new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                        new OA\Property(property: 'status', type: 'string', example: 'ended'),
                        new OA\Property(property: 'seconds', type: 'integer'),
                        new OA\Property(property: 'endReason', type: 'string', example: 'admin_disabled'),
                        new OA\Property(property: 'provider', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Session not found'),
        ]
    )]
    public function kill(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        try {
            return $this->json($this->sessions->adminKill($id));
        } catch (VoiceLiveRejectedException $e) {
            return $this->json($e->payload(), $e->statusCode());
        }
    }
}

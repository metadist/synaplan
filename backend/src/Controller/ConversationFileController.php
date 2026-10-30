<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Service\File\ConversationFileDetacher;
use App\Service\Iam\AccessGate;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\ConversationKind;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Detach a file from a chat. The file itself stays in the user's files.
 */
#[Route('/api/v1/chats', name: 'api_chats_')]
class ConversationFileController extends AbstractController
{
    public function __construct(
        private ChatRepository $chatRepository,
        private ConversationFileDetacher $detacher,
        private AccessGate $accessGate,
        private IamConfig $iamConfig,
    ) {
    }

    #[Route('/{id}/files/{fileId}/detach', name: 'file_detach', methods: ['POST'], requirements: ['id' => '\d+', 'fileId' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/chats/{id}/files/{fileId}/detach',
        summary: 'Remove a file from this chat without deleting it',
        description: 'Drops the file from this conversation so later questions no longer use it. The file stays in the user\'s files.',
        tags: ['Chats', 'Files'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'fileId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The file is no longer part of this chat',
                content: new OA\JsonContent(
                    required: ['success', 'detached'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'detached', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'This chat cannot be changed'),
            new OA\Response(response: 404, description: 'Chat not found, or the file is not in it'),
        ]
    )]
    public function detach(int $id, int $fileId, #[CurrentUser] ?User $user): JsonResponse
    {
        $chat = $user instanceof User ? $this->chatRepository->find($id) : null;
        $denied = $this->deny($user, $chat);
        if (null !== $denied) {
            return $denied;
        }
        \assert($chat instanceof Chat);

        $detached = $this->detacher->detach($chat->getUserId(), (int) $chat->getId(), $fileId);
        if (!$detached) {
            return $this->json(['error' => 'File is not in this chat'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'detached' => true]);
    }

    private function deny(?User $user, ?Chat $chat): ?JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$chat instanceof Chat) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }
        if ($chat->getUserId() === (int) $user->getId()) {
            return null;
        }
        if (!$this->iamConfig->isSharingEnabled((int) $user->getId())) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessGate->decide($user, ConversationKind::KEY, (string) $chat->getId(), Permission::Use)) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}

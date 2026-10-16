<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\Chat\ChatAskUserService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/messages', name: 'api_messages_')]
#[OA\Tag(name: 'Messages')]
final class ChatAskUserController extends AbstractController
{
    public function __construct(
        private MessageRepository $messages,
        private ChatAskUserService $askUser,
    ) {
    }

    #[Route('/{id}/ask-user', name: 'ask_user', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/messages/{id}/ask-user',
        summary: 'Answer or skip a paused ask-the-user step and continue the same run',
        tags: ['Messages'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['nodeId'], properties: [
            new OA\Property(property: 'nodeId', type: 'string'),
            new OA\Property(property: 'answer', type: 'string'),
            new OA\Property(property: 'skip', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The run continued'),
            new OA\Response(response: 400, description: 'The answer was missing'),
            new OA\Response(response: 404, description: 'Message not found'),
        ]
    )]
    public function submit(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $message = $this->messages->find($id);
        if (!$message instanceof Message || $message->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_string($payload['nodeId'] ?? null) || '' === $payload['nodeId']) {
            return $this->json(['error' => 'nodeId is required'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $result = $this->askUser->submit(
                $message,
                $payload['nodeId'],
                is_string($payload['answer'] ?? null) ? $payload['answer'] : null,
                true === ($payload['skip'] ?? false),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['success' => true, 'answer' => $result['answer'], 'continued' => $result['continued']]);
    }
}

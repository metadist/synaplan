<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\Message\MessageVersionService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Links an "Again" answer or an edited question onto the turn it belongs to,
 * and records which version the next message should build on.
 */
#[Route('/api/v1/messages', name: 'api_messages_')]
#[OA\Tag(name: 'Messages')]
final class MessageVersionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageRepository $messages,
        private MessageVersionService $versions,
    ) {
    }

    #[Route('/{id}/turn-link', name: 'turn_link', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/messages/{id}/turn-link',
        summary: 'Attach a new answer or an edited question to its earlier version',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), description: 'The new assistant message id'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['kind', 'previousId'],
                properties: [
                    new OA\Property(property: 'kind', type: 'string', enum: ['again', 'edit']),
                    new OA\Property(property: 'previousId', type: 'integer', description: 'The earlier assistant message (again) or user message (edit)'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'The version was recorded', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 400, description: 'The two messages cannot be linked'),
            new OA\Response(response: 404, description: 'Message not found'),
        ]
    )]
    public function link(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
        $kind = $payload['kind'] ?? '';
        $previousId = $payload['previousId'] ?? null;
        if (!in_array($kind, ['again', 'edit'], true) || !is_numeric($previousId)) {
            return $this->json(['error' => 'kind and previousId are required'], Response::HTTP_BAD_REQUEST);
        }

        $newer = $this->owned($id, $user);
        $older = $this->owned((int) $previousId, $user);
        if (!$newer instanceof Message || !$older instanceof Message) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }
        if ($newer->getChatId() !== $older->getChatId() || null === $newer->getChatId()) {
            return $this->json(['error' => 'Those messages are not in the same chat'], Response::HTTP_BAD_REQUEST);
        }

        try {
            if ('again' === $kind) {
                $this->versions->linkAgain($newer, $older);
            } else {
                $incoming = $this->incomingFor($newer);
                if (!$incoming instanceof Message) {
                    return $this->json(['error' => 'The edited question was not saved'], Response::HTTP_BAD_REQUEST);
                }
                $this->versions->linkEdit($incoming, $older, $newer, $this->between($older, $incoming));
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/select-version', name: 'select_version', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/messages/{id}/select-version',
        summary: 'Choose which answer or edited question the next message builds on',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['kind'],
                properties: [
                    new OA\Property(property: 'kind', type: 'string', enum: ['answer', 'edit']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'That version is now the active one', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 400, description: 'This message has no versions'),
            new OA\Response(response: 404, description: 'Message not found'),
        ]
    )]
    public function select(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $payload = json_decode($request->getContent(), true);
        $kind = is_array($payload) ? ($payload['kind'] ?? '') : '';
        if (!in_array($kind, ['answer', 'edit'], true)) {
            return $this->json(['error' => 'kind must be answer or edit'], Response::HTTP_BAD_REQUEST);
        }
        $chosen = $this->owned($id, $user);
        if (!$chosen instanceof Message || null === $chosen->getChatId()) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }
        $chatMessages = $this->messages->findBy(['chatId' => $chosen->getChatId()]);
        try {
            if ('answer' === $kind) {
                $this->versions->selectAnswer($chosen, $chatMessages);
            } else {
                $this->versions->selectEdit($chosen, $chatMessages, $chatMessages);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    private function owned(int $id, User $user): ?Message
    {
        $message = $this->messages->find($id);
        if (!$message instanceof Message) {
            return null;
        }
        if ($message->getUserId() !== $user->getId()) {
            return null;
        }

        return $message;
    }

    private function incomingFor(Message $answer): ?Message
    {
        $chatId = $answer->getChatId();
        if (null === $chatId) {
            return null;
        }
        $candidates = $this->messages->findBy([
            'chatId' => $chatId,
            'trackingId' => $answer->getTrackingId(),
            'direction' => 'IN',
        ], ['id' => 'DESC']);
        foreach ($candidates as $candidate) {
            if ($candidate instanceof Message && $candidate->getId() !== $answer->getId()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<Message>
     */
    private function between(Message $oldUser, Message $newUser): array
    {
        $chatId = $oldUser->getChatId();
        $oldId = $oldUser->getId();
        $newId = $newUser->getId();
        if (null === $chatId || null === $oldId || null === $newId) {
            return [];
        }
        $hidden = [];
        foreach ($this->messages->findBy(['chatId' => $chatId]) as $message) {
            $id = $message->getId();
            if (null === $id || $id <= $oldId || $id >= $newId) {
                continue;
            }
            $hidden[] = $message;
        }

        return $hidden;
    }
}

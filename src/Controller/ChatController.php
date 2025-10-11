<?php

namespace App\Controller;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/chats', name: 'api_chats_')]
class ChatController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private LoggerInterface $logger
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chats = $this->chatRepository->findByUser($user->getId());

        $result = array_map(function (Chat $chat) {
            return [
                'id' => $chat->getId(),
                'title' => $chat->getTitle() ?? 'New Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
                'updatedAt' => $chat->getUpdatedAt()->format('c'),
                'messageCount' => $chat->getMessages()->count(),
                'isShared' => $chat->isPublic(),
            ];
        }, $chats);

        return $this->json(['success' => true, 'chats' => $result]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $title = $data['title'] ?? null;

        $chat = new Chat();
        $chat->setUserId($user->getId());
        
        if ($title) {
            $chat->setTitle($title);
        }

        $this->em->persist($chat);
        $this->em->flush();

        $this->logger->info('Chat created', [
            'chat_id' => $chat->getId(),
            'user_id' => $user->getId()
        ]);

        return $this->json([
            'success' => true,
            'chat' => [
                'id' => $chat->getId(),
                'title' => $chat->getTitle() ?? 'New Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
                'updatedAt' => $chat->getUpdatedAt()->format('c'),
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chat = $this->chatRepository->find($id);

        if (!$chat || $chat->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'chat' => [
                'id' => $chat->getId(),
                'title' => $chat->getTitle() ?? 'New Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
                'updatedAt' => $chat->getUpdatedAt()->format('c'),
                'isShared' => $chat->isPublic(),
                'shareToken' => $chat->getShareToken(),
            ]
        ]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chat = $this->chatRepository->find($id);

        if (!$chat || $chat->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['title'])) {
            $chat->setTitle($data['title']);
        }

        $chat->updateTimestamp();
        $this->em->flush();

        return $this->json([
            'success' => true,
            'chat' => [
                'id' => $chat->getId(),
                'title' => $chat->getTitle(),
                'updatedAt' => $chat->getUpdatedAt()->format('c'),
            ]
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(
        int $id,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chat = $this->chatRepository->find($id);

        if (!$chat || $chat->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($chat);
        $this->em->flush();

        $this->logger->info('Chat deleted', [
            'chat_id' => $id,
            'user_id' => $user->getId()
        ]);

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/share', name: 'share', methods: ['POST'])]
    public function share(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chat = $this->chatRepository->find($id);

        if (!$chat || $chat->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $enable = $data['enable'] ?? true;

        if ($enable) {
            if (!$chat->getShareToken()) {
                $chat->generateShareToken();
            }
            $chat->setIsPublic(true);
        } else {
            $chat->setIsPublic(false);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'shareToken' => $chat->getShareToken(),
            'isShared' => $chat->isPublic(),
            'shareUrl' => $chat->isPublic() 
                ? $this->generateUrl('api_chats_shared', ['token' => $chat->getShareToken()], true)
                : null
        ]);
    }

    #[Route('/{id}/messages', name: 'messages', methods: ['GET'])]
    public function getMessages(
        int $id,
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $chat = $this->chatRepository->find($id);

        if (!$chat || $chat->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        $limit = (int) $request->query->get('limit', 50);
        $offset = (int) $request->query->get('offset', 0);
        $limit = min($limit, 100);

        $queryBuilder = $this->messageRepository->createQueryBuilder('m')
            ->where('m.chatId = :chatId')
            ->setParameter('chatId', $chat->getId())
            ->orderBy('m.unixTimestamp', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $messages = $queryBuilder->getQuery()->getResult();
        $messages = array_reverse($messages);

        $messageData = array_map(function ($m) {
            return [
                'id' => $m->getId(),
                'text' => $m->getText(),
                'direction' => $m->getDirection(),
                'timestamp' => $m->getUnixTimestamp(),
                'provider' => $m->getProviderIndex(),
                'topic' => $m->getTopic(),
                'language' => $m->getLanguage(),
                'createdAt' => $m->getDateTime(),
            ];
        }, $messages);

        $totalCount = $this->messageRepository->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.chatId = :chatId')
            ->setParameter('chatId', $chat->getId())
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json([
            'success' => true,
            'messages' => $messageData,
            'pagination' => [
                'offset' => $offset,
                'limit' => $limit,
                'total' => (int) $totalCount,
                'hasMore' => ($offset + count($messages)) < $totalCount
            ]
        ]);
    }

    #[Route('/shared/{token}', name: 'shared', methods: ['GET'])]
    public function getShared(string $token): JsonResponse
    {
        $chat = $this->chatRepository->findPublicByShareToken($token);

        if (!$chat) {
            return $this->json(['error' => 'Chat not found or not shared'], Response::HTTP_NOT_FOUND);
        }

        $messages = $this->messageRepository->findBy(
            ['chatId' => $chat->getId()],
            ['unixTimestamp' => 'ASC']
        );

        $messageData = array_map(function ($m) {
            return [
                'id' => $m->getId(),
                'text' => $m->getText(),
                'direction' => $m->getDirection(),
                'timestamp' => $m->getUnixTimestamp(),
                'provider' => $m->getProviderIndex(),
            ];
        }, $messages);

        return $this->json([
            'success' => true,
            'chat' => [
                'title' => $chat->getTitle() ?? 'Shared Chat',
                'createdAt' => $chat->getCreatedAt()->format('c'),
            ],
            'messages' => $messageData
        ]);
    }
}


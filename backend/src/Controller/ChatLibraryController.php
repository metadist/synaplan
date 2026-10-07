<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Service\Chat\ChatActionPolicy;
use App\Service\Chat\ChatTranscriptExporter;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/chats', name: 'api_chats_')]
#[OA\Tag(name: 'Chats')]
final class ChatLibraryController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChatRepository $chats,
        private MessageRepository $messages,
        private ChatTranscriptExporter $exporter,
        private ChatActionPolicy $actions,
    ) {
    }

    #[Route('/export-all', name: 'export_all', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chats/export-all',
        summary: 'Download every chat you own as one JSON file',
        tags: ['Chats'],
        responses: [
            new OA\Response(response: 200, description: 'All chats'),
            new OA\Response(response: 403, description: 'Export is turned off'),
        ]
    )]
    public function exportAll(#[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->actions->canExport($user->getId())) {
            return $this->json(['error' => 'Exporting chats is turned off for your account'], Response::HTTP_FORBIDDEN);
        }
        $chats = $this->chats->findByUser($user->getId(), null);
        $documents = [];
        foreach ($chats as $chat) {
            $messages = $this->messages->findBy(
                ['chatId' => $chat->getId()],
                ['unixTimestamp' => 'ASC', 'id' => 'ASC'],
            );
            $documents[] = $this->exporter->jsonDocument($chat, $messages);
        }

        return new JsonResponse(['chats' => $documents], Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="chats.json"',
        ]);
    }

    #[Route('/{id}/archive', name: 'archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/chats/{id}/archive',
        summary: 'Move a chat out of the sidebar into Archived',
        tags: ['Chats'],
        responses: [
            new OA\Response(response: 200, description: 'The chat is archived', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'archived', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 404, description: 'Chat not found'),
        ]
    )]
    public function archive(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $chat = $this->owned($id, $user);
        if (!$chat instanceof Chat) {
            return $this->missing($user);
        }
        $chat->setArchived(true);
        $this->em->flush();

        return $this->json(['success' => true, 'archived' => true]);
    }

    #[Route('/{id}/unarchive', name: 'unarchive', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/chats/{id}/unarchive',
        summary: 'Put an archived chat back in the sidebar',
        tags: ['Chats'],
        responses: [
            new OA\Response(response: 200, description: 'The chat is in the sidebar again', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'archived', type: 'boolean', example: false),
            ])),
        ]
    )]
    public function unarchive(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $chat = $this->owned($id, $user);
        if (!$chat instanceof Chat) {
            return $this->missing($user);
        }
        $chat->setArchived(false);
        $this->em->flush();

        return $this->json(['success' => true, 'archived' => false]);
    }

    #[Route('/{id}/tags', name: 'tags', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[OA\Put(
        path: '/api/v1/chats/{id}/tags',
        summary: 'Replace the tags on a chat',
        tags: ['Chats'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Tags saved', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
            ])),
        ]
    )]
    public function tags(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $chat = $this->owned($id, $user);
        if (!$chat instanceof Chat) {
            return $this->missing($user);
        }
        $payload = json_decode($request->getContent(), true);
        $tags = is_array($payload) && is_array($payload['tags'] ?? null) ? $payload['tags'] : [];
        $strings = [];
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $strings[] = $tag;
            }
        }
        $chat->setTags($strings);
        $this->em->flush();

        return $this->json(['success' => true, 'tags' => $chat->getTags()]);
    }

    #[Route('/{id}/export', name: 'export', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/chats/{id}/export',
        summary: 'Download one chat as Markdown, JSON, or PDF',
        tags: ['Chats'],
        parameters: [
            new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['md', 'json', 'pdf'], default: 'md')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The chat file'),
            new OA\Response(response: 403, description: 'Export is turned off'),
            new OA\Response(response: 404, description: 'Chat not found'),
        ]
    )]
    public function export(int $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->actions->canExport($user->getId())) {
            return $this->json(['error' => 'Exporting chats is turned off for your account'], Response::HTTP_FORBIDDEN);
        }
        $chat = $this->owned($id, $user);
        if (!$chat instanceof Chat) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }
        $messages = $this->messages->findBy(
            ['chatId' => $chat->getId()],
            ['unixTimestamp' => 'ASC', 'id' => 'ASC'],
        );
        $format = (string) $request->query->get('format', 'md');
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $chat->getTitle() ?: 'chat') ?: 'chat';

        return match ($format) {
            'json' => new JsonResponse($this->exporter->jsonDocument($chat, $messages), Response::HTTP_OK, [
                'Content-Disposition' => 'attachment; filename="'.$slug.'.json"',
            ]),
            'pdf' => new Response($this->exporter->pdf($chat, $messages), Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$slug.'.pdf"',
            ]),
            default => new Response($this->exporter->markdown($chat, $messages), Response::HTTP_OK, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$slug.'.md"',
            ]),
        };
    }

    private function owned(int $id, ?User $user): ?Chat
    {
        if (!$user instanceof User) {
            return null;
        }
        $chat = $this->chats->find($id);
        if (!$chat instanceof Chat || $chat->getUserId() !== $user->getId()) {
            return null;
        }

        return $chat;
    }

    private function missing(?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
    }
}

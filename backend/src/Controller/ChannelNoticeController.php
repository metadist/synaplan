<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\EmptyAiNoticeException;
use App\Service\Disclosure\AiDisclosure;
use App\Service\Disclosure\ChannelNoticeStore;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The sentence a channel shows so people know they are talking to an AI.
 */
#[Route('/api/v1/channels/{channel}/notice', name: 'api_channel_notice_')]
#[OA\Tag(name: 'Channels')]
final class ChannelNoticeController extends AbstractController
{
    public function __construct(
        private readonly ChannelNoticeStore $store,
        private readonly AiDisclosure $disclosure,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/channels/{channel}/notice',
        summary: 'Read the AI notice for a channel binding',
        security: [['Bearer' => []]],
        tags: ['Channels']
    )]
    #[OA\Parameter(name: 'channel', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['mail', 'whatsapp', 'telegram']))]
    #[OA\Parameter(name: 'binding', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 0))]
    #[OA\Response(
        response: 200,
        description: 'Saved sentence and the default that is used when none is saved',
        content: new OA\JsonContent(
            required: ['success', 'text', 'defaultText'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'text', type: 'string', example: 'This reply was written by an AI assistant of Acme.'),
                new OA\Property(property: 'defaultText', type: 'string', example: 'Hi, I am Acme, an AI assistant.'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 404, description: 'Unknown channel or binding')]
    public function read(string $channel, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $this->store->read($user, $channel, $request->query->getInt('binding'), $user->getLocale());
        } catch (HttpExceptionInterface $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['success' => true] + $payload);
    }

    #[Route('', name: 'put', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/channels/{channel}/notice',
        summary: 'Save the AI notice for a channel binding',
        description: 'An empty sentence is refused. The notice cannot be turned off.',
        security: [['Bearer' => []]],
        tags: ['Channels']
    )]
    #[OA\Parameter(name: 'channel', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['mail', 'whatsapp', 'telegram']))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['text'],
            properties: [
                new OA\Property(property: 'bindingId', type: 'integer', example: 0),
                new OA\Property(property: 'text', type: 'string', example: 'This reply was written by an AI assistant of Acme.'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true)]))]
    #[OA\Response(response: 422, description: 'The sentence is empty or too long')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function write(string $channel, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        $text = is_array($payload) ? trim((string) ($payload['text'] ?? '')) : '';
        $bindingId = is_array($payload) ? (int) ($payload['bindingId'] ?? 0) : 0;
        if (mb_strlen($text) > 500) {
            return $this->json([
                'success' => false,
                'error' => 'Write one sentence, at most 500 characters.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->store->write($user, $channel, $bindingId, $text);
        } catch (EmptyAiNoticeException) {
            return $this->json([
                'success' => false,
                'error' => $this->disclosure->emptyMessage($user->getLocale()),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (HttpExceptionInterface $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json(['success' => true]);
    }
}

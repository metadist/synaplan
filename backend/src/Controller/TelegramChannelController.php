<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\TelegramChannelState;
use App\Entity\User;
use App\Service\Telegram\TelegramChannelException;
use App\Service\Telegram\TelegramConnectionService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The signed-in user's Telegram bot. The token is write-only.
 */
#[Route('/api/v1/channels/telegram')]
#[OA\Tag(name: 'Telegram')]
final class TelegramChannelController extends AbstractController
{
    public function __construct(
        private readonly TelegramConnectionService $connections,
    ) {
    }

    #[Route('', name: 'api_telegram_channel_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/channels/telegram',
        summary: 'Read the signed-in user\'s Telegram bot',
        security: [['Bearer' => []]],
        tags: ['Telegram']
    )]
    #[OA\Response(response: 200, description: 'Current bot, or status none', content: new OA\JsonContent(ref: new Model(type: TelegramChannelState::class)))]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function show(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        return $this->json($this->connections->status($user));
    }

    #[Route('', name: 'api_telegram_channel_connect', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/channels/telegram',
        summary: 'Connect a BotFather bot and start pairing',
        security: [['Bearer' => []]],
        tags: ['Telegram']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['token'],
            properties: [
                new OA\Property(property: 'token', type: 'string', example: '123456789:AAHexampleTokenValue'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Waiting for the first Telegram message', content: new OA\JsonContent(ref: new Model(type: TelegramChannelState::class)))]
    #[OA\Response(
        response: 422,
        description: 'The token or the public address was rejected',
        content: new OA\JsonContent(
            required: ['success', 'error'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'telegram_token_invalid'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function connect(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->json(
                ['success' => false, 'error' => TelegramChannelException::TOKEN_INVALID],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        $token = $payload['token'] ?? '';
        if (!is_string($token)) {
            $token = '';
        }

        try {
            return $this->json($this->connections->connect($user, $token));
        } catch (TelegramChannelException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/pairing', name: 'api_telegram_channel_renew_pairing', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/channels/telegram/pairing',
        summary: 'Create a new pairing link for a bot that is still waiting',
        security: [['Bearer' => []]],
        tags: ['Telegram']
    )]
    #[OA\Response(response: 200, description: 'Current bot with a fresh pairing link while pairing', content: new OA\JsonContent(ref: new Model(type: TelegramChannelState::class)))]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function renewPairing(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        return $this->json($this->connections->renewPairing($user));
    }

    #[Route('', name: 'api_telegram_channel_disconnect', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/channels/telegram',
        summary: 'Disconnect the Telegram bot. Chat history stays.',
        security: [['Bearer' => []]],
        tags: ['Telegram']
    )]
    #[OA\Response(response: 200, description: 'Bot disconnected', content: new OA\JsonContent(ref: new Model(type: TelegramChannelState::class)))]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function disconnect(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        return $this->json($this->connections->disconnect($user));
    }

    private function unauthorized(): JsonResponse
    {
        return $this->json(['success' => false, 'error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
    }
}

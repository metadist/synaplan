<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\ProcessTelegramUpdateCommand;
use App\Service\Telegram\TelegramWebhookAcceptor;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Telegram Bot API webhook. Always answers 200 so a refused or duplicate
 * update is not retried. Work happens in the worker.
 */
#[OA\Tag(name: 'Telegram')]
final class TelegramWebhookController extends AbstractController
{
    public function __construct(
        private readonly TelegramWebhookAcceptor $acceptor,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/v1/webhooks/telegram/{botKey}', name: 'api_webhooks_telegram', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/webhooks/telegram/{botKey}',
        summary: 'Receive a Telegram bot update',
        tags: ['Telegram']
    )]
    #[OA\Parameter(name: 'botKey', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(
        response: 200,
        description: 'Accepted. The body does not describe the outcome.',
        content: new OA\JsonContent(
            required: ['ok'],
            properties: [
                new OA\Property(property: 'ok', type: 'boolean', example: true),
            ]
        )
    )]
    public function receive(string $botKey, Request $request): JsonResponse
    {
        try {
            $update = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['ok' => true]);
        }

        $decision = $this->acceptor->decide(
            $botKey,
            $request->headers->get('X-Telegram-Bot-Api-Secret-Token'),
            $update,
        );
        if ($decision->dispatch && null !== $decision->botId) {
            $updateId = $update['update_id'] ?? 0;
            $this->bus->dispatch(new ProcessTelegramUpdateCommand(
                $decision->botId,
                is_int($updateId) ? $updateId : (int) $updateId,
                $update,
            ));
        }

        return $this->json(['ok' => true]);
    }
}

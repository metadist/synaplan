<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\ProcessTelegramUpdateCommand;
use App\Service\Telegram\TelegramWebhookAcceptor;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Telegram Bot API webhook. A refused or duplicate update gets 200 so it is
 * not retried; only a failed enqueue answers 503 so Telegram delivers again.
 * Work happens in the worker.
 */
#[OA\Tag(name: 'Telegram')]
final class TelegramWebhookController extends AbstractController
{
    public function __construct(
        private readonly TelegramWebhookAcceptor $acceptor,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
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
    #[OA\Response(
        response: 503,
        description: 'The update could not be queued. Telegram retries it.',
        content: new OA\JsonContent(
            required: ['ok'],
            properties: [
                new OA\Property(property: 'ok', type: 'boolean', example: false),
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
        if ($decision->dispatch && null !== $decision->botId && null !== $decision->updateId) {
            try {
                $this->bus->dispatch(new ProcessTelegramUpdateCommand($decision->botId, $decision->updateId, $update));
            } catch (\Throwable $e) {
                $this->acceptor->release($decision);
                $this->logger->error('Telegram update could not be queued', [
                    'bot_id' => $decision->botId,
                    'update_id' => $decision->updateId,
                    'exception_class' => $e::class,
                ]);

                return $this->json(['ok' => false], Response::HTTP_SERVICE_UNAVAILABLE);
            }
        }

        return $this->json(['ok' => true]);
    }
}

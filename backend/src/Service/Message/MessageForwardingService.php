<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Chat;
use App\Repository\MessageRepository;
use App\Service\WhatsAppService;
use Psr\Log\LoggerInterface;

/**
 * Forwards outgoing messages to external channels based on the chat's source.
 *
 * When a platform user triggers an AI response in a chat that originated
 * from WhatsApp (or another external channel), this service ensures the
 * response is delivered back through the original channel.
 */
final readonly class MessageForwardingService
{
    public function __construct(
        private WhatsAppService $whatsAppService,
        private MessageRepository $messageRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Forward a message to the external channel if the chat originated from one.
     *
     * This is a best-effort operation: failures are logged but never propagated
     * so the web UI flow is not disrupted.
     */
    public function forwardIfNeeded(Chat $chat, string $text): void
    {
        if ('whatsapp' !== $chat->getSource()) {
            return;
        }

        $this->forwardToWhatsApp($chat, $text);
    }

    private function forwardToWhatsApp(Chat $chat, string $text): void
    {
        if (!$this->whatsAppService->isAvailable()) {
            $this->logger->warning('WhatsApp service unavailable, cannot forward message', [
                'chat_id' => $chat->getId(),
            ]);

            return;
        }

        $chatId = $chat->getId();
        $inboundMessage = $this->messageRepository->findLatestInboundByChannel($chatId, 'whatsapp');

        if (!$inboundMessage) {
            $this->logger->warning('Cannot forward to WhatsApp: no inbound message found', [
                'chat_id' => $chatId,
            ]);

            return;
        }

        $toPhone = $inboundMessage->getMeta('from_phone');
        $phoneNumberId = $inboundMessage->getMeta('to_phone_number_id');

        if (!$toPhone || !$phoneNumberId) {
            $this->logger->warning('Cannot forward to WhatsApp: missing contact metadata', [
                'chat_id' => $chatId,
                'has_phone' => !empty($toPhone),
                'has_phone_number_id' => !empty($phoneNumberId),
            ]);

            return;
        }

        try {
            $result = $this->whatsAppService->sendMessage($toPhone, $text, $phoneNumberId);

            if ($result['success']) {
                $this->logger->info('Message forwarded to WhatsApp', [
                    'chat_id' => $chatId,
                    'to' => $toPhone,
                    'wa_message_id' => $result['message_id'] ?? null,
                ]);
            } else {
                $this->logger->error('Failed to forward message to WhatsApp', [
                    'chat_id' => $chatId,
                    'to' => $toPhone,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Exception while forwarding message to WhatsApp', [
                'chat_id' => $chatId,
                'to' => $toPhone,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

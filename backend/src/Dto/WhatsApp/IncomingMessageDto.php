<?php

declare(strict_types=1);

namespace App\DTO\WhatsApp;

/**
 * Data Transfer Object for incoming WhatsApp messages.
 */
final readonly class IncomingMessageDto
{
    public function __construct(
        public array $incomingMsg,
        public array $value,
        public string $from,
        public string $messageId,
        public int $timestamp,
        public string $type,
        public string $phoneNumberId,
        public ?string $displayPhoneNumber = null,
    ) {
    }

    /**
     * Factory method to create DTO from WhatsApp webhook payload.
     */
    public static function fromPayload(array $incomingMsg, array $value): self
    {
        return new self(
            incomingMsg: $incomingMsg,
            value: $value,
            from: (string) ($incomingMsg['from'] ?? ''),
            messageId: (string) ($incomingMsg['id'] ?? ''),
            timestamp: (int) ($incomingMsg['timestamp'] ?? time()),
            type: (string) ($incomingMsg['type'] ?? 'text'),
            phoneNumberId: (string) ($value['metadata']['phone_number_id'] ?? ''),
            displayPhoneNumber: $value['metadata']['display_phone_number'] ?? null
        );
    }
}

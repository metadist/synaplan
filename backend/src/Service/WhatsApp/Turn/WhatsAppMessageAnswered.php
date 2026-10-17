<?php

namespace App\Service\WhatsApp\Turn;

/**
 * An inbound WhatsApp message the turn answers early: a redelivery, an
 * unsupported type, a verification code, a spent limit or media that could
 * not be fetched. Carries the answer the webhook returns for it.
 */
final class WhatsAppMessageAnswered extends \RuntimeException
{
    /**
     * @param array<string, mixed> $answer
     */
    private function __construct(public readonly array $answer)
    {
        parent::__construct(sprintf('WhatsApp message %s answered early', (string) ($answer['message_id'] ?? '')));
    }

    /**
     * @param array<string, mixed> $answer
     */
    public static function with(array $answer): self
    {
        return new self($answer);
    }
}

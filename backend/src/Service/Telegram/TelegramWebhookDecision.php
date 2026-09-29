<?php

declare(strict_types=1);

namespace App\Service\Telegram;

final readonly class TelegramWebhookDecision
{
    private function __construct(
        public bool $dispatch,
        public ?int $botId,
        public ?int $updateId = null,
        public ?string $reservationKey = null,
    ) {
    }

    public static function drop(): self
    {
        return new self(false, null);
    }

    public static function dispatch(int $botId, int $updateId, string $reservationKey): self
    {
        return new self(true, $botId, $updateId, $reservationKey);
    }
}

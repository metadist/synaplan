<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One accepted Telegram webhook update. The bot token is not on this
 * message; the worker loads it from the credential vault.
 */
final readonly class ProcessTelegramUpdateCommand
{
    /**
     * @param array<string, mixed> $update
     */
    public function __construct(
        private int $botRowId,
        private int $updateId,
        private array $update,
    ) {
    }

    public function getBotRowId(): int
    {
        return $this->botRowId;
    }

    public function getUpdateId(): int
    {
        return $this->updateId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUpdate(): array
    {
        return $this->update;
    }
}

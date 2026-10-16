<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\TelegramBot;
use App\Entity\User;

/**
 * Everything one inbound update needs to answer and store its turn.
 */
final readonly class TelegramTurn
{
    public function __construct(
        public TelegramBot $bot,
        public User $owner,
        public string $token,
        public string $tgChatId,
        public string $updateKey,
        public string $locale,
    ) {
    }
}

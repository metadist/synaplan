<?php

declare(strict_types=1);

namespace App\Service\Telegram;

enum TelegramPairResult
{
    case Paired;
    case Expired;
    case Mismatch;
}

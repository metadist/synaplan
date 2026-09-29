<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * A Telegram channel failure the UI can explain. The message is the stable
 * error code; raw Telegram text never leaves the API client log.
 */
final class TelegramChannelException extends \RuntimeException
{
    public const PUBLIC_URL_REQUIRED = 'telegram_public_url_required';
    public const TOKEN_INVALID = 'telegram_token_invalid';
    public const TOKEN_REVOKED = 'telegram_token_revoked';
    public const BOT_BLOCKED = 'telegram_bot_blocked';
    public const WEBHOOK_FAILED = 'telegram_webhook_failed';
    public const SEND_FAILED = 'telegram_send_failed';

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}

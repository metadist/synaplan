<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * A received file that is not stored. The reason is the translation key of
 * the sentence the person gets in Telegram.
 */
final class TelegramMediaRejected extends \RuntimeException
{
    public const TOO_LARGE = 'file_too_large';
    public const UNSUPPORTED = 'file_unsupported';
    public const STORAGE_FULL = 'storage_full';
    public const DOWNLOAD_FAILED = 'download_failed';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

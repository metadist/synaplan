<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * Bot API upload methods and the form field each one expects.
 */
enum TelegramFileMethod: string
{
    case Photo = 'sendPhoto';
    case Video = 'sendVideo';
    case Audio = 'sendAudio';
    case Voice = 'sendVoice';
    case Document = 'sendDocument';

    public function field(): string
    {
        return match ($this) {
            self::Photo => 'photo',
            self::Video => 'video',
            self::Audio => 'audio',
            self::Voice => 'voice',
            self::Document => 'document',
        };
    }

    public function chatAction(): string
    {
        return match ($this) {
            self::Photo => 'upload_photo',
            self::Video => 'upload_video',
            self::Audio, self::Voice => 'upload_voice',
            self::Document => 'upload_document',
        };
    }
}

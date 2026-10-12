<?php

declare(strict_types=1);

namespace App\Service\Voice;

/**
 * Direct browser audio is an admin switch and only for OpenAI.
 * Google and xAI stay on the relay.
 */
final class VoiceLiveTransport
{
    public static function choose(string $provider, string $model, bool $enabled): string
    {
        if (!$enabled || 'openai' !== strtolower($provider)) {
            return 'relay';
        }
        if (str_starts_with($model, 'gpt-live') || str_starts_with($model, 'gpt-realtime')) {
            return 'webrtc';
        }

        return 'relay';
    }
}

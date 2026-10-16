<?php

declare(strict_types=1);

namespace App\Service\Voice;

/**
 * One install-wide OpenAI-Realtime-compatible socket (vLLM-Omni, Azure, Qwen Cloud).
 * The sidecar opens it. This only decides whether that model may be offered.
 */
final readonly class VoiceLiveCompatibleEndpoint
{
    public const PROVIDER = 'compatible';

    public function __construct(private string $wsUrl)
    {
    }

    public function configured(): bool
    {
        return self::isWsUrl($this->wsUrl);
    }

    public static function isWsUrl(string $url): bool
    {
        $url = trim($url);
        if (!str_starts_with($url, 'ws://') && !str_starts_with($url, 'wss://')) {
            return false;
        }

        return false !== filter_var($url, FILTER_VALIDATE_URL);
    }
}

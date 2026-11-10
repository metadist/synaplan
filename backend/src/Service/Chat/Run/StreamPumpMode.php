<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

/**
 * `RUNTIME.STREAM_PUMP`. `off` leaves every turn in the request.
 * `gateways` pumps `/v1/chat/completions` and `/v1/messages`.
 * `all` also pumps the web chat and the widget.
 */
enum StreamPumpMode: string
{
    case Off = 'off';
    case Gateways = 'gateways';
    case All = 'all';

    public static function fromRaw(?string $raw): self
    {
        return self::tryFrom(strtolower(trim((string) $raw))) ?? self::Off;
    }
}

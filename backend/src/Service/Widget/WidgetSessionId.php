<?php

declare(strict_types=1);

namespace App\Service\Widget;

/**
 * The shape of a widget session id.
 *
 * The visitor's browser picks the id (`sess_` + a UUID), and the owner later
 * sees it in the session list and in CSV and Excel exports. Letters, digits,
 * `_` and `-` are all the widget ever sends; anything else is text a stranger
 * wants shown or run somewhere else.
 */
final class WidgetSessionId
{
    public const MAX_LENGTH = 64;

    private const PATTERN = '/^[A-Za-z0-9_-]{1,64}\z/';

    public static function isValid(string $sessionId): bool
    {
        return 1 === preg_match(self::PATTERN, $sessionId);
    }
}

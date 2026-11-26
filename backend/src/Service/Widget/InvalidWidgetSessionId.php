<?php

declare(strict_types=1);

namespace App\Service\Widget;

/**
 * A new widget session was asked for under an id the widget never sends.
 */
final class InvalidWidgetSessionId extends \InvalidArgumentException
{
    public const MESSAGE = 'Invalid sessionId';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}

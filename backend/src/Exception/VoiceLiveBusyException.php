<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The user already has a live conversation open.
 */
final class VoiceLiveBusyException extends \RuntimeException
{
}

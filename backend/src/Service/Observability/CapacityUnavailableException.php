<?php

declare(strict_types=1);

namespace App\Service\Observability;

/**
 * The shared cache did not answer, so no server's report can be read. The
 * servers themselves keep working; the page says so.
 */
final class CapacityUnavailableException extends \RuntimeException
{
    public static function redisDown(): self
    {
        return new self('Capacity cannot be read because Redis did not answer');
    }
}

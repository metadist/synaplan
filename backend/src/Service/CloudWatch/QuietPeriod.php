<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

/**
 * A file is handled once it has been quiet. Another version before that
 * replaces it, so a desktop that saves five times produces one run.
 */
final class QuietPeriod
{
    public const SECONDS = 60;

    public function due(int $changedAt, int $now): bool
    {
        return $now - $changedAt >= self::SECONDS;
    }
}

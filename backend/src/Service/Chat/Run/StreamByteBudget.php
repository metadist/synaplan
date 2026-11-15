<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

/**
 * Whether one more frame still fits in the run log.
 *
 * The Redis script in {@see StreamRedis::appendFrame()} applies the same
 * three answers. A gap is written once; later frames are dropped while the
 * turn keeps generating.
 */
final class StreamByteBudget
{
    public const KEEP = 'keep';

    public const GAP = 'gap';

    public const DROP = 'drop';

    public static function decide(int $used, int $incoming, int $max, bool $gapped): string
    {
        if ($gapped || $incoming < 1) {
            return self::DROP;
        }
        if ($used + $incoming > $max) {
            return self::GAP;
        }

        return self::KEEP;
    }
}

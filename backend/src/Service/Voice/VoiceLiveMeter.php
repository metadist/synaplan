<?php

declare(strict_types=1);

namespace App\Service\Voice;

/**
 * Cumulative provider usage for one live conversation.
 *
 * Audio input tokens are the uncached ones. Cached audio is `audioCacheTokens`
 * and is priced on its own, so it must not also be included in `audioInTokens`.
 * Text output includes thinking tokens where the provider bills them as text.
 */
final readonly class VoiceLiveMeter
{
    public function __construct(
        public float $seconds = 0.0,
        public int $audioInTokens = 0,
        public int $audioOutTokens = 0,
        public int $audioCacheTokens = 0,
        public int $textInTokens = 0,
        public int $textOutTokens = 0,
        public int $textItems = 0,
    ) {
    }
}

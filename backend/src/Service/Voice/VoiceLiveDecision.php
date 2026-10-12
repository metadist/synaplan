<?php

declare(strict_types=1);

namespace App\Service\Voice;

/**
 * What the gateway should do after a usage tick.
 *
 * `continue` keeps the conversation going. `warn` asks the model to mention
 * that about a minute of budget is left. `wrap_up` asks for one goodbye
 * sentence. `stop` closes the session.
 */
final readonly class VoiceLiveDecision
{
    public function __construct(
        public string $action,
        public int $allowedSeconds,
        public string $reason,
    ) {
    }
}

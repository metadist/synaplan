<?php

declare(strict_types=1);

namespace App\Service\Message\Capability;

/**
 * A message's topic, the intent that routes it to a handler, and whether the
 * model the user picked answers it (else the handler picks its own).
 */
final readonly class TopicIntent
{
    public function __construct(
        public string $topic,
        public string $intent,
        public bool $usesPickedModel = true,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Message\Capability;

/**
 * A message's topic and the intent that routes it to a handler.
 */
final readonly class TopicIntent
{
    public function __construct(
        public string $topic,
        public string $intent,
    ) {
    }
}

<?php

namespace App\Service\Message\Classification;

/**
 * What a leading slash command means for the cascade: an answer that ends it
 * (a usage hint, a tool topic), or `/search` and `/docs` with an argument,
 * which strip the command and carry on as overlays.
 */
final readonly class SlashOutcome
{
    /**
     * @param array<string, mixed>|null $answer
     */
    public function __construct(
        public ?array $answer = null,
        public bool $forceWebSearch = false,
        public bool $docs = false,
    ) {
    }
}

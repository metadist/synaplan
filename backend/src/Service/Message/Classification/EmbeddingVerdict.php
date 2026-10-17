<?php

namespace App\Service\Message\Classification;

/**
 * What the embedding router made of a message: a classification that skips
 * the AI sorter, or a confident topic it declined because the language was
 * unknown — a request for the sorter that the deferral must not intercept.
 */
final readonly class EmbeddingVerdict
{
    /**
     * @param array<string, mixed>|null $classification
     */
    public function __construct(
        public ?array $classification = null,
        public bool $declinedForLanguage = false,
    ) {
    }
}

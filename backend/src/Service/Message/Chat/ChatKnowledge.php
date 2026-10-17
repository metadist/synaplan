<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

/**
 * What the knowledge base contributed to a chat turn, and the web results
 * the classifier already fetched for it.
 */
final readonly class ChatKnowledge
{
    /**
     * @param string                     $context    the knowledge-base block for the system prompt
     * @param list<array<string, mixed>> $sources    the documents the block was cut from
     * @param array<string, mixed>|null  $webResults the turn's web results (buffered turns)
     */
    public function __construct(
        public string $context = '',
        public array $sources = [],
        public ?array $webResults = null,
    ) {
    }
}

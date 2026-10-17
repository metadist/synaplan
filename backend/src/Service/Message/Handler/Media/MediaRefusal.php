<?php

namespace App\Service\Message\Handler\Media;

/**
 * A media turn that ends before anything is generated: the sentence the user
 * reads and the metadata the caller gets.
 */
final readonly class MediaRefusal
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $text,
        public array $metadata,
    ) {
    }

    /**
     * @return array{metadata: array<string, mixed>}
     */
    public function answer(callable $streamCallback): array
    {
        $streamCallback($this->text);

        return ['metadata' => $this->metadata];
    }
}

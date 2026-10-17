<?php

namespace App\Service\Message\Handler\Media;

/**
 * The model row that renders a media turn: its id, provider, provider-side
 * name and settings. No provider means no model is configured.
 */
final readonly class MediaModel
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public ?int $id,
        public ?string $provider,
        public ?string $name,
        public array $config,
    ) {
    }
}

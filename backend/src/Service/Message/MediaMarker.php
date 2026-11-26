<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * What a reply's `[IMAGE:…]` / `[VIDEO:…]` marker resolved to.
 *
 * `text` is the reply without any marker. `path` is empty when the reply had
 * no marker or the marker named something this account may not attach.
 */
final readonly class MediaMarker
{
    public function __construct(
        public string $text,
        public string $path = '',
        public string $type = '',
    ) {
    }

    public function hasFile(): bool
    {
        return '' !== $this->path;
    }
}

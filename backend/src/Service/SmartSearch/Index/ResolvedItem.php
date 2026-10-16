<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

/**
 * What the palette shows for an indexed hit, read from the source of truth.
 */
final readonly class ResolvedItem
{
    public function __construct(
        public string $title,
        public string $route,
        public ?string $subtitle = null,
        /** Display name of the owner when the item reached the user through a share. */
        public ?string $sharedBy = null,
    ) {
    }
}

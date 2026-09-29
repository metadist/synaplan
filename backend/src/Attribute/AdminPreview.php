<?php

declare(strict_types=1);

namespace App\Attribute;

/**
 * Marks a controller (or one action) as an admin preview.
 *
 * The feature id must be listed in {@see \App\Service\Feature\AdminPreview::FEATURES}.
 * {@see \App\EventListener\AdminPreviewListener} answers 404 for everyone who
 * is not an admin. Release the feature by deleting this attribute and the id
 * together.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class AdminPreview
{
    public function __construct(
        public string $feature,
    ) {
    }
}

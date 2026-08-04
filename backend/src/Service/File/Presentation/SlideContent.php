<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * The parsed content of a single slide, independent of how it gets drawn.
 *
 * Images are kept as the model-facing marker references (`file:42`) and only
 * resolved to a path by the renderer, so an unresolvable reference costs the
 * picture and never the presentation.
 */
final readonly class SlideContent
{
    /**
     * @param list<SlideBullet> $bullets
     * @param list<string>      $imageReferences
     * @param list<SlideTable>  $tables
     */
    public function __construct(
        public ?string $title,
        public array $bullets = [],
        public array $imageReferences = [],
        public array $tables = [],
        public bool $titleSlide = false,
    ) {
    }

    public function hasBody(): bool
    {
        return [] !== $this->bullets || [] !== $this->tables;
    }

    public function isEmpty(): bool
    {
        return null === $this->title && !$this->hasBody() && [] === $this->imageReferences;
    }
}

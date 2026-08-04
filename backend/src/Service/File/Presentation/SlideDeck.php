<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * A whole parsed presentation: the look the model asked for plus its slides.
 */
final readonly class SlideDeck
{
    /**
     * @param list<SlideContent> $slides
     */
    public function __construct(
        public array $slides,
        public PptxTheme $theme = PptxTheme::Default,
        public SlideTransitionKind $transition = SlideTransitionKind::None,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * A styled fragment of a slide text line.
 *
 * Inline markdown is kept as real formatting instead of being stripped: a bullet
 * written as `**Born:** 2019` becomes a bold run plus a normal run, which is what
 * the user sees in PowerPoint — the old writer dropped the markers and lost the
 * emphasis entirely.
 */
final readonly class SlideTextRun
{
    public function __construct(
        public string $text,
        public bool $bold = false,
        public bool $italic = false,
        public bool $monospace = false,
    ) {
    }
}

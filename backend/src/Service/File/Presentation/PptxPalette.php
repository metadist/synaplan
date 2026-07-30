<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * Colors of one presentation theme, as 6-digit RGB hex without a leading hash.
 *
 * Every ink color is picked to clear WCAG AA against the background (>= 4.5:1
 * for body text, >= 3:1 for the large title and the accent rule), so a
 * generated deck stays readable when it is projected. `onAccent` is the ink for
 * text drawn on top of an accent-filled surface, such as a table header row.
 * {@see \App\Tests\Unit\Service\File\Presentation\PptxThemeTest} measures the
 * ratios and fails the build if a palette regresses.
 */
final readonly class PptxPalette
{
    public function __construct(
        public string $background,
        public string $title,
        public string $body,
        public string $accent,
        public string $muted,
        public string $onAccent,
    ) {
    }
}

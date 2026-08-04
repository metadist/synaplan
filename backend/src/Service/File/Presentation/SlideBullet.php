<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * One body line of a slide.
 *
 * Despite the name this covers every non-title text line: a bulleted item, a
 * numbered item, a plain paragraph ({@see SlideBulletMarker::None}) and a
 * sub-heading (`### …`, which sets {@see self::$heading} so the renderer can give
 * it accent color and breathing room).
 */
final readonly class SlideBullet
{
    /** Deepest indent level a slide body renders; deeper input is clamped. */
    public const MAX_LEVEL = 3;

    /**
     * @param list<SlideTextRun> $runs
     */
    public function __construct(
        public array $runs,
        public int $level = 0,
        public SlideBulletMarker $marker = SlideBulletMarker::Dot,
        public bool $heading = false,
    ) {
    }

    public function text(): string
    {
        return implode('', array_map(static fn (SlideTextRun $run): string => $run->text, $this->runs));
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->text());
    }
}

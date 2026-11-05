<?php

declare(strict_types=1);

namespace App\Service\Message\Classification;

/**
 * How an attached picture should be changed — image edit, file work, or ask.
 */
enum ImageEditKind
{
    /** No attached image, or the text is not asking to change that picture. */
    case None;

    /** Stamp, watermark, or a named script — pixel-exact file work. */
    case Programmatic;

    /** Composition, layout, or look — PIC2PIC image edit, not file work. */
    case Aesthetic;

    /** Crop/resize/scale without more context — ask before choosing. */
    case Ambiguous;
}

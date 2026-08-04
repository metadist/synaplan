<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * How a slide body line is marked: as a bulleted item, a numbered item, or not
 * at all (a plain paragraph or a sub-heading).
 */
enum SlideBulletMarker
{
    case None;
    case Dot;
    case Number;
}

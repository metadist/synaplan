<?php

declare(strict_types=1);

namespace App\AI\Image;

/**
 * An image part the upstream cannot accept and Synaplan cannot convert.
 * The message is written for the end user.
 */
final class UnsupportedImageInputException extends \RuntimeException
{
}

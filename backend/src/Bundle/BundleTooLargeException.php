<?php

declare(strict_types=1);

namespace App\Bundle;

/**
 * The uploaded body exceeds the bundle size limit. Raised before decoding,
 * so an oversized upload never reaches json_decode.
 */
final class BundleTooLargeException extends \RuntimeException
{
    public function __construct(public readonly int $maxBytes)
    {
        parent::__construct(sprintf('bundle is larger than %d MB', intdiv($maxBytes, 1024 * 1024)));
    }
}

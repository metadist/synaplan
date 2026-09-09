<?php

declare(strict_types=1);

namespace App\Bundle;

final class BundleEnvelopeException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $path,
        string $message,
    ) {
        parent::__construct(sprintf('%s: %s', $path, $message));
    }
}

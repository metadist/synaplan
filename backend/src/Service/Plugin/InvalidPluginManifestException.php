<?php

declare(strict_types=1);

namespace App\Service\Plugin;

final class InvalidPluginManifestException extends \RuntimeException
{
    public function __construct(string $field, string $reason)
    {
        parent::__construct(sprintf('Plugin manifest field "%s": %s', $field, $reason));
    }
}

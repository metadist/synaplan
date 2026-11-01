<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * A provider name the catalog does not know.
 */
final class UnknownProviderException extends \InvalidArgumentException
{
    /**
     * @param list<string> $known
     */
    public static function forName(string $name, array $known): self
    {
        return new self(sprintf('Unknown AI provider "%s". Known: %s.', $name, implode(', ', $known)));
    }
}

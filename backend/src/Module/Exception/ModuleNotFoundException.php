<?php

declare(strict_types=1);

namespace App\Module\Exception;

final class ModuleNotFoundException extends \RuntimeException
{
    /**
     * @param list<string> $knownIds
     */
    public static function forId(string $id, array $knownIds): self
    {
        return new self(sprintf(
            'Unknown feature module "%s". Known modules: %s',
            $id,
            [] === $knownIds ? '(none registered)' : implode(', ', $knownIds),
        ));
    }
}

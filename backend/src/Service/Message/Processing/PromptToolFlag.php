<?php

declare(strict_types=1);

namespace App\Service\Message\Processing;

/**
 * A prompt's tool flag as the turn decides on it. The prompt service hands
 * flags over as booleans; a classifier verdict or a stored task can carry
 * them as '0' / '1'. A missing flag is no preference. A scalar reads as PHP
 * converts it to a boolean, so a flag means what it meant before strict
 * types; a value that is no flag at all is refused where it enters.
 */
final class PromptToolFlag
{
    /**
     * @param array<string, mixed> $promptMetadata
     */
    public static function read(array $promptMetadata, string $flag): ?bool
    {
        $value = $promptMetadata[$flag] ?? null;
        if (null === $value || \is_bool($value)) {
            return $value;
        }
        if (\is_scalar($value)) {
            return (bool) $value;
        }

        throw new \InvalidArgumentException(\sprintf('Prompt tool flag "%s" must be a boolean or a scalar, %s given.', $flag, get_debug_type($value)));
    }
}

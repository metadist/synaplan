<?php

declare(strict_types=1);

namespace App\Service\Usage;

/**
 * The install and alias of the Synaplan Demo call that is in progress.
 *
 * The gate sets it for the request. The usage writer copies it onto the
 * BUSELOG row of the chat, embedding or image that follows. A worker clears
 * it at the start and end of every request, so one call cannot stamp the next.
 */
final class DemoCallContext
{
    private static ?string $installId = null;

    private static ?string $alias = null;

    public static function attach(string $installId, string $alias): void
    {
        $installId = UsageOutcome::installId($installId);
        $alias = UsageOutcome::token($alias);
        self::$installId = '' !== $installId ? $installId : null;
        self::$alias = '' !== $alias ? $alias : null;
    }

    public static function clear(): void
    {
        self::$installId = null;
        self::$alias = null;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public static function apply(array $metadata): array
    {
        if (null !== self::$installId && !isset($metadata['install_id'])) {
            $metadata['install_id'] = self::$installId;
        }
        if (null !== self::$alias && !isset($metadata['alias'])) {
            $metadata['alias'] = self::$alias;
        }

        return $metadata;
    }
}

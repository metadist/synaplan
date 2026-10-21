<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Pause after each chunk of a {@see TestProvider} stream.
 *
 * SYNAPLAN_TEST_PROVIDER_CHUNK_MS stretches the canned stream so a benchmark
 * can hold a worker without a paid provider. Unset, empty, or not a
 * non-negative number stays at 50 ms, the historical default.
 */
final class TestProviderChunkDelay
{
    public const DEFAULT_MS = 50;

    public static function microseconds(): int
    {
        $raw = $_SERVER['SYNAPLAN_TEST_PROVIDER_CHUNK_MS']
            ?? $_ENV['SYNAPLAN_TEST_PROVIDER_CHUNK_MS']
            ?? getenv('SYNAPLAN_TEST_PROVIDER_CHUNK_MS');

        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return self::DEFAULT_MS * 1000;
        }
        if (!is_numeric($raw) || (float) $raw < 0) {
            return self::DEFAULT_MS * 1000;
        }

        return (int) round((float) $raw * 1000);
    }
}

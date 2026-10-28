<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use PHPUnit\Framework\Assert;

/**
 * File-backed snapshot for the S1 AI-path characterization suite (AU9).
 *
 * Record with UPDATE_AI_PATH_SNAPSHOTS=1. A missing file is a failure, so CI
 * cannot pass without the committed baseline.
 */
final class AiPathSnapshot
{
    public const UPDATE_ENV = 'UPDATE_AI_PATH_SNAPSHOTS';

    /**
     * @param array<string, mixed> $actual
     */
    public static function assertSame(string $path, array $actual, string $what): void
    {
        $snapshot = new RoutingSnapshot($path);

        if (RoutingSnapshot::updateMode(self::UPDATE_ENV)) {
            $snapshot->write($actual);
            Assert::assertNotEmpty($actual, 'Recorded '.$what.' baseline.');

            return;
        }

        Assert::assertTrue(
            $snapshot->exists(),
            'Missing '.$what.' baseline. Generate it once with '.self::UPDATE_ENV.'=1 and commit '.$path,
        );

        Assert::assertSame(
            RoutingSnapshot::encodeCase($snapshot->load()),
            RoutingSnapshot::encodeCase($actual),
            $what.' drifted from the committed snapshot.',
        );
    }
}

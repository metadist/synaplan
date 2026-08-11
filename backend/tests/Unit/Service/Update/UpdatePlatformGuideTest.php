<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Update;

use App\Service\Update\UpdatePlatformGuide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SYNAPLAN_PLATFORM is an optional deployment hint, so an unset, empty or
 * unknown value must resolve to the self-hosted guide rather than to a broken
 * link.
 */
final class UpdatePlatformGuideTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function platformProvider(): iterable
    {
        yield 'elestio' => [
            'elestio',
            UpdatePlatformGuide::PLATFORM_ELESTIO,
            UpdatePlatformGuide::GUIDE_URL_ELESTIO,
        ];
        yield 'elestio with stray whitespace and case' => [
            '  Elestio ',
            UpdatePlatformGuide::PLATFORM_ELESTIO,
            UpdatePlatformGuide::GUIDE_URL_ELESTIO,
        ];
        yield 'aws' => [
            'aws',
            UpdatePlatformGuide::PLATFORM_AWS,
            UpdatePlatformGuide::GUIDE_URL_AWS,
        ];
        yield 'selfhost' => [
            'selfhost',
            UpdatePlatformGuide::PLATFORM_SELFHOST,
            UpdatePlatformGuide::GUIDE_URL_SELFHOST,
        ];
        yield 'unset' => [
            '',
            UpdatePlatformGuide::PLATFORM_SELFHOST,
            UpdatePlatformGuide::GUIDE_URL_SELFHOST,
        ];
        yield 'unknown platform' => [
            'some-future-marketplace',
            UpdatePlatformGuide::PLATFORM_SELFHOST,
            UpdatePlatformGuide::GUIDE_URL_SELFHOST,
        ];
    }

    #[DataProvider('platformProvider')]
    public function testPlatformHintResolvesToTheMatchingGuide(
        string $configured,
        string $expectedPlatform,
        string $expectedGuideUrl,
    ): void {
        $guide = new UpdatePlatformGuide($configured);

        self::assertSame($expectedPlatform, $guide->platform());
        self::assertSame($expectedGuideUrl, $guide->guideUrl());
    }
}

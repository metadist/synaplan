<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bundle;

use App\Bundle\BundleRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class BundleRateLimitTest extends TestCase
{
    public function testEleventhImportIsDenied(): void
    {
        $limiter = new BundleRateLimiter(new ArrayAdapter());

        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($limiter->consume(4, BundleRateLimiter::ACTION_IMPORT));
        }
        self::assertFalse($limiter->consume(4, BundleRateLimiter::ACTION_IMPORT));
        self::assertTrue($limiter->consume(5, BundleRateLimiter::ACTION_IMPORT));
    }

    public function testTwentyFirstExportIsDenied(): void
    {
        $limiter = new BundleRateLimiter(new ArrayAdapter());

        for ($i = 0; $i < 20; ++$i) {
            self::assertTrue($limiter->consume(4, BundleRateLimiter::ACTION_EXPORT));
        }
        self::assertFalse($limiter->consume(4, BundleRateLimiter::ACTION_EXPORT));
    }
}

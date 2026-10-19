<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Config\Readiness;

use App\Service\Config\Readiness\ComposeServiceLookup;
use PHPUnit\Framework\TestCase;

final class ComposeServiceLookupTest extends TestCase
{
    public function testAnUnknownNameIsAbsentAndDoesNotWarn(): void
    {
        // Symfony's dev handler throws on warnings unless the call is silenced,
        // which is what would take down GET /api/v1/config/features.
        set_error_handler(static function (int $severity, string $message): bool {
            if (0 === error_reporting()) {
                return true;
            }

            throw new \ErrorException($message, 0, $severity);
        });

        try {
            self::assertFalse((new ComposeServiceLookup())->exists('synaplan-readiness-missing.invalid'));
        } finally {
            restore_error_handler();
        }
    }
}

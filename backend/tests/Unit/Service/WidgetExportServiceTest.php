<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\WidgetExportService;
use PHPUnit\Framework\TestCase;

class WidgetExportServiceTest extends TestCase
{
    public function testResolveTimezoneWithValidTimezone(): void
    {
        $tz = WidgetExportService::resolveTimezone('Europe/Berlin');

        $this->assertSame('Europe/Berlin', $tz->getName());
    }

    public function testResolveTimezoneWithUtc(): void
    {
        $tz = WidgetExportService::resolveTimezone('UTC');

        $this->assertSame('UTC', $tz->getName());
    }

    public function testResolveTimezoneFallsBackToUtcOnInvalidInput(): void
    {
        $tz = WidgetExportService::resolveTimezone('Invalid/Timezone');

        $this->assertSame('UTC', $tz->getName());
    }

    public function testResolveTimezoneFallsBackToUtcOnEmptyString(): void
    {
        $tz = WidgetExportService::resolveTimezone('');

        $this->assertSame('UTC', $tz->getName());
    }

    public function testResolveTimezoneWithAmericaNewYork(): void
    {
        $tz = WidgetExportService::resolveTimezone('America/New_York');

        $this->assertSame('America/New_York', $tz->getName());
    }

    public function testResolveTimezoneWithAsiaTokyo(): void
    {
        $tz = WidgetExportService::resolveTimezone('Asia/Tokyo');

        $this->assertSame('Asia/Tokyo', $tz->getName());
    }
}

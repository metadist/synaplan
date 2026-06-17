<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Prompt;

use App\Service\Prompt\TimeContextBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TimeContextBuilderTest extends TestCase
{
    /** 19:05 UTC == 21:05 CEST (Germany, June) — the exact case that motivated this. */
    private const INSTANT_UTC = '2026-06-17 19:05:00';

    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
    }

    private function builder(): TimeContextBuilder
    {
        return new TimeContextBuilder(
            new MockClock(new \DateTimeImmutable(self::INSTANT_UTC, new \DateTimeZone('UTC'))),
        );
    }

    public function testProfileTimezoneIsAuthoritativeAndConvertsTheClock(): void
    {
        // Country says US (ambiguous) but the profile timezone must win.
        $out = $this->builder()->build('Europe/Berlin', 'US');

        self::assertStringContainsString('Current local time for the user', $out);
        self::assertStringContainsString('Wednesday, 17 June 2026, 21:05', $out);
        self::assertStringContainsString('Europe/Berlin', $out);
        self::assertStringContainsString('UTC+02:00', $out);
        self::assertStringContainsString('2026-06-17T21:05:00+02:00', $out);
    }

    public function testCountryFallbackUsedWhenSingleOffset(): void
    {
        $out = $this->builder()->build(null, 'DE');

        self::assertStringContainsString('inferred from approximate country DE', $out);
        self::assertStringContainsString('Wednesday, 17 June 2026, 21:05', $out);
        self::assertStringContainsString('UTC+02:00', $out);
        // We only know the country, so we must NOT assert a specific city zone.
        self::assertStringNotContainsString('Europe/Berlin', $out);
        self::assertStringContainsString('ask the user to confirm', $out);
    }

    public function testCountryIsLowercaseTolerant(): void
    {
        $out = $this->builder()->build(null, 'de');

        self::assertStringContainsString('inferred from approximate country DE', $out);
        self::assertStringContainsString('21:05', $out);
    }

    public function testAmbiguousCountryFallsBackToServer(): void
    {
        // The US spans many offsets — we cannot state a single local time.
        date_default_timezone_set('UTC');
        $out = $this->builder()->build(null, 'US');

        self::assertStringContainsString('Current server time', $out);
        self::assertStringContainsString('Wednesday, 17 June 2026, 19:05', $out);
        self::assertStringContainsString('UTC+00:00', $out);
        self::assertStringContainsString("user's timezone is unknown", $out);
    }

    public function testNoSignalsFallsBackToServer(): void
    {
        date_default_timezone_set('UTC');
        $out = $this->builder()->build(null, null);

        self::assertStringContainsString('Current server time', $out);
        self::assertStringContainsString('19:05', $out);
    }

    public function testServerFallbackHonoursServerTimezone(): void
    {
        // A non-UTC server must render in its own zone, not UTC.
        date_default_timezone_set('Europe/Berlin');
        $out = $this->builder()->build(null, null);

        self::assertStringContainsString('Current server time', $out);
        self::assertStringContainsString('Wednesday, 17 June 2026, 21:05', $out);
        self::assertStringContainsString('Europe/Berlin', $out);
    }

    public function testInvalidProfileTimezoneIsIgnored(): void
    {
        // Garbage profile value must not throw — it falls through to country DE.
        $out = $this->builder()->build('Not/AZone', 'DE');

        self::assertStringContainsString('inferred from approximate country DE', $out);
        self::assertStringContainsString('21:05', $out);
    }

    public function testSentinelCountryIsIgnored(): void
    {
        date_default_timezone_set('UTC');

        foreach (['XX', 'T1'] as $sentinel) {
            $out = $this->builder()->build(null, $sentinel);
            self::assertStringContainsString('Current server time', $out, "sentinel {$sentinel} must be ignored");
        }
    }

    public function testRelativeDateInstructionAlwaysPresent(): void
    {
        $out = $this->builder()->build('Europe/Berlin', null);

        self::assertStringContainsString('Use this as "now"', $out);
        self::assertStringStartsWith("\n\n## Current date and time", $out);
    }

    public function testDefaultClockConstructsWithoutInjection(): void
    {
        // The NativeClock default must keep the service usable when no clock
        // is wired (belt-and-suspenders against autowiring surprises).
        $out = (new TimeContextBuilder())->build('Europe/Berlin', null);

        self::assertStringContainsString('## Current date and time', $out);
    }
}
